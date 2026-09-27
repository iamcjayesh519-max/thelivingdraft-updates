<?php
/**
 * Listen: reading the article aloud.
 *
 * === WHY THE BROWSER AND NOT AN API ===
 *
 * There are two ways to build this. One sends the article to a text-to-speech
 * API, gets an MP3 back, stores it, and serves it from an <audio> element.
 * The other asks the reader's own device to speak, using the Web Speech API
 * that has shipped in every major browser since 2018.
 *
 * The API route sounds better. It also means: a per-article cost, a key to
 * store, a queue to run, a regeneration problem every time a correction is
 * published on a site whose masthead promises corrections are published, and
 * an audio file per article per voice sitting in the uploads directory
 * forever. For a feature whose honest description is "some readers would
 * rather listen", that is a large standing bill and a large standing
 * liability.
 *
 * The browser route costs nothing, needs no key, has no queue, and is always
 * current because it reads the page as it exists right now. A correction
 * published at 4pm is spoken at 4:01. The voice is worse. That trade is the
 * right way round for this site, and the wrong way round is easy to switch to
 * later: livingdraft_listen_audio_url() below is the seam. Return a URL from
 * it and the player uses a real audio file instead, with no other change.
 *
 * === WHY THE TEXT IS NOT EXTRACTED HERE ===
 *
 * The obvious design is to build the spoken text in PHP and hand the browser
 * a string. It is wrong, for a reason that only shows up once the player
 * works: highlighting.
 *
 * A reader following along wants to see which paragraph is being spoken. If
 * PHP produced the text, the browser would have to match spoken chunks back
 * onto the rendered DOM by string comparison — which breaks on every smart
 * quote, every entity, every block that a filter modified after the extract
 * was taken. Reading the chunks straight off the rendered page means the
 * element being highlighted IS the element being spoken, by construction.
 * There is nothing to keep in sync.
 *
 * So PHP prints a player and a word count. The text is the page.
 *
 * @package LivingDraftCore
 * @since 4.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option name.
 *
 * @since 4.2.0
 * @var string
 */
const LD_LISTEN_SETTINGS = 'livingdraft_listen_settings';

/**
 * Words per minute used for the duration estimate.
 *
 * 155 is deliberately below the 180-200 used for the theme's silent
 * "reading time". Speech is slower than reading, and a listener who is told
 * seven minutes and gets nine is more annoyed than one told nine and given
 * seven.
 *
 * @since 4.2.0
 * @var int
 */
const LD_LISTEN_WPM = 155;

/* ==================================================================
 * 1. SETTINGS
 * ================================================================== */

/**
 * Settings, with defaults.
 *
 * @since 4.2.0
 * @return array
 */
function livingdraft_listen_settings() {
	$defaults = array(
		'enabled'    => true,
		'post_types' => array( 'post' ),
		'position'   => 'before',   // 'before' or 'after' the content.
		'selector'   => '.entry-content',
		'rate'       => 1.0,        // Starting speed. Readers can change it.
		'highlight'  => true,       // Mark the paragraph being spoken.
		'min_words'  => 120,        // Below this, no player. See below.
	);

	$stored = get_option( LD_LISTEN_SETTINGS, array() );

	return wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );
}

/**
 * Should the player appear on this request?
 *
 * The min_words floor is the part worth explaining. A two-hundred-word brief
 * takes about eighty seconds to speak, which is less time than it takes to
 * find the play button, wait for the voice to load, and decide the voice is
 * not the one you wanted. Offering to read out something that short is
 * offering a worse version of just reading it.
 *
 * @since 4.2.0
 * @param int|null $post_id Post to test. Defaults to the current one.
 * @return bool
 */
function livingdraft_listen_available( $post_id = null ) {
	$settings = livingdraft_listen_settings();

	if ( empty( $settings['enabled'] ) ) {
		return false;
	}

	if ( is_feed() || is_embed() || is_admin() ) {
		return false;
	}

	$post = get_post( $post_id );

	if ( ! $post || ! in_array( $post->post_type, (array) $settings['post_types'], true ) ) {
		return false;
	}

	if ( livingdraft_listen_word_count( $post ) < (int) $settings['min_words'] ) {
		return false;
	}

	/**
	 * Filter whether the listen player renders for a post.
	 *
	 * @since 4.2.0
	 * @param bool    $available Whether to show the player.
	 * @param WP_Post $post      The post.
	 */
	return (bool) apply_filters( 'livingdraft_listen_available', true, $post );
}

/**
 * Word count of the article body.
 *
 * Counted from the raw content rather than the filtered output on purpose:
 * running the_content() here would execute every block render, every
 * shortcode and every embed a second time, on every page view, to produce a
 * number used for one estimate.
 *
 * @since 4.2.0
 * @param WP_Post $post The post.
 * @return int
 */
