<?php
/**
 * Light, dark and automatic.
 *
 * Before 4.0 the theme simply followed the reader's operating system and
 * offered no way to disagree with it. That is the wrong default for a
 * newspaper: a reader on a dark phone at midday may still want the paper
 * white, and the editor may want the site to open one particular way for
 * a first-time visitor regardless of their device.
 *
 * So there are two separate decisions here, and they must not be confused:
 *
 *   1. THE SITE DEFAULT — set by the editor in the Customizer. This is what
 *      a reader sees on their very first visit, before they have expressed
 *      any preference of their own. Light, Dark, or Auto.
 *
 *   2. THE READER'S CHOICE — set by the reader with the control in the
 *      masthead, remembered in their own browser. Once made, it always
 *      wins over the site default, on every page, until they change it.
 *
 * === WHY THE SCRIPT IS INLINE AND IN THE HEAD ===
 *
 * The reader's choice lives in localStorage, which only JavaScript can
 * read. If we waited for a normal deferred script to run, a dark-mode
 * reader would get a full white page for a moment on every single
 * navigation before it flipped to dark. That flash is worse than having
 * no switch at all — it looks broken, and it is genuinely unpleasant in
 * a dark room, which is the exact situation the reader chose dark for.
 *
 * The only way to avoid it is a small blocking script in the head that
 * runs before the browser paints anything. It is deliberately tiny
 * (a few hundred bytes, no dependencies) because a blocking script is a
 * cost and this one has to earn its place.
 *
 * === WHY THE ATTRIBUTE IS ALSO SET IN PHP ===
 *
 * A reader with JavaScript disabled never runs the script above. So PHP
 * writes the site default onto <html> as well. The script then upgrades
 * that to the reader's saved choice if there is one. Nobody is left with
 * an unstyled or half-styled page.
 *
 * @package LivingDraft
 * @since 4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The three schemes, and their labels.
 *
 * @since 4.0.0
 * @return array<string,string> Scheme key => translated label.
 */
function livingdraft_scheme_choices() {
	return array(
		'light' => __( 'Light', 'livingdraft' ),
		'dark'  => __( 'Dark', 'livingdraft' ),
		'auto'  => __( 'Match my device', 'livingdraft' ),
	);
}

/**
 * The site default — what a first-time reader sees.
 *
 * @since 4.0.0
 * @return string One of 'light', 'dark', 'auto'.
 */
function livingdraft_scheme_default() {
	$value = get_theme_mod( 'livingdraft_scheme_default', 'auto' );

	if ( ! array_key_exists( $value, livingdraft_scheme_choices() ) ) {
		$value = 'auto';
	}

	/**
	 * Filter the site's default colour scheme.
	 *
	 * @since 4.0.0
	 * @param string $value One of 'light', 'dark', 'auto'.
	 */
	return (string) apply_filters( 'livingdraft_scheme_default', $value );
}

/**
 * Should readers get a control of their own?
 *
 * Some publications want to lock the paper to one appearance. Turning this
 * off hides the button but leaves the site default in force.
 *
 * @since 4.0.0
 * @return bool
 */
function livingdraft_scheme_toggle_enabled() {
	$enabled = (bool) get_theme_mod( 'livingdraft_scheme_toggle', true );

	/**
	 * Filter whether the reader-facing scheme control is shown.
	 *
	 * @since 4.0.0
	 * @param bool $enabled
	 */
	return (bool) apply_filters( 'livingdraft_scheme_toggle', $enabled );
}

/**
 * Sanitise a scheme value coming from the Customizer.
 *
 * @since 4.0.0
 * @param mixed $value Raw value.
 * @return string A valid scheme key.
 */
function livingdraft_sanitize_scheme( $value ) {
	$value = is_string( $value ) ? $value : '';

	return array_key_exists( $value, livingdraft_scheme_choices() ) ? $value : 'auto';
}

/* ------------------------------------------------------------------
 * 1. Marking the page
 * ------------------------------------------------------------------ */

/**
 * Write the site default onto <html> so a no-JavaScript reader is served
 * a fully styled page, and so the inline script has something to correct
 * rather than something to create.
 *
 * @since 4.0.0
 * @param string $output Existing language attributes.
 * @return string
 */
function livingdraft_scheme_html_attr( $output ) {
	return $output . ' data-scheme="' . esc_attr( livingdraft_scheme_default() ) . '"';
}
add_filter( 'language_attributes', 'livingdraft_scheme_html_attr' );

/**
 * The no-flash script.
 *
 * Runs before the browser paints. Reads the reader's saved choice and, if
 * there is one, replaces the site default already on <html>. Wrapped in
 * try/catch because localStorage throws outright in Safari private mode
 * and in some embedded webviews — a thrown error here would leave the
 * page unstyled, so failing silently back to the site default is the only
 * acceptable behaviour.
 *
 * Not enqueued through wp_add_inline_script() because that would attach it
 * to a deferred handle in the footer, which defeats the entire purpose.
 *
 * @since 4.0.0
 */
function livingdraft_scheme_head_script() {
	if ( ! livingdraft_scheme_toggle_enabled() ) {
		return; // Nothing can change it, so nothing needs correcting.
	}
	?>
<script id="livingdraft-scheme">(function(){try{var s=localStorage.getItem("livingdraft-scheme");if(s==="light"||s==="dark"||s==="auto"){document.documentElement.setAttribute("data-scheme",s);}}catch(e){}})();</script>
	<?php
}
add_action( 'wp_head', 'livingdraft_scheme_head_script', 0 );

