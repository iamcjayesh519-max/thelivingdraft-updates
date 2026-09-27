<?php
/**
 * Living Draft — Customizer token registry.
 *
 * SINGLE SOURCE OF TRUTH for every design decision that the Customizer
 * exposes. Every setting is described here as a "token" — a small array
 * of metadata that tells three different systems what to do:
 *
 *   emit.php      → turns tokens into CSS custom properties in <head>
 *   register.php  → turns tokens into Customizer sections and controls
 *   live-preview  → turns tokens into instant-update rules in the preview
 *
 * If you want to add a new customizer control, you add ONE entry here
 * and the other three systems pick it up automatically. Never edit
 * emit.php or register.php to add a control.
 *
 * === TOKEN SHAPE ===
 *
 * Each token is one array with these keys:
 *
 *   'id'           string   theme_mod key (e.g. 'livingdraft_color_ink')
 *   'default'      mixed    default value if user has not set anything
 *   'sanitize'     string   sanitize callback ('colour'|'int'|'text'|'select'|
 *                           'checkbox'|'url'|callable) — shortcut names
 *                           resolve to WP core sanitisers, or pass any
 *                           callable directly.
 *   'section'      string   Customizer section id (e.g. 'ld_colour')
 *   'panel'        string   optional Customizer panel id (for grouping
 *                           multiple sections under one panel)
 *   'label'        string   user-facing control label
 *   'description'  string   user-facing help text under the label
 *   'control'      array    { type, choices, input_attrs, priority }
 *                           type is 'colour'|'text'|'number'|'select'|
 *                           'checkbox'|'range'|'textarea'|'url'
 *   'css'          array    { var, unit, skip_when_default, format }
 *                           - var:   CSS custom property name incl. '--'
 *                           - unit:  suffix appended to value (px|%|ch|rem|'')
 *                           - skip_when_default: (bool) if true, only emit
 *                             when value !== default. Defaults true.
 *                           - format: printf format string for complex vars
 *                             like '--wrap: min(%dvw, %dpx)'. When 'format'
 *                             is set, use 'inputs' to list source token ids.
 *   'inputs'       array    token ids (for computed CSS vars — see 'format')
 *   'lookup'       string   callable that returns an array; the setting's
 *                           value is looked up as a key in that array, and
 *                           lookup_field pulls a sub-field. Used for fonts.
 *   'lookup_field' string   sub-field to extract from lookup result
 *   'preview'      array    { transport, selector, property, wrapper }
 *                           - transport: 'refresh' or 'postMessage'
 *                           - selector: CSS selector to update live
 *                           - property: CSS property to update
 *                           - wrapper: printf format for the value being set
 *                             (e.g. 'min(%svw, 2100px)' for a computed var)
 *   'ui_only'      bool     if true, this token has no CSS output — it only
 *                           drives PHP logic (e.g. a visibility toggle). The
 *                           emitter skips it entirely.
 *
 * Not every key is required for every token. Simple colour tokens only
 * need id/default/sanitize/section/label/control/css/preview.
 *
 * === WHY THIS EXISTS ===
 *
 * Before this file, adding a control meant editing 3 places: setting
 * registration, control registration, CSS emission. Each with slightly
 * different conventions. When you're up to 90+ controls, that's chaos.
 *
 * Now: add one array entry. Done. Framework handles the rest.
 *
 * @package LivingDraft
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return the full token registry.
 *
 * Structured as a two-level array:
 *   [ section_id => [ token, token, ... ], ... ]
 *
 * Sections are declared in livingdraft_ld_sections(); tokens live inside them.
 * This shape lets register.php walk sections in priority order and place
 * controls in the right home.
 *
 * @return array
 */
