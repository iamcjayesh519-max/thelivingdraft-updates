<?php
/**
 * Template tags — the page furniture, in one place.
 *
 * @package LivingDraft
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A marker, not a feature.
 *
 * Living Draft Core checks for this before deciding whether to append the
 * update log to the end of the story. This theme prints it properly, under
 * the byline, so the plugin stays out of the way.
 *
 * @return bool
 */
function livingdraft_theme_prints_update_log() {
	return true;
}

/* ------------------------------------------------------------------
 * Masthead furniture
 * ------------------------------------------------------------------ */

/**
 * Roman numerals, for the volume number in the masthead.
 *
 * @param int $number Any number from 1 to 3999.
 * @return string
 */
function livingdraft_roman( $number ) {
	$number = (int) $number;
	if ( $number < 1 || $number > 3999 ) {
		return (string) $number;
	}

	$map = array(
		'M'  => 1000,
		'CM' => 900,
		'D'  => 500,
		'CD' => 400,
		'C'  => 100,
		'XC' => 90,
		'L'  => 50,
		'XL' => 40,
		'X'  => 10,
		'IX' => 9,
		'V'  => 5,
		'IV' => 4,
		'I'  => 1,
	);

	$out = '';
	foreach ( $map as $glyph => $value ) {
		while ( $number >= $value ) {
			$out    .= $glyph;
			$number -= $value;
		}
	}
	return $out;
}

/**
 * Volume and issue number for the top-left of the masthead.
 *
 * The issue number is the count of published stories, cached for an hour so
 * the front page never pays for a COUNT on every request.
 *
 * @return array {volume, number}
 */
function livingdraft_edition() {
	$this_year = (int) wp_date( 'Y' );
	$founded   = (int) get_theme_mod( 'livingdraft_founded', $this_year );

	if ( $founded < 1800 || $founded > $this_year ) {
		$founded = $this_year;
	}

	$number = get_transient( 'livingdraft_issue_no' );
	if ( false === $number ) {
		$counts = wp_count_posts( 'post' );
		$number = isset( $counts->publish ) ? (int) $counts->publish : 0;
		set_transient( 'livingdraft_issue_no', $number, HOUR_IN_SECONDS );
	}

	return array(
		'volume' => livingdraft_roman( ( $this_year - $founded ) + 1 ),
		'number' => max( 1, (int) $number ),
	);
}

/**
 * The most-used tags, for the index line under the navigation.
 *
 * Cached: this runs in the header on every request, cache hit or not.
 *
 * @param int $limit How many tags.
 * @return array
 */
function livingdraft_top_tags( $limit = 8 ) {
	// Capped so the flush loop (1..12) can always clean up every transient.
	$limit = max( 1, min( 12, (int) $limit ) );
	$key   = 'livingdraft_top_tags_' . $limit;

	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$tags = get_tags(
		array(
			'orderby'    => 'count',
			'order'      => 'DESC',
			'number'     => $limit,
			'hide_empty' => true,
		)
	);

	if ( is_wp_error( $tags ) || ! is_array( $tags ) ) {
		$tags = array();
	}

	// Only what the strip prints, so the cached row stays small.
	$slim = array();
	foreach ( $tags as $tag ) {
		$slim[] = array(
			'name' => $tag->name,
			'url'  => get_tag_link( $tag->term_id ),
		);
	}

	set_transient( $key, $slim, 12 * HOUR_IN_SECONDS );

	return $slim;
}

/**
 * Clear the cached edition number and index line when the paper changes.
 *
 * Only fires when a post moves into or out of 'publish' — draft autosaves
 * would otherwise flush the cache on every keystroke.
 *
 * @param string $new_status New post status.
 * @param string $old_status Previous post status.
 */
function livingdraft_flush_edition_cache( $new_status = '', $old_status = '' ) {
	if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
		return;
	}

	delete_transient( 'livingdraft_issue_no' );

	for ( $i = 1; $i <= 12; $i++ ) {
		delete_transient( 'livingdraft_top_tags_' . $i );
	}

	// Slider transients (see Patch 2.5). Range covers the min(3)–max(20)
	// slider_count values the customizer can produce.
	for ( $s = 3; $s <= 20; $s++ ) {
		delete_transient( 'livingdraft_slider_' . $s );
	}
}
add_action( 'transition_post_status', 'livingdraft_flush_edition_cache', 10, 2 );

/* ------------------------------------------------------------------
 * Story furniture
 * ------------------------------------------------------------------ */

