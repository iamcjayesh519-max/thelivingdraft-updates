<?php
/**
 * Living Draft — CSS variable emitter.
 *
 * Walks the token registry and prints a single <style id="livingdraft-vars">
 * block in <head> containing a :root { ... } declaration with one CSS custom
 * property per configured setting.
 *
 * The stylesheet itself references those variables — so changing a colour
 * in Customizer instantly re-styles every rule that uses --ink, --mark, etc.
 * That's how one setting can control the appearance of hundreds of elements.
 *
 * Emission rules:
 *   1. Tokens with 'ui_only' → skipped (they drive PHP logic, not CSS).
 *   2. Tokens with 'css.skip' → skipped (participate in computed vars only).
 *   3. Tokens with 'css.compute' → run a callback to produce the value.
 *   4. Tokens with 'css.format' → sprintf with values from 'inputs'.
 *   5. Tokens with 'css.lookup' → key-lookup into a callable's return.
 *   6. Simple tokens → value + unit → CSS var.
 *
 * By default, a var is emitted only when the current value differs from the
 * default. That keeps the stylesheet lean — a fresh install emits almost
 * nothing extra.
 *
 * @package LivingDraft
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emit :root { ... } CSS variables based on the token registry.
 * Hooked to wp_head at priority 20 (after core styles, before block styles).
 */
function livingdraft_customizer_css() {
	$declarations = array();

	foreach ( livingdraft_tokens() as $section_id => $tokens ) {
		foreach ( $tokens as $token ) {
			$decl = livingdraft_emit_token( $token );
			if ( '' !== $decl ) {
				$declarations[] = $decl;
			}
		}
	}

	if ( empty( $declarations ) ) {
		return;
	}

	/*
	 * Not esc_html(): <style> is a raw-text element, so HTML entities are not
	 * decoded inside it. Escaping would turn "Times New Roman" into
	 * &quot;Times New Roman&quot; — an invalid font family the browser drops.
	 * Every value produced by livingdraft_emit_token() is already sanitised
	 * at the source (hex colours via sanitize_hex_color, ints via absint,
	 * font stacks from a hard-coded array).
	 */
	printf(
		'<style id="livingdraft-vars">:root{%s}</style>' . "\n",
		wp_strip_all_tags( implode( ';', $declarations ) )
	);
}
add_action( 'wp_head', 'livingdraft_customizer_css', 20 );

/**
 * Produce a single CSS declaration (or '' to skip) for one token.
 *
 * @param array $token Token definition from livingdraft_tokens().
 * @return string 'e.g. "--ink:#111"' or '' when nothing should be emitted.
 */
function livingdraft_emit_token( $token ) {
	// Toggles that only drive PHP visibility — never touch CSS.
	if ( ! empty( $token['ui_only'] ) ) {
		return '';
	}

	// Some tokens participate in computed vars and don't emit their own line.
	if ( isset( $token['css']['skip'] ) && $token['css']['skip'] ) {
		return '';
	}

	// No 'css' block at all → nothing to emit.
	if ( empty( $token['css'] ) ) {
		return '';
	}

	$css_def = $token['css'];
	$value   = null;

	// --- Computed tokens: run a callback with named input values ---
	if ( ! empty( $css_def['compute'] ) ) {
		$inputs = array();
		foreach ( (array) ( $css_def['inputs'] ?? array() ) as $input_id ) {
			$inputs[] = livingdraft_get_token_value( $input_id );
		}
		$computed = call_user_func_array( $css_def['compute'], $inputs );

		if ( '' === $computed || null === $computed ) {
			return ''; // Callback signalled "skip".
		}
		return $css_def['var'] . ':' . $computed;
	}

	// --- Format tokens: sprintf with values from multiple settings ---
	if ( ! empty( $css_def['format'] ) ) {
		$inputs = array();
		$bounds = $css_def['bounds'] ?? array();

		foreach ( (array) ( $css_def['inputs'] ?? array() ) as $i => $input_id ) {
			$raw = (int) livingdraft_get_token_value( $input_id );

			// Optional bounds enforcement.
			if ( isset( $bounds[ $i ] ) ) {
				$min = $bounds[ $i ]['min'] ?? PHP_INT_MIN;
				$max = $bounds[ $i ]['max'] ?? PHP_INT_MAX;
				$raw = max( $min, min( $max, $raw ) );
			}

			$inputs[] = $raw;
		}

		$formatted = vsprintf( $css_def['format'], $inputs );
		return $css_def['var'] . ':' . $formatted;
	}

	// --- Standard tokens: value is the theme_mod itself ---
	$value = get_theme_mod( $token['id'], $token['default'] );

	// Belt-and-braces clamp — protect against corrupt DB values or bad user
	// input that slipped past the browser's number-input min/max. Otherwise
	// something like --measure: 0ch collapses paragraph text into a vertical
	// column of single characters.
	if ( function_exists( 'livingdraft_clamp_token_value' ) ) {
		$value = livingdraft_clamp_token_value( $value, $token );
	}

	// Skip if unchanged from default (keeps the stylesheet minimal).
	$skip_when_default = $css_def['skip_when_default'] ?? true;
	if ( $skip_when_default && $value === $token['default'] ) {
		return '';
	}

	// --- Lookup tokens: value is a key into an external array (e.g. fonts) ---
	if ( ! empty( $css_def['lookup'] ) ) {
		$lookup_fn = $css_def['lookup'];
		$field     = $css_def['lookup_field'] ?? 'stack';

		if ( ! is_callable( $lookup_fn ) ) {
			return '';
		}

		$table = call_user_func( $lookup_fn );

		if ( ! isset( $table[ $value ][ $field ] ) ) {
			return '';
		}

		return $css_def['var'] . ':' . $table[ $value ][ $field ];
	}

	// --- Simple value + unit ---
	$unit = $css_def['unit'] ?? '';
	return $css_def['var'] . ':' . $value . $unit;
}

/**
 * Fetch the resolved value for a token id — used by computed/format tokens.
 * Walks the registry to find the token, applies its default if unset.
 *
 * @param string $token_id
 * @return mixed
 */
function livingdraft_get_token_value( $token_id ) {
	static $cache = null;

	// Build a flat id→token map once per request.
	if ( null === $cache ) {
		$cache = array();
		foreach ( livingdraft_tokens() as $tokens ) {
			foreach ( $tokens as $token ) {
				if ( ! empty( $token['id'] ) ) {
					$cache[ $token['id'] ] = $token;
				}
			}
		}
	}

	if ( ! isset( $cache[ $token_id ] ) ) {
		return null;
	}

	$token = $cache[ $token_id ];
	return get_theme_mod( $token_id, $token['default'] );
}
