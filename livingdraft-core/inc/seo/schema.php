<?php
/**
 * JSON-LD schema output.
 *
 * Emits a single `<script type="application/ld+json">` block per page
 * wrapped in @graph, which is how the current schema.org validators
 * and Google Rich Results Test expect a page's structured data. One
 * block, one @graph, multiple entities inside — beats scattering N
 * separate blocks and easier to reason about.
 *
 * Content of the graph varies by page:
 *   - Front / archive / any page: Organization + WebSite
 *   - Singular post:              NewsArticle + BreadcrumbList + above
 *   - Singular page:              WebPage       + BreadcrumbList + above
 *
 * The FAQ and Rating schemas emitted by the existing content-schema.php
 * module ride alongside as separate blocks — Google accepts multiple
 * JSON-LD blocks on one page. Merging them into this @graph would
 * require touching that module and its block-attribute plumbing.
 *
 * === WHY IT'S OFF BY DEFAULT ===
 *
 * If Rank Math or Yoast is still active they're already emitting their
 * own Article schema. Two JSON-LD blocks with the same @type isn't a
 * bug — Google reads whichever is more complete — but it's noise in
 * Search Console. The toggle stays off until the user is ready.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------
 * 1. TOGGLE + SETTINGS
 * ------------------------------------------------------------------ */

function livingdraft_schema_enabled() {
	return (bool) apply_filters( 'livingdraft_schema_enabled', (bool) get_option( 'livingdraft_schema_enabled', false ) );
}

/**
 * Central settings blob for the schema layer.
 */
