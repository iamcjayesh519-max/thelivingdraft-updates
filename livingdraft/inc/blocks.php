<?php
/**
 * Block editor support: style variations and the pattern category.
 *
 * The patterns themselves live in /patterns and are registered by WordPress.
 *
 * @package LivingDraft
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A home for the theme's own patterns in the inserter.
 */
function livingdraft_pattern_category() {
	if ( ! function_exists( 'register_block_pattern_category' ) ) {
		return;
	}

	register_block_pattern_category(
		'livingdraft',
		array( 'label' => __( 'The Living Draft', 'livingdraft' ) )
	);
}
add_action( 'init', 'livingdraft_pattern_category' );

/**
 * Style variations, so writers can reach the theme's furniture from the
 * editor without knowing any class names.
 */
function livingdraft_block_styles() {
	if ( ! function_exists( 'register_block_style' ) ) {
		return;
	}

	register_block_style(
		'core/quote',
		array(
			'name'  => 'brief',
			'label' => __( 'Brief', 'livingdraft' ),
		)
	);

	register_block_style(
		'core/separator',
		array(
			'name'  => 'double-rule',
			'label' => __( 'Double rule', 'livingdraft' ),
		)
	);

	register_block_style(
		'core/group',
		array(
			'name'  => 'fact-box',
			'label' => __( 'Fact box', 'livingdraft' ),
		)
	);

	register_block_style(
		'core/group',
		array(
			'name'  => 'correction',
			'label' => __( 'Correction notice', 'livingdraft' ),
		)
	);

	register_block_style(
		'core/image',
		array(
			'name'  => 'framed',
			'label' => __( 'Ruled frame', 'livingdraft' ),
		)
	);

	register_block_style(
		'core/list',
		array(
			'name'  => 'ruled',
			'label' => __( 'Ruled list', 'livingdraft' ),
		)
	);
}
add_action( 'init', 'livingdraft_block_styles' );