/**
 * True when a post was meaningfully revised after publication.
 *
 * @param int|null $post_id Post ID.
 * @return bool
 */
function livingdraft_is_revised( $post_id = null ) {
	$post_id   = $post_id ? $post_id : get_the_ID();
	$published = (int) get_post_time( 'U', true, $post_id );
	$modified  = (int) get_post_modified_time( 'U', true, $post_id );

	$window = (int) apply_filters( 'livingdraft_revision_window', 6 * HOUR_IN_SECONDS );

	return ( $modified - $published ) > $window;
}

/**
 * The kicker: the section a story sits in, set in red above the headline.
 *
 * @param bool $echo Print or return.
 * @return string
 */
function livingdraft_kicker( $echo = true ) {
	$cats = get_the_category();
	$out  = '';

	if ( ! empty( $cats ) ) {
		$out = sprintf(
			'<div class="kicker-line"><a href="%s">%s</a>%s</div>',
			esc_url( get_category_link( $cats[0]->term_id ) ),
			esc_html( $cats[0]->name ),
			livingdraft_label( false )
		);
	} else {
		$label = livingdraft_label( false );
		if ( $label ) {
			$out = '<div class="kicker-line">' . $label . '</div>';
		}
	}

	if ( $echo ) {
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	return $out;
}

/**
 * The editorial label — Opinion, Analysis, Sponsored and so on. This is not a
 * category: it says what kind of writing this is, not what it is about.
 *
 * @param bool     $echo    Print or return.
 * @param int|null $post_id Post ID.
 * @return string
 */
function livingdraft_label( $echo = true, $post_id = null ) {
	$post_id = $post_id ? $post_id : get_the_ID();
	$key     = get_post_meta( $post_id, '_livingdraft_label', true );
	$labels  = livingdraft_label_choices();

	$out = '';
	if ( $key && isset( $labels[ $key ] ) ) {
		$out = sprintf(
			'<span class="label label-%s">%s</span>',
			esc_attr( $key ),
			esc_html( $labels[ $key ] )
		);
	}

	if ( $echo ) {
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	return $out;
}

/**
 * The labels a story can carry.
 *
 * @return array
 */
if ( ! function_exists( 'livingdraft_label_choices' ) ) :
	function livingdraft_label_choices() {
		return apply_filters(
			'livingdraft_label_choices',
			array(
				'opinion'   => __( 'Opinion', 'livingdraft' ),
				'analysis'  => __( 'Analysis', 'livingdraft' ),
				'interview' => __( 'Interview', 'livingdraft' ),
				'review'    => __( 'Review', 'livingdraft' ),
				'explainer' => __( 'Explainer', 'livingdraft' ),
				'sponsored' => __( 'Sponsored', 'livingdraft' ),
			)
		);
	}
endif;

/**
 * The mono metadata line: section, timestamp, revision marker.
 *
 * @param array $args Options: show_author, show_cat, show_rev.
 */
function livingdraft_stamp( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'show_author' => false,
			'show_cat'    => true,
			'show_rev'    => true,
		)
	);

	echo '<div class="stamp">';

	if ( $args['show_cat'] ) {
		$cats = get_the_category();
		if ( ! empty( $cats ) ) {
			printf(
				'<a class="kicker" href="%s">%s</a><span class="sep">/</span>',
				esc_url( get_category_link( $cats[0]->term_id ) ),
				esc_html( $cats[0]->name )
			);
		}
	}

	printf(
		'<time datetime="%s">%s</time>',
		esc_attr( get_the_date( DATE_W3C ) ),
		esc_html( get_the_date( 'j M Y' ) )
	);

	if ( $args['show_author'] ) {
		printf(
			'<span class="sep">/</span><a href="%s" rel="author">%s</a>',
			esc_url( get_author_posts_url( get_the_author_meta( 'ID' ) ) ),
			esc_html( livingdraft_authors_text() )
		);
	}

	if ( $args['show_rev'] && livingdraft_is_revised() ) {
		printf(
			'<span class="live">%s %s</span>',
			esc_html__( 'Rev.', 'livingdraft' ),
			esc_html( get_the_modified_date( 'j M' ) )
		);
	}

	echo '</div>';
}

/**
 * The byline names, as plain text. Two-name bylines are the newsroom norm,
 * so a second name can be typed in without installing a co-authors plugin.
 *
 * @param int|null $post_id Post ID.
 * @return string
 */
