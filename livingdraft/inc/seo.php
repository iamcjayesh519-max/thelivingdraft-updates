<?php
/**
 * Search and social metadata.
 *
 * The theme emits Open Graph tags and a JSON-LD graph itself, because a news
 * site without them shares badly and reads poorly to a crawler. If a dedicated
 * SEO plugin is running, all of it stands down rather than duplicating tags.
 *
 * @package LivingDraft
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Is another plugin already handling this?
 *
 * @return bool
 */
function livingdraft_seo_plugin_active() {
	$active = defined( 'WPSEO_VERSION' )                // Yoast.
		|| defined( 'RANK_MATH_VERSION' )               // Rank Math.
		|| defined( 'AIOSEO_VERSION' )                  // All in One SEO.
		|| defined( 'SEOPRESS_VERSION' )                // SEOPress.
		|| defined( 'THE_SEO_FRAMEWORK_VERSION' )       // The SEO Framework.
		|| defined( 'SLIM_SEO_VER' )                    // Slim SEO.
		|| defined( 'SQ_VERSION' )                      // Squirrly.
		// The Living Draft Core plugin ships its own full SEO stack.
		// We call its `_output_enabled` helper rather than just
		// checking for the plugin's presence, because the plugin's
		// SEO output has a master switch that defaults to OFF —
		// during the "installed but not switched on" phase the theme
		// should still print SEO. Once the operator flips the plugin's
		// switch, the theme steps aside automatically.
		|| ( function_exists( 'livingdraft_seo_output_enabled' ) && livingdraft_seo_output_enabled() );

	return (bool) apply_filters( 'livingdraft_seo_plugin_active', $active );
}

/**
 * Should the theme print its own metadata?
 *
 * @return bool
 */
function livingdraft_do_seo() {
	return (bool) apply_filters( 'livingdraft_output_seo', ! livingdraft_seo_plugin_active() );
}

/**
 * A one-line description of whatever is being viewed.
 *
 * @return string
 */
function livingdraft_meta_description() {
	$text = '';

	if ( is_singular() ) {
		$text = has_excerpt() ? get_the_excerpt() : wp_strip_all_tags( strip_shortcodes( get_the_content() ) );
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$text = term_description();
	} elseif ( is_author() ) {
		$text = get_the_author_meta( 'description' );
	} elseif ( is_search() ) {
		$text = sprintf(
			/* translators: %s: search term. */
			__( 'Stories matching %s', 'livingdraft' ),
			get_search_query()
		);
	}

	if ( ! $text ) {
		$text = get_bloginfo( 'description', 'display' );
	}

	$text = wp_strip_all_tags( (string) $text );
	$text = preg_replace( '/\s+/u', ' ', $text );

	return trim( wp_html_excerpt( (string) $text, 155, '…' ) );
}

/**
 * The share image for the current view, at 1200x628.
 *
 * @return array {url, width, height, alt} or an empty array.
 */
function livingdraft_share_image() {
	$id = 0;

	if ( is_singular() && has_post_thumbnail() ) {
		$id = (int) get_post_thumbnail_id();
	} else {
		$fallback = (int) get_theme_mod( 'livingdraft_share_image', 0 );
		if ( $fallback ) {
			$id = $fallback;
		} elseif ( has_custom_logo() ) {
			$id = (int) get_theme_mod( 'custom_logo' );
		}
	}

	if ( ! $id ) {
		return array();
	}

	$src = wp_get_attachment_image_src( $id, 'livingdraft-lede' );
	if ( ! $src ) {
		return array();
	}

	return array(
		'url'    => $src[0],
		'width'  => (int) $src[1],
		'height' => (int) $src[2],
		'alt'    => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
	);
}

/**
 * The canonical address of the current view.
 *
 * @return string
 */
function livingdraft_current_url() {
	if ( is_singular() ) {
		return (string) get_permalink();
	}

	if ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();
		if ( $term instanceof WP_Term ) {
			$link = get_term_link( $term );
			if ( ! is_wp_error( $link ) ) {
				return (string) $link;
			}
		}
	}

	if ( is_author() ) {
		return (string) get_author_posts_url( (int) get_queried_object_id() );
	}

	if ( is_search() ) {
		return (string) get_search_link();
	}

	// $wp->request is the path without the query string, so this cannot
	// double the subdirectory on an install that lives in one.
	global $wp;

	return home_url( isset( $wp->request ) ? $wp->request : '' );
}

/* ------------------------------------------------------------------
 * Open Graph and Twitter
 * ------------------------------------------------------------------ */

/**
 * Print the sharing tags.
 */