function livingdraft_schema_settings() {
	$stored = get_option( 'livingdraft_schema_settings', array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	$defaults = array(
		'article_type'    => 'NewsArticle',            // NewsArticle | Article | BlogPosting
		'publisher_name'  => get_bloginfo( 'name' ),
		'publisher_logo'  => '',
		'organization'    => 'Organization',           // Organization | Person
		'social_profiles' => '',                       // Textarea, one URL per line.
	);
	$s = wp_parse_args( $stored, $defaults );

	// Legacy site-icon fallback for the publisher logo — if the user
	// hasn't set one but WP's site icon is present, use that. Better
	// than emitting an empty logo which Google flags as a warning.
	if ( '' === $s['publisher_logo'] && function_exists( 'get_site_icon_url' ) ) {
		$s['publisher_logo'] = get_site_icon_url( 512 );
	}

	return $s;
}

/* ------------------------------------------------------------------
 * 2. HEAD OUTPUT
 * ------------------------------------------------------------------ */

function livingdraft_schema_print_head() {
	if ( ! livingdraft_schema_enabled() || is_admin() ) {
		return;
	}
	// Feed/embed/xmlrpc etc. shouldn't get JSON-LD.
	if ( is_feed() || is_embed() ) {
		return;
	}

	$graph = array();

	// Site-wide entities on every page.
	$graph[] = livingdraft_schema_build_website();
	$graph[] = livingdraft_schema_build_organization();

	// Page-specific.
	if ( is_singular() ) {
		$post_id = (int) get_queried_object_id();
		$post    = get_post( $post_id );

		if ( $post && livingdraft_schema_should_emit_for( $post ) ) {
			if ( 'post' === $post->post_type ) {
				$article = livingdraft_schema_build_article( $post );
				if ( $article ) {
					$graph[] = $article;
				}
			} else {
				$page = livingdraft_schema_build_webpage( $post );
				if ( $page ) {
					$graph[] = $page;
				}
			}

			$crumbs = livingdraft_schema_build_breadcrumb( $post );
			if ( $crumbs ) {
				$graph[] = $crumbs;
			}
		}
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();
		if ( $term && ! empty( $term->term_id ) ) {
			$collection = livingdraft_schema_build_collection( $term );
			if ( $collection ) {
				$graph[] = $collection;
			}
			$crumbs = livingdraft_schema_build_term_breadcrumb( $term );
			if ( $crumbs ) {
				$graph[] = $crumbs;
			}
		}
	}

	// Drop nulls (a builder can return null to opt out).
	$graph = array_values( array_filter( $graph ) );
	if ( empty( $graph ) ) {
		return;
	}

	/**
	 * Filter the entire @graph before output. Lets other modules or
	 * themes inject their own entities in one place rather than adding
	 * more <script> tags.
	 */
	$graph = (array) apply_filters( 'livingdraft_schema_graph', $graph );

	$doc = array(
		'@context' => 'https://schema.org',
		'@graph'   => $graph,
	);

	echo "\n<!-- The Living Draft Schema -->\n";
	echo '<script type="application/ld+json">' . "\n";
	echo wp_json_encode( $doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	echo "\n</script>\n<!-- /The Living Draft Schema -->\n\n";
}
add_action( 'wp_head', 'livingdraft_schema_print_head', 20 );

/* ------------------------------------------------------------------
 * 3. BUILDERS
 *
 * Each returns an associative array (one @graph node) or null. All
 * strings are HTML-entity-decoded so JSON encoding doesn't leave
 * "&amp;" fragments in the wild.
 * ------------------------------------------------------------------ */

/**
 * Build the site-wide Organization node. Also serves as `publisher`
 * for Article, referenced by @id so Google doesn't see duplicate
 * entities.
 */
function livingdraft_schema_build_organization() {
	$s     = livingdraft_schema_settings();
	$id    = home_url( '/#organization' );
	$node  = array(
		'@type' => $s['organization'],
		'@id'   => $id,
		'name'  => livingdraft_schema_clean_text( $s['publisher_name'] ),
		'url'   => home_url( '/' ),
	);

	if ( '' !== $s['publisher_logo'] ) {
		$node['logo'] = array(
			'@type' => 'ImageObject',
			'@id'   => home_url( '/#logo' ),
			'url'   => esc_url_raw( $s['publisher_logo'] ),
			'contentUrl' => esc_url_raw( $s['publisher_logo'] ),
		);
		// Article's publisher.logo is REQUIRED by Google. Setting the
		// image URL on the org itself covers that reference.
		$node['image'] = array( '@id' => home_url( '/#logo' ) );
	}

	// Social profiles → sameAs array.
	$social = array_filter(
		array_map( 'trim', preg_split( '/\r?\n/', (string) $s['social_profiles'] ) )
	);
	if ( ! empty( $social ) ) {
		$node['sameAs'] = array_values( array_map( 'esc_url_raw', $social ) );
	}

	return apply_filters( 'livingdraft_schema_organization', $node );
}

/**
 * WebSite node with SearchAction so Google can offer the sitelinks
 * search box in the SERP.
 */
function livingdraft_schema_build_website() {
	$id   = home_url( '/#website' );
	$node = array(
		'@type'         => 'WebSite',
		'@id'           => $id,
		'url'           => home_url( '/' ),
		'name'          => livingdraft_schema_clean_text( get_bloginfo( 'name' ) ),
		'description'   => livingdraft_schema_clean_text( get_bloginfo( 'description' ) ),
		'publisher'     => array( '@id' => home_url( '/#organization' ) ),
		'inLanguage'    => str_replace( '_', '-', get_locale() ),
		'potentialAction' => array(
			array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => home_url( '/?s={search_term_string}' ),
				),
				'query-input' => 'required name=search_term_string',
			),
		),
	);
	return apply_filters( 'livingdraft_schema_website', $node );
}

/**
 * NewsArticle / Article / BlogPosting for a post. The article_type
 * setting picks which one — NewsArticle is the default for the news
 * identity this plugin is built for.
 */