function livingdraft_authors_text( $post_id = null ) {
	$post_id = $post_id ? $post_id : get_the_ID();
	$primary = get_the_author_meta( 'display_name', (int) get_post_field( 'post_author', $post_id ) );
	$second  = trim( (string) get_post_meta( $post_id, '_livingdraft_coauthor', true ) );

	if ( '' === $second ) {
		return (string) $primary;
	}

	return sprintf(
		/* translators: 1: first byline name. 2: second byline name. */
		_x( '%1$s and %2$s', 'byline', 'livingdraft' ),
		$primary,
		$second
	);
}

/**
 * The byline rule under a headline: author, filing time, revision, length.
 *
 * @param bool $show_reading_time Append the reading estimate.
 */
function livingdraft_byline( $show_reading_time = true ) {
	$second = trim( (string) get_post_meta( get_the_ID(), '_livingdraft_coauthor', true ) );
	?>
	<div class="byline">
		<span class="byline-authors">
			<?php esc_html_e( 'By', 'livingdraft' ); ?>
			<a href="<?php echo esc_url( get_author_posts_url( get_the_author_meta( 'ID' ) ) ); ?>" rel="author"><?php the_author(); ?></a>
			<?php if ( '' !== $second ) : ?>
				<?php
				printf(
					/* translators: %s: the second name on a two-name byline. */
					esc_html__( 'and %s', 'livingdraft' ),
					esc_html( $second )
				);
				?>
			<?php endif; ?>
		</span>
		<time datetime="<?php echo esc_attr( get_the_date( DATE_W3C ) ); ?>">
			<?php echo esc_html( get_the_date( 'j M Y, H:i' ) ); ?>
		</time>
		<?php if ( livingdraft_is_revised() ) : ?>
			<span class="revised">
				<time datetime="<?php echo esc_attr( get_the_modified_date( DATE_W3C ) ); ?>">
					<?php
					printf(
						/* translators: %s: date the story was last updated. */
						esc_html__( 'Revised %s', 'livingdraft' ),
						esc_html( get_the_modified_date( 'j M Y, H:i' ) )
					);
					?>
				</time>
			</span>
		<?php endif; ?>
		<?php if ( $show_reading_time ) : ?>
			<span>
				<?php
				printf(
					/* translators: %d: number of minutes. */
					esc_html__( '%d min read', 'livingdraft' ),
					(int) livingdraft_reading_time()
				);
				?>
			</span>
		<?php endif; ?>
		<?php
		/*
		 * The read count, if the desk has chosen to show it. Off by default:
		 * a low number under a headline discourages the next reader, and a
		 * story published an hour ago always has a low number. Turn it on
		 * once the counts are worth showing.
		 *
		 * livingdraft_views_total() lives in The Living Draft Core, so this
		 * prints nothing when the plugin is absent.
		 */
		if ( get_theme_mod( 'livingdraft_show_view_count', false ) && function_exists( 'livingdraft_views_total' ) ) :
			$ld_reads = (int) livingdraft_views_total();
			if ( $ld_reads >= (int) apply_filters( 'livingdraft_view_count_floor', 50 ) ) :
				?>
				<span class="byline-reads">
					<?php
					printf(
						/* translators: %s: number of times the story has been read. */
						esc_html__( '%s reads', 'livingdraft' ),
						esc_html( number_format_i18n( $ld_reads ) )
					);
					?>
				</span>
				<?php
			endif;
		endif;
		?>
	</div>
	<?php
}

/**
 * The opening of a story, for the front page. Whole paragraphs only, so the
 * drop cap always has a paragraph to sit in and no sentence is cut mid-clause.
 *
 * Running the_content filters is expensive, so the result is cached against
 * the post's modified time and thrown away the moment the story is edited.
 *
 * @param int $paragraphs How many paragraphs to carry.
 * @return string
 */
function livingdraft_teaser( $paragraphs = 3 ) {
	$post_id    = get_the_ID();
	$paragraphs = max( 1, (int) $paragraphs );
	$key        = 'livingdraft_teaser_' . $post_id . '_' . $paragraphs;
	$stamp      = (string) get_post_modified_time( 'U', true, $post_id );

	$cached = get_transient( $key );
	if ( is_array( $cached ) && isset( $cached['stamp'], $cached['html'] ) && $cached['stamp'] === $stamp ) {
		return $cached['html'];
	}

	$content = apply_filters( 'the_content', strip_shortcodes( get_the_content() ) );

	preg_match_all( '/<p[^>]*>.*?<\/p>/is', (string) $content, $matches );

	if ( empty( $matches[0] ) ) {
		$excerpt = get_the_excerpt();
		$html    = $excerpt ? '<p>' . esc_html( $excerpt ) . '</p>' : '';
	} else {
		$html = wp_kses_post( implode( "\n", array_slice( $matches[0], 0, $paragraphs ) ) );
	}

	set_transient(
		$key,
		array(
			'stamp' => $stamp,
			'html'  => $html,
		),
		WEEK_IN_SECONDS
	);

	return $html;
}