function livingdraft_social_meta() {
	if ( ! livingdraft_do_seo() ) {
		return;
	}

	$desc  = livingdraft_meta_description();
	$image = livingdraft_share_image();
	$url   = livingdraft_current_url();

	if ( is_singular() ) {
		$title = get_the_title();
		$type  = is_singular( 'post' ) ? 'article' : 'website';
	} elseif ( is_archive() ) {
		$title = wp_strip_all_tags( get_the_archive_title() );
		$type  = 'website';
	} else {
		$title = get_bloginfo( 'name', 'display' );
		$type  = 'website';
	}

	echo "\n";
	printf( '<meta name="description" content="%s">' . "\n", esc_attr( $desc ) );

	printf( '<meta property="og:type" content="%s">' . "\n", esc_attr( $type ) );
	printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( $title ) );
	printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( $desc ) );
	printf( '<meta property="og:url" content="%s">' . "\n", esc_url( $url ) );
	printf( '<meta property="og:site_name" content="%s">' . "\n", esc_attr( get_bloginfo( 'name', 'display' ) ) );
	printf( '<meta property="og:locale" content="%s">' . "\n", esc_attr( get_locale() ) );

	if ( ! empty( $image ) ) {
		printf( '<meta property="og:image" content="%s">' . "\n", esc_url( $image['url'] ) );
		printf( '<meta property="og:image:width" content="%d">' . "\n", (int) $image['width'] );
		printf( '<meta property="og:image:height" content="%d">' . "\n", (int) $image['height'] );
		if ( $image['alt'] ) {
			printf( '<meta property="og:image:alt" content="%s">' . "\n", esc_attr( $image['alt'] ) );
		}
	}

	if ( is_singular( 'post' ) ) {
		printf(
			'<meta property="article:published_time" content="%s">' . "\n",
			esc_attr( get_post_time( DATE_W3C, true ) )
		);
		// livingdraft_last_changed_gmt() lives in Living Draft Core. Without
		// the plugin, fall back to the post's own modified time so we still
		// emit a valid meta tag.
		$modified_gmt = function_exists( 'livingdraft_last_changed_gmt' )
			? livingdraft_last_changed_gmt()
			: (string) get_post_modified_time( 'Y-m-d H:i:s', true );

		printf(
			'<meta property="article:modified_time" content="%s">' . "\n",
			esc_attr( gmdate( DATE_W3C, (int) strtotime( $modified_gmt ) ) )
		);

		$cats = get_the_category();
		if ( ! empty( $cats ) ) {
			printf( '<meta property="article:section" content="%s">' . "\n", esc_attr( $cats[0]->name ) );
		}

		$tags = get_the_tags();
		if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) {
			foreach ( $tags as $tag ) {
				printf( '<meta property="article:tag" content="%s">' . "\n", esc_attr( $tag->name ) );
			}
		}
	}

	printf(
		'<meta name="twitter:card" content="%s">' . "\n",
		esc_attr( empty( $image ) ? 'summary' : 'summary_large_image' )
	);
	printf( '<meta name="twitter:title" content="%s">' . "\n", esc_attr( $title ) );
	printf( '<meta name="twitter:description" content="%s">' . "\n", esc_attr( $desc ) );

	if ( ! empty( $image ) ) {
		printf( '<meta name="twitter:image" content="%s">' . "\n", esc_url( $image['url'] ) );
	}
}
add_action( 'wp_head', 'livingdraft_social_meta', 5 );

/* ------------------------------------------------------------------
 * Structured data
 * ------------------------------------------------------------------ */

/**
 * The publisher node, shared by every graph on the site.
 *
 * @return array
 */
function livingdraft_schema_publisher() {
	$publisher = array(
		'@type' => 'Organization',
		'@id'   => home_url( '/#publisher' ),
		'name'  => get_bloginfo( 'name', 'display' ),
		'url'   => home_url( '/' ),
	);

	if ( has_custom_logo() ) {
		$logo_id  = (int) get_theme_mod( 'custom_logo' );
		$logo_src = wp_get_attachment_image_src( $logo_id, 'full' );

		if ( $logo_src ) {
			$publisher['logo'] = array(
				'@type'  => 'ImageObject',
				'url'    => $logo_src[0],
				'width'  => (int) $logo_src[1],
				'height' => (int) $logo_src[2],
			);
		}
	}

	// Strengthens Google's entity graph for the organisation. Only fields the
	// customizer has actually populated are emitted.
	$same_as = array_filter(
		array(
			get_theme_mod( 'livingdraft_x_url', '' ),
			get_theme_mod( 'livingdraft_instagram_url', '' ),
		)
	);

	if ( ! empty( $same_as ) ) {
		$publisher['sameAs'] = array_values( $same_as );
	}

	return $publisher;
}

/**
 * Print the JSON-LD graph.
 */