function livingdraft_tokens() {
	// Font stack lookup — used by typography tokens below.
	$fonts = livingdraft_font_stacks();

	return array(

		/* ==============================================================
		 * SECTION: Colours
		 * ============================================================== */
		'ld_colour' => array(

			array(
				'id'          => 'livingdraft_color_ink',
				'default'     => '#111111',
				'sanitize'    => 'colour',
				'section'     => 'ld_colour',
				'label'       => __( 'Ink (main text)', 'livingdraft' ),
				'description' => __( 'The primary body text colour. Everything reads against the paper background.', 'livingdraft' ),
				'control'     => array( 'type' => 'colour', 'priority' => 10 ),
				'css'         => array( 'var' => '--ink' ),
				'preview'     => array( 'transport' => 'postMessage', 'selector' => ':root', 'property' => '--ink' ),
			),

			array(
				'id'          => 'livingdraft_color_ink_2',
				'default'     => '#4a4a45',
				'sanitize'    => 'colour',
				'section'     => 'ld_colour',
				'label'       => __( 'Ink secondary', 'livingdraft' ),
				'description' => __( 'Byline, links inside body copy, meta text.', 'livingdraft' ),
				'control'     => array( 'type' => 'colour', 'priority' => 20 ),
				'css'         => array( 'var' => '--ink-2' ),
				'preview'     => array( 'transport' => 'postMessage', 'selector' => ':root', 'property' => '--ink-2' ),
			),

			array(
				'id'          => 'livingdraft_color_ink_3',
				'default'     => '#63635c',
				'sanitize'    => 'colour',
				'section'     => 'ld_colour',
				'label'       => __( 'Ink tertiary', 'livingdraft' ),
				'description' => __( 'Captions, timestamps, hairline labels, small furniture.', 'livingdraft' ),
				'control'     => array( 'type' => 'colour', 'priority' => 30 ),
				'css'         => array( 'var' => '--ink-3' ),
				'preview'     => array( 'transport' => 'postMessage', 'selector' => ':root', 'property' => '--ink-3' ),
			),

			array(
				'id'          => 'livingdraft_color_paper',
				'default'     => '#fdfdfb',
				'sanitize'    => 'colour',
				'section'     => 'ld_colour',
				'label'       => __( 'Paper', 'livingdraft' ),
				'description' => __( 'The page background. Off-white by default; broadsheets are rarely pure white.', 'livingdraft' ),
				'control'     => array( 'type' => 'colour', 'priority' => 40 ),
				'css'         => array( 'var' => '--paper' ),
				'preview'     => array( 'transport' => 'postMessage', 'selector' => ':root', 'property' => '--paper' ),
			),

			array(
				'id'          => 'livingdraft_color_paper_2',
				'default'     => '#f4f3ee',
				'sanitize'    => 'colour',
				'section'     => 'ld_colour',
				'label'       => __( 'Paper alternate', 'livingdraft' ),
				'description' => __( 'Table stripes, quote pull-outs, subtle band backgrounds.', 'livingdraft' ),
				'control'     => array( 'type' => 'colour', 'priority' => 50 ),
				'css'         => array( 'var' => '--paper-2' ),
				'preview'     => array( 'transport' => 'postMessage', 'selector' => ':root', 'property' => '--paper-2' ),
			),

			array(
				'id'          => 'livingdraft_color_rule',
				'default'     => '#d8d5cc',
				'sanitize'    => 'colour',
				'section'     => 'ld_colour',
				'label'       => __( 'Rule (hairlines)', 'livingdraft' ),
				'description' => __( 'Colour of the thin horizontal rules between sections and stories.', 'livingdraft' ),
				'control'     => array( 'type' => 'colour', 'priority' => 60 ),
				'css'         => array( 'var' => '--rule' ),
				'preview'     => array( 'transport' => 'postMessage', 'selector' => ':root', 'property' => '--rule' ),
			),

			array(
				'id'          => 'livingdraft_color_rule_2',
				'default'     => '#e6e3da',
				'sanitize'    => 'colour',
				'section'     => 'ld_colour',
				'label'       => __( 'Rule secondary', 'livingdraft' ),
				'description' => __( 'Even lighter hairlines — column dividers, ghost separators.', 'livingdraft' ),
				'control'     => array( 'type' => 'colour', 'priority' => 70 ),
				'css'         => array( 'var' => '--rule-2' ),
				'preview'     => array( 'transport' => 'postMessage', 'selector' => ':root', 'property' => '--rule-2' ),
			),

			array(
				'id'          => 'livingdraft_color_mark',
				'default'     => '#a3271f',
				'sanitize'    => 'colour',
				'section'     => 'ld_colour',
				'label'       => __( 'Mark (accent)', 'livingdraft' ),
				'description' => __( 'Editorial highlight colour — the current section indicator, hover states, corrections.', 'livingdraft' ),
				'control'     => array( 'type' => 'colour', 'priority' => 80 ),
				'css'         => array( 'var' => '--mark' ),
				'preview'     => array( 'transport' => 'postMessage', 'selector' => ':root', 'property' => '--mark' ),
			),

			array(
				'id'          => 'livingdraft_color_link',
				'default'     => '#1b3f7a',
				'sanitize'    => 'colour',
				'section'     => 'ld_colour',
				'label'       => __( 'Link', 'livingdraft' ),
				'description' => __( 'Body-text links. Newspapers historically use blue; use whatever contrasts with your ink.', 'livingdraft' ),
				'control'     => array( 'type' => 'colour', 'priority' => 90 ),
				'css'         => array( 'var' => '--link' ),
				'preview'     => array( 'transport' => 'postMessage', 'selector' => ':root', 'property' => '--link' ),
			),

		),

		/* ==============================================================
		 * SECTION: Typography
		 * ============================================================== */
		'ld_typography' => array(

			array(
				'id'          => 'livingdraft_font_body',
				'default'     => 'georgia',
				'sanitize'    => 'select',
				'section'     => 'ld_typography',
				'label'       => __( 'Body font', 'livingdraft' ),
				'description' => __( 'The font used for paragraphs and body copy. Serif fonts read best for long-form.', 'livingdraft' ),
				'control'     => array(
					'type'     => 'select',
					'choices'  => wp_list_pluck( $fonts, 'label' ),
					'priority' => 10,
				),
				'css'         => array(
					'var'          => '--serif',
					'lookup'       => 'livingdraft_font_stacks',
					'lookup_field' => 'stack',
				),
				'preview'     => array(
					'transport' => 'postMessage',
					'selector'  => ':root',
					'property'  => '--serif',
					'lookup'    => 'livingdraft_font_stacks',
				),
			),

			array(
				'id'          => 'livingdraft_font_head',
				'default'     => 'georgia',
				'sanitize'    => 'select',
				'section'     => 'ld_typography',
				'label'       => __( 'Heading font', 'livingdraft' ),
				'description' => __( 'The font for headlines and section titles. Often the same as body for a broadsheet feel.', 'livingdraft' ),
				'control'     => array(
					'type'     => 'select',
					'choices'  => wp_list_pluck( $fonts, 'label' ),
					'priority' => 20,
				),
				'css'         => array(
					'var'          => '--display',
					'lookup'       => 'livingdraft_font_stacks',
					'lookup_field' => 'stack',
				),
				'preview'     => array(
					'transport' => 'postMessage',
					'selector'  => ':root',
					'property'  => '--display',
					'lookup'    => 'livingdraft_font_stacks',
				),
			),

			array(
				'id'          => 'livingdraft_base_size',
				'default'     => 17,
				'sanitize'    => 'int',
				'section'     => 'ld_typography',
				'label'       => __( 'Base font size (px)', 'livingdraft' ),
				'description' => __( 'Body text size. 16–18 is the sweet spot for editorial reading.', 'livingdraft' ),
				'control'     => array(
					'type'        => 'number',
					'input_attrs' => array( 'min' => 14, 'max' => 22, 'step' => 1 ),
					'priority'    => 30,
				),
				'css'         => array( 'var' => '--base', 'unit' => 'px' ),
				'preview'     => array(
					'transport' => 'postMessage',
					'selector'  => ':root',
					'property'  => '--base',
					'wrapper'   => '%dpx',
				),
			),

			array(
				'id'          => 'livingdraft_measure',
				'default'     => 68,
				'sanitize'    => 'int',
				'section'     => 'ld_typography',
				'label'       => __( 'Reading measure (characters)', 'livingdraft' ),
				'description' => __( 'How many characters wide a paragraph is at most. 60–75 is comfortable; wider hurts readability.', 'livingdraft' ),
				'control'     => array(
					'type'        => 'number',
					'input_attrs' => array( 'min' => 50, 'max' => 90, 'step' => 1 ),
					'priority'    => 40,
				),
				'css'         => array( 'var' => '--measure', 'unit' => 'ch' ),
				'preview'     => array(
					'transport' => 'postMessage',
					'selector'  => ':root',
					'property'  => '--measure',
					'wrapper'   => '%dch',
				),
			),

		),

		/* ==============================================================
		 * SECTION: Page layout (site width + sidebar)
		 * ============================================================== */
		'ld_layout' => array(

			array(
				'id'          => 'livingdraft_wrap_fill',
				'default'     => 94,
				'sanitize'    => 'int',
				'section'     => 'ld_layout',
				'label'       => __( 'Fill this much of the window (%)', 'livingdraft' ),
				'description' => __( '94 leaves a thin margin at every size. Lower it for more white space.', 'livingdraft' ),
				'control'     => array(
					'type'        => 'number',
					'input_attrs' => array( 'min' => 70, 'max' => 100, 'step' => 1 ),
					'priority'    => 10,
				),
				// This value participates in a computed --wrap var; the actual
				// emission happens in the 'ld_wrap_computed' token below.
				'css'         => array( 'skip' => true ),
				'preview'     => array( 'transport' => 'refresh' ),
			),

			array(
				'id'          => 'livingdraft_wrap_max',
				'default'     => 2100,
				'sanitize'    => 'int',
				'section'     => 'ld_layout',
				'label'       => __( 'But never wider than (px)', 'livingdraft' ),
				'description' => __( '2100 keeps the margins thin even at 80 per cent zoom on a 1920 screen. Lower it toward 1300 for a narrower, more traditional page.', 'livingdraft' ),
				'control'     => array(
					'type'        => 'number',
					'input_attrs' => array( 'min' => 1100, 'max' => 2400, 'step' => 50 ),
					'priority'    => 20,
				),
				'css'         => array( 'skip' => true ),
				'preview'     => array( 'transport' => 'refresh' ),
			),

			// Computed token: emits --wrap: min(94vw, 2100px) from the two
			// settings above. Not a user-visible control (has no 'section' or
			// 'control' — this is a CSS-only token).
			array(
				'id'      => 'ld_wrap_computed',
				'default' => 'min(94vw, 2100px)',
				'css'     => array(
					'var'    => '--wrap',
					'format' => 'min(%dvw, %dpx)',
					'inputs' => array( 'livingdraft_wrap_fill', 'livingdraft_wrap_max' ),
					// Bounds enforced at emission time so bad values can't produce
					// broken CSS even if a database migration went weird.
					'bounds' => array(
						array( 'min' => 70, 'max' => 100 ),
						array( 'min' => 1100, 'max' => 2400 ),
					),
				),
			),

			array(
				'id'          => 'livingdraft_rail_width',
				'default'     => 300,
				'sanitize'    => 'int',
				'section'     => 'ld_layout',
				'label'       => __( 'Sidebar width (px, fixed)', 'livingdraft' ),
				'description' => __( 'A fixed sidebar width in pixels. Only used when the percentage below is at its default. Useful if you want the sidebar the same size at every screen width.', 'livingdraft' ),
				'control'     => array(
					'type'        => 'number',
					'input_attrs' => array( 'min' => 200, 'max' => 400, 'step' => 10 ),
					'priority'    => 40,
				),
				// Emission handled via computed --rail token below, which chooses
				// between the % and px controls.
				'css'         => array( 'skip' => true ),
				'preview'     => array( 'transport' => 'refresh' ),
			),

			array(
				'id'          => 'livingdraft_rail_percent',
				'default'     => 0,
				'sanitize'    => 'int',
				'section'     => 'ld_layout',
				'label'       => __( 'Sidebar width (% of screen)', 'livingdraft' ),
				'description' => __( 'How much of the screen the sidebar takes up. 15% is typical newspaper; 20% gives the sidebar more room; 10% makes it a thin rail. Set to 0 to use the pixel value below instead.', 'livingdraft' ),
				'control'     => array(
					'type'        => 'number',
					'input_attrs' => array( 'min' => 0, 'max' => 30, 'step' => 1 ),
					'priority'    => 50,
				),
				'css'         => array( 'skip' => true ),
				'preview'     => array( 'transport' => 'refresh' ),
			),

			// Computed --rail: picks % when >0, else px.
			array(
				'id'  => 'ld_rail_computed',
				'css' => array(
					'var'     => '--rail',
					'compute' => 'livingdraft_compute_rail',
					'inputs'  => array( 'livingdraft_rail_percent', 'livingdraft_rail_width' ),
				),
			),

			array(
				'id'          => 'livingdraft_content_max_width',
				'default'     => 800,
				'sanitize'    => 'int',
				'section'     => 'ld_layout',
				'label'       => __( 'Article width (px)', 'livingdraft' ),
				'description' => __( 'How wide the article body is on desktop. Lower numbers make articles narrower and easier to read; higher numbers spread text wider.', 'livingdraft' ),
				'control'     => array(
					'type'        => 'number',
					'input_attrs' => array( 'min' => 500, 'max' => 1200, 'step' => 20 ),
					'priority'    => 60,
				),
				'css'         => array( 'var' => '--content-max', 'unit' => 'px' ),
				'preview'     => array(
					'transport' => 'postMessage',
					'selector'  => ':root',
					'property'  => '--content-max',
					'wrapper'   => '%dpx',
				),
			),

			array(
				'id'          => 'livingdraft_content_gap',
				'default'     => 44,
				'sanitize'    => 'int',
				'section'     => 'ld_layout',
				'label'       => __( 'Gap between article and sidebar (px)', 'livingdraft' ),
				'description' => __( 'The empty space between the article\'s right edge and the sidebar. Wider gaps feel more like magazine spreads; tighter gaps feel like a webapp.', 'livingdraft' ),
				'control'     => array(
					'type'        => 'number',
					'input_attrs' => array( 'min' => 20, 'max' => 100, 'step' => 4 ),
					'priority'    => 70,
				),
				'css'         => array( 'var' => '--content-gap', 'unit' => 'px' ),
				'preview'     => array(
					'transport' => 'postMessage',
					'selector'  => ':root',
					'property'  => '--content-gap',
					'wrapper'   => '%dpx',
				),
			),

			array(
				'id'          => 'livingdraft_sidebar_position',
				'default'     => 'right',
				'sanitize'    => 'livingdraft_sanitize_sidebar_position',
				'section'     => 'ld_layout',
				'label'       => __( 'Sidebar position', 'livingdraft' ),
				'description' => __( 'Where the sidebar sits relative to the article: on the right (default, most common), on the left, or hidden entirely.', 'livingdraft' ),
				'control'     => array(
					'type'     => 'select',
					'choices'  => array(
						'right'  => __( 'Right of article (default)', 'livingdraft' ),
						'left'   => __( 'Left of article', 'livingdraft' ),
						'hidden' => __( 'Hidden (article fills full width)', 'livingdraft' ),
					),
					'priority' => 80,
				),
				// Emitted via computed --sidebar-order and body class.
				'css'         => array( 'skip' => true ),
				'preview'     => array( 'transport' => 'refresh' ),
			),

			array(
				'id'      => 'ld_sidebar_order_computed',
				'css'     => array(
					'var'     => '--sidebar-order',
					'compute' => 'livingdraft_compute_sidebar_order',
					'inputs'  => array( 'livingdraft_sidebar_position' ),
				),
			),

			array(
				'id'          => 'livingdraft_sidebar_mobile',
				'default'     => 'below',
				'sanitize'    => 'livingdraft_sanitize_sidebar_mobile',
				'section'     => 'ld_layout',
				'label'       => __( 'Sidebar on mobile', 'livingdraft' ),
				'description' => __( 'On phones, the sidebar can appear below the article (default), hide behind a menu button, or not show at all.', 'livingdraft' ),
				'control'     => array(
					'type'     => 'select',
					'choices'  => array(
						'below'  => __( 'Below the article', 'livingdraft' ),
						'drawer' => __( 'Behind a menu button', 'livingdraft' ),
						'hidden' => __( 'Hidden on mobile', 'livingdraft' ),
					),
					'priority' => 90,
				),
				'css'         => array( 'skip' => true ),
				'preview'     => array( 'transport' => 'refresh' ),
			),

			array(
				'id'          => 'livingdraft_layout_scale_cap',
				'default'     => 2000,
				'sanitize'    => 'int',
				'section'     => 'ld_layout',
				'label'       => __( 'Maximum layout width (px)', 'livingdraft' ),
				'description' => __( 'Beyond this viewport width, the whole page shrinks uniformly (like an image scaling down) instead of spreading out further. Prevents the site looking odd when a reader zooms out past 80%. Set to 0 to disable — the page will then keep spreading out at wide viewports.', 'livingdraft' ),
				'control'     => array(
					'type'        => 'number',
					'input_attrs' => array( 'min' => 0, 'max' => 3000, 'step' => 100 ),
					'priority'    => 100,
				),
				// This setting drives a small JavaScript on the frontend that
				// applies CSS zoom above the threshold — see scale-cap.js. It
				// does not emit a CSS variable directly.
				'css'         => array( 'skip' => true ),
				'preview'     => array( 'transport' => 'refresh' ),
			),

			array(
				'id'          => 'livingdraft_cols',
				'default'     => 3,
				'sanitize'    => 'int',
				'section'     => 'ld_layout',
				'label'       => __( 'Columns in the story grid', 'livingdraft' ),
				'description' => __( 'Three is the newspaper default. Four suits a very wide page. Used by the front-page story grid.', 'livingdraft' ),
				'control'     => array(
					'type'     => 'select',
					'choices'  => array(
						3 => __( 'Three (default)', 'livingdraft' ),
						4 => __( 'Four', 'livingdraft' ),
					),
					'priority' => 110,
				),
				// UI-only: livingdraft_column_css() reads this value directly and
				// emits its own CSS. No CSS variable involved.
				'ui_only'     => true,
			),

		),

		/* ==============================================================
		 * SECTION: Article page — featured image controls, article furniture
		 * ============================================================== */
		'ld_article' => array(

			array(
				'id'          => 'livingdraft_lede_width',
				'default'     => 100,
				'sanitize'    => 'int',
				'section'     => 'ld_article',
				'label'       => __( 'Featured image width (% of article)', 'livingdraft' ),
				'description' => __( 'How much of the article column the hero image fills. 100 keeps the image at its intrinsic size (matches your paragraph width). Lower for a smaller image with breathing room. Mobile stays full-width regardless.', 'livingdraft' ),
				'control'     => array(
					'type'        => 'number',
					'input_attrs' => array( 'min' => 40, 'max' => 100, 'step' => 5 ),
					'priority'    => 10,
				),
				'css'         => array( 'var' => '--lede-width', 'unit' => '%', 'skip_when_default' => true ),
				'preview'     => array(
					'transport' => 'postMessage',
					'selector'  => ':root',
					'property'  => '--lede-width',
					'wrapper'   => '%d%%',
				),
			),

			array(
				'id'          => 'livingdraft_lede_width_px',
				'default'     => 0,
				'sanitize'    => 'int',
				'section'     => 'ld_article',
				'label'       => __( 'Featured image width (px, desktop only)', 'livingdraft' ),
				'description' => __( 'A fixed pixel width for the hero image on desktop only — overrides the % control above when set. 0 disables. Mobile and tablet always stay full-width regardless of what you set here.', 'livingdraft' ),
				'control'     => array(
					'type'        => 'number',
					'input_attrs' => array( 'min' => 0, 'max' => 1600, 'step' => 20 ),
					'priority'    => 15,
				),
				'css'         => array( 'var' => '--lede-width-px', 'unit' => 'px', 'skip_when_default' => true ),
				'preview'     => array(
					'transport' => 'postMessage',
					'selector'  => ':root',
					'property'  => '--lede-width-px',
					'wrapper'   => '%dpx',
				),
			),

			array(
				'id'          => 'livingdraft_lede_height_px',
				'default'     => 0,
				'sanitize'    => 'int',
				'section'     => 'ld_article',
				'label'       => __( 'Featured image height (px, desktop only)', 'livingdraft' ),
				'description' => __( 'A fixed pixel height for the hero image on desktop only — crops the image to that exact height, keeping the centre of the frame. 0 keeps the natural aspect ratio. Mobile and tablet always use natural height.', 'livingdraft' ),
				'control'     => array(
					'type'        => 'number',
					'input_attrs' => array( 'min' => 0, 'max' => 900, 'step' => 10 ),
					'priority'    => 18,
				),
				'css'         => array( 'var' => '--lede-height-px', 'unit' => 'px', 'skip_when_default' => true ),
				'preview'     => array(
					'transport' => 'postMessage',
					'selector'  => ':root',
					'property'  => '--lede-height-px',
					'wrapper'   => '%dpx',
				),
			),

			array(
				'id'          => 'livingdraft_lede_max_height',
				'default'     => 0,
				'sanitize'    => 'int',
				'section'     => 'ld_article',
				'label'       => __( 'Featured image max height (px)', 'livingdraft' ),
				'description' => __( '0 keeps the natural 1.91:1 crop the newsroom uploads. Set a number (say 360) to letterbox tall images into a wider crop — the centre of the frame is preserved and top/bottom edges are trimmed.', 'livingdraft' ),
				'control'     => array(
					'type'        => 'number',
					'input_attrs' => array( 'min' => 0, 'max' => 900, 'step' => 10 ),
					'priority'    => 20,
				),
				'css'         => array( 'var' => '--lede-max-height', 'unit' => 'px' ),
				'preview'     => array(
					'transport' => 'postMessage',
					'selector'  => ':root',
					'property'  => '--lede-max-height',
					'wrapper'   => '%dpx',
				),
			),

			array(
				'id'          => 'livingdraft_lede_align',
				'default'     => 'center',
				'sanitize'    => 'livingdraft_sanitize_lede_align',
				'section'     => 'ld_article',
				'label'       => __( 'Featured image alignment', 'livingdraft' ),
				'description' => __( 'Only matters when the image is narrower than its container.', 'livingdraft' ),
				'control'     => array(
					'type'     => 'select',
					'choices'  => array(
						'left'   => __( 'Left', 'livingdraft' ),
						'center' => __( 'Centre', 'livingdraft' ),
						'right'  => __( 'Right', 'livingdraft' ),
					),
					'priority' => 30,
				),
				// Emitted via custom logic — 'left' → margin: 0, 'right' → 0 0 0 auto.
				'css'         => array( 'skip' => true ),
				'preview'     => array( 'transport' => 'refresh' ),
			),

			// Computed token for --lede-margin from the align select above.
			array(
				'id'      => 'ld_lede_margin_computed',
				'default' => '',
				'css'     => array(
					'var'      => '--lede-margin',
					'compute'  => 'livingdraft_compute_lede_margin',
					'inputs'   => array( 'livingdraft_lede_align' ),
				),
			),

			// Article furniture toggles (existing settings, preserved).
			array(
				'id'          => 'livingdraft_reading_progress',
				'default'     => true,
				'sanitize'    => 'checkbox',
				'section'     => 'ld_article',
				'label'       => __( 'Reading progress bar', 'livingdraft' ),
				'description' => __( 'A thin rule at the top of the screen showing how far through the story the reader is. Measures the article itself, not the page, so it reads 100% at the last paragraph rather than after the comments. Hidden for readers who have asked for reduced motion.', 'livingdraft' ),
				'control'     => array( 'type' => 'checkbox', 'priority' => 95 ),
				'ui_only'     => true,
			),

			array(
				'id'          => 'livingdraft_show_reading_time',
				'default'     => true,
				'sanitize'    => 'checkbox',
				'section'     => 'ld_article',
				'label'       => __( 'Show reading time in byline', 'livingdraft' ),
				'control'     => array( 'type' => 'checkbox', 'priority' => 100 ),
				'ui_only'     => true,
			),

			array(
				'id'          => 'livingdraft_show_author_box',
				'default'     => true,
				'sanitize'    => 'checkbox',
				'section'     => 'ld_article',
				'label'       => __( 'Show author box under story', 'livingdraft' ),
				'control'     => array( 'type' => 'checkbox', 'priority' => 110 ),
				'ui_only'     => true,
			),

			array(
				'id'          => 'livingdraft_show_read_next',
				'default'     => true,
				'sanitize'    => 'checkbox',
				'section'     => 'ld_article',
				'label'       => __( 'Show Read Next block', 'livingdraft' ),
				'control'     => array( 'type' => 'checkbox', 'priority' => 120 ),
				'ui_only'     => true,
			),

			array(
				'id'          => 'livingdraft_show_post_nav',
				'default'     => true,
				'sanitize'    => 'checkbox',
				'section'     => 'ld_article',
				'label'       => __( 'Show Earlier / Later links', 'livingdraft' ),
				'control'     => array( 'type' => 'checkbox', 'priority' => 130 ),
				'ui_only'     => true,
			),

			array(
				'id'          => 'livingdraft_show_breadcrumbs',
				'default'     => true,
				'sanitize'    => 'checkbox',
				'section'     => 'ld_article',
				'label'       => __( 'Show breadcrumb trail', 'livingdraft' ),
				'control'     => array( 'type' => 'checkbox', 'priority' => 140 ),
				'ui_only'     => true,
			),

		),

		/* ==============================================================
		 * SECTION: Masthead (formerly livingdraft_masthead)
		 * ============================================================== */
		'ld_masthead' => array(

			array(
				'id'          => 'livingdraft_edition_line',
				'default'     => '',
				'sanitize'    => 'text',
				'section'     => 'ld_masthead',
				'label'       => __( 'Edition line', 'livingdraft' ),
				'description' => __( 'Sits at the top right, opposite the volume number. For example: Kalyan · Online.', 'livingdraft' ),
				'control'     => array( 'type' => 'text', 'priority' => 10 ),
				'ui_only'     => true,
			),

			array(
				'id'          => 'livingdraft_strapline',
				'default'     => '',
				'sanitize'    => 'text',
				'section'     => 'ld_masthead',
				'label'       => __( 'Dateline strapline', 'livingdraft' ),
				'description' => __( 'Runs beside the date in the strip under the title. Leave blank to use the site tagline.', 'livingdraft' ),
				'control'     => array( 'type' => 'text', 'priority' => 20 ),
				'ui_only'     => true,
			),

			array(
				'id'          => 'livingdraft_founded',
				// Default: current year — dynamic default, computed once per request.
				'default'     => (int) wp_date( 'Y' ),
				'sanitize'    => 'int',
				'section'     => 'ld_masthead',
				'label'       => __( 'Year founded', 'livingdraft' ),
				'description' => __( 'Sets the volume number: one volume per year since this date.', 'livingdraft' ),
				'control'     => array(
					'type'        => 'number',
					'input_attrs' => array( 'min' => 1800, 'max' => (int) wp_date( 'Y' ), 'step' => 1 ),
					'priority'    => 30,
				),
				'ui_only'     => true,
			),

		),

		/* ==============================================================
		 * SECTION: Front page (formerly livingdraft_front + additions in customizer-extra)
		 *
		 * Includes both the migrated original controls AND the four "extras"
		 * the user asked for: homepage layout, card style, pagination style,
		 * category card style. Extras are ui_only tokens — they drive PHP
		 * logic and body classes rather than CSS variables.
		 * ============================================================== */
		'ld_front' => array(

			array(
				'id'          => 'livingdraft_brief_count',
				'default'     => 5,
				'sanitize'    => 'int',
				'section'     => 'ld_front',
				'label'       => __( 'Stories in the brief rail', 'livingdraft' ),
				'description' => __( 'Set to 0 to run the lead full width. Above four, the rest fall under a second heading.', 'livingdraft' ),
				'control'     => array(
					'type'        => 'number',
					'input_attrs' => array( 'min' => 0, 'max' => 8, 'step' => 1 ),
					'priority'    => 10,
				),
				'ui_only'     => true,
			),

			array(
				'id'          => 'livingdraft_dropcap',
				'default'     => true,
				'sanitize'    => 'checkbox',
				'section'     => 'ld_front',
				'label'       => __( 'Drop cap on the opening paragraph', 'livingdraft' ),
				'description' => __( 'The large decorative first letter that starts each article. Applies to the lead on the front page and to every story.', 'livingdraft' ),
				'control'     => array( 'type' => 'checkbox', 'priority' => 20 ),
				'ui_only'     => true,
			),

			array(
				'id'          => 'livingdraft_slider_on',
				'default'     => true,
				'sanitize'    => 'checkbox',
				'section'     => 'ld_front',
				'label'       => __( 'Show the latest-stories strip', 'livingdraft' ),
				'description' => __( 'A swipeable row of recent stories under the lead. Nothing rotates on its own.', 'livingdraft' ),
				'control'     => array( 'type' => 'checkbox', 'priority' => 30 ),
				'ui_only'     => true,
			),

			array(
				'id'          => 'livingdraft_slider_count',
				'default'     => 8,
				'sanitize'    => 'int',
				'section'     => 'ld_front',
				'label'       => __( 'Stories in the strip', 'livingdraft' ),
				'description' => __( 'How many recent stories appear in the swipeable strip.', 'livingdraft' ),
				'control'     => array(
					'type'        => 'number',
					'input_attrs' => array( 'min' => 3, 'max' => 15, 'step' => 1 ),
					'priority'    => 40,
				),
				'ui_only'     => true,
			),

			array(
				'id'          => 'livingdraft_tabs_on',
				'default'     => true,
				'sanitize'    => 'checkbox',
				'section'     => 'ld_front',
				'label'       => __( 'Show Latest / Popular / Trending tabs', 'livingdraft' ),
				'description' => __( 'Popular and Trending need the The Living Draft Core plugin, which does the counting.', 'livingdraft' ),
				'control'     => array( 'type' => 'checkbox', 'priority' => 50 ),
				'ui_only'     => true,
			),

			array(
				'id'          => 'livingdraft_tabs_count',
				'default'     => 5,
				'sanitize'    => 'int',
				'section'     => 'ld_front',
				'label'       => __( 'Stories in each tab list', 'livingdraft' ),
				'description' => __( 'How many stories appear in each of the Latest / Popular / Trending lists.', 'livingdraft' ),
				'control'     => array(
					'type'        => 'number',
					'input_attrs' => array( 'min' => 3, 'max' => 10, 'step' => 1 ),
					'priority'    => 60,
				),
				'ui_only'     => true,
			),

			array(
				'id'          => 'livingdraft_cat_limit',
				'default'     => 5,
				'sanitize'    => 'int',
				'section'     => 'ld_front',
				'label'       => __( 'Sections listed in the sidebar', 'livingdraft' ),
				'description' => __( 'How many section categories appear in the sidebar list. The busiest sections first; the rest stay reachable through the sections link.', 'livingdraft' ),
				'control'     => array(
					'type'        => 'number',
					'input_attrs' => array( 'min' => 3, 'max' => 12, 'step' => 1 ),
					'priority'    => 70,
				),
				'ui_only'     => true,
			),

			// --- EXTRAS the user asked for ---

			array(
				'id'          => 'livingdraft_homepage_layout',
				'default'     => 'grid',
				'sanitize'    => 'livingdraft_sanitize_homepage_layout',
				'section'     => 'ld_front',
				'label'       => __( 'Homepage layout style', 'livingdraft' ),
				'description' => __( 'How story cards are arranged on the front page: a tidy grid, a scannable list, or a magazine-style masonry.', 'livingdraft' ),
				'control'     => array(
					'type'     => 'select',
					'choices'  => array(
						'grid'    => __( 'Grid (default) — even columns, equal card heights', 'livingdraft' ),
						'list'    => __( 'List — stacked rows with image beside text', 'livingdraft' ),
						'masonry' => __( 'Masonry — mixed heights, magazine feel', 'livingdraft' ),
					),
					'priority' => 100,
				),
				'ui_only'     => true,
			),

			array(
				'id'          => 'livingdraft_card_style',
				'default'     => 'standard',
				'sanitize'    => 'livingdraft_sanitize_card_style',
				'section'     => 'ld_front',
				'label'       => __( 'Story card style', 'livingdraft' ),
				'description' => __( 'How much visual weight each story card carries: compact for scanning, standard for balance, or prominent for larger images.', 'livingdraft' ),
				'control'     => array(
					'type'     => 'select',
					'choices'  => array(
						'compact'   => __( 'Compact — small image, tight spacing', 'livingdraft' ),
						'standard'  => __( 'Standard (default) — balanced', 'livingdraft' ),
						'prominent' => __( 'Prominent — larger image, more breathing room', 'livingdraft' ),
					),
					'priority' => 110,
				),
				'ui_only'     => true,
			),

			array(
				'id'          => 'livingdraft_pagination_style',
				'default'     => 'numbered',
				'sanitize'    => 'livingdraft_sanitize_pagination_style',
				'section'     => 'ld_front',
				'label'       => __( 'Pagination style', 'livingdraft' ),
				'description' => __( 'How readers get to the next set of stories: page numbers (traditional), a Load More button, or endless scroll.', 'livingdraft' ),
				'control'     => array(
					'type'     => 'select',
					'choices'  => array(
						'numbered'  => __( 'Numbered pages (default)', 'livingdraft' ),
						'load_more' => __( 'Load More button', 'livingdraft' ),
						'infinite'  => __( 'Infinite scroll', 'livingdraft' ),
					),
					'priority' => 120,
				),
				'ui_only'     => true,
			),

			array(
				'id'          => 'livingdraft_category_card_style',
				'default'     => 'uniform',
				'sanitize'    => 'livingdraft_sanitize_category_card',
				'section'     => 'ld_front',
				'label'       => __( 'Category card style', 'livingdraft' ),
				'description' => __( 'Story cards on category/section pages: all the same shape (uniform) or a mix with the first story larger (varied).', 'livingdraft' ),
				'control'     => array(
					'type'     => 'select',
					'choices'  => array(
						'uniform' => __( 'Uniform (default) — all cards same size', 'livingdraft' ),
						'varied'  => __( 'Varied — first story larger, rest smaller', 'livingdraft' ),
					),
					'priority' => 130,
				),
				'ui_only'     => true,
			),

			array(
				'id'          => 'livingdraft_newsletter_text',
				'default'     => '',
				'sanitize'    => 'text',
				'section'     => 'ld_front',
				'label'       => __( 'Newsletter pitch (in brief rail)', 'livingdraft' ),
				'description' => __( 'One line at the foot of the brief rail. Needs a URL below to appear.', 'livingdraft' ),
				'control'     => array( 'type' => 'text', 'priority' => 200 ),
				'ui_only'     => true,
			),

			array(
				'id'          => 'livingdraft_newsletter_url',
				'default'     => '',
				'sanitize'    => 'url',
				'section'     => 'ld_front',
				'label'       => __( 'Newsletter sign-up URL', 'livingdraft' ),
				'description' => __( 'Where the newsletter pitch link goes.', 'livingdraft' ),
				'control'     => array( 'type' => 'url', 'priority' => 210 ),
				'ui_only'     => true,
			),

		),

		/* ==============================================================
		 * SECTION: Sharing (formerly livingdraft_sharing)
		 * ============================================================== */
		'ld_sharing' => array(

			array(
				'id'          => 'livingdraft_share_image',
				'default'     => 0,
				'sanitize'    => 'int',
				'section'     => 'ld_sharing',
				'label'       => __( 'Default share image', 'livingdraft' ),
				'description' => __( 'Shown when a page has no featured image and someone shares the link on social media. Upload at 1200 by 628 pixels for best results.', 'livingdraft' ),
				'control'     => array(
					// Rendered via WP_Customize_Media_Control in register.php.
					'type'     => 'media',
					'priority' => 10,
				),
				'ui_only'     => true,
			),

		),

		/* ==============================================================
		 * SECTION: Social links (formerly livingdraft_social)
		 * ============================================================== */
		'ld_social' => array(

			array(
				'id'          => 'livingdraft_x_url',
				'default'     => '',
				'sanitize'    => 'url',
				'section'     => 'ld_social',
				'label'       => __( 'X (Twitter) profile URL', 'livingdraft' ),
				'description' => __( 'Full URL to your X profile. Leave blank to hide the X icon.', 'livingdraft' ),
				'control'     => array( 'type' => 'url', 'priority' => 10 ),
				'ui_only'     => true,
			),

			array(
				'id'          => 'livingdraft_instagram_url',
				'default'     => '',
				'sanitize'    => 'url',
				'section'     => 'ld_social',
				'label'       => __( 'Instagram profile URL', 'livingdraft' ),
				'description' => __( 'Full URL to your Instagram profile. Leave blank to hide the Instagram icon.', 'livingdraft' ),
				'control'     => array( 'type' => 'url', 'priority' => 20 ),
				'ui_only'     => true,
			),

		),

		/* ==============================================================
		 * SECTION: Footer & newsletter (formerly livingdraft_footer)
		 * ============================================================== */
		'ld_footer' => array(

			array(
				'id'          => 'livingdraft_newsletter_on',
				'default'     => true,
				'sanitize'    => 'checkbox',
				'section'     => 'ld_footer',
				'label'       => __( 'Show newsletter band above footer', 'livingdraft' ),
				'description' => __( 'The dedicated email signup band that appears above the site footer on every page.', 'livingdraft' ),
				'control'     => array( 'type' => 'checkbox', 'priority' => 10 ),
				'ui_only'     => true,
			),

			array(
				'id'          => 'livingdraft_newsletter_title',
				'default'     => 'The edition, by email',
				'sanitize'    => 'text',
				'section'     => 'ld_footer',
				'label'       => __( 'Newsletter heading', 'livingdraft' ),
				'description' => __( 'The headline shown at the top of the newsletter signup band.', 'livingdraft' ),
				'control'     => array( 'type' => 'text', 'priority' => 20 ),
				'ui_only'     => true,
			),

			array(
				'id'          => 'livingdraft_footer_note',
				'default'     => '',
				'sanitize'    => 'text',
				'section'     => 'ld_footer',
				'label'       => __( 'Copyright line', 'livingdraft' ),
				'description' => __( 'The line at the very bottom of every page. Leave blank to auto-generate "© [year] [site name]".', 'livingdraft' ),
				'control'     => array( 'type' => 'text', 'priority' => 30 ),
				'ui_only'     => true,
			),

		),

		/* ==============================================================
		 * SECTION: Cookies & tracking (formerly livingdraft_privacy)
		 * ============================================================== */
		'ld_privacy' => array(

			array(
				'id'          => 'livingdraft_consent_on',
				'default'     => true,
				'sanitize'    => 'checkbox',
				'section'     => 'ld_privacy',
				'label'       => __( 'Show cookie consent banner', 'livingdraft' ),
				'description' => __( 'Hold Google Analytics back until the reader clicks Accept. Turn off only if you run no analytics at all.', 'livingdraft' ),
				'control'     => array( 'type' => 'checkbox', 'priority' => 10 ),
				'ui_only'     => true,
			),

			array(
				'id'          => 'livingdraft_consent_text',
				'default'     => 'We use cookies to understand which stories are read. Nothing is loaded until you choose.',
				'sanitize'    => 'textarea',
				'section'     => 'ld_privacy',
				'label'       => __( 'Banner wording', 'livingdraft' ),
				'description' => __( 'The text shown in the cookie banner. Keep it short and honest.', 'livingdraft' ),
				'control'     => array( 'type' => 'textarea', 'priority' => 20 ),
				'ui_only'     => true,
			),

			array(
				'id'          => 'livingdraft_consent_link',
				'default'     => '',
				'sanitize'    => 'url',
				'section'     => 'ld_privacy',
				'label'       => __( 'Cookie notice page URL', 'livingdraft' ),
				'description' => __( 'Where the "Read the notice" link in the banner goes. Leave blank to use your privacy policy page.', 'livingdraft' ),
				'control'     => array( 'type' => 'url', 'priority' => 30 ),
				'ui_only'     => true,
			),

		),

	);
}

