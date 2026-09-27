<?php
/**
 * Customizer options — masthead furniture, front page, sharing, social.
 *
 * @package LivingDraft
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param WP_Customize_Manager $wp_customize Customizer.
 */
function livingdraft_customize_register( $wp_customize ) {

	// Phase 3 migration guard: when the token-driven customizer is loaded
	// (inc/customizer/bootstrap.php), it owns Masthead, Front page, Sharing,
	// and Social sections — everything below is skipped. The old code is
	// preserved here as reference; it will be deleted in a future cleanup.
	if ( function_exists( 'livingdraft_tokens' ) ) {
		return;
	}

	/* --------------------------------------------------------------
	 * Masthead
	 * -------------------------------------------------------------- */

	$wp_customize->add_section(
		'livingdraft_masthead',
		array(
			'title'       => __( 'Masthead', 'livingdraft' ),
			'description' => __( 'The lines that flank the title and run under it.', 'livingdraft' ),
			'priority'    => 30,
		)
	);

	$wp_customize->add_setting(
		'livingdraft_edition_line',
		array(
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
			'transport'         => 'refresh',
		)
	);
	$wp_customize->add_control(
		'livingdraft_edition_line',
		array(
			'label'       => __( 'Edition line', 'livingdraft' ),
			'description' => __( 'Sits at the top right, opposite the volume number. For example: Kalyan · Online.', 'livingdraft' ),
			'section'     => 'livingdraft_masthead',
			'type'        => 'text',
		)
	);

	$wp_customize->add_setting(
		'livingdraft_strapline',
		array(
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
			'transport'         => 'refresh',
		)
	);
	$wp_customize->add_control(
		'livingdraft_strapline',
		array(
			'label'       => __( 'Dateline strapline', 'livingdraft' ),
			'description' => __( 'Runs beside the date in the strip under the title. Leave blank to use the site tagline.', 'livingdraft' ),
			'section'     => 'livingdraft_masthead',
			'type'        => 'text',
		)
	);

	$wp_customize->add_setting(
		'livingdraft_founded',
		array(
			'default'           => (int) wp_date( 'Y' ),
			'sanitize_callback' => 'absint',
			'transport'         => 'refresh',
		)
	);
	$wp_customize->add_control(
		'livingdraft_founded',
		array(
			'label'       => __( 'Year founded', 'livingdraft' ),
			'description' => __( 'Sets the volume number: one volume per year since this date.', 'livingdraft' ),
			'section'     => 'livingdraft_masthead',
			'type'        => 'number',
			'input_attrs' => array(
				'min'  => 1800,
				'max'  => (int) wp_date( 'Y' ),
				'step' => 1,
			),
		)
	);

	/* --------------------------------------------------------------
	 * Front page
	 * -------------------------------------------------------------- */

	$wp_customize->add_section(
		'livingdraft_front',
		array(
			'title'       => __( 'Front page', 'livingdraft' ),
			'description' => __( 'How the lead story and the brief rail are set. The same shape is used on section fronts.', 'livingdraft' ),
			'priority'    => 31,
		)
	);

	$wp_customize->add_setting(
		'livingdraft_brief_count',
		array(
			'default'           => 5,
			'sanitize_callback' => 'absint',
			'transport'         => 'refresh',
		)
	);
	$wp_customize->add_control(
		'livingdraft_brief_count',
		array(
			'label'       => __( 'Stories in the brief rail', 'livingdraft' ),
			'description' => __( 'Set to 0 to run the lead full width. Above four, the rest fall under a second heading.', 'livingdraft' ),
			'section'     => 'livingdraft_front',
			'type'        => 'number',
			'input_attrs' => array(
				'min'  => 0,
				'max'  => 8,
				'step' => 1,
			),
		)
	);

	$wp_customize->add_setting(
		'livingdraft_dropcap',
		array(
			'default'           => true,
			'sanitize_callback' => 'wp_validate_boolean',
			'transport'         => 'refresh',
		)
	);
	$wp_customize->add_control(
		'livingdraft_dropcap',
		array(
			'label'       => __( 'Drop cap on the opening paragraph', 'livingdraft' ),
			'description' => __( 'Applies to the lead on the front page and to every story.', 'livingdraft' ),
			'section'     => 'livingdraft_front',
			'type'        => 'checkbox',
		)
	);

	$wp_customize->add_setting(
		'livingdraft_newsletter_text',
		array(
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
			'transport'         => 'refresh',
		)
	);
	$wp_customize->add_control(
		'livingdraft_newsletter_text',
		array(
			'label'       => __( 'Newsletter pitch', 'livingdraft' ),
			'description' => __( 'One line at the foot of the brief rail. Needs a link below to appear.', 'livingdraft' ),
			'section'     => 'livingdraft_front',
			'type'        => 'text',
		)
	);

	$wp_customize->add_setting(
		'livingdraft_newsletter_url',
		array(
			'default'           => '',
			'sanitize_callback' => 'esc_url_raw',
			'transport'         => 'refresh',
		)
	);
	$wp_customize->add_control(
		'livingdraft_newsletter_url',
		array(
			'label'   => __( 'Newsletter sign-up URL', 'livingdraft' ),
			'section' => 'livingdraft_front',
			'type'    => 'url',
		)
	);

	/* --------------------------------------------------------------
	 * Sharing
	 * -------------------------------------------------------------- */

	$wp_customize->add_section(
		'livingdraft_sharing',
		array(
			'title'       => __( 'Sharing', 'livingdraft' ),
			'description' => __( 'Used when a link to this site is posted elsewhere.', 'livingdraft' ),
			'priority'    => 32,
		)
	);

	$wp_customize->add_setting(
		'livingdraft_share_image',
		array(
			'default'           => 0,
			'sanitize_callback' => 'absint',
			'transport'         => 'refresh',
		)
	);
	$wp_customize->add_control(
		new WP_Customize_Media_Control(
			$wp_customize,
			'livingdraft_share_image',
			array(
				'label'       => __( 'Default share image', 'livingdraft' ),
				'description' => __( 'Shown when a page has no featured image. Upload at 1200 by 628 pixels.', 'livingdraft' ),
				'section'     => 'livingdraft_sharing',
				'mime_type'   => 'image',
			)
		)
	);

	/* --------------------------------------------------------------
	 * Social
	 * -------------------------------------------------------------- */

	$wp_customize->add_section(
		'livingdraft_social',
		array(
			'title'       => __( 'Social links', 'livingdraft' ),
			'description' => __( 'Shown under the edition line, top right of the masthead. Leave blank to hide.', 'livingdraft' ),
			'priority'    => 90,
		)
	);

	$wp_customize->add_setting(
		'livingdraft_x_url',
		array(
			'default'           => '',
			'sanitize_callback' => 'esc_url_raw',
			'transport'         => 'refresh',
		)
	);
	$wp_customize->add_control(
		'livingdraft_x_url',
		array(
			'label'   => __( 'X profile URL', 'livingdraft' ),
			'section' => 'livingdraft_social',
			'type'    => 'url',
		)
	);

	$wp_customize->add_setting(
		'livingdraft_instagram_url',
		array(
			'default'           => '',
			'sanitize_callback' => 'esc_url_raw',
			'transport'         => 'refresh',
		)
	);
	$wp_customize->add_control(
		'livingdraft_instagram_url',
		array(
			'label'   => __( 'Instagram profile URL', 'livingdraft' ),
			'section' => 'livingdraft_social',
			'type'    => 'url',
		)
	);

	if ( isset( $wp_customize->selective_refresh ) ) {
		/*
		 * A partial only ever fires when its setting uses postMessage. Core
		 * ships blogname and blogdescription as 'refresh', so without these
		 * three lines the partials below are dead code.
		 */
		foreach ( array( 'blogname', 'blogdescription', 'livingdraft_strapline' ) as $ld_pm ) {
			$ld_setting = $wp_customize->get_setting( $ld_pm );
			if ( $ld_setting ) {
				$ld_setting->transport = 'postMessage';
			}
		}

		$wp_customize->selective_refresh->add_partial(
			'blogname',
			array(
				'selector'        => '.masthead-title a',
				'render_callback' => function () {
					return get_bloginfo( 'name', 'display' );
				},
			)
		);

		// Bound to both settings: the strapline falls back to the tagline, so
		// editing either one has to repaint this strip.
		$wp_customize->selective_refresh->add_partial(
			'livingdraft_strapline',
			array(
				'selector'            => '.dateline',
				'settings'            => array( 'livingdraft_strapline', 'blogdescription' ),
				'container_inclusive' => false,
				'render_callback'     => function () {
					$strapline = livingdraft_strapline_text();
					$out       = esc_html( wp_date( 'l, j F Y' ) );

					if ( $strapline ) {
						$out .= '<span class="sep">—</span>' . esc_html( $strapline );
					}

					return $out;
				},
			)
		);
	}
}
add_action( 'customize_register', 'livingdraft_customize_register' );