/**
 * How many words are in the story. Multibyte-safe: str_word_count() counts
 * bytes, which badly undercounts Devanagari and every other non-Latin script.
 *
 * @return int
 */
function livingdraft_word_count() {
	static $cache = array();

	$post_id = get_the_ID();
	if ( isset( $cache[ $post_id ] ) ) {
		return $cache[ $post_id ];
	}

	$text  = wp_strip_all_tags( strip_shortcodes( get_the_content() ) );
	$words = preg_split( '/[\s\x{3000}]+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );

	$cache[ $post_id ] = is_array( $words ) ? count( $words ) : 0;

	return $cache[ $post_id ];
}

/**
 * Estimated reading time, in whole minutes.
 *
 * @return int
 */
function livingdraft_reading_time() {
	return max( 1, (int) ceil( livingdraft_word_count() / 220 ) );
}

/**
 * The heading over the column grid.
 *
 * @return string
 */
function livingdraft_section_title() {
	if ( is_paged() ) {
		return __( 'Earlier Stories', 'livingdraft' );
	}

	if ( is_archive() ) {
		return __( 'More in this Section', 'livingdraft' );
	}

	return __( 'More from this Edition', 'livingdraft' );
}

/* ------------------------------------------------------------------
 * Navigation
 * ------------------------------------------------------------------ */

/**
 * Numbered pagination.
 */
function livingdraft_pagination() {
	// Pagination style comes from Customizer → Front page → Pagination style.
	// Numbered (default) uses paginate_links(); load_more / infinite render a
	// button that assets/js/load-more.js hooks into to fetch the next page.
	$style = get_theme_mod( 'livingdraft_pagination_style', 'numbered' );

	// Not on paged views if there's nothing beyond page 1.
	global $wp_query;
	$total = isset( $wp_query->max_num_pages ) ? (int) $wp_query->max_num_pages : 1;
	if ( $total <= 1 ) {
		return;
	}

	if ( 'load_more' === $style || 'infinite' === $style ) {
		$current  = max( 1, get_query_var( 'paged' ) );
		$next     = $current + 1;
		$next_url = get_pagenum_link( $next );

		// data-auto="1" tells load-more.js to click the button when it enters
		// the viewport, giving infinite-scroll behaviour. Otherwise the reader
		// clicks it manually. Same HTML, one attribute difference.
		printf(
			'<nav class="pagination" aria-label="%s">
				<button type="button" class="ld-load-more"
					data-next-url="%s"
					data-current-page="%d"
					data-max-pages="%d"
					data-auto="%s">%s</button>
				<noscript><a class="ld-load-more" href="%s">%s</a></noscript>
			</nav>' . "\n",
			esc_attr__( 'Posts', 'livingdraft' ),
			esc_url( $next_url ),
			(int) $current,
			(int) $total,
			'infinite' === $style ? '1' : '0',
			esc_html__( 'Load more stories', 'livingdraft' ),
			esc_url( $next_url ),
			esc_html__( 'Next page', 'livingdraft' )
		);
		return;
	}

	// Default: numbered pagination.
	$links = paginate_links(
		array(
			'type'      => 'plain',
			'mid_size'  => 1,
			'prev_text' => __( 'Previous', 'livingdraft' ),
			'next_text' => __( 'Next', 'livingdraft' ),
		)
	);

	if ( ! $links ) {
		return;
	}

	echo '<nav class="pagination" aria-label="' . esc_attr__( 'Posts', 'livingdraft' ) . '">';
	echo wp_kses_post( $links );
	echo '</nav>';
}

/**
 * Where the reader is, as a trail. Also the source for the BreadcrumbList
 * structured data, so the two can never disagree.
 *
 * @return array List of {name, url}. The last item has an empty url.
 */
function livingdraft_breadcrumb_trail() {
	$trail = array(
		array(
			/*
			 * "Home", not "Front page".
			 *
			 * These two have to agree with the BreadcrumbList the plugin
			 * emits in inc/seo/schema.php, which has always said "Home".
			 * Google's structured data guidance is that the markup must
			 * describe what the reader actually sees on the page; a crumb
			 * reading "Front page" while the schema for the same crumb says
			 * "Home" is a mismatch on every article on the site.
			 *
			 * Filterable so the label can be changed in one place without
			 * editing the theme — but change the schema to match if you do.
			 */
			'name' => apply_filters( 'livingdraft_breadcrumb_home_label', __( 'Home', 'livingdraft' ) ),
			'url'  => home_url( '/' ),
		),
	);

	if ( is_singular( 'post' ) ) {
		$cats = get_the_category();
		if ( ! empty( $cats ) ) {
			$trail[] = array(
				'name' => $cats[0]->name,
				'url'  => get_category_link( $cats[0]->term_id ),
			);
		}
		$trail[] = array(
			'name' => get_the_title(),
			'url'  => '',
		);
	} elseif ( is_page() ) {
		foreach ( array_reverse( (array) get_post_ancestors( get_the_ID() ) ) as $ancestor ) {
			$trail[] = array(
				'name' => get_the_title( $ancestor ),
				'url'  => get_permalink( $ancestor ),
			);
		}
		$trail[] = array(
			'name' => get_the_title(),
			'url'  => '',
		);
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$trail[] = array(
			'name' => single_term_title( '', false ),
			'url'  => '',
		);
	} elseif ( is_author() ) {
		$trail[] = array(
			'name' => get_the_author(),
			'url'  => '',
		);
	} elseif ( is_search() ) {
		$trail[] = array(
			'name' => sprintf(
				/* translators: %s: search term. */
				__( 'Search: %s', 'livingdraft' ),
				get_search_query()
			),
			'url'  => '',
		);
	} elseif ( is_archive() ) {
		$trail[] = array(
			'name' => wp_strip_all_tags( get_the_archive_title() ),
			'url'  => '',
		);
	}

	return $trail;
}

/**
 * Print the trail.
 */
function livingdraft_breadcrumbs() {
	if ( is_front_page() ) {
		return;
	}

	$trail = livingdraft_breadcrumb_trail();
	if ( count( $trail ) < 2 ) {
		return;
	}

	echo '<nav class="breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'livingdraft' ) . '">';

	$last = count( $trail ) - 1;
	foreach ( $trail as $i => $crumb ) {
		if ( $i > 0 ) {
			echo '<span class="sep" aria-hidden="true">/</span>';
		}

		if ( $i === $last || '' === $crumb['url'] ) {
			printf(
				'<span aria-current="page">%s</span>',
				esc_html( wp_html_excerpt( $crumb['name'], 60, '…' ) )
			);
		} else {
			printf(
				'<a href="%s">%s</a>',
				esc_url( $crumb['url'] ),
				esc_html( $crumb['name'] )
			);
		}
	}

	echo '</nav>';
}

/**
 * Stories a reader of this one would want next: same section or same tags,
 * newest first. Chronological neighbours are rarely what anyone wants.
 *
 * @param int $limit How many to return.
 * @return WP_Post[]
 */
function livingdraft_read_next( $limit = 3 ) {
	$post_id = get_the_ID();
	$limit   = max( 1, (int) $limit );

	// Cache the resolved ID list against this post's modified time, so the
	// tax_query only runs when the story (or a neighbour that changes its
	// modified time by editing) actually changes.
	$key   = 'livingdraft_readnext_' . $post_id . '_' . $limit;
	$stamp = (string) get_post_modified_time( 'U', true, $post_id );

	$cached = get_transient( $key );
	if ( is_array( $cached ) && isset( $cached['stamp'], $cached['ids'] ) && $cached['stamp'] === $stamp ) {
		return array_filter( array_map( 'get_post', $cached['ids'] ) );
	}

	$cats = wp_get_post_categories( $post_id );
	$tags = wp_get_post_tags( $post_id, array( 'fields' => 'ids' ) );

	$tax_query = array( 'relation' => 'OR' );

	if ( ! empty( $cats ) ) {
		$tax_query[] = array(
			'taxonomy' => 'category',
			'field'    => 'term_id',
			'terms'    => $cats,
		);
	}

	if ( ! empty( $tags ) ) {
		$tax_query[] = array(
			'taxonomy' => 'post_tag',
			'field'    => 'term_id',
			'terms'    => $tags,
		);
	}

	$args = array(
		'post__not_in'        => array( $post_id ),
		'posts_per_page'      => $limit,
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
		'post_status'         => 'publish',
		'fields'              => 'ids',
	);

	if ( count( $tax_query ) > 1 ) {
		$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
	}

	$ids = get_posts( $args );

	// A story with no section and no tags still deserves something to follow.
	if ( empty( $ids ) ) {
		$ids = get_posts(
			array(
				'post__not_in'        => array( $post_id ),
				'posts_per_page'      => $limit,
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
				'post_status'         => 'publish',
				'fields'              => 'ids',
			)
		);
	}

	set_transient(
		$key,
		array(
			'stamp' => $stamp,
			'ids'   => $ids,
		),
		WEEK_IN_SECONDS
	);

	return array_filter( array_map( 'get_post', $ids ) );
}

/* ------------------------------------------------------------------
 * Icons and comments
 * ------------------------------------------------------------------ */

/**
 * Inline SVG icons. No icon font, no sprite request.
 *
 * @param string $name Icon name.
 * @return string
 */
function livingdraft_icon( $name ) {
	$icons = array(
		'menu'      => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 6h18M3 12h18M3 18h18"/></svg>',
		'close'     => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 5l14 14M19 5L5 19"/></svg>',
		'search'    => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/></svg>',
		'x'         => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M18.9 2H22l-6.9 7.9L23.2 22h-6.3l-5-6.5L6.1 22H3l7.4-8.4L2.8 2h6.5l4.5 5.9L18.9 2zm-1.1 18.1h1.7L8.3 3.8H6.5l11.3 16.3z"/></svg>',
		'instagram' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 2.2c3.2 0 3.6 0 4.9.1 1.2.1 1.8.2 2.2.4.6.2 1 .5 1.4.9.4.4.7.8.9 1.4.2.4.4 1 .4 2.2.1 1.3.1 1.7.1 4.9s0 3.6-.1 4.9c-.1 1.2-.2 1.8-.4 2.2-.2.6-.5 1-.9 1.4-.4.4-.8.7-1.4.9-.4.2-1 .4-2.2.4-1.3.1-1.7.1-4.9.1s-3.6 0-4.9-.1c-1.2-.1-1.8-.2-2.2-.4-.6-.2-1-.5-1.4-.9-.4-.4-.7-.8-.9-1.4-.2-.4-.4-1-.4-2.2-.1-1.3-.1-1.7-.1-4.9s0-3.6.1-4.9c.1-1.2.2-1.8.4-2.2.2-.6.5-1 .9-1.4.4-.4.8-.7 1.4-.9.4-.2 1-.4 2.2-.4 1.3-.1 1.7-.1 4.9-.1zm0 3.8a6 6 0 100 12 6 6 0 000-12zm0 9.9a3.9 3.9 0 110-7.8 3.9 3.9 0 010 7.8zm7.6-10.1a1.4 1.4 0 11-2.8 0 1.4 1.4 0 012.8 0z"/></svg>',
	);

	return isset( $icons[ $name ] ) ? $icons[ $name ] : '';
}

/**
 * A single comment.
 *
 * @param WP_Comment $comment Comment.
 * @param array      $args    Args.
 * @param int        $depth   Depth.
 */
function livingdraft_comment( $comment, $args, $depth ) {
	?>
	<li id="comment-<?php comment_ID(); ?>" <?php comment_class( 'comment-item' ); ?>>
		<article class="comment-body">
			<div class="comment-meta">
				<?php echo esc_html( get_comment_author() ); ?>
				<span class="sep">/</span>
				<time datetime="<?php echo esc_attr( get_comment_date( DATE_W3C ) ); ?>">
					<?php echo esc_html( get_comment_date( 'j M Y' ) ); ?>
				</time>
			</div>
			<?php if ( '0' === $comment->comment_approved ) : ?>
				<p><em><?php esc_html_e( 'Awaiting moderation.', 'livingdraft' ); ?></em></p>
			<?php endif; ?>
			<div class="comment-text"><?php comment_text(); ?></div>
			<div class="comment-actions">
				<?php
				comment_reply_link(
					array_merge(
						$args,
						array(
							'depth'      => $depth,
							'max_depth'  => $args['max_depth'],
							'reply_text' => __( 'Reply', 'livingdraft' ),
						)
					)
				);
				?>
			</div>
		</article>
	<?php
	// WordPress closes the </li> itself.
}