/**
 * Compute the --lede-margin CSS value from the align setting.
 * Called by the emitter when it encounters the ld_lede_margin_computed token.
 *
 * @param string $align 'left'|'center'|'right'.
 * @return string CSS margin shorthand, or empty to skip emission.
 */
function livingdraft_compute_lede_margin( $align ) {
	switch ( $align ) {
		case 'left':
			return '0 auto 0 0';
		case 'right':
			return '0 0 0 auto';
		default:
			return ''; // centre is the default — skip emission.
	}
}

/**
 * Compute the --rail CSS value from either the % or px setting.
 * Percentage takes precedence when > 0; otherwise fall back to pixels.
 *
 * @param int $percent 0–30 (%)
 * @param int $pixels  200–400 (px)
 * @return string CSS value.
 */
function livingdraft_compute_rail( $percent, $pixels ) {
	$percent = (int) $percent;
	$pixels  = (int) $pixels;

	if ( $percent > 0 && $percent <= 30 ) {
		// Guarded so bad values can't produce broken CSS.
		return max( 5, min( 30, $percent ) ) . 'vw';
	}

	return max( 200, min( 400, $pixels ?: 300 ) ) . 'px';
}

/**
 * Compute the --sidebar-order CSS value from the position select.
 * Used by the .layout grid to swap column order (left/right/hidden).
 *
 * @param string $pos 'right'|'left'|'hidden'
 * @return string An integer as a string — 1 or 2 in flexbox/grid order.
 */