function livingdraft_schema() {
	if ( ! livingdraft_do_seo() ) {
		return;
	}

	$graph = array();

	$graph[] = array(
		'@type'           => 'WebSite',
		'@id'             => home_url( '/#website' ),
		'url'             => home_url( '/' ),
		'name'            => get_bloginfo( 'name', 'display' ),
		'description'     => get_bloginfo( 'description', 'display' ),
		'inLanguage'      => get_bloginfo( 'language' ),
		'publisher'       => array( '@id' => home_url( '/#publisher' ) ),
		'potentialAction' => array(
			'@type'       => 'SearchAction',
			'target'      => array(
				'@type'       => 'EntryPoint',
				'urlTemplate' => home_url( '/?s={search_term_string}' ),
			),
			'query-input' => 'required name=search_term_string',
		),
	);

	$graph[] = livingdraft_schema_publisher();

	if ( is_singular( 'post' ) ) {
		$image  = livingdraft_share_image();
		$author = get_the_author_meta( 'display_name' );
		$second = trim( (string) get_post_meta( get_the_ID(), '_livingdraft_coauthor', true ) );

		$authors = array(
			array(
				'@type' => 'Person',
				'name'  => $author,
				'url'   => get_author_posts_url( (int) get_the_author_meta( 'ID' ) ),
			),
		);

		if ( '' !== $second ) {
			$authors[] = array(
				'@type' => 'Person',
				'name'  => $second,
			);
		}

		// Same fallback pattern as the article:modified_time meta tag above.
		$modified_gmt = function_exists( 'livingdraft_last_changed_gmt' )
			? livingdraft_last_changed_gmt()
			: (string) get_post_modified_time( 'Y-m-d H:i:s', true );

		$article = array(
			'@type'            => 'NewsArticle',
			'@id'              => get_permalink() . '#article',
			'mainEntityOfPage' => array( '@id' => get_permalink() ),
			'headline'         => wp_html_excerpt( get_the_title(), 110, '' ),
			'description'      => livingdraft_meta_description(),
			'datePublished'    => get_post_time( DATE_W3C, true ),
			'dateModified'     => gmdate( DATE_W3C, (int) strtotime( $modified_gmt ) ),
			'author'           => $authors,
			'publisher'        => array( '@id' => home_url( '/#publisher' ) ),
			'isAccessibleForFree' => true,
			'wordCount'        => livingdraft_word_count(),
		);

		if ( ! empty( $image ) ) {
			$article['image'] = array(
				'@type'  => 'ImageObject',
				'url'    => $image['url'],
				'width'  => $image['width'],
				'height' => $image['height'],
			);
		}

		$cats = get_the_category();
		if ( ! empty( $cats ) ) {
			$article['articleSection'] = $cats[0]->name;
		}

		$tags = get_the_tags();
		if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) {
			$article['keywords'] = wp_list_pluck( $tags, 'name' );
		}

		// A logged correction is a public record of the change. Only surfaced
		// when Living Draft Core is active — the update log itself lives there.
		if ( function_exists( 'livingdraft_get_updates' ) ) {
			$updates = livingdraft_get_updates();
			if ( ! empty( $updates ) ) {
				$notes = array();
				foreach ( $updates as $row ) {
					$notes[] = $row['note'];
				}
				$article['correction'] = $notes;
			}
		}

		$graph[] = $article;
	}

	if ( is_author() ) {
		$author_id = (int) get_queried_object_id();

		$graph[] = array(
			'@type'       => 'ProfilePage',
			'@id'         => get_author_posts_url( $author_id ) . '#profile',
			'url'         => get_author_posts_url( $author_id ),
			'mainEntity'  => array(
				'@type'       => 'Person',
				'name'        => get_the_author_meta( 'display_name', $author_id ),
				'description' => get_the_author_meta( 'description', $author_id ),
				'url'         => get_author_posts_url( $author_id ),
			),
			'isPartOf'    => array( '@id' => home_url( '/#website' ) ),
		);
	}

	$trail = livingdraft_breadcrumb_trail();
	if ( ! is_front_page() && count( $trail ) > 1 ) {
		$items = array();
		foreach ( $trail as $i => $crumb ) {
			$item = array(
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'name'     => wp_html_excerpt( $crumb['name'], 110, '' ),
			);

			if ( '' !== $crumb['url'] ) {
				$item['item'] = $crumb['url'];
			}

			$items[] = $item;
		}

		$graph[] = array(
			'@type'           => 'BreadcrumbList',
			'@id'             => livingdraft_current_url() . '#breadcrumb',
			'itemListElement' => $items,
		);
	}

	$data = array(
		'@context' => 'https://schema.org',
		'@graph'   => $graph,
	);

	printf(
		'<script type="application/ld+json">%s</script>' . "\n",
		wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	);
}
add_action( 'wp_head', 'livingdraft_schema', 6 );
