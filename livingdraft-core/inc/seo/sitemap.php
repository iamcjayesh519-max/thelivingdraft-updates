<?php
/**
 * XML sitemaps.
 *
 * Produces:
 *   /sitemap.xml               — the index. Lists every sub-sitemap.
 *   /sitemap-{type}-{page}.xml — posts, pages, and public CPTs, 500 per file.
 *   /sitemap-taxonomy-{tax}.xml — one file per public taxonomy.
 *   /sitemap-author.xml        — author archives (opt-in, off by default).
 *   /news-sitemap.xml          — Google News: last 48 hours, per spec.
 *
 * Also serves aliases so that Search Console submissions from a prior
 * SEO plugin keep working during migration:
 *   /sitemap_index.xml → /sitemap.xml     (Rank Math + Yoast used this)
 *   /wp-sitemap.xml    → /sitemap.xml     (WordPress core's built-in path)
 *
 * When this module is enabled, WordPress core's own sitemap generator
 * (introduced in 5.5) is switched off so we don't emit two sets.
 *
 * === WHY IT'S OFF BY DEFAULT ===
 *
 * If Rank Math or Yoast is still active they're already handling
 * sitemap URLs, and two systems fighting for the same paths is worse
 * than either alone. The user enables this from the SEO admin once
 * they're ready — ideally before they disable Rank Math, so that
 * Search Console never sees a gap.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------
 * 1. TOGGLES + OPTIONS
 * ------------------------------------------------------------------ */

/**
 * Master switch. Filterable for staging environments.
 */
function livingdraft_sitemap_enabled() {
	return (bool) apply_filters( 'livingdraft_sitemap_enabled', (bool) get_option( 'livingdraft_sitemap_enabled', false ) );
}

/**
 * Include the Google News sitemap? Default on for the "news" site
 * identity this plugin is built for; a purely evergreen site can filter
 * this off.
 */
function livingdraft_sitemap_news_enabled() {
	return (bool) apply_filters( 'livingdraft_sitemap_news_enabled', (bool) get_option( 'livingdraft_sitemap_news_enabled', true ) );
}

/**
 * How many URLs per sub-sitemap file. Google allows up to 50k; keeping
 * it small means faster generation and easier debugging in browser.
 */
function livingdraft_sitemap_per_page() {
	return (int) apply_filters( 'livingdraft_sitemap_per_page', 500 );
}

/**
 * Which post types belong in the sitemap. Anything with `public` true
 * except the attachment CPT (attachment pages are almost always noise).
 */
function livingdraft_sitemap_post_types() {
	$types = get_post_types( array( 'public' => true ), 'names' );
	unset( $types['attachment'] );
	return apply_filters( 'livingdraft_sitemap_post_types', array_values( $types ) );
}

/**
 * Which taxonomies belong in the sitemap.
 */
function livingdraft_sitemap_taxonomies() {
	$taxes = get_taxonomies( array( 'public' => true ), 'names' );
	unset( $taxes['post_format'] ); // Post-format archives rarely rank.
	return apply_filters( 'livingdraft_sitemap_taxonomies', array_values( $taxes ) );
}

/**
 * Newsroom identity for the News sitemap's <news:publication> block.
 * Defaults to the site name + WP locale short code.
 */
function livingdraft_sitemap_publication() {
	$locale = get_locale();
	$lang   = substr( $locale, 0, 2 ); // 'en_US' → 'en'
	return apply_filters(
		'livingdraft_sitemap_publication',
		array(
			'name'     => get_option( 'livingdraft_sitemap_pub_name', get_bloginfo( 'name' ) ),
			'language' => $lang ?: 'en',
		)
	);
}

/* ------------------------------------------------------------------
 * 2. REWRITE RULES + QUERY VARS
 *
 * The rules map pretty URLs to internal query vars we then dispatch
 * on at template_redirect. Registered on init; flushed once per
 * version bump so users don't have to visit the Permalinks screen.
 * ------------------------------------------------------------------ */

