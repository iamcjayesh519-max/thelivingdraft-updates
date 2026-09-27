<?php
/**
 * The editor blocks.
 *
 * Everything here is a one-click insert from the block inserter. You click it,
 * a finished section appears, you paste your words in. No formatting by hand.
 *
 * Nothing carries a hardcoded colour. Every block reads the theme's own
 * variables, so changing your red in the Customizer changes every FAQ box and
 * every rating on the site at the same time.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A category in the inserter so the blocks are together and easy to find.
 */
function livingdraft_core_pattern_category() {
	if ( function_exists( 'register_block_pattern_category' ) ) {
		register_block_pattern_category(
			'livingdraft-editorial',
			array( 'label' => __( 'The Living Draft — Article parts', 'livingdraft-core' ) )
		);
	}
}
add_action( 'init', 'livingdraft_core_pattern_category', 9 );

/**
 * Register every pattern file in /patterns.
 */
function livingdraft_core_register_patterns() {
	if ( ! function_exists( 'register_block_pattern' ) ) {
		return;
	}

	foreach ( glob( LIVINGDRAFT_CORE_DIR . 'patterns/*.php' ) as $file ) {
		$data = get_file_data(
			$file,
			array(
				'title'       => 'Title',
				'slug'        => 'Slug',
				'categories'  => 'Categories',
				'keywords'    => 'Keywords',
				'description' => 'Description',
			)
		);

		if ( empty( $data['title'] ) || empty( $data['slug'] ) ) {
			continue;
		}

		ob_start();
		include $file;
		$content = ob_get_clean();

		register_block_pattern(
			$data['slug'],
			array(
				'title'       => $data['title'],
				'content'     => $content,
				'description' => $data['description'],
				'categories'  => array_filter( array_map( 'trim', explode( ',', $data['categories'] ) ) ),
				'keywords'    => array_filter( array_map( 'trim', explode( ',', $data['keywords'] ) ) ),
			)
		);
	}
}
add_action( 'init', 'livingdraft_core_register_patterns', 11 );

/**
 * Block styling, on the page and inside the editor.
 *
 * Loaded from the plugin so the blocks still look right if the theme is ever
 * swapped — but written against the theme's variables, so while Living Draft
 * is active they take its colours automatically.
 */
