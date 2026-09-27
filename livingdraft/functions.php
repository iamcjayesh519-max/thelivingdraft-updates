<?php
/**
 * Living Draft — theme functions.
 *
 * @package LivingDraft
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LIVINGDRAFT_VERSION', '4.2.0' );

/**
 * Featured images are cut to 1.91:1 — the same frame as a share card, so one
 * upload serves the page, the Open Graph tag and the structured data.
 */
define( 'LIVINGDRAFT_IMAGE_W', 1200 );
define( 'LIVINGDRAFT_IMAGE_H', 628 );

/* ------------------------------------------------------------------
 * 1. Theme setup
 * ------------------------------------------------------------------ */

function livingdraft_setup() {
	load_theme_textdomain( 'livingdraft', get_template_directory() . '/languages' );

	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'responsive-embeds' );
	// The theme draws the plugin's AI "In brief" box itself (template-parts/ai-summary.php),
	// so the plugin should not prepend its own copy to the content.
	add_theme_support( 'livingdraft-ai-summary' );
	add_theme_support( 'align-wide' );
	add_theme_support( 'customize-selective-refresh-widgets' );

	add_theme_support(
		'html5',
		array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' )
	);

	/*
	 * Declaring custom-header with header-text is what makes WordPress show
	 * the "Display Site Title and Tagline" checkbox under Site Identity.
	 * Without it, core hides the control and display_header_text() is false.
	 */
	add_theme_support(
		'custom-header',
		array(
			'default-text-color' => '111111',
			'header-text'        => true,
			'width'              => 1200,
			'height'             => 280,
			'flex-width'         => true,
			'flex-height'        => true,
		)
	);

	add_theme_support(
		'custom-logo',
		array(
			'height'      => 148,
			'width'       => 600,
			'flex-height' => true,
			'flex-width'  => true,
		)
	);

	// The editor is dressed to match the page: same serif, same measure.
	add_theme_support( 'editor-styles' );
	add_editor_style( 'editor-style.css' );

	set_post_thumbnail_size( 480, 251, true );
	add_image_size( 'livingdraft-lede', LIVINGDRAFT_IMAGE_W, LIVINGDRAFT_IMAGE_H, true );
	add_image_size( 'livingdraft-column', 600, 314, true );
	add_image_size( 'livingdraft-thumb', 400, 209, true );

	register_nav_menus(
		array(
			'primary' => __( 'Primary menu', 'livingdraft' ),
			'footer'  => __( 'Footer menu', 'livingdraft' ),
		)
	);
}
add_action( 'after_setup_theme', 'livingdraft_setup' );

function livingdraft_content_width() {
	$GLOBALS['content_width'] = 720;
}
add_action( 'after_setup_theme', 'livingdraft_content_width', 0 );

/* ------------------------------------------------------------------
 * 2. Assets
 *
 * One stylesheet and one small deferred script. The stylesheet is small
 * enough (~5 KB gzipped) that inlining it beats a blocking request, so
 * that is the default; sites behind a CDN can switch back with a filter.
 * ------------------------------------------------------------------ */

/**
 * Should the stylesheet be printed inline rather than linked?
 *
 * @return bool
 */
function livingdraft_inline_css() {
	return (bool) apply_filters( 'livingdraft_inline_css', ! is_customize_preview() );
}