function livingdraft_sitemap_add_rewrites() {
	if ( ! livingdraft_sitemap_enabled() ) {
		return;
	}

	add_rewrite_rule( '^sitemap\.xml$',                    'index.php?ld_sitemap=index',                                             'top' );
	add_rewrite_rule( '^sitemap_index\.xml$',              'index.php?ld_sitemap=index',                                             'top' );
	add_rewrite_rule( '^wp-sitemap\.xml$',                 'index.php?ld_sitemap=index',                                             'top' );

	add_rewrite_rule( '^sitemap-pt-([^/]+)-([0-9]+)\.xml$', 'index.php?ld_sitemap=post_type&ld_sitemap_subject=$matches[1]&ld_sitemap_page=$matches[2]', 'top' );
	add_rewrite_rule( '^sitemap-tax-([^/]+)\.xml$',         'index.php?ld_sitemap=taxonomy&ld_sitemap_subject=$matches[1]',           'top' );
	add_rewrite_rule( '^sitemap-author\.xml$',              'index.php?ld_sitemap=author',                                            'top' );

	if ( livingdraft_sitemap_news_enabled() ) {
		add_rewrite_rule( '^news-sitemap\.xml$', 'index.php?ld_sitemap=news', 'top' );
	}
}
add_action( 'init', 'livingdraft_sitemap_add_rewrites', 5 );

function livingdraft_sitemap_query_vars( $vars ) {
	$vars[] = 'ld_sitemap';
	$vars[] = 'ld_sitemap_subject';
	$vars[] = 'ld_sitemap_page';
	return $vars;
}
add_filter( 'query_vars', 'livingdraft_sitemap_query_vars' );

/**
 * One-shot flush when the plugin version changes so users don't have
 * to visit the Permalinks screen to activate new rules.
 */
function livingdraft_sitemap_maybe_flush() {
	$installed = get_option( 'livingdraft_sitemap_rewrites_ver' );
	if ( $installed === LIVINGDRAFT_CORE_VERSION ) {
		return;
	}
	flush_rewrite_rules( false );
	update_option( 'livingdraft_sitemap_rewrites_ver', LIVINGDRAFT_CORE_VERSION, false );
}
add_action( 'init', 'livingdraft_sitemap_maybe_flush', 20 );

/**
 * Silence WordPress core's built-in sitemap so we don't run two.
 */
add_filter(
	'wp_sitemaps_enabled',
	function ( $enabled ) {
		return livingdraft_sitemap_enabled() ? false : $enabled;
	}
);

/**
 * Advertise the sitemap in robots.txt.
 */
add_filter(
	'robots_txt',
	function ( $output ) {
		if ( ! livingdraft_sitemap_enabled() ) {
			return $output;
		}
		$output .= "\nSitemap: " . esc_url_raw( home_url( '/sitemap.xml' ) );
		if ( livingdraft_sitemap_news_enabled() ) {
			$output .= "\nSitemap: " . esc_url_raw( home_url( '/news-sitemap.xml' ) );
		}
		return $output . "\n";
	}
);

/* ------------------------------------------------------------------
 * 3. DISPATCH
 * ------------------------------------------------------------------ */

function livingdraft_sitemap_dispatch() {
	$which = get_query_var( 'ld_sitemap' );
	if ( ! $which ) {
		return;
	}

	nocache_headers();
	header( 'Content-Type: application/xml; charset=' . get_option( 'blog_charset' ) );
	header( 'X-Robots-Tag: noindex, follow', true );

	switch ( $which ) {
		case 'index':     livingdraft_sitemap_render_index(); break;
		case 'post_type': livingdraft_sitemap_render_posts( get_query_var( 'ld_sitemap_subject' ), (int) get_query_var( 'ld_sitemap_page' ) ); break;
		case 'taxonomy':  livingdraft_sitemap_render_taxonomy( get_query_var( 'ld_sitemap_subject' ) ); break;
		case 'author':    livingdraft_sitemap_render_authors(); break;
		case 'news':      livingdraft_sitemap_render_news(); break;
		default:
			status_header( 404 );
	}
	exit;
}
add_action( 'template_redirect', 'livingdraft_sitemap_dispatch' );

