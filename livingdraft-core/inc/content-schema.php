<?php
/**
 * Structured data read straight out of the article.
 *
 * The FAQ and Rating blocks are ordinary markup, so nothing extra has to be
 * typed twice. This reads what is already on the page and tells Google about
 * it — which is how you win the question dropdowns and the star ratings in
 * search results.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pull the questions and answers out of a story.
 *
 * @param string $html Post content.
 * @return array
 */
function livingdraft_core_read_faqs( $html ) {
	if ( false === strpos( $html, 'ld-faq' ) ) {
		return array();
	}

	$faqs = array();

	if ( preg_match_all( '#<details[^>]*class="[^"]*ld-faq-item[^"]*"[^>]*>(.*?)</details>#is', $html, $blocks ) ) {
		foreach ( $blocks[1] as $block ) {
			if ( ! preg_match( '#<summary[^>]*>(.*?)</summary>#is', $block, $q ) ) {
				continue;
			}

			$question = trim( wp_strip_all_tags( $q[1] ) );
			$answer   = trim( wp_strip_all_tags( str_replace( $q[0], '', $block ) ) );

			if ( '' === $question || '' === $answer ) {
				continue;
			}

			$faqs[] = array(
				'q' => $question,
				'a' => $answer,
			);
		}
	}

	return $faqs;
}

/**
 * Pull a rating out of a story, if one has been given.
 *
 * @param string $html Post content.
 * @return array
 */
function livingdraft_core_read_rating( $html ) {
	if ( false === strpos( $html, 'ld-rating' ) ) {
		return array();
	}

	if ( ! preg_match( '#data-ld-score="([\d.]+)"#i', $html, $score ) ) {
		return array();
	}

	$best = 5;
	if ( preg_match( '#data-ld-best="([\d.]+)"#i', $html, $b ) ) {
		$best = (float) $b[1];
	}

	$subject = '';
	if ( preg_match( '#data-ld-subject="([^"]+)"#i', $html, $s ) ) {
		$subject = wp_strip_all_tags( $s[1] );
	}

	return array(
		'score'   => (float) $score[1],
		'best'    => $best ? $best : 5,
		'subject' => $subject ? $subject : get_the_title(),
	);
}

/**
 * Print whatever the article contains.
 */
function livingdraft_core_content_schema() {
	if ( ! is_singular( 'post' ) ) {
		return;
	}

	$html  = (string) get_post_field( 'post_content', get_the_ID() );
	$graph = array();

	$faqs = livingdraft_core_read_faqs( $html );
	if ( ! empty( $faqs ) ) {
		$items = array();
		foreach ( $faqs as $faq ) {
			$items[] = array(
				'@type'          => 'Question',
				'name'           => $faq['q'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $faq['a'],
				),
			);
		}

		$graph[] = array(
			'@type'      => 'FAQPage',
			'@id'        => get_permalink() . '#faq',
			'mainEntity' => $items,
		);
	}

	$rating = livingdraft_core_read_rating( $html );
	if ( ! empty( $rating ) ) {
		$graph[] = array(
			'@type'        => 'Review',
			'@id'          => get_permalink() . '#review',
			'itemReviewed' => array(
				'@type' => 'Thing',
				'name'  => $rating['subject'],
			),
			'reviewRating' => array(
				'@type'       => 'Rating',
				'ratingValue' => $rating['score'],
				'bestRating'  => $rating['best'],
				'worstRating' => 0,
			),
			'author'       => array(
				'@type' => 'Person',
				'name'  => get_the_author_meta( 'display_name' ),
			),
		);
	}

	if ( empty( $graph ) ) {
		return;
	}

	printf(
		'<script type="application/ld+json">%s</script>' . "\n",
		wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $graph,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	);
}
add_action( 'wp_head', 'livingdraft_core_content_schema', 7 );