function livingdraft_listen_word_count( $post ) {
	$cached = get_post_meta( $post->ID, '_ld_listen_words', true );

	if ( '' !== $cached ) {
		return (int) $cached;
	}

	$text  = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
	$words = str_word_count( wp_specialchars_decode( $text, ENT_QUOTES ) );

	update_post_meta( $post->ID, '_ld_listen_words', $words );

	return (int) $words;
}

/**
 * Drop the cached word count when a post is saved.
 *
 * @since 4.2.0
 * @param int $post_id Post id.
 * @return void
 */
function livingdraft_listen_clear_word_count( $post_id ) {
	delete_post_meta( $post_id, '_ld_listen_words' );
}
add_action( 'save_post', 'livingdraft_listen_clear_word_count' );

/**
 * Estimated spoken length, in whole minutes.
 *
 * @since 4.2.0
 * @param WP_Post $post The post.
 * @return int
 */
function livingdraft_listen_minutes( $post ) {
	return max( 1, (int) ceil( livingdraft_listen_word_count( $post ) / LD_LISTEN_WPM ) );
}

/* ==================================================================
 * 2. THE SEAM FOR REAL AUDIO
 * ================================================================== */

/**
 * URL of a pre-generated audio file for this post, if one exists.
 *
 * Nothing in this plugin returns anything here. It is the single point at
 * which the browser-speech decision can be reversed: hook this filter, return
 * a URL, and the player renders an <audio> element instead of driving the
 * speech synthesiser. The markup, the settings and the placement logic all
 * stay as they are.
 *
 * @since 4.2.0
 * @param WP_Post $post The post.
 * @return string URL, or '' for browser speech.
 */
function livingdraft_listen_audio_url( $post ) {
	/**
	 * Filter a pre-generated narration URL.
	 *
	 * @since 4.2.0
	 * @param string  $url  Empty by default.
	 * @param WP_Post $post The post.
	 */
	return (string) apply_filters( 'livingdraft_listen_audio_url', '', $post );
}

/* ==================================================================
 * 3. ASSETS
 * ================================================================== */

/**
 * Enqueue the player, on the views that have one.
 *
 * @since 4.2.0
 * @return void
 */