/**
 * The dateline strapline, resolved once.
 *
 * The setting is registered with an empty default, so the tagline fallback has
 * to be applied explicitly rather than as get_theme_mod()'s second argument —
 * otherwise the Customizer's preview filter returns the registered default and
 * the tagline silently disappears inside the preview while still showing on
 * the live site.
 *
 * @return string
 */
function livingdraft_strapline_text() {
	if ( ! display_header_text() ) {
		return '';
	}

	$strapline = (string) get_theme_mod( 'livingdraft_strapline', '' );

	if ( '' === $strapline ) {
		$strapline = (string) get_bloginfo( 'description', 'display' );
	}

	return $strapline;
}

/**
 * Live preview for the colour controls.
 *
 * The colour settings use postMessage. Without a listener in the preview frame
 * nothing repaints until Publish, which reads as "the colour pickers do not
 * work".
 */
function livingdraft_customize_preview_js() {
	$path = get_template_directory() . '/assets/js/customize-preview.js';

	if ( ! file_exists( $path ) ) {
		return;
	}

	wp_enqueue_script(
		'livingdraft-preview',
		get_template_directory_uri() . '/assets/js/customize-preview.js',
		array( 'customize-preview' ),
		filemtime( $path ),
		true
	);
}
add_action( 'customize_preview_init', 'livingdraft_customize_preview_js' );
