<?php
/**
 * Living Draft — Customizer registrar.
 *
 * Reads the token registry and calls WP_Customize_Manager methods to:
 *   1. Register each section (Colours, Typography, Layout, Article page).
 *   2. Register each setting (with sanitiser and default from the token).
 *   3. Register each control (colour picker, number input, select, etc.).
 *
 * Also enqueues the live-preview JavaScript when the Customizer preview
 * frame is loading, so postMessage tokens update instantly on drag.
 *
 * === WHAT THIS FILE DOES NOT DO ===
 *
 * It does NOT define which controls exist — that's tokens.php.
 * It does NOT emit any CSS — that's emit.php.
 * It's pure plumbing: read the registry, register with WordPress.
 *
 * If a control is showing up in the wrong place, or with the wrong
 * default, or missing a description — the fix is in tokens.php,
 * not here.
 *
 * @package LivingDraft
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register everything from the token registry with WordPress Customizer.
 *
 * @param WP_Customize_Manager $wp_customize
 */
function livingdraft_customize_register_from_tokens( $wp_customize ) {

	// 1. Register sections first, so controls have somewhere to land.
	foreach ( livingdraft_ld_sections() as $section_id => $section_def ) {
		$wp_customize->add_section(
			$section_id,
			array(
				'title'       => $section_def['title'],
				'description' => $section_def['description'] ?? '',
				'priority'    => $section_def['priority'] ?? 100,
				'panel'       => $section_def['panel'] ?? '',
			)
		);
	}

	// 2. Walk tokens and register setting + control for each user-facing one.
	foreach ( livingdraft_tokens() as $section_id => $tokens ) {
		foreach ( $tokens as $token ) {

			// Computed / helper tokens have no user-facing control.
			if ( empty( $token['section'] ) || empty( $token['control'] ) ) {
				continue;
			}

			// --- Register the setting (this is where the value is stored) ---
			$transport = $token['preview']['transport'] ?? 'refresh';

			$wp_customize->add_setting(
				$token['id'],
				array(
					'default'           => $token['default'],
					'sanitize_callback' => livingdraft_resolve_sanitizer( $token['sanitize'] ?? 'text' ),
					'transport'         => $transport,
				)
			);

			// --- Register the control (this is what the user sees + interacts with) ---
			$control_type = $token['control']['type'] ?? 'text';
			$common_args  = array(
				'label'       => $token['label'] ?? '',
				'description' => $token['description'] ?? '',
				'section'     => $token['section'],
				'priority'    => $token['control']['priority'] ?? 100,
			);

			if ( 'colour' === $control_type || 'color' === $control_type ) {
				$wp_customize->add_control(
					new WP_Customize_Color_Control(
						$wp_customize,
						$token['id'],
						$common_args
					)
				);
			} elseif ( 'media' === $control_type ) {
				// Media library picker — used for share image, favicon variants,
				// and any future file upload controls.
				$media_args = $common_args;
				if ( ! empty( $token['control']['mime_type'] ) ) {
					$media_args['mime_type'] = $token['control']['mime_type'];
				} else {
					$media_args['mime_type'] = 'image';
				}
				$wp_customize->add_control(
					new WP_Customize_Media_Control(
						$wp_customize,
						$token['id'],
						$media_args
					)
				);
			} else {
				$args = $common_args;

				// Map our control types to WP Customize control types.
				$type_map = array(
					'text'     => 'text',
					'textarea' => 'textarea',
					'number'   => 'number',
					'select'   => 'select',
					'checkbox' => 'checkbox',
					'range'    => 'range',
					'radio'    => 'radio',
					'url'      => 'url',
				);
				$args['type'] = $type_map[ $control_type ] ?? 'text';

				if ( ! empty( $token['control']['choices'] ) ) {
					$args['choices'] = $token['control']['choices'];
				}
				if ( ! empty( $token['control']['input_attrs'] ) ) {
					$args['input_attrs'] = $token['control']['input_attrs'];
				}

				$wp_customize->add_control( $token['id'], $args );
			}
		}
	}
}
add_action( 'customize_register', 'livingdraft_customize_register_from_tokens', 5 );

/**
 * Enqueue the live-preview script in the Customizer preview frame.
 * Only runs INSIDE the Customizer, not on public front-end pages.
 *
 * The script reads the same token registry (serialised as JSON) and wires
 * up wp.customize() subscribers for every token whose transport is
 * postMessage, so preview updates without a page reload.
 */
function livingdraft_customize_preview_enqueue() {
	// Version tied to theme version so the script busts cache with each release.
	$theme_version = wp_get_theme()->get( 'Version' );

	wp_enqueue_script(
		'livingdraft-customize-preview',
		get_template_directory_uri() . '/inc/customizer/live-preview.js',
		array( 'jquery', 'customize-preview' ),
		$theme_version,
		true
	);

	// Serialize the tokens that need live-preview wiring, and hand the JS
	// only the shape it needs (not the full PHP registry).
	$preview_map = array();

	foreach ( livingdraft_tokens() as $tokens ) {
		foreach ( $tokens as $token ) {
			$transport = $token['preview']['transport'] ?? 'refresh';
			if ( 'postMessage' !== $transport || empty( $token['id'] ) ) {
				continue;
			}

			$entry = array(
				'selector' => $token['preview']['selector'] ?? ':root',
				'property' => $token['preview']['property'] ?? '',
			);

			// Wrapper: printf format for the value (e.g. '%dpx' for numbers).
			if ( ! empty( $token['preview']['wrapper'] ) ) {
				$entry['wrapper'] = $token['preview']['wrapper'];
			}

			// Lookup: JS-side value transformation via a lookup table.
			if ( ! empty( $token['preview']['lookup'] ) && is_callable( $token['preview']['lookup'] ) ) {
				$entry['lookup'] = call_user_func( $token['preview']['lookup'] );
			}

			$preview_map[ $token['id'] ] = $entry;
		}
	}

	wp_localize_script(
		'livingdraft-customize-preview',
		'livingdraftPreview',
		array( 'tokens' => $preview_map )
	);
}
add_action( 'customize_preview_init', 'livingdraft_customize_preview_enqueue' );

/**
 * Enqueue the frontend scale-cap script — only when the setting is enabled
 * (non-zero). Skips loading the JS file entirely for users who leave it off.
 */
function livingdraft_scale_cap_enqueue() {
	$cap = (int) get_theme_mod( 'livingdraft_layout_scale_cap', 2000 );
	if ( $cap <= 0 ) {
		return;
	}

	wp_enqueue_script(
		'livingdraft-scale-cap',
		get_template_directory_uri() . '/inc/customizer/scale-cap.js',
		array(),
		wp_get_theme()->get( 'Version' ),
		// In footer so it runs after DOM is parsed. Applying zoom before the
		// first paint would cause an FOUC-style flicker on wide viewports.
		true
	);
}
add_action( 'wp_enqueue_scripts', 'livingdraft_scale_cap_enqueue' );

/**
 * Add the data-ld-scale-cap attribute to the <html> tag so scale-cap.js
 * can read the configured cap without a second HTTP round-trip.
 * Uses language_attributes filter (fires inside <html ...>).
 *
 * @param string $output existing attribute string.
 * @return string
 */
function livingdraft_scale_cap_html_attribute( $output ) {
	$cap = (int) get_theme_mod( 'livingdraft_layout_scale_cap', 2000 );
	if ( $cap > 0 ) {
		$output .= ' data-ld-scale-cap="' . esc_attr( $cap ) . '"';
	}
	return $output;
}
add_filter( 'language_attributes', 'livingdraft_scale_cap_html_attribute' );