function livingdraft_compute_sidebar_order( $pos ) {
	switch ( $pos ) {
		case 'left':
			return '-1'; // Places sidebar first in the flex/grid order.
		case 'hidden':
			return ''; // Skip emission — body class handles hiding.
		default:
			return ''; // 'right' is the default; no override needed.
	}
}

/**
 * Sanitise the sidebar position select. Falls back to 'right' for any
 * unexpected value (e.g. a broken database migration).
 *
 * @param string $value
 * @return string
 */
function livingdraft_sanitize_sidebar_position( $value ) {
	return in_array( $value, array( 'right', 'left', 'hidden' ), true ) ? $value : 'right';
}

/**
 * Sanitise the mobile-sidebar behaviour select.
 *
 * @param string $value
 * @return string
 */
function livingdraft_sanitize_sidebar_mobile( $value ) {
	return in_array( $value, array( 'below', 'drawer', 'hidden' ), true ) ? $value : 'below';
}

/**
 * Sanitise the homepage layout select. Falls back to 'grid' for any unexpected
 * value (e.g. old migration data or a legacy import).
 *
 * @param string $value
 * @return string
 */
function livingdraft_sanitize_homepage_layout( $value ) {
	return in_array( $value, array( 'grid', 'list', 'masonry' ), true ) ? $value : 'grid';
}