function livingdraft_listen_assets() {
	if ( ! is_singular() || ! livingdraft_listen_available() ) {
		return;
	}

	$settings = livingdraft_listen_settings();

	$css = LIVINGDRAFT_CORE_DIR . 'assets/css/listen.css';
	$js  = LIVINGDRAFT_CORE_DIR . 'assets/js/listen.js';

	if ( file_exists( $css ) ) {
		wp_enqueue_style(
			'livingdraft-listen',
			LIVINGDRAFT_CORE_URL . 'assets/css/listen.css',
			array(),
			(string) filemtime( $css )
		);
	}

	if ( ! file_exists( $js ) ) {
		return;
	}

	wp_enqueue_script(
		'livingdraft-listen',
		LIVINGDRAFT_CORE_URL . 'assets/js/listen.js',
		array(),
		(string) filemtime( $js ),
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);

	wp_localize_script(
		'livingdraft-listen',
		'ldListen',
		array(
			'selector'  => (string) $settings['selector'],
			'rate'      => (float) $settings['rate'],
			'highlight' => (bool) $settings['highlight'],
			'lang'      => get_bloginfo( 'language' ),
			'i18n'      => array(
				'listen'      => __( 'Listen', 'livingdraft-core' ),
				'pause'       => __( 'Pause', 'livingdraft-core' ),
				'resume'      => __( 'Resume', 'livingdraft-core' ),
				'restart'     => __( 'Start again', 'livingdraft-core' ),
				'stop'        => __( 'Stop', 'livingdraft-core' ),
				'voice'       => __( 'Voice', 'livingdraft-core' ),
				'speed'       => __( 'Speed', 'livingdraft-core' ),
				'options'     => __( 'Playback options', 'livingdraft-core' ),
				'unsupported' => __( 'This browser cannot read pages aloud.', 'livingdraft-core' ),
				/* translators: %s: a percentage, e.g. "40". */
				'progress'    => __( '%s%% through the article', 'livingdraft-core' ),
				'resumeFrom'  => __( 'Continue where you stopped', 'livingdraft-core' ),
				'fromStart'   => __( 'From the beginning', 'livingdraft-core' ),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'livingdraft_listen_assets' );

/* ==================================================================
 * 4. THE PLAYER
 * ================================================================== */

/**
 * The player markup.
 *
 * Printed with the `hidden` attribute set. The script removes it after
 * confirming the browser can actually speak. A reader on a browser without
 * the Web Speech API — or with speech disabled at the OS level — sees no
 * control at all, which is better than a play button that does nothing.
 *
 * @since 4.2.0
 * @param WP_Post|null $post The post.
 * @return string HTML.
 */
function livingdraft_listen_player( $post = null ) {
	$post = get_post( $post );

	if ( ! $post || ! livingdraft_listen_available( $post->ID ) ) {
		return '';
	}

	$minutes = livingdraft_listen_minutes( $post );
	$audio   = livingdraft_listen_audio_url( $post );

	ob_start();
	?>
	<div class="ld-listen" data-ld-listen data-post="<?php echo esc_attr( (string) $post->ID ); ?>"<?php echo $audio ? ' data-audio="' . esc_url( $audio ) . '"' : ''; ?> hidden>

		<div class="ld-listen__row">
			<button type="button" class="ld-listen__play" aria-describedby="ld-listen-note-<?php echo esc_attr( (string) $post->ID ); ?>">
				<span class="ld-listen__icon" aria-hidden="true"></span>
				<span class="ld-listen__label"><?php esc_html_e( 'Listen', 'livingdraft-core' ); ?></span>
			</button>

			<p class="ld-listen__note" id="ld-listen-note-<?php echo esc_attr( (string) $post->ID ); ?>">
				<?php
				printf(
					/* translators: %d: estimated minutes. */
					esc_html( _n( 'About %d minute, read by your device', 'About %d minutes, read by your device', $minutes, 'livingdraft-core' ) ),
					(int) $minutes
				);
				?>
			</p>

			<button type="button" class="ld-listen__toggle" aria-expanded="false" aria-controls="ld-listen-panel-<?php echo esc_attr( (string) $post->ID ); ?>">
				<span class="screen-reader-text"><?php esc_html_e( 'Playback options', 'livingdraft-core' ); ?></span>
				<span aria-hidden="true">&#9662;</span>
			</button>
		</div>

		<div class="ld-listen__track" aria-hidden="true"><div class="ld-listen__fill"></div></div>

		<div class="ld-listen__panel" id="ld-listen-panel-<?php echo esc_attr( (string) $post->ID ); ?>" hidden>
			<label class="ld-listen__field">
				<span><?php esc_html_e( 'Voice', 'livingdraft-core' ); ?></span>
				<select class="ld-listen__voice"></select>
			</label>

			<label class="ld-listen__field">
				<span><?php esc_html_e( 'Speed', 'livingdraft-core' ); ?></span>
				<select class="ld-listen__rate">
					<option value="0.75">0.75&times;</option>
					<option value="1">1&times;</option>
					<option value="1.25">1.25&times;</option>
					<option value="1.5">1.5&times;</option>
					<option value="1.75">1.75&times;</option>
					<option value="2">2&times;</option>
				</select>
			</label>

			<p class="ld-listen__disclosure">
				<?php esc_html_e( 'Spoken by your own browser or phone, not by a recording. Nothing is sent anywhere, and the voices available depend on your device.', 'livingdraft-core' ); ?>
			</p>
		</div>

		<!--
			The polite live region. Only state changes go through it —
			started, paused, finished — never progress. A region that
			announced every paragraph would talk over the speech it is
			describing.
		-->
		<p class="ld-listen__status screen-reader-text" role="status" aria-live="polite"></p>
	</div>
	<?php

	return (string) ob_get_clean();
}

/**
 * Put the player on the article.
 *
 * Guarded hard. `the_content` is one of the most-called filters in WordPress
 * and it runs for feeds, REST responses, excerpt fallbacks, and any plugin
 * that renders a post body inside another page. Every one of those would get
 * a player injected into it without the in_the_loop() and is_main_query()
 * checks.
 *
 * @since 4.2.0
 * @param string $content Post content.
 * @return string
 */
function livingdraft_listen_inject( $content ) {
	if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	if ( ! livingdraft_listen_available() ) {
		return $content;
	}

	$player = livingdraft_listen_player();

	if ( '' === $player ) {
		return $content;
	}

	$settings = livingdraft_listen_settings();

	return 'after' === $settings['position'] ? $content . $player : $player . $content;
}
add_filter( 'the_content', 'livingdraft_listen_inject', 8 );

/**
 * `[listen]` — for anyone placing the player by hand.
 *
 * Useful with position set to 'before' but a template that needs the control
 * somewhere specific, e.g. next to the byline rather than above the first
 * paragraph.
 *
 * @since 4.2.0
 * @return string
 */
function livingdraft_listen_shortcode() {
	return livingdraft_listen_player();
}
add_shortcode( 'listen', 'livingdraft_listen_shortcode' );