function livingdraft_core_block_assets() {
	$css = LIVINGDRAFT_CORE_DIR . 'assets/blocks.css';

	if ( file_exists( $css ) ) {
		/*
		 * If the theme is inlining its own stylesheet, inline this one too.
		 * A separate file here would be a second render-blocking request for
		 * about 8 KB — which is exactly what a page speed report flags, and
		 * it delays the first paint for no benefit.
		 */
		$inline = function_exists( 'livingdraft_inline_css' ) && livingdraft_inline_css();

		if ( $inline ) {
			wp_register_style( 'livingdraft-blocks', false, array(), filemtime( $css ) );
			wp_enqueue_style( 'livingdraft-blocks' );
			wp_add_inline_style( 'livingdraft-blocks', livingdraft_core_squeeze_css( $css ) );
		} else {
			wp_enqueue_style(
				'livingdraft-blocks',
				LIVINGDRAFT_CORE_URL . 'assets/blocks.css',
				array(),
				filemtime( $css )
			);
		}
	}

	$js = LIVINGDRAFT_CORE_DIR . 'assets/blocks-front.js';

	if ( file_exists( $js ) ) {
		wp_enqueue_script(
			'livingdraft-blocks-front',
			LIVINGDRAFT_CORE_URL . 'assets/blocks-front.js',
			array(),
			filemtime( $js ),
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		// The one string the script has to produce on its own, so it can be
		// translated like everything else.
		wp_localize_script(
			'livingdraft-blocks-front',
			'livingdraftL10n',
			array(
				'networkError' => __( 'Could not reach the server. Please try again.', 'livingdraft-core' ),
			)
		);
	}
}
add_action( 'wp_enqueue_scripts', 'livingdraft_core_block_assets', 20 );

/**
 * The same stylesheet inside the editor, so what you see is what runs.
 */
function livingdraft_core_editor_assets() {
	$css = LIVINGDRAFT_CORE_DIR . 'assets/blocks.css';

	if ( file_exists( $css ) ) {
		wp_enqueue_style(
			'livingdraft-blocks-editor',
			LIVINGDRAFT_CORE_URL . 'assets/blocks.css',
			array(),
			filemtime( $css )
		);
	}

	$js = LIVINGDRAFT_CORE_DIR . 'assets/blocks-editor.js';

	if ( ! file_exists( $js ) ) {
		return;
	}

	wp_enqueue_script(
		'livingdraft-blocks-editor',
		LIVINGDRAFT_CORE_URL . 'assets/blocks-editor.js',
		array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
		filemtime( $js ),
		true
	);
}
add_action( 'enqueue_block_editor_assets', 'livingdraft_core_editor_assets' );

/**
 * Read and squeeze the block stylesheet for inlining.
 *
 * Cached against the file's own timestamp, so editing it invalidates the
 * cache without leaving dead transients behind.
 *
 * @param string $path Absolute path.
 * @return string
 */
function livingdraft_core_squeeze_css( $path ) {
	$stamp  = (string) filemtime( $path );
	$cached = get_transient( 'livingdraft_blocks_css' );

	if ( is_array( $cached ) && isset( $cached['stamp'], $cached['css'] ) && $cached['stamp'] === $stamp ) {
		return $cached['css'];
	}

	$css = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	if ( function_exists( 'livingdraft_squeeze_css' ) ) {
		$css = livingdraft_squeeze_css( $css );
	} else {
		$css = trim( (string) preg_replace( '#/\*.*?\*/#s', '', $css ) );
	}

	set_transient(
		'livingdraft_blocks_css',
		array(
			'stamp' => $stamp,
			'css'   => $css,
		),
		WEEK_IN_SECONDS
	);

	return $css;
}

/* ------------------------------------------------------------------
 * The Custom block: your own HTML, CSS and JavaScript
 * ------------------------------------------------------------------ */

/**
 * Render it.
 *
 * The capability is checked against the AUTHOR of the post, never against
 * whoever happens to be reading it. Checking the reader had two consequences,
 * both bad:
 *
 *   1. The block rendered differently for different people. An administrator
 *      saw their script run; a logged-out reader got a stripped version. The
 *      usual report is "my custom code works for me but not for visitors".
 *
 *   2. Worse, it was a way up. The js attribute holds plain JavaScript with no
 *      angle brackets, so wp_filter_post_kses() does not strip it on save. A
 *      contributor could store code and have it execute in an administrator's
 *      browser the moment the admin opened the post to review it.
 *
 * Trust now travels with the post, so the same HTML is served to everybody.
 *
 * @param array         $attrs   Block attributes.
 * @param string        $content Inner content (unused).
 * @param WP_Block|null $block   Block instance.
 * @return string
 */
function livingdraft_core_render_custom( $attrs, $content = '', $block = null ) {
	$html = isset( $attrs['html'] ) ? (string) $attrs['html'] : '';
	$css  = isset( $attrs['css'] ) ? (string) $attrs['css'] : '';
	$js   = isset( $attrs['js'] ) ? (string) $attrs['js'] : '';

	if ( '' === trim( $html . $css . $js ) ) {
		return '';
	}

	$post_id = get_the_ID();
	$author  = $post_id ? (int) get_post_field( 'post_author', $post_id ) : 0;
	$trusted = $author && user_can( $author, 'unfiltered_html' );

	/**
	 * Whether this block's raw CSS and JavaScript may be printed.
	 *
	 * @param bool $trusted Whether the post author may post unfiltered HTML.
	 * @param int  $post_id The post being rendered.
	 */
	$trusted = (bool) apply_filters( 'livingdraft_custom_block_trusted', $trusted, $post_id );

	// wp_unique_id() (WP 6.4+) guarantees no collisions on pages with many
	// blocks; fall back to a wider random range on older cores.
	$id = function_exists( 'wp_unique_id' ) ? wp_unique_id( 'ld-custom-' ) : 'ld-custom-' . wp_rand( 100000, 999999 );
	$out = '<div class="ld-custom" id="' . esc_attr( $id ) . '">';

	/*
	 * This CSS is global, not scoped to the block — a rule for "body" really
	 * does apply to the whole page. Scoping it automatically would silently
	 * break every site already relying on that, so it is opt-in. Because only
	 * a trusted author can reach this, global CSS grants nothing that editing
	 * a stylesheet would not.
	 */
	if ( '' !== trim( $css ) && $trusted ) {
		$clean = wp_strip_all_tags( $css );

		if ( apply_filters( 'livingdraft_custom_block_scope_css', false, $post_id ) ) {
			$clean = (string) preg_replace(
				'/(^|\})\s*([^@{}]+)\{/',
				'$1 #' . $id . ' $2{',
				$clean
			);
		}

		$out .= '<style>' . $clean . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	$out .= $trusted ? $html : wp_kses_post( $html );

	if ( '' !== trim( $js ) && $trusted ) {
		// The 'unfiltered_html' capability IS the trust check. wp_strip_all_tags()
		// on JS eats every '<' and breaks legitimate code like "if ( x < 5 )"
		// or JSX-like syntax. We only need to prevent the one string that would
		// break out of the surrounding <script> tag.
		$safe_js = str_replace( '</script>', '<\/script>', $js );
		$out    .= '<script>(function(){' . $safe_js . '}());</script>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	$out .= '</div>';

	return $out;
}

/**
 * Register it.
 */
function livingdraft_core_register_custom_block() {
	if ( ! function_exists( 'register_block_type' ) ) {
		return;
	}

	register_block_type(
		'livingdraft/custom',
		array(
			'api_version'     => 2,
			'title'           => __( 'Custom code', 'livingdraft-core' ),
			'category'        => 'widgets',
			'icon'            => 'editor-code',
			'description'     => __( 'Your own HTML, CSS and JavaScript in one block.', 'livingdraft-core' ),
			'attributes'      => array(
				'html' => array(
					'type'    => 'string',
					'default' => '',
				),
				'css'  => array(
					'type'    => 'string',
					'default' => '',
				),
				'js'   => array(
					'type'    => 'string',
					'default' => '',
				),
			),
			'render_callback' => 'livingdraft_core_render_custom',
		)
	);
}
add_action( 'init', 'livingdraft_core_register_custom_block' );