/**
 * Sanitise the story card style select.
 *
 * @param string $value
 * @return string
 */
function livingdraft_sanitize_card_style( $value ) {
	return in_array( $value, array( 'compact', 'standard', 'prominent' ), true ) ? $value : 'standard';
}

/**
 * Sanitise the pagination style select.
 *
 * @param string $value
 * @return string
 */
function livingdraft_sanitize_pagination_style( $value ) {
	return in_array( $value, array( 'numbered', 'load_more', 'infinite' ), true ) ? $value : 'numbered';
}

/**
 * Sanitise the category card style select.
 *
 * @param string $value
 * @return string
 */
function livingdraft_sanitize_category_card( $value ) {
	return in_array( $value, array( 'uniform', 'varied' ), true ) ? $value : 'uniform';
}

/**
 * Clamp a numeric setting into a safe range.
 *
 * Used as a factory for sanitize callbacks on numeric controls where an
 * out-of-range value would produce broken CSS. For example, --measure at
 * 0ch or 1ch collapses paragraph text into a vertical column of single
 * characters. The customizer UI has min/max attributes, but those are
 * enforced only in the browser — a bad database migration, an old saved
 * value, or a direct API call can still slip past them.
 *
 * @param int $min
 * @param int $max
 * @param int $default
 * @return callable
 */