function livingdraft_assets() {
	$dir     = get_template_directory();
	$css     = $dir . '/style.css';
	$css_ver = file_exists( $css ) ? filemtime( $css ) : LIVINGDRAFT_VERSION;

	if ( livingdraft_inline_css() && file_exists( $css ) ) {
		// Registered but never printed as a file: the handle still exists so
		// child themes and plugins can declare it as a dependency.
		wp_register_style( 'livingdraft', false, array(), $css_ver );
		wp_enqueue_style( 'livingdraft' );
		wp_add_inline_style( 'livingdraft', livingdraft_get_css( $css ) );
	} else {
		wp_enqueue_style(
			'livingdraft',
			get_template_directory_uri() . '/style.css',
			array(),
			$css_ver
		);
	}

	// A child theme's own stylesheet always loads as a file, after the parent.
	if ( is_child_theme() ) {
		wp_enqueue_style(
			'livingdraft-child',
			get_stylesheet_uri(),
			array( 'livingdraft' ),
			wp_get_theme()->get( 'Version' )
		);
	}

	$js_path = $dir . '/assets/js/theme.js';
	if ( file_exists( $js_path ) ) {
		wp_enqueue_script(
			'livingdraft',
			get_template_directory_uri() . '/assets/js/theme.js',
			array(),
			filemtime( $js_path ),
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
	}

	// Light / dark control. Only loaded when readers are allowed to change
	// it — a site locked to one appearance ships none of this. The head
	// script in inc/color-scheme.php has already applied the saved choice
	// before paint; this file only handles presses and other open tabs, so
	// deferring it costs nothing.
	if ( function_exists( 'livingdraft_scheme_toggle_enabled' ) && livingdraft_scheme_toggle_enabled() ) {
		$scheme_path = $dir . '/assets/js/color-scheme.js';

		if ( file_exists( $scheme_path ) ) {
			wp_enqueue_script(
				'livingdraft-scheme',
				get_template_directory_uri() . '/assets/js/color-scheme.js',
				array(),
				(string) filemtime( $scheme_path ),
				array(
					'strategy'  => 'defer',
					'in_footer' => true,
				)
			);
		}
	}

	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		wp_enqueue_script( 'comment-reply' );
	}

	/*
	 * Reading progress bar. Singular views only, and only when switched on
	 * — a page with no article to measure would load the script just to
	 * have it remove its own markup.
	 */
	if ( livingdraft_reading_progress_enabled() ) {
		$progress_path = $dir . '/assets/js/reading-progress.js';

		if ( file_exists( $progress_path ) ) {
			wp_enqueue_script(
				'livingdraft-reading-progress',
				get_template_directory_uri() . '/assets/js/reading-progress.js',
				array(),
				(string) filemtime( $progress_path ),
				array(
					'strategy'  => 'defer',
					'in_footer' => true,
				)
			);
		}
	}

	// Load More / Infinite Scroll — only enqueued when the setting is
	// non-default AND we're on a paginated view where the button will
	// actually render. Keeps default installs free of extra JS weight.
	$pagination = get_theme_mod( 'livingdraft_pagination_style', 'numbered' );
	if ( in_array( $pagination, array( 'load_more', 'infinite' ), true ) && ( is_home() || is_front_page() || is_archive() || is_search() ) ) {
		$path = get_template_directory() . '/assets/js/load-more.js';
		if ( file_exists( $path ) ) {
			wp_enqueue_script(
				'livingdraft-load-more',
				get_template_directory_uri() . '/assets/js/load-more.js',
				array(),
				(string) filemtime( $path ),
				true // in footer, no render-blocking
			);
		}
	}
}
add_action( 'wp_enqueue_scripts', 'livingdraft_assets' );

/**
 * Should the reading progress bar render on this view?
 *
 * Deliberately narrow: single posts and attachment pages, never pages,
 * archives or the front page. A progress bar on a listing measures how far
 * down a list of links someone has scrolled, which is not progress through
 * anything.
 *
 * @since 4.1.0
 * @return bool
 */
function livingdraft_reading_progress_enabled() {
	$on = is_singular( 'post' ) && ! is_embed() && (bool) get_theme_mod( 'livingdraft_reading_progress', true );

	/**
	 * Filter whether the reading progress bar renders.
	 *
	 * @since 4.1.0
	 * @param bool $on Whether to render.
	 */
	return (bool) apply_filters( 'livingdraft_reading_progress', $on );
}

/**
 * Print the bar.
 *
 * Rendered on wp_body_open rather than inside single.php so it survives a
 * template override and works for any plugin that supplies its own single
 * template, as long as the content still lives in .entry-content.
 *
 * The role="progressbar" is on the track, not the fill, and it carries an
 * aria-valuenow that the script keeps current. It is NOT announced live: a
 * screen reader reading "twelve per cent, thirteen per cent" over the top of
 * the article would be actively hostile. It is there to be queried, not
 * broadcast.
 *
 * @since 4.1.0
 * @return void
 */
