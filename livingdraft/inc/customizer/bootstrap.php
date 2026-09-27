<?php
/**
 * Living Draft — Customizer bootstrap.
 *
 * Loads the customizer subsystem in the correct order:
 *   1. tokens.php    → defines the registry (must be first)
 *   2. emit.php      → CSS variable emission (reads registry)
 *   3. register.php  → WP Customizer registration (reads registry)
 *
 * Other theme code calls livingdraft_tokens() etc. at hook time (wp_head,
 * customize_register), so as long as this bootstrap runs during theme
 * setup, everything wires up automatically.
 *
 * The old customizer.php and customizer-extra.php still handle a few
 * settings that don't fit the token model cleanly yet — sponsor URLs,
 * front-page column counts, footer notes, privacy toggles. Those will
 * migrate in Phase 2/3. For now, both systems coexist without conflict
 * because they operate on different theme_mod keys.
 *
 * @package LivingDraft
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/tokens.php';
require_once __DIR__ . '/emit.php';
require_once __DIR__ . '/register.php';