function livingdraft_bounded_int( $min, $max, $default ) {
	return function ( $value ) use ( $min, $max, $default ) {
		$value = (int) $value;
		if ( $value < $min || $value > $max ) {
			return $default;
		}
		return $value;
	};
}

/**
 * Safety pass at emission time: clamp CSS-bound numeric values into their
 * declared bounds, even if a bad value somehow reached the emitter. Used
 * defensively by the emit.php pipeline for `unit`-based tokens.
 *
 * The customizer's number controls declare min/max via input_attrs, and
 * `livingdraft_bounded_int` applies them at save time. This function is the
 * belt to those braces — if a token bypasses both (custom code, hook, etc.)
 * we still won't emit CSS like `--measure: 1ch` that ruins the layout.
 *
 * @param mixed $value
 * @param array $token
 * @return mixed
 */
function livingdraft_clamp_token_value( $value, $token ) {
	if ( ! is_numeric( $value ) ) {
		return $value;
	}

	$attrs = $token['control']['input_attrs'] ?? array();
	if ( empty( $attrs['min'] ) && empty( $attrs['max'] ) ) {
		return $value;
	}

	$min = isset( $attrs['min'] ) ? (int) $attrs['min'] : PHP_INT_MIN;
	$max = isset( $attrs['max'] ) ? (int) $attrs['max'] : PHP_INT_MAX;
	$val = (int) $value;

	if ( $val < $min || $val > $max ) {
		return $token['default'];
	}

	return $val;
}