function livingdraft_reading_progress_markup() {
	if ( ! livingdraft_reading_progress_enabled() ) {
		return;
	}
	?>
	<div class="reading-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="<?php esc_attr_e( 'Reading progress', 'livingdraft' ); ?>">
		<div class="reading-progress__bar"></div>
	</div>
	<?php
}
add_action( 'wp_body_open', 'livingdraft_reading_progress_markup', 5 );

/**
 * Read the stylesheet for inlining, with the theme header comment stripped.
 *
 * @param string $path Absolute path to style.css.
 * @return string
 */
function livingdraft_get_css( $path ) {
	$stamp  = (string) filemtime( $path );
	$cached = get_transient( 'livingdraft_css' );

	// Keyed on the file's own timestamp, so editing style.css invalidates it
	// without leaving a trail of dead transients behind.
	if ( is_array( $cached ) && isset( $cached['stamp'], $cached['css'] ) && $cached['stamp'] === $stamp ) {
		return $cached['css'];
	}

	$css = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$css = livingdraft_squeeze_css( $css );

	set_transient(
		'livingdraft_css',
		array(
			'stamp' => $stamp,
			'css'   => $css,
		),
		WEEK_IN_SECONDS
	);

	return $css;
}

/**
 * Squeeze a stylesheet down for inlining.
 *
 * Comments and indentation are there for whoever edits the theme; they are
 * dead weight on a phone over mobile data. This is deliberately conservative —
 * it removes comments and collapses whitespace, and does nothing clever that
 * could change what a rule means.
 *
 * @param string $css Raw stylesheet.
 * @return string
 */
function livingdraft_squeeze_css( $css ) {
	// Comments, including the theme header WordPress has already read.
	$css = preg_replace( '#/\*.*?\*/#s', '', $css );

	// Runs of whitespace become one space.
	$css = preg_replace( '/\s+/', ' ', (string) $css );

	// Space around punctuation that never needs it.
	$css = preg_replace( '/\s*([{}:;,>])\s*/', '$1', (string) $css );

	// The last semicolon before a closing brace.
	$css = str_replace( ';}', '}', (string) $css );

	return trim( (string) $css );
}

/**
 * RTL sites get the flipped sheet as a normal file.
 */
function livingdraft_rtl_assets() {
	if ( ! is_rtl() ) {
		return;
	}

	$rtl = get_template_directory() . '/rtl.css';
	if ( ! file_exists( $rtl ) ) {
		return;
	}

	wp_enqueue_style(
		'livingdraft-rtl',
		get_template_directory_uri() . '/rtl.css',
		array( 'livingdraft' ),
		filemtime( $rtl )
	);
}
add_action( 'wp_enqueue_scripts', 'livingdraft_rtl_assets', 20 );

/**
 * Body classes: the drop cap switch, and a marker for pages with no rail.
 *
 * @param array $classes Body classes.
 * @return array
 */
