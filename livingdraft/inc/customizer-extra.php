<?php
/**
 * The rest of the Customizer: colours, type, layout, footer, privacy.
 *
 * Everything here writes to a CSS custom property, which is why one control
 * can repaint the whole site — the stylesheet reads the variable, and every
 * block in the The Living Draft Core plugin reads the same variable.
 *
 * @package LivingDraft
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything the Customizer can change, and what it defaults to.
 *
 * One list, used to build the controls and to print the CSS, so the two can
 * never drift apart.
 *
 * @return array
 */
function livingdraft_settings() {
	return array(
		// Colours.
		'livingdraft_color_paper' => array(
			'default' => '#fdfdfb',
			'var'     => '--paper',
			'label'   => __( 'Paper (page background)', 'livingdraft' ),
			'type'    => 'color',
		),
		'livingdraft_color_ink'   => array(
			'default' => '#111111',
			'var'     => '--ink',
			'label'   => __( 'Ink (text and heavy rules)', 'livingdraft' ),
			'type'    => 'color',
		),
		'livingdraft_color_soft'  => array(
			'default' => '#4a4a45',
			'var'     => '--ink-2',
			'label'   => __( 'Soft ink (standfirsts, excerpts)', 'livingdraft' ),
			'type'    => 'color',
		),
		'livingdraft_color_mark'  => array(
			'default' => '#a3271f',
			'var'     => '--mark',
			'label'   => __( 'Mark (kickers, corrections, accents)', 'livingdraft' ),
			'type'    => 'color',
		),
		'livingdraft_color_link'  => array(
			'default' => '#1b3f7a',
			'var'     => '--link',
			'label'   => __( 'Links inside stories', 'livingdraft' ),
			'type'    => 'color',
		),
		'livingdraft_color_rule'  => array(
			'default' => '#d8d5cc',
			'var'     => '--rule',
			'label'   => __( 'Hairline rules', 'livingdraft' ),
			'type'    => 'color',
		),
	);
}

/**
 * The font stacks on offer. No webfonts: every one of these is already on the
 * reader's device, so nothing has to be downloaded before the page can paint.
 *
 * @return array
 */
function livingdraft_font_stacks() {
	return array(
		'georgia'   => array(
			'label' => __( 'Georgia (newspaper serif)', 'livingdraft' ),
			'stack' => 'Georgia, "Iowan Old Style", "Times New Roman", Times, serif',
		),
		'times'     => array(
			'label' => __( 'Times (classic broadsheet)', 'livingdraft' ),
			'stack' => '"Times New Roman", Times, Georgia, serif',
		),
		'palatino'  => array(
			'label' => __( 'Palatino (softer serif)', 'livingdraft' ),
			'stack' => 'Palatino, "Palatino Linotype", "Book Antiqua", Georgia, serif',
		),
		'system'    => array(
			'label' => __( 'System sans (modern)', 'livingdraft' ),
			'stack' => 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
		),
	);
}

/**
 * Add the sections and controls.
 *
 * @param WP_Customize_Manager $wp_customize Customizer.
 */