function livingdraft_schema_build_article( $post ) {
	$s = livingdraft_schema_settings();

	$permalink   = get_permalink( $post );
	$title       = livingdraft_schema_clean_text( get_the_title( $post ) );
	$description = livingdraft_seo_get( 'description', $post->ID );
	if ( '' === $description ) {
		$description = wp_trim_words( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ), 30 );
	}
	$description = livingdraft_schema_clean_text( $description );

	// Google News limits headline to 110 chars. Truncate at word boundary
	// only if the SEO title is being used — the post title itself we
	// leave alone (that's the editor's call).
	$seo_title = livingdraft_seo_get( 'title', $post->ID );
	$headline  = '' !== $seo_title ? livingdraft_schema_clean_text( $seo_title ) : $title;
	if ( mb_strlen( $headline ) > 110 ) {
		$headline = mb_substr( $headline, 0, 107 ) . '...';
	}

	$node = array(
		'@type'            => $s['article_type'],
		'@id'              => $permalink . '#article',
		'isPartOf'         => array( '@id' => home_url( '/#website' ) ),
		'mainEntityOfPage' => array( '@id' => $permalink ),
		'headline'         => $headline,
		'name'             => $title,
		'datePublished'    => get_post_time( 'c', true, $post ),
		'dateModified'     => livingdraft_schema_modified_date( $post ),
		'author'           => livingdraft_schema_authors( $post ),
		'publisher'        => array( '@id' => home_url( '/#organization' ) ),
	);

	if ( '' !== $description ) {
		$node['description'] = $description;
	}

	$image = livingdraft_schema_primary_image( $post );
	if ( $image ) {
		$node['image'] = $image;
	}

	// Keywords: tags (posts) + comma-joined so validators are happy.
	$tags = get_the_terms( $post, 'post_tag' );
	if ( is_array( $tags ) && ! empty( $tags ) ) {
		$node['keywords'] = implode( ', ', wp_list_pluck( $tags, 'name' ) );
	}

	// Sections: primary category name.
	$cats = get_the_terms( $post, 'category' );
	if ( is_array( $cats ) && ! empty( $cats ) ) {
		$node['articleSection'] = $cats[0]->name;
	}

	$word_count = str_word_count( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) );
	if ( $word_count > 0 ) {
		$node['wordCount'] = $word_count;
	}

	return apply_filters( 'livingdraft_schema_article', $node, $post );
}

/**
 * Plain WebPage node for non-post singular content (pages, CPTs).
 */
function livingdraft_schema_build_webpage( $post ) {
	$permalink   = get_permalink( $post );
	$description = livingdraft_seo_get( 'description', $post->ID );
	if ( '' === $description ) {
		$description = wp_trim_words( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ), 30 );
	}

	$node = array(
		'@type'        => 'WebPage',
		'@id'          => $permalink,
		'url'          => $permalink,
		'name'         => livingdraft_schema_clean_text( get_the_title( $post ) ),
		'isPartOf'     => array( '@id' => home_url( '/#website' ) ),
		'datePublished' => get_post_time( 'c', true, $post ),
		'dateModified' => livingdraft_schema_modified_date( $post ),
		'inLanguage'   => str_replace( '_', '-', get_locale() ),
	);

	if ( '' !== $description ) {
		$node['description'] = livingdraft_schema_clean_text( $description );
	}

	$image = livingdraft_schema_primary_image( $post );
	if ( $image ) {
		$node['primaryImageOfPage'] = $image;
	}

	return apply_filters( 'livingdraft_schema_webpage', $node, $post );
}

/**
 * BreadcrumbList. Simple three-level default: Home → primary category
 * (posts only) → post title. Filterable end-to-end for themes that
 * want a hierarchical trail.
 */