/* ------------------------------------------------------------------
 * 4. SITEMAP INDEX
 * ------------------------------------------------------------------ */

function livingdraft_sitemap_render_index() {
	$entries = array();

	// Post types, paginated.
	$per = livingdraft_sitemap_per_page();
	foreach ( livingdraft_sitemap_post_types() as $pt ) {
		$count = livingdraft_sitemap_count_posts_of_type( $pt );
		if ( $count < 1 ) {
			continue;
		}
		$pages = (int) ceil( $count / $per );
		for ( $p = 1; $p <= $pages; $p++ ) {
			$entries[] = array(
				'loc'     => home_url( sprintf( '/sitemap-pt-%s-%d.xml', $pt, $p ) ),
				'lastmod' => livingdraft_sitemap_latest_mod_for_type( $pt ),
			);
		}
	}

	// Taxonomies, one file each.
	foreach ( livingdraft_sitemap_taxonomies() as $tax ) {
		$has_terms = (int) wp_count_terms( array( 'taxonomy' => $tax, 'hide_empty' => true ) );
		if ( ! $has_terms ) {
			continue;
		}
		$entries[] = array(
			'loc'     => home_url( sprintf( '/sitemap-tax-%s.xml', $tax ) ),
			'lastmod' => null,
		);
	}

	// Authors (opt-in).
	if ( (bool) get_option( 'livingdraft_sitemap_authors', false ) ) {
		$entries[] = array(
			'loc'     => home_url( '/sitemap-author.xml' ),
			'lastmod' => null,
		);
	}

	// News.
	if ( livingdraft_sitemap_news_enabled() ) {
		$entries[] = array(
			'loc'     => home_url( '/news-sitemap.xml' ),
			'lastmod' => gmdate( 'c' ), // News regenerates constantly.
		);
	}

	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
	foreach ( $entries as $e ) {
		echo "  <sitemap>\n";
		echo '    <loc>' . esc_url( $e['loc'] ) . "</loc>\n";
		if ( ! empty( $e['lastmod'] ) ) {
			echo '    <lastmod>' . esc_html( $e['lastmod'] ) . "</lastmod>\n";
		}
		echo "  </sitemap>\n";
	}
	echo '</sitemapindex>' . "\n";
}

/* ------------------------------------------------------------------
 * 5. POST-TYPE SUB-SITEMAPS
 * ------------------------------------------------------------------ */

function livingdraft_sitemap_render_posts( $post_type, $page ) {
	if ( ! in_array( $post_type, livingdraft_sitemap_post_types(), true ) || $page < 1 ) {
		status_header( 404 );
		return;
	}

	$per    = livingdraft_sitemap_per_page();
	$offset = ( $page - 1 ) * $per;

	$q = new WP_Query(
		array(
			'post_type'              => $post_type,
			'post_status'            => 'publish',
			'posts_per_page'         => $per,
			'offset'                 => $offset,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'has_password'           => false,
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
			'suppress_filters'       => true,
		)
	);

	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

	// Homepage lives in the first page of the primary post-type sitemap
	// so it doesn't require its own file.
	if ( 1 === $page && $post_type === apply_filters( 'livingdraft_sitemap_primary_post_type', 'post' ) ) {
		echo "  <url>\n";
		echo '    <loc>' . esc_url( home_url( '/' ) ) . "</loc>\n";
		echo '    <lastmod>' . esc_html( livingdraft_sitemap_latest_mod_for_type( $post_type ) ) . "</lastmod>\n";
		echo "  </url>\n";
	}

	foreach ( $q->posts as $post ) {
		if ( ! livingdraft_sitemap_should_include_post( $post ) ) {
			continue;
		}

		$loc     = get_permalink( $post );
		$lastmod = livingdraft_sitemap_post_lastmod( $post );

		echo "  <url>\n";
		echo '    <loc>' . esc_url( $loc ) . "</loc>\n";
		echo '    <lastmod>' . esc_html( $lastmod ) . "</lastmod>\n";

		// Image extension: featured image if we have one. Everything
		// else in the image-sitemap namespace has been deprecated since
		// 2022 — only <image:loc> still counts.
		$thumb_id = get_post_thumbnail_id( $post );
		if ( $thumb_id ) {
			$src = wp_get_attachment_image_src( $thumb_id, 'full' );
			if ( is_array( $src ) ) {
				echo "    <image:image>\n";
				echo '      <image:loc>' . esc_url( $src[0] ) . "</image:loc>\n";
				echo "    </image:image>\n";
			}
		}

		echo "  </url>\n";
	}

	echo '</urlset>' . "\n";
}

