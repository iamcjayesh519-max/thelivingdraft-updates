<?php
/**
 * Starter content — what a fresh install looks like before anyone writes.
 *
 * @package LivingDraft
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register starter content for the customizer's fresh-site flow.
 */
function livingdraft_starter_content() {
	add_theme_support(
		'starter-content',
		array(
			'posts'       => array(
				'about'   => array(
					'post_type'  => 'page',
					'post_title' => _x( 'About this paper', 'Theme starter content', 'livingdraft' ),
					'post_content' => _x( 'Who writes here, what this paper covers, and how corrections are handled.', 'Theme starter content', 'livingdraft' ),
				),
				'contact' => array(
					'post_type'  => 'page',
					'post_title' => _x( 'Contact the desk', 'Theme starter content', 'livingdraft' ),
				),
				'privacy_policy',
			),

			'options'     => array(
				'show_on_front'  => 'posts',
				'posts_per_page' => 10,
			),

			'theme_mods'  => array(
				'livingdraft_edition_line' => _x( 'Online edition', 'Theme starter content', 'livingdraft' ),
				'livingdraft_strapline'    => _x( 'The world, in its draft form', 'Theme starter content', 'livingdraft' ),
				'livingdraft_brief_count'  => 5,
				'livingdraft_dropcap'      => true,
			),

			'widgets'     => array(
				'sidebar-1' => array( 'search', 'recent-posts', 'categories' ),
			),

			'nav_menus'   => array(
				'primary' => array(
					'name'  => _x( 'Sections', 'Theme starter content', 'livingdraft' ),
					'items' => array(
						'link_home',
						'page_about',
						'page_contact',
					),
				),
				'footer'  => array(
					'name'  => _x( 'The paper', 'Theme starter content', 'livingdraft' ),
					'items' => array(
						'page_about',
						'page_contact',
						'page_privacy',
					),
				),
			),
		)
	);
}
add_action( 'after_setup_theme', 'livingdraft_starter_content', 20 );