function livingdraft_customize_extra( $wp_customize ) {

	// Phase 1 migration sentinel: once the token-driven customizer is loaded
	// (inc/customizer/bootstrap.php), it owns Colours / Typography / Layout /
	// Article page. The four blocks below are guarded so they only run when
	// the new system is absent — e.g. someone downgrades or the bootstrap
	// file is removed for testing.
	$ld_new_system = function_exists( 'livingdraft_tokens' );

	/* -------------------------------------------------- Colours */

	if ( ! $ld_new_system ) :

	$wp_customize->add_section(
		'livingdraft_colours',
		array(
			'title'       => __( 'Colours', 'livingdraft' ),
			'description' => __( 'Changes here repaint the whole site, including every article block. Dark mode adjusts itself from these.', 'livingdraft' ),
			'priority'    => 33,
		)
	);

	foreach ( livingdraft_settings() as $id => $setting ) {
		$wp_customize->add_setting(
			$id,
			array(
				'default'           => $setting['default'],
				'sanitize_callback' => 'sanitize_hex_color',
				'transport'         => 'postMessage',
			)
		);

		$wp_customize->add_control(
			new WP_Customize_Color_Control(
				$wp_customize,
				$id,
				array(
					'label'   => $setting['label'],
					'section' => 'livingdraft_colours',
				)
			)
		);
	}

	endif; // ! $ld_new_system (Colours)

	/* -------------------------------------------------- Typography */

	if ( ! $ld_new_system ) :

	$wp_customize->add_section(
		'livingdraft_type',
		array(
			'title'       => __( 'Typography', 'livingdraft' ),
			'description' => __( 'All device fonts. Nothing is downloaded, so the page can paint immediately.', 'livingdraft' ),
			'priority'    => 34,
		)
	);

	$choices = array();
	foreach ( livingdraft_font_stacks() as $key => $font ) {
		$choices[ $key ] = $font['label'];
	}

	$wp_customize->add_setting(
		'livingdraft_font_head',
		array(
			'default'           => 'georgia',
			'sanitize_callback' => 'sanitize_key',
		)
	);
	$wp_customize->add_control(
		'livingdraft_font_head',
		array(
			'label'   => __( 'Headline font', 'livingdraft' ),
			'section' => 'livingdraft_type',
			'type'    => 'select',
			'choices' => $choices,
		)
	);

	$wp_customize->add_setting(
		'livingdraft_font_body',
		array(
			'default'           => 'georgia',
			'sanitize_callback' => 'sanitize_key',
		)
	);
	$wp_customize->add_control(
		'livingdraft_font_body',
		array(
			'label'   => __( 'Body font', 'livingdraft' ),
			'section' => 'livingdraft_type',
			'type'    => 'select',
			'choices' => $choices,
		)
	);

	$wp_customize->add_setting(
		'livingdraft_base_size',
		array(
			'default'           => 17,
			'sanitize_callback' => 'absint',
		)
	);
	$wp_customize->add_control(
		'livingdraft_base_size',
		array(
			'label'       => __( 'Body text size (px)', 'livingdraft' ),
			'description' => __( 'Between 15 and 21. Newspapers set body text small; screens want it larger.', 'livingdraft' ),
			'section'     => 'livingdraft_type',
			'type'        => 'number',
			'input_attrs' => array(
				'min'  => 15,
				'max'  => 21,
				'step' => 1,
			),
		)
	);

	$wp_customize->add_setting(
		'livingdraft_measure',
		array(
			'default'           => 68,
			'sanitize_callback' => 'absint',
		)
	);
	$wp_customize->add_control(
		'livingdraft_measure',
		array(
			'label'       => __( 'Line length (characters)', 'livingdraft' ),
			'description' => __( 'How wide a line of story text may run. Above about 75 the eye starts losing its place on the way back.', 'livingdraft' ),
			'section'     => 'livingdraft_type',
			'type'        => 'number',
			'input_attrs' => array(
				'min'  => 50,
				'max'  => 90,
				'step' => 1,
			),
		)
	);

	endif; // ! $ld_new_system (Typography)

	/* -------------------------------------------------- Layout */

	if ( ! $ld_new_system ) :

	$wp_customize->add_section(
		'livingdraft_layout',
		array(
			'title'       => __( 'Layout and width', 'livingdraft' ),
			'description' => __( 'The page is fluid: it fills a share of the window and stops at a maximum. That is what keeps the margins sensible when a reader zooms out to 80 per cent.', 'livingdraft' ),
			'priority'    => 35,
		)
	);

	$wp_customize->add_setting(
		'livingdraft_wrap_fill',
		array(
			'default'           => 94,
			'sanitize_callback' => 'absint',
		)
	);
	$wp_customize->add_control(
		'livingdraft_wrap_fill',
		array(
			'label'       => __( 'Fill this much of the window (%)', 'livingdraft' ),
			'description' => __( '94 leaves a thin margin at every size. Lower it for more white space.', 'livingdraft' ),
			'section'     => 'livingdraft_layout',
			'type'        => 'number',
			'input_attrs' => array(
				'min'  => 70,
				'max'  => 100,
				'step' => 1,
			),
		)
	);

	$wp_customize->add_setting(
		'livingdraft_wrap_max',
		array(
			'default'           => 2100,
			'sanitize_callback' => 'absint',
		)
	);
	$wp_customize->add_control(
		'livingdraft_wrap_max',
		array(
			'label'       => __( 'But never wider than (px)', 'livingdraft' ),
			'description' => __( '2100 keeps the margins thin even at 80 per cent zoom on a 1920 screen. Lower it towards 1300 for a narrower, more traditional page.', 'livingdraft' ),
			'section'     => 'livingdraft_layout',
			'type'        => 'number',
			'input_attrs' => array(
				'min'  => 1100,
				'max'  => 2600,
				'step' => 20,
			),
		)
	);

	$wp_customize->add_setting(
		'livingdraft_rail_width',
		array(
			'default'           => 300,
			'sanitize_callback' => 'absint',
		)
	);
	$wp_customize->add_control(
		'livingdraft_rail_width',
		array(
			'label'       => __( 'Sidebar width (px)', 'livingdraft' ),
			'section'     => 'livingdraft_layout',
			'type'        => 'number',
			'input_attrs' => array(
				'min'  => 240,
				'max'  => 420,
				'step' => 10,
			),
		)
	);

	$wp_customize->add_setting(
		'livingdraft_cols',
		array(
			'default'           => 3,
			'sanitize_callback' => 'absint',
		)
	);
	$wp_customize->add_control(
		'livingdraft_cols',
		array(
			'label'       => __( 'Columns in the story grid', 'livingdraft' ),
			'description' => __( 'Three is the newspaper default. Four suits a very wide page.', 'livingdraft' ),
			'section'     => 'livingdraft_layout',
			'type'        => 'select',
			'choices'     => array(
				3 => __( 'Three', 'livingdraft' ),
				4 => __( 'Four', 'livingdraft' ),
			),
		)
	);

	endif; // ! $ld_new_system (Layout)

	/* -------------------------------------------------- Front page additions */

	if ( ! $ld_new_system ) :

	$wp_customize->add_setting(
		'livingdraft_slider_on',
		array(
			'default'           => true,
			'sanitize_callback' => 'wp_validate_boolean',
		)
	);
	$wp_customize->add_control(
		'livingdraft_slider_on',
		array(
			'label'       => __( 'Show the latest-stories strip', 'livingdraft' ),
			'description' => __( 'A swipeable row under the lead. Nothing rotates on its own.', 'livingdraft' ),
			'section'     => 'livingdraft_front',
			'type'        => 'checkbox',
		)
	);

	$wp_customize->add_setting(
		'livingdraft_slider_count',
		array(
			'default'           => 8,
			'sanitize_callback' => 'absint',
		)
	);
	$wp_customize->add_control(
		'livingdraft_slider_count',
		array(
			'label'       => __( 'Stories in the strip', 'livingdraft' ),
			'section'     => 'livingdraft_front',
			'type'        => 'number',
			'input_attrs' => array(
				'min'  => 3,
				'max'  => 15,
				'step' => 1,
			),
		)
	);

	$wp_customize->add_setting(
		'livingdraft_tabs_on',
		array(
			'default'           => true,
			'sanitize_callback' => 'wp_validate_boolean',
		)
	);
	$wp_customize->add_control(
		'livingdraft_tabs_on',
		array(
			'label'       => __( 'Show Latest / Popular / Trending', 'livingdraft' ),
			'description' => __( 'Popular and Trending need the The Living Draft Core plugin, which does the counting.', 'livingdraft' ),
			'section'     => 'livingdraft_front',
			'type'        => 'checkbox',
		)
	);

	$wp_customize->add_setting(
		'livingdraft_tabs_count',
		array(
			'default'           => 5,
			'sanitize_callback' => 'absint',
		)
	);
	$wp_customize->add_control(
		'livingdraft_tabs_count',
		array(
			'label'       => __( 'Stories in each list', 'livingdraft' ),
			'section'     => 'livingdraft_front',
			'type'        => 'number',
			'input_attrs' => array(
				'min'  => 3,
				'max'  => 10,
				'step' => 1,
			),
		)
	);

	$wp_customize->add_setting(
		'livingdraft_cat_limit',
		array(
			'default'           => 5,
			'sanitize_callback' => 'absint',
		)
	);
	$wp_customize->add_control(
		'livingdraft_cat_limit',
		array(
			'label'       => __( 'Sections listed in the sidebar', 'livingdraft' ),
			'description' => __( 'The busiest sections first. The rest stay reachable through a link.', 'livingdraft' ),
			'section'     => 'livingdraft_front',
			'type'        => 'number',
			'input_attrs' => array(
				'min'  => 3,
				'max'  => 15,
				'step' => 1,
			),
		)
	);

	endif; // ! $ld_new_system (Front page additions)

	/* -------------------------------------------------- Article page */

	if ( ! $ld_new_system ) :

	$wp_customize->add_section(
		'livingdraft_article',
		array(
			'title'       => __( 'Article page', 'livingdraft' ),
			'description' => __( 'What appears on a single story.', 'livingdraft' ),
			'priority'    => 36,
		)
	);

	$toggles = array(
		'livingdraft_reading_progress'  => __( 'Reading progress bar', 'livingdraft' ),
		'livingdraft_show_reading_time' => __( 'Reading time in the byline', 'livingdraft' ),
		'livingdraft_show_author_box'   => __( 'Author box under the story', 'livingdraft' ),
		'livingdraft_show_read_next'    => __( 'Read Next block', 'livingdraft' ),
		// 4.0: the running-story timeline. Only ever renders when The Living
		// Draft Core has grouped the article into a story, so on a site
		// without the plugin this switch controls nothing visible.
		'livingdraft_show_timeline'     => __( 'Story timeline ("This Story So Far")', 'livingdraft' ),
		'livingdraft_show_post_nav'     => __( 'Earlier / Later links', 'livingdraft' ),
		'livingdraft_show_breadcrumbs'  => __( 'Breadcrumb trail', 'livingdraft' ),
	);

	/*
	 * Read counts default to OFF, unlike every toggle above, so they are
	 * registered separately rather than bent into the shared loop.
	 *
	 * Why off: a count under a headline is a signal in both directions. A
	 * story with 4,000 reads invites the next reader in; a story published
	 * this morning showing 6 pushes them away, and every story is that
	 * story for its first few hours. Turn it on once the numbers are worth
	 * showing. The floor filter hides anything below 50 either way.
	 */
	$wp_customize->add_setting(
		'livingdraft_show_view_count',
		array(
			'default'           => false,
			'sanitize_callback' => 'wp_validate_boolean',
		)
	);
	$wp_customize->add_control(
		'livingdraft_show_view_count',
		array(
			'label'       => __( 'Read count in the byline', 'livingdraft' ),
			'description' => __( 'Shows how many times a story has been read, under the headline. Hidden on stories with fewer than 50 reads. The Popular and Trending lists show their counts regardless.', 'livingdraft' ),
			'section'     => 'livingdraft_article',
			'type'        => 'checkbox',
		)
	);

	foreach ( $toggles as $id => $label ) {
		$wp_customize->add_setting(
			$id,
			array(
				'default'           => true,
				'sanitize_callback' => 'wp_validate_boolean',
			)
		);
		$wp_customize->add_control(
			$id,
			array(
				'label'   => $label,
				'section' => 'livingdraft_article',
				'type'    => 'checkbox',
			)
		);
	}

	/* ---- Featured image sizing on single posts ------------------- */

	$wp_customize->add_setting(
		'livingdraft_lede_width',
		array(
			'default'           => 100,
			'sanitize_callback' => 'absint',
			'transport'         => 'refresh',
		)
	);
	$wp_customize->add_control(
		'livingdraft_lede_width',
		array(
			'label'       => __( 'Featured image width (%)', 'livingdraft' ),
			'description' => __( 'Percent of the reading column the hero image fills on desktop. 100 (default) matches the paragraph width beneath it. Drop lower for a narrower, more magazine-like image. Mobile stays full-bleed regardless.', 'livingdraft' ),
			'section'     => 'livingdraft_article',
			'type'        => 'number',
			'input_attrs' => array(
				'min'  => 40,
				'max'  => 100,
				'step' => 5,
			),
		)
	);

	$wp_customize->add_setting(
		'livingdraft_lede_max_height',
		array(
			'default'           => 0,
			'sanitize_callback' => 'absint',
			'transport'         => 'refresh',
		)
	);
	$wp_customize->add_control(
		'livingdraft_lede_max_height',
		array(
			'label'       => __( 'Max image height (px)', 'livingdraft' ),
			'description' => __( '0 keeps the natural 1.91:1 crop the newsroom uploads. Set a number (say 360) to letterbox tall images into a wider crop — the centre of the frame is preserved and top/bottom edges are trimmed.', 'livingdraft' ),
			'section'     => 'livingdraft_article',
			'type'        => 'number',
			'input_attrs' => array(
				'min'  => 0,
				'max'  => 900,
				'step' => 10,
			),
		)
	);

	$wp_customize->add_setting(
		'livingdraft_lede_align',
		array(
			'default'           => 'center',
			'sanitize_callback' => 'livingdraft_sanitize_lede_align',
			'transport'         => 'refresh',
		)
	);
	$wp_customize->add_control(
		'livingdraft_lede_align',
		array(
			'label'       => __( 'Image alignment', 'livingdraft' ),
			'description' => __( 'Only matters when width is under 100.', 'livingdraft' ),
			'section'     => 'livingdraft_article',
			'type'        => 'select',
			'choices'     => array(
				'left'   => __( 'Left', 'livingdraft' ),
				'center' => __( 'Centre', 'livingdraft' ),
				'right'  => __( 'Right', 'livingdraft' ),
			),
		)
	);

	endif; // ! $ld_new_system (Article page)

	/* -------------------------------------------------- Footer */

	if ( ! $ld_new_system ) :

	$wp_customize->add_section(
		'livingdraft_footer',
		array(
			'title'       => __( 'Footer and newsletter', 'livingdraft' ),
			'description' => __( 'Subscribers are stored in your own database by the The Living Draft Core plugin. Find them under Subscribers in the admin menu.', 'livingdraft' ),
			'priority'    => 37,
		)
	);

	$wp_customize->add_setting(
		'livingdraft_newsletter_on',
		array(
			'default'           => true,
			'sanitize_callback' => 'wp_validate_boolean',
		)
	);
	$wp_customize->add_control(
		'livingdraft_newsletter_on',
		array(
			'label'   => __( 'Show the newsletter band above the footer', 'livingdraft' ),
			'section' => 'livingdraft_footer',
			'type'    => 'checkbox',
		)
	);

	$wp_customize->add_setting(
		'livingdraft_newsletter_title',
		array(
			'default'           => __( 'The edition, by email', 'livingdraft' ),
			'sanitize_callback' => 'sanitize_text_field',
		)
	);
	$wp_customize->add_control(
		'livingdraft_newsletter_title',
		array(
			'label'   => __( 'Newsletter heading', 'livingdraft' ),
			'section' => 'livingdraft_footer',
			'type'    => 'text',
		)
	);

	$wp_customize->add_setting(
		'livingdraft_footer_note',
		array(
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);
	$wp_customize->add_control(
		'livingdraft_footer_note',
		array(
			'label'       => __( 'Copyright line', 'livingdraft' ),
			'description' => __( 'Leave blank for the year and your site name.', 'livingdraft' ),
			'section'     => 'livingdraft_footer',
			'type'        => 'text',
		)
	);

	endif; // ! $ld_new_system (Footer)

	/* -------------------------------------------------- Privacy */

	if ( ! $ld_new_system ) :

	$wp_customize->add_section(
		'livingdraft_privacy',
		array(
			'title'       => __( 'Cookies and tracking', 'livingdraft' ),
			'description' => __( 'The banner holds Google Analytics back until a reader agrees. A banner that shows a message and loads the tracker anyway would not count as consent.', 'livingdraft' ),
			'priority'    => 38,
		)
	);

	$wp_customize->add_setting(
		'livingdraft_consent_on',
		array(
			'default'           => true,
			'sanitize_callback' => 'wp_validate_boolean',
		)
	);
	$wp_customize->add_control(
		'livingdraft_consent_on',
		array(
			'label'       => __( 'Ask before tracking', 'livingdraft' ),
			'description' => __( 'Switch off only if you run no analytics at all.', 'livingdraft' ),
			'section'     => 'livingdraft_privacy',
			'type'        => 'checkbox',
		)
	);

	$wp_customize->add_setting(
		'livingdraft_consent_text',
		array(
			'default'           => __( 'We use cookies to understand which stories are read. Nothing is loaded until you choose.', 'livingdraft' ),
			'sanitize_callback' => 'sanitize_text_field',
		)
	);
	$wp_customize->add_control(
		'livingdraft_consent_text',
		array(
			'label'   => __( 'Banner wording', 'livingdraft' ),
			'section' => 'livingdraft_privacy',
			'type'    => 'textarea',
		)
	);

	$wp_customize->add_setting(
		'livingdraft_consent_link',
		array(
			'default'           => '',
			'sanitize_callback' => 'esc_url_raw',
		)
	);
	$wp_customize->add_control(
		'livingdraft_consent_link',
		array(
			'label'       => __( 'Cookie notice page', 'livingdraft' ),
			'description' => __( 'Leave blank to use your privacy policy page.', 'livingdraft' ),
			'section'     => 'livingdraft_privacy',
			'type'        => 'url',
		)
	);

	endif; // ! $ld_new_system (Privacy)
}
add_action( 'customize_register', 'livingdraft_customize_extra', 20 );

/**
 * Restrict the featured-image alignment to the three positions the CSS knows
 * how to place. Anything else falls back to centre.
 *
 * @param string $value Raw value from the customizer.
 * @return string
 */
function livingdraft_sanitize_lede_align( $value ) {
	$allowed = array( 'left', 'center', 'right' );
	return in_array( $value, $allowed, true ) ? $value : 'center';
}

/**
 * Turn the settings into CSS variables and print them.
 *
 * This is the whole reason one colour control can change the entire site,
 * blocks included: everything reads these variables.
 */
/*
 * === PHASE 1 MIGRATION NOTE ===
 *
 * The old livingdraft_customizer_css() function that used to live here has
 * been replaced by the token-driven emitter in inc/customizer/emit.php. Its
 * hook is no longer registered — the new emitter reads from the same
 * settings via livingdraft_tokens(), producing identical output.
 *
 * The old function definition has been renamed to *_legacy_ and left below
 * (unhooked) purely for reference. It will be deleted entirely in Phase 3
 * once every setting has migrated to the token registry.
 */
function livingdraft_customizer_css_legacy_do_not_call() {
	$css   = array();
	$fonts = livingdraft_font_stacks();

	foreach ( livingdraft_settings() as $id => $setting ) {
		$value = get_theme_mod( $id, $setting['default'] );

		if ( $value && $value !== $setting['default'] ) {
			$css[] = $setting['var'] . ':' . $value;
		}
	}

	$head = get_theme_mod( 'livingdraft_font_head', 'georgia' );
	$body = get_theme_mod( 'livingdraft_font_body', 'georgia' );

	if ( isset( $fonts[ $body ] ) && 'georgia' !== $body ) {
		$css[] = '--serif:' . $fonts[ $body ]['stack'];
	}
	if ( isset( $fonts[ $head ] ) ) {
		$css[] = '--display:' . $fonts[ $head ]['stack'];
	}

	$fill = (int) get_theme_mod( 'livingdraft_wrap_fill', 94 );
	$max  = (int) get_theme_mod( 'livingdraft_wrap_max', 2100 );
	$css[] = sprintf( '--wrap:min(%dvw, %dpx)', max( 70, min( 100, $fill ) ), max( 1100, $max ) );

	$rail = (int) get_theme_mod( 'livingdraft_rail_width', 300 );
	if ( 300 !== $rail ) {
		$css[] = '--rail:' . $rail . 'px';
	}

	$size = (int) get_theme_mod( 'livingdraft_base_size', 17 );
	if ( 17 !== $size ) {
		$css[] = '--base:' . $size . 'px';
	}

	$measure = (int) get_theme_mod( 'livingdraft_measure', 68 );
	if ( 68 !== $measure ) {
		$css[] = '--measure:' . $measure . 'ch';
	}

	$lede_w = max( 40, min( 100, (int) get_theme_mod( 'livingdraft_lede_width', 100 ) ) );
	if ( 100 !== $lede_w ) {
		$css[] = '--lede-width:' . $lede_w . '%';
	}

	$lede_h = max( 0, min( 900, (int) get_theme_mod( 'livingdraft_lede_max_height', 0 ) ) );
	if ( 0 !== $lede_h ) {
		$css[] = '--lede-max-height:' . $lede_h . 'px';
	}

	$lede_a = get_theme_mod( 'livingdraft_lede_align', 'center' );
	if ( 'left' === $lede_a ) {
		$css[] = '--lede-margin:0 auto 0 0';
	} elseif ( 'right' === $lede_a ) {
		$css[] = '--lede-margin:0 0 0 auto';
	}

	if ( empty( $css ) ) {
		return;
	}

	printf(
		'<style id="livingdraft-vars">:root{%s}</style>' . "\n",
		wp_strip_all_tags( implode( ';', $css ) )
	);
}
// Hook removed. New emitter at inc/customizer/emit.php owns wp_head, priority 20.

/**
 * Four columns is a layout choice, not a variable, so it gets its own rule.
 */
function livingdraft_column_css() {
	if ( 4 !== (int) get_theme_mod( 'livingdraft_cols', 3 ) ) {
		return;
	}

	echo '<style id="livingdraft-cols">@media (min-width:1100px){'
		. '.cols{grid-template-columns:repeat(4,1fr)}'
		. '.col-item:nth-child(3n+1){padding-left:22px;border-left:1px solid var(--rule)}'
		. '.col-item:nth-child(3n){padding-right:22px}'
		. '.col-item:nth-child(4n+1){padding-left:0;border-left:0}'
		. '.col-item:nth-child(4n){padding-right:0}'
		. '}</style>' . "\n";
}
add_action( 'wp_head', 'livingdraft_column_css', 21 );

/* ------------------------------------------------------------------
 * Sections in the sidebar: the busiest few, not all twenty-three
 * ------------------------------------------------------------------ */

/**
 * Limit the classic Categories widget to the busiest sections.
 *
 * @param array $args Widget arguments.
 * @return array
 */
function livingdraft_trim_categories( $args ) {
	$args['orderby']      = 'count';
	$args['order']        = 'DESC';
	$args['number']       = max( 1, (int) get_theme_mod( 'livingdraft_cat_limit', 5 ) );
	$args['show_count']   = true;
	$args['hierarchical'] = false;

	return $args;
}
add_filter( 'widget_categories_args', 'livingdraft_trim_categories' );
add_filter( 'widget_categories_dropdown_args', 'livingdraft_trim_categories' );

/**
 * Rebuild the block version of the Categories widget.
 *
 * The block ignores the arguments the classic widget accepts, so it always
 * lists categories alphabetically. Trimming that list to five gave the first
 * five in the alphabet, not the five busiest — which is the opposite of
 * useful on a news site, because it buries the section carrying most of the
 * work behind whichever one happens to start with A.
 *
 * So the list is rebuilt from scratch, ordered by how much has been filed.
 * The rest are not thrown away: they sit inside a disclosure below, which
 * keeps every section in the HTML for search engines while showing a reader
 * only the five that matter.
 *
 * @param string $content Rendered block.
 * @param array  $block   Block data.
 * @return string
 */
function livingdraft_trim_category_block( $content, $block ) {
	if ( empty( $block['blockName'] ) || 'core/categories' !== $block['blockName'] ) {
		return $content;
	}

	// The dropdown variant is already compact; leave it alone.
	if ( false !== strpos( $content, '<select' ) ) {
		return $content;
	}

	$limit = max( 1, (int) get_theme_mod( 'livingdraft_cat_limit', 5 ) );

	$all = get_categories(
		array(
			'orderby'    => 'count',
			'order'      => 'DESC',
			'hide_empty' => true,
		)
	);

	if ( is_wp_error( $all ) || count( $all ) < 2 ) {
		return $content;
	}

	$top  = array_slice( $all, 0, $limit );
	$rest = array_slice( $all, $limit );

	$show_counts = false !== strpos( $content, 'wp-block-categories__post-count' )
		|| false !== strpos( $content, 'post-count' );

	$out = '<ul class="wp-block-categories wp-block-categories-list ld-sections">';

	foreach ( $top as $term ) {
		$out .= sprintf(
			'<li class="cat-item cat-item-%1$d"><a href="%2$s">%3$s</a>%4$s</li>',
			(int) $term->term_id,
			esc_url( (string) get_category_link( $term->term_id ) ),
			esc_html( $term->name ),
			$show_counts ? ' <span class="post-count">(' . esc_html( number_format_i18n( $term->count ) ) . ')</span>' : ''
		);
	}

	$out .= '</ul>';

	if ( ! empty( $rest ) ) {
		$out .= '<details class="ld-more-sections"><summary>';
		$out .= sprintf(
			/* translators: %s: how many further sections there are. */
			esc_html( _n( '%s more section', '%s more sections', count( $rest ), 'livingdraft' ) ),
			esc_html( number_format_i18n( count( $rest ) ) )
		);
		$out .= '</summary><ul class="wp-block-categories ld-sections">';

		foreach ( $rest as $term ) {
			$out .= sprintf(
				'<li class="cat-item cat-item-%1$d"><a href="%2$s">%3$s</a></li>',
				(int) $term->term_id,
				esc_url( (string) get_category_link( $term->term_id ) ),
				esc_html( $term->name )
			);
		}

		$out .= '</ul></details>';
	}

	return $out;
}
add_filter( 'render_block', 'livingdraft_trim_category_block', 10, 2 );