/* ------------------------------------------------------------------
 * 6. TAXONOMY + AUTHOR SUB-SITEMAPS
 * ------------------------------------------------------------------ */

function livingdraft_sitemap_render_taxonomy( $taxonomy ) {
	if ( ! in_array( $taxonomy, livingdraft_sitemap_taxonomies(), true ) ) {
		status_header( 404 );
		return;
	}

	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => true,
			'number'     => 1000,
		)
	);

	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

	if ( ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term ) {
			// Respect per-term noindex — if the user marked this term as
			// noindex from the term SEO panel, don't bother listing it
			// in the sitemap either.
			if ( function_exists( 'livingdraft_seo_term_get' ) ) {
				$robots = livingdraft_seo_term_get( 'robots', $term->term_id );
				if ( is_array( $robots ) && in_array( 'noindex', $robots, true ) ) {
					continue;
				}
			}
			$link = get_term_link( $term );
			if ( is_wp_error( $link ) ) {
				continue;
			}
			echo "  <url>\n";
			echo '    <loc>' . esc_url( $link ) . "</loc>\n";
			echo "  </url>\n";
		}
	}

	echo '</urlset>' . "\n";
}

function livingdraft_sitemap_render_authors() {
	global $wpdb;

	// Authors who have at least one published post.
	$author_ids = $wpdb->get_col(
		"SELECT DISTINCT post_author FROM {$wpdb->posts}
		 WHERE post_type = 'post' AND post_status = 'publish' AND post_password = ''
		 LIMIT 1000"
	);

	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
	foreach ( $author_ids as $id ) {
		$url = get_author_posts_url( (int) $id );
		if ( ! $url ) {
			continue;
		}
		echo "  <url>\n";
		echo '    <loc>' . esc_url( $url ) . "</loc>\n";
		echo "  </url>\n";
	}
	echo '</urlset>' . "\n";
}

/* ------------------------------------------------------------------
 * 7. NEWS SITEMAP
 *
 * Google's spec: last 48 hours only, max 1000 URLs, dedicated schema
 * with <news:publication>, <news:publication_date>, <news:title>.
 * Older articles must be removed — leaving them in downgrades the
 * sitemap's trustworthiness.
 * ------------------------------------------------------------------ */