/* ------------------------------------------------------------------
 * 2. The reader's control
 * ------------------------------------------------------------------ */

/**
 * Print the scheme control for the masthead.
 *
 * Rendered as three real radio inputs inside a fieldset rather than a
 * single cycling button. A cycling button cannot say what it will do next
 * without being pressed, which is guesswork for a sighted reader and
 * close to useless with a screen reader. Three labelled options state all
 * the choices at once and are operable by keyboard with no JavaScript
 * involved in the markup at all.
 *
 * @since 4.0.0
 */
function livingdraft_scheme_control() {
	if ( ! livingdraft_scheme_toggle_enabled() ) {
		return;
	}

	$choices = livingdraft_scheme_choices();
	$current = livingdraft_scheme_default();
	?>
	<div class="scheme-control" data-scheme-control>
		<fieldset>
			<legend class="screen-reader-text"><?php esc_html_e( 'Page appearance', 'livingdraft' ); ?></legend>
			<?php foreach ( $choices as $ld_key => $ld_label ) : ?>
				<?php $ld_id = 'ld-scheme-' . $ld_key; ?>
				<input
					type="radio"
					name="livingdraft-scheme"
					id="<?php echo esc_attr( $ld_id ); ?>"
					value="<?php echo esc_attr( $ld_key ); ?>"
					<?php checked( $current, $ld_key ); ?>>
				<label for="<?php echo esc_attr( $ld_id ); ?>" title="<?php echo esc_attr( $ld_label ); ?>">
					<?php echo livingdraft_scheme_icon( $ld_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="screen-reader-text"><?php echo esc_html( $ld_label ); ?></span>
				</label>
			<?php endforeach; ?>
		</fieldset>
	</div>
	<?php
}

/**
 * Inline SVG for each scheme. Kept here rather than in the icon set
 * because these three are only ever used by this control.
 *
 * @since 4.0.0
 * @param string $which Scheme key.
 * @return string SVG markup, or an empty string for an unknown key.
 */
function livingdraft_scheme_icon( $which ) {
	$open = '<svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">';

	switch ( $which ) {
		case 'light':
			return $open . '<circle cx="12" cy="12" r="4.2"/><path d="M12 2.4v2.2M12 19.4v2.2M4.2 4.2l1.6 1.6M18.2 18.2l1.6 1.6M2.4 12h2.2M19.4 12h2.2M4.2 19.8l1.6-1.6M18.2 5.8l1.6-1.6"/></svg>';

		case 'dark':
			return $open . '<path d="M20 13.4A8.2 8.2 0 1 1 10.6 4a6.4 6.4 0 0 0 9.4 9.4z"/></svg>';

		case 'auto':
			return $open . '<rect x="2.6" y="4.2" width="18.8" height="13" rx="1.6"/><path d="M8.4 20.4h7.2"/></svg>';
	}

	return '';
}

/* ------------------------------------------------------------------
 * 3. Customizer controls
 * ------------------------------------------------------------------ */

/**
 * Register the two editor-facing settings in their own section.
 *
 * A separate section rather than an addition to the token registry in
 * inc/customizer/tokens.php, because these two settings do not emit CSS
 * variables — they change behaviour, and the registry's emitter would
 * have to be taught an exception to skip them.
 *
 * @since 4.0.0
 * @param WP_Customize_Manager $wp_customize Customizer manager.
 */
function livingdraft_scheme_customize( $wp_customize ) {
	$wp_customize->add_section(
		'livingdraft_scheme',
		array(
			'title'       => __( 'Light and dark', 'livingdraft' ),
			'priority'    => 32,
			'description' => __( 'How the paper looks, and whether readers may choose for themselves.', 'livingdraft' ),
		)
	);

	$wp_customize->add_setting(
		'livingdraft_scheme_default',
		array(
			'default'           => 'auto',
			'sanitize_callback' => 'livingdraft_sanitize_scheme',
			'transport'         => 'refresh',
		)
	);

	$wp_customize->add_control(
		'livingdraft_scheme_default',
		array(
			'label'       => __( 'How the paper opens', 'livingdraft' ),
			'description' => __( 'What a reader sees on their first visit. "Match my device" follows whatever their phone or computer is set to. Readers who make their own choice keep it, whatever this is set to.', 'livingdraft' ),
			'section'     => 'livingdraft_scheme',
			'type'        => 'select',
			'choices'     => livingdraft_scheme_choices(),
		)
	);

	$wp_customize->add_setting(
		'livingdraft_scheme_toggle',
		array(
			'default'           => true,
			'sanitize_callback' => 'wp_validate_boolean',
			'transport'         => 'refresh',
		)
	);

	$wp_customize->add_control(
		'livingdraft_scheme_toggle',
		array(
			'label'       => __( 'Let readers choose', 'livingdraft' ),
			'description' => __( 'Shows a small light/dark control in the masthead. Turn this off to lock the paper to the appearance chosen above.', 'livingdraft' ),
			'section'     => 'livingdraft_scheme',
			'type'        => 'checkbox',
		)
	);
}
add_action( 'customize_register', 'livingdraft_scheme_customize', 30 );