/**
 * Return the section definitions the Customizer will register.
 *
 * Each section becomes a collapsible panel in Appearance → Customize.
 * Tokens declare which section they belong to via their 'section' key.
 *
 * @return array
 */
function livingdraft_ld_sections() {
	return array(

		'ld_colour' => array(
			'title'       => __( 'Colours', 'livingdraft' ),
			'description' => __( 'Every colour on the site reads from these controls. Change one, and it updates everywhere — including inside blocks.', 'livingdraft' ),
			'priority'    => 30,
		),

		'ld_typography' => array(
			'title'       => __( 'Typography', 'livingdraft' ),
			'description' => __( 'Fonts and text sizing. Changes preview live as you adjust.', 'livingdraft' ),
			'priority'    => 35,
		),

		'ld_layout' => array(
			'title'       => __( 'Page layout', 'livingdraft' ),
			'description' => __( 'Site width, sidebar width, and how the page fills the window.', 'livingdraft' ),
			'priority'    => 40,
		),

		'ld_article' => array(
			'title'       => __( 'Article page', 'livingdraft' ),
			'description' => __( 'What appears on a single story: featured image behaviour, byline options, and article furniture.', 'livingdraft' ),
			'priority'    => 45,
		),

		'ld_masthead' => array(
			'title'       => __( 'Masthead', 'livingdraft' ),
			'description' => __( 'The lines that flank the site title and run under it.', 'livingdraft' ),
			'priority'    => 50,
		),

		'ld_front' => array(
			'title'       => __( 'Front page', 'livingdraft' ),
			'description' => __( 'Homepage layout, story slider, and card style options.', 'livingdraft' ),
			'priority'    => 55,
		),

		'ld_sharing' => array(
			'title'       => __( 'Sharing', 'livingdraft' ),
			'description' => __( 'Used when a link to this site is posted elsewhere.', 'livingdraft' ),
			'priority'    => 60,
		),

		'ld_social' => array(
			'title'       => __( 'Social links', 'livingdraft' ),
			'description' => __( 'Shown under the edition line, top right of the masthead. Leave blank to hide.', 'livingdraft' ),
			'priority'    => 65,
		),

		'ld_footer' => array(
			'title'       => __( 'Footer and newsletter', 'livingdraft' ),
			'description' => __( 'The newsletter block above the footer, and the copyright line at the very bottom. Subscribers are stored in the The Living Draft Core plugin database.', 'livingdraft' ),
			'priority'    => 70,
		),

		'ld_privacy' => array(
			'title'       => __( 'Cookies and tracking', 'livingdraft' ),
			'description' => __( 'The cookie banner holds Google Analytics back until a reader agrees. A banner that shows a message but loads the tracker anyway would not count as consent.', 'livingdraft' ),
			'priority'    => 75,
		),

	);
}

/**
 * Return the sanitiser callback for a token's shortcut name.
 *
 * Keeps register.php from repeating this switch statement everywhere.
 * If the token's 'sanitize' value is already a callable, it's returned as-is.
 *
 * @param string|callable $shortcut A shortcut name or a real callable.
 * @return callable
 */
function livingdraft_resolve_sanitizer( $shortcut ) {
	if ( is_callable( $shortcut ) ) {
		return $shortcut;
	}

	switch ( $shortcut ) {
		case 'colour':
		case 'color':
			return 'sanitize_hex_color';
		case 'int':
			return 'absint';
		case 'text':
			return 'sanitize_text_field';
		case 'select':
		case 'key':
			return 'sanitize_key';
		case 'checkbox':
		case 'bool':
			return 'wp_validate_boolean';
		case 'url':
			return 'esc_url_raw';
		case 'textarea':
			return 'sanitize_textarea_field';
		default:
			// Unknown shortcut → treat as text (safe fallback).
			return 'sanitize_text_field';
	}
}