function livingdraft_body_class( $classes ) {
	if ( get_theme_mod( 'livingdraft_dropcap', true ) ) {
		$classes[] = 'has-dropcap';
	}

	if ( ! is_active_sidebar( 'sidebar-1' ) ) {
		$classes[] = 'no-rail';
	}

	// Sidebar position (Page layout → Sidebar position). Drives whether the
	// sidebar renders on the left, right (default), or is hidden entirely.
	$position = get_theme_mod( 'livingdraft_sidebar_position', 'right' );
	if ( 'left' === $position ) {
		$classes[] = 'ld-sidebar-left';
	} elseif ( 'hidden' === $position ) {
		$classes[] = 'ld-no-sidebar';
	}

	// Mobile sidebar behaviour (Page layout → Sidebar on mobile).
	$mobile = get_theme_mod( 'livingdraft_sidebar_mobile', 'below' );
	if ( 'hidden' === $mobile ) {
		$classes[] = 'ld-mobile-sidebar-hidden';
	} elseif ( 'drawer' === $mobile ) {
		$classes[] = 'ld-mobile-sidebar-drawer';
	}

	// --- Phase 3 extras (Front page section) ---
	// These four map to body classes so a single CSS ruleset can restyle
	// every story loop on the site without touching template PHP.

	// Homepage layout mode: grid (default) | list | masonry
	$layout = get_theme_mod( 'livingdraft_homepage_layout', 'grid' );
	$classes[] = 'ld-home-' . sanitize_html_class( $layout, 'grid' );

	// Story card visual weight: compact | standard (default) | prominent
	$card_style = get_theme_mod( 'livingdraft_card_style', 'standard' );
	$classes[] = 'ld-card-' . sanitize_html_class( $card_style, 'standard' );

	// Pagination style: numbered (default) | load_more | infinite
	// PHP for load_more/infinite is wired via livingdraft_pagination() itself;
	// the body class is only used for CSS tweaks to the pagination area.
	$pagination = get_theme_mod( 'livingdraft_pagination_style', 'numbered' );
	$classes[] = 'ld-pagination-' . sanitize_html_class( str_replace( '_', '-', $pagination ), 'numbered' );

	// Category card style: uniform (default) | varied (first story bigger).
	// Only applies visually to archive pages where "first" has meaning.
	$catcard = get_theme_mod( 'livingdraft_category_card_style', 'uniform' );
	if ( is_archive() ) {
		$classes[] = 'ld-catcard-' . sanitize_html_class( $catcard, 'uniform' );
	}

	return $classes;
}
add_filter( 'body_class', 'livingdraft_body_class' );

/* ------------------------------------------------------------------
 * 3. Performance
 * ------------------------------------------------------------------ */

/**
 * Strip the parts of core that cost requests and bytes but render nothing.
 */
function livingdraft_trim_head() {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
	remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
	remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );

	remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
	remove_action( 'wp_head', 'wp_oembed_add_host_js' );
	remove_action( 'wp_head', 'rsd_link' );
	remove_action( 'wp_head', 'wlwmanifest_link' );
	remove_action( 'wp_head', 'wp_generator' );
	remove_action( 'wp_head', 'wp_shortlink_wp_head' );
}
add_action( 'init', 'livingdraft_trim_head' );

function livingdraft_drop_embed_script() {
	wp_dequeue_script( 'wp-embed' );
}
add_action( 'wp_footer', 'livingdraft_drop_embed_script' );

/**
 * Remove the emoji DNS prefetch to s.w.org.
 *
 * @param array  $urls          Resource hint URLs.
 * @param string $relation_type Hint type.
 * @return array
 */
function livingdraft_resource_hints( $urls, $relation_type ) {
	if ( 'dns-prefetch' === $relation_type ) {
		$urls = array_filter(
			$urls,
			function ( $url ) {
				return false === strpos( (string) $url, 's.w.org' );
			}
		);
	}
	return $urls;
}
add_filter( 'wp_resource_hints', 'livingdraft_resource_hints', 10, 2 );

/**
 * Drop jQuery Migrate. The theme itself never loads jQuery at all.
 *
 * @param WP_Scripts $scripts Scripts registry.
 */
function livingdraft_no_jquery_migrate( $scripts ) {
	if ( ! is_admin() && isset( $scripts->registered['jquery'] ) ) {
		$scripts->registered['jquery']->deps = array_diff(
			$scripts->registered['jquery']->deps,
			array( 'jquery-migrate' )
		);
	}
}
add_action( 'wp_default_scripts', 'livingdraft_no_jquery_migrate' );

/**
 * Classic theme styles are only needed by the old editor markup.
 */
function livingdraft_dequeue_classic_styles() {
	wp_dequeue_style( 'classic-theme-styles' );
}
add_action( 'wp_enqueue_scripts', 'livingdraft_dequeue_classic_styles', 100 );

/* ------------------------------------------------------------------
 * 4. Widgets
 * ------------------------------------------------------------------ */