function livingdraft_sitemap_render_news() {
	if ( ! livingdraft_sitemap_news_enabled() ) {
		status_header( 404 );
		return;
	}

	$pub    = livingdraft_sitemap_publication();
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - 48 * HOUR_IN_SECONDS );

	$q = new WP_Query(
		array(
			'post_type'              => apply_filters( 'livingdraft_news_sitemap_post_types', array( 'post' ) ),
			'post_status'            => 'publish',
			'posts_per_page'         => 1000, // Google cap.
			'date_query'             => array(
				array(
					'column' => 'post_date_gmt',
					'after'  => $cutoff,
				),
			),
			'has_password'           => false,
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'suppress_filters'       => true,
		)
	);

	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";

	foreach ( $q->posts as $post ) {
		if ( ! livingdraft_sitemap_should_include_post( $post ) ) {
			continue;
		}

		$published = get_post_time( 'c', true, $post ); // ISO 8601 UTC.

		echo "  <url>\n";
		echo '    <loc>' . esc_url( get_permalink( $post ) ) . "</loc>\n";
		echo "    <news:news>\n";
		echo "      <news:publication>\n";
		echo '        <news:name>' . esc_html( $pub['name'] ) . "</news:name>\n";
		echo '        <news:language>' . esc_html( $pub['language'] ) . "</news:language>\n";
		echo "      </news:publication>\n";
		echo '      <news:publication_date>' . esc_html( $published ) . "</news:publication_date>\n";
		echo '      <news:title>' . esc_html( html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) ) . "</news:title>\n";

		// Optional keywords, populated from post tags.
		$tags = get_the_tags( $post );
		if ( is_array( $tags ) && $tags ) {
			$names = array_slice( wp_list_pluck( $tags, 'name' ), 0, 10 );
			echo '      <news:keywords>' . esc_html( implode( ', ', $names ) ) . "</news:keywords>\n";
		}

		echo "    </news:news>\n";
		echo "  </url>\n";
	}

	echo '</urlset>' . "\n";
}

/* ------------------------------------------------------------------
 * 8. HELPERS
 * ------------------------------------------------------------------ */

/**
 * Whether a post should appear in the sitemap. Central place so news
 * + regular sitemaps stay consistent, and so migration meta from Rank
 * Math / Yoast is respected.
 */
function livingdraft_sitemap_should_include_post( $post ) {
	if ( ! $post || 'publish' !== $post->post_status ) {
		return false;
	}
	if ( ! empty( $post->post_password ) ) {
		return false;
	}

	// Our own robots meta.
	$robots = get_post_meta( $post->ID, '_ld_seo_robots', true );
	if ( is_array( $robots ) && in_array( 'noindex', $robots, true ) ) {
		return false;
	}

	// Migration compat: honour existing Rank Math + Yoast noindex flags.
	$rm_robots = get_post_meta( $post->ID, 'rank_math_robots', true );
	if ( is_array( $rm_robots ) && in_array( 'noindex', $rm_robots, true ) ) {
		return false;
	}
	$yoast_no = get_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', true );
	if ( '1' === (string) $yoast_no ) {
		return false;
	}

	return (bool) apply_filters( 'livingdraft_sitemap_should_include_post', true, $post );
}

/**
 * Best lastmod for a post: prefers the update-log derived timestamp
 * (from `livingdraft_last_changed_gmt`) when available, since that
 * reflects real editorial changes rather than autosaves.
 */
function livingdraft_sitemap_post_lastmod( $post ) {
	if ( function_exists( 'livingdraft_last_changed_gmt' ) ) {
		$raw = livingdraft_last_changed_gmt( $post->ID );
		if ( $raw ) {
			$ts = is_numeric( $raw ) ? (int) $raw : (int) strtotime( (string) $raw );
			if ( $ts > 0 ) {
				return gmdate( 'c', $ts );
			}
		}
	}
	return get_post_modified_time( 'c', true, $post );
}

function livingdraft_sitemap_count_posts_of_type( $post_type ) {
	global $wpdb;
	$count = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type = %s AND post_status = 'publish' AND post_password = ''",
			$post_type
		)
	);
	return (int) $count;
}

function livingdraft_sitemap_latest_mod_for_type( $post_type ) {
	global $wpdb;
	$modified = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT MAX(post_modified_gmt) FROM {$wpdb->posts}
			 WHERE post_type = %s AND post_status = 'publish' AND post_password = ''",
			$post_type
		)
	);
	if ( ! $modified ) {
		return gmdate( 'c' );
	}
	return gmdate( 'c', (int) strtotime( $modified ) );
}