function livingdraft_schema_build_breadcrumb( $post ) {
	$items = array(
		array(
			'name' => __( 'Home', 'livingdraft-core' ),
			'url'  => home_url( '/' ),
		),
	);

	if ( 'post' === $post->post_type ) {
		$cats = get_the_terms( $post, 'category' );
		if ( is_array( $cats ) && ! empty( $cats ) ) {
			$term = $cats[0];
			$items[] = array(
				'name' => $term->name,
				'url'  => get_term_link( $term ),
			);
		}
	} elseif ( $post->post_parent ) {
		// For pages, walk up the parent chain.
		$ancestors = array_reverse( get_post_ancestors( $post ) );
		foreach ( $ancestors as $ancestor_id ) {
			$items[] = array(
				'name' => get_the_title( $ancestor_id ),
				'url'  => get_permalink( $ancestor_id ),
			);
		}
	}

	$items[] = array(
		'name' => get_the_title( $post ),
		'url'  => get_permalink( $post ),
	);

	$items = apply_filters( 'livingdraft_schema_breadcrumb_items', $items, $post );

	if ( count( $items ) < 2 ) {
		return null; // Just "Home" isn't a breadcrumb.
	}

	$list = array();
	$pos  = 1;
	foreach ( $items as $item ) {
		if ( empty( $item['name'] ) || empty( $item['url'] ) || is_wp_error( $item['url'] ) ) {
			continue;
		}
		$list[] = array(
			'@type'    => 'ListItem',
			'position' => $pos++,
			'name'     => livingdraft_schema_clean_text( $item['name'] ),
			'item'     => $item['url'],
		);
	}

	return array(
		'@type'           => 'BreadcrumbList',
		'@id'             => get_permalink( $post ) . '#breadcrumb',
		'itemListElement' => $list,
	);
}

/* ------------------------------------------------------------------
 * 4. HELPERS
 * ------------------------------------------------------------------ */

/**
 * Whether we should emit article/webpage schema for a post. Reuses the
 * sitemap exclusion so the two systems can't disagree.
 */
function livingdraft_schema_should_emit_for( $post ) {
	if ( function_exists( 'livingdraft_sitemap_should_include_post' ) ) {
		return livingdraft_sitemap_should_include_post( $post );
	}
	return $post && 'publish' === $post->post_status && empty( $post->post_password );
}

/**
 * Prefer the update-log-derived dateModified when it exists, since
 * autosaves and metadata-only touches shouldn't churn schema either.
 */
function livingdraft_schema_modified_date( $post ) {
	if ( function_exists( 'livingdraft_last_changed_gmt' ) ) {
		$raw = livingdraft_last_changed_gmt( $post->ID );
		if ( $raw ) {
			$ts = is_numeric( $raw ) ? (int) $raw : (int) strtotime( (string) $raw );
			// Guard against strtotime returning false (int-cast to 0):
			// a schema dateModified of 1970-01-01 flags immediately in
			// Google's Rich Results Test and hurts NewsArticle ranking.
			if ( $ts > 0 ) {
				return gmdate( 'c', $ts );
			}
		}
	}
	return get_post_modified_time( 'c', true, $post );
}

/**
 * Featured image → first inline image → null. Google wants an image on
 * NewsArticle and warns loudly when it's missing; content extraction
 * is worth the effort.
 */
function livingdraft_schema_primary_image( $post ) {
	$id = get_post_thumbnail_id( $post );
	if ( $id ) {
		$src = wp_get_attachment_image_src( $id, 'full' );
		if ( is_array( $src ) ) {
			return array(
				'@type'  => 'ImageObject',
				'url'    => $src[0],
				'width'  => (int) $src[1],
				'height' => (int) $src[2],
			);
		}
	}

	// Fall back to the first <img> in the body.
	if ( preg_match( '/<img\b[^>]*\bsrc=(["\'])([^"\']+)\1/i', (string) $post->post_content, $m ) ) {
		return array(
			'@type' => 'ImageObject',
			'url'   => esc_url_raw( $m[2] ),
		);
	}

	return null;
}

/**
 * Article authors. Default: the post's `post_author`. Filterable so
 * a coauthors module or bylines plugin can inject its own list.
 */
function livingdraft_schema_authors( $post ) {
	$author_id   = (int) $post->post_author;
	$author_name = get_the_author_meta( 'display_name', $author_id );
	$author_url  = get_author_posts_url( $author_id );

	$authors = array(
		array(
			'@type' => 'Person',
			'name'  => livingdraft_schema_clean_text( $author_name ),
			'url'   => $author_url,
		),
	);

	return apply_filters( 'livingdraft_schema_authors', $authors, $post );
}

/**
 * Decode HTML entities in a title/description before JSON-encoding
 * them, so we don't end up with "&amp;" fragments in the emitted graph.
 */
function livingdraft_schema_clean_text( $text ) {
	$text = wp_strip_all_tags( (string) $text );
	$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
	return trim( $text );
}