function livingdraft_widgets() {
	register_sidebar(
		array(
			'name'          => __( 'Sidebar', 'livingdraft' ),
			'id'            => 'sidebar-1',
			'description'   => __( 'The right-hand rail on articles, archives and search results.', 'livingdraft' ),
			'before_widget' => '<section id="%1$s" class="widget %2$s">',
			'after_widget'  => '</section>',
			'before_title'  => '<h2 class="widget-title">',
			'after_title'   => '</h2>',
		)
	);

	register_sidebar(
		array(
			'name'          => __( 'Footer', 'livingdraft' ),
			'id'            => 'footer-1',
			'description'   => __( 'Shown in the footer, beside the policy links.', 'livingdraft' ),
			'before_widget' => '<section id="%1$s" class="widget %2$s">',
			'after_widget'  => '</section>',
			'before_title'  => '<h2 class="widget-title">',
			'after_title'   => '</h2>',
		)
	);
}
add_action( 'widgets_init', 'livingdraft_widgets' );

/* ------------------------------------------------------------------
 * 5. Excerpts, archive titles, navigation state
 * ------------------------------------------------------------------ */

function livingdraft_excerpt_length() {
	return 26;
}
add_filter( 'excerpt_length', 'livingdraft_excerpt_length', 999 );

function livingdraft_excerpt_more() {
	return '…';
}
add_filter( 'excerpt_more', 'livingdraft_excerpt_more' );

/**
 * A section front is headed "Politics", not "Category: Politics".
 *
 * @param string $title Archive title.
 * @return string
 */
function livingdraft_archive_title( $title ) {
	if ( is_category() || is_tag() || is_tax() ) {
		$title = single_term_title( '', false );
	} elseif ( is_author() ) {
		$title = get_the_author();
	} elseif ( is_post_type_archive() ) {
		$title = post_type_archive_title( '', false );
	}

	return $title;
}
add_filter( 'get_the_archive_title', 'livingdraft_archive_title' );

/**
 * Mark the current menu item for assistive technology.
 *
 * @param array   $atts Link attributes.
 * @param WP_Post $item Menu item.
 * @return array
 */
function livingdraft_nav_current( $atts, $item ) {
	if ( ! empty( $item->current ) ) {
		$atts['aria-current'] = 'page';
	} elseif ( ! empty( $item->current_item_ancestor ) || ! empty( $item->current_item_parent ) ) {
		$atts['aria-current'] = 'true';
	}

	return $atts;
}
add_filter( 'nav_menu_link_attributes', 'livingdraft_nav_current', 10, 2 );

/* ------------------------------------------------------------------
 * 6. Includes
 * ------------------------------------------------------------------ */

require_once get_template_directory() . '/inc/lcp.php';
require_once get_template_directory() . '/inc/template-tags.php';
// Update log lives in the Living Draft Core plugin. The theme's copy was a
// fallback for theme-only installs, but the plugin owns update logs by
// design — running the theme without the plugin now leaves posts with no
// visible log, which is the intended behaviour.
require_once get_template_directory() . '/inc/blocks.php';
require_once get_template_directory() . '/inc/seo.php';
// 4.0: light / dark / auto, with a reader-facing control.
require_once get_template_directory() . '/inc/color-scheme.php';
// 4.0: the demo content importer (Appearance > Demo Content). Admin only —
// the front-end pieces it needs (noindex, sitemap exclusion) are small and
// live inside the same file behind their own hooks.
require_once get_template_directory() . '/inc/demo/demo-content.php';
require_once get_template_directory() . '/inc/starter-content.php';
require_once get_template_directory() . '/inc/customizer.php';
require_once get_template_directory() . '/inc/customizer-extra.php';
// Phase 1: new token-based customizer architecture. This bootstrap loads
// tokens.php + emit.php + register.php in the correct order. Runs AFTER the
// old files so its hooks (registered at priority 5 for customize_register,
// priority 20 for wp_head) can coexist without collision. Settings whose
// theme_mod ids exist in tokens.php are handled by the new system; anything
// else still runs through the old files until Phase 2/3 migrates it.
require_once get_template_directory() . '/inc/customizer/bootstrap.php';