/* ------------------------------------------------------------------
 * TERM ARCHIVE BUILDERS
 * ------------------------------------------------------------------ */

/**
 * CollectionPage schema for a term archive. Includes the archive's
 * name, description, canonical URL, and a hasPart ItemList of the
 * most recent posts in the term (up to 10) so search engines can
 * see it's a real archive with real content, not a thin tag page.
 */
function livingdraft_schema_build_collection( $term ) {
	if ( ! $term || empty( $term->term_id ) ) {
		return null;
	}

	// Reuse the same resolved fields the front-end emitter uses so
	// schema and OG tags stay in sync.
	$name = function_exists( 'livingdraft_seo_term_get' ) ? livingdraft_seo_term_get( 'title', $term->term_id ) : '';
	if ( '' === $name ) { $name = $term->name; }

	$desc = function_exists( 'livingdraft_seo_term_get' ) ? livingdraft_seo_term_get( 'description', $term->term_id ) : '';
	if ( '' === $desc ) {
		$desc = wp_strip_all_tags( (string) $term->description );
	}

	$url = function_exists( 'livingdraft_seo_term_paginated_link' )
		? livingdraft_seo_term_paginated_link( $term )
		: get_term_link( $term );
	if ( is_wp_error( $url ) ) {
		return null;
	}

	$node = array(
		'@type'       => 'CollectionPage',
		'@id'         => $url . '#collection',
		'url'         => $url,
		'name'        => livingdraft_schema_clean_text( $name ),
		'isPartOf'    => array( '@id' => home_url( '/' ) . '#website' ),
		'inLanguage'  => get_bloginfo( 'language' ),
	);
	if ( '' !== $desc ) {
		$node['description'] = livingdraft_schema_clean_text( $desc );
	}

	// hasPart ItemList — up to 10 most recent posts in the term.
	$posts = get_posts( array(
		'posts_per_page' => 10,
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'tax_query'      => array(
			array(
				'taxonomy' => $term->taxonomy,
				'field'    => 'term_id',
				'terms'    => (int) $term->term_id,
			),
		),
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	) );

	if ( ! empty( $posts ) ) {
		$elements = array();
		$pos = 1;
		foreach ( $posts as $p ) {
			$elements[] = array(
				'@type'    => 'ListItem',
				'position' => $pos++,
				'url'      => get_permalink( $p ),
				'name'     => livingdraft_schema_clean_text( get_the_title( $p ) ),
			);
		}
		$node['hasPart'] = array(
			'@type'           => 'ItemList',
			'numberOfItems'   => count( $elements ),
			'itemListElement' => $elements,
		);
	}

	return $node;
}

/**
 * BreadcrumbList for a term archive: Home › (parent term chain) › Term.
 * For taxonomies without hierarchy this collapses to Home › Term.
 */
function livingdraft_schema_build_term_breadcrumb( $term ) {
	if ( ! $term || empty( $term->term_id ) ) {
		return null;
	}

	$items = array();
	$pos   = 1;

	$items[] = array(
		'@type'    => 'ListItem',
		'position' => $pos++,
		'name'     => __( 'Home', 'livingdraft-core' ),
		'item'     => home_url( '/' ),
	);

	// Walk parents (for hierarchical taxonomies like Category).
	if ( ! empty( $term->parent ) && is_taxonomy_hierarchical( $term->taxonomy ) ) {
		$ancestors = array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) );
		foreach ( $ancestors as $anc_id ) {
			$anc = get_term( $anc_id, $term->taxonomy );
			if ( $anc && ! is_wp_error( $anc ) ) {
				$items[] = array(
					'@type'    => 'ListItem',
					'position' => $pos++,
					'name'     => livingdraft_schema_clean_text( $anc->name ),
					'item'     => get_term_link( $anc ),
				);
			}
		}
	}

	$items[] = array(
		'@type'    => 'ListItem',
		'position' => $pos,
		'name'     => livingdraft_schema_clean_text( $term->name ),
	);

	return array(
		'@type'           => 'BreadcrumbList',
		'itemListElement' => $items,
	);
}
