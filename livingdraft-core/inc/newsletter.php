<?php
/**
 * Newsletter subscribers.
 *
 * This stores the emails in YOUR database, not a third party's. Nothing is
 * sent from here — that is deliberate. Sending bulk email from a web server
 * gets you marked as spam within a week.
 *
 * The plan: collect here, export the list, and connect a sending service
 * (Brevo is the best fit for India — free to 300 emails a day, and it does
 * not require a credit card) when you are ready. The hook to do that is
 * already in place: livingdraft_subscriber_added.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A private post type, so the WordPress admin gives us a list screen, search,
 * sorting and pagination for nothing.
 */
function livingdraft_core_subscriber_type() {
	register_post_type(
		'ld_subscriber',
		array(
			'labels'          => array(
				'name'          => __( 'Subscribers', 'livingdraft-core' ),
				'singular_name' => __( 'Subscriber', 'livingdraft-core' ),
				'menu_name'     => __( 'Subscribers', 'livingdraft-core' ),
				'search_items'  => __( 'Search subscribers', 'livingdraft-core' ),
				'not_found'     => __( 'Nobody has subscribed yet.', 'livingdraft-core' ),
			),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => true,
			'menu_icon'       => 'dashicons-email-alt',
			'menu_position'   => 26,
			'capability_type' => 'post',
			'capabilities'    => array(
				'create_posts' => 'do_not_allow', // Only the form may add them.
			),
			'map_meta_cap'    => true,
			'supports'        => array( 'title' ),
			'has_archive'     => false,
			'rewrite'         => false,
			'show_in_rest'    => false,
		)
	);
}
add_action( 'init', 'livingdraft_core_subscriber_type' );

/**
 * Handle the form.
 */
function livingdraft_core_subscribe() {
	check_ajax_referer( 'livingdraft_subscribe', 'nonce' );

	// A hidden field a human never fills in. Most bots fill everything.
	if ( ! empty( $_POST['website'] ) ) {
		wp_send_json_success( array( 'message' => __( 'Thank you.', 'livingdraft-core' ) ) );
	}

	$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

	if ( ! is_email( $email ) ) {
		wp_send_json_error( array( 'message' => __( 'That address does not look right.', 'livingdraft-core' ) ) );
	}

	// Rate limit by address, so nobody can flood the table.
	$raw_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$who    = function_exists( 'wp_privacy_anonymize_ip' ) ? wp_privacy_anonymize_ip( $raw_ip ) : $raw_ip;
	$gate   = 'ld_sub_' . md5( $who );

	if ( (int) get_transient( $gate ) > 5 ) {
		wp_send_json_error( array( 'message' => __( 'Too many attempts. Try again later.', 'livingdraft-core' ) ) );
	}
	set_transient( $gate, (int) get_transient( $gate ) + 1, HOUR_IN_SECONDS );

	$existing = get_posts(
		array(
			'post_type'      => 'ld_subscriber',
			'post_status'    => 'any',
			'title'          => $email,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	if ( ! empty( $existing ) ) {
		wp_send_json_success( array( 'message' => __( 'You are already on the list.', 'livingdraft-core' ) ) );
	}

	$id = wp_insert_post(
		array(
			'post_type'   => 'ld_subscriber',
			'post_title'  => $email,
			'post_status' => 'publish',
		),
		true
	);

	if ( is_wp_error( $id ) ) {
		wp_send_json_error( array( 'message' => __( 'Something went wrong. Please try again.', 'livingdraft-core' ) ) );
	}

	update_post_meta( $id, '_ld_source', esc_url_raw( wp_get_referer() ) );

	/**
	 * Fires once an address is stored. Hook a sending service on here later.
	 *
	 * @param string $email The address.
	 * @param int    $id    The subscriber post ID.
	 */
	do_action( 'livingdraft_subscriber_added', $email, $id );

	/**
	 * Filter the message the form shows after a successful signup.
	 *
	 * Added in 4.3.0 because the honest wording depends on something this
	 * function no longer decides. With double opt-in switched on, "You are
	 * on the list" is false — the address is pending, and a reader told
	 * they are subscribed will not go and look for the confirmation email.
	 *
	 * @since 4.3.0
	 * @param array  $response Payload sent to the form.
	 * @param string $email    The address.
	 * @param int    $id       Subscriber post id.
	 */
	$response = apply_filters(
		'livingdraft_subscribe_response',
		array( 'message' => __( 'You are on the list. Thank you.', 'livingdraft-core' ) ),
		$email,
		$id
	);

	wp_send_json_success( $response );
}
add_action( 'wp_ajax_livingdraft_subscribe', 'livingdraft_core_subscribe' );
add_action( 'wp_ajax_nopriv_livingdraft_subscribe', 'livingdraft_core_subscribe' );

/**
 * Columns on the subscriber list.
 *
 * @param array $cols Columns.
 * @return array
 */
function livingdraft_core_subscriber_columns( $cols ) {
	return array(
		'cb'        => isset( $cols['cb'] ) ? $cols['cb'] : '',
		'title'     => __( 'Email', 'livingdraft-core' ),
		'ld_source' => __( 'Signed up from', 'livingdraft-core' ),
		'date'      => __( 'When', 'livingdraft-core' ),
	);
}
add_filter( 'manage_ld_subscriber_posts_columns', 'livingdraft_core_subscriber_columns' );

/**
 * @param string $col     Column.
 * @param int    $post_id Post ID.
 */
function livingdraft_core_subscriber_cell( $col, $post_id ) {
	if ( 'ld_source' === $col ) {
		echo esc_html( (string) get_post_meta( $post_id, '_ld_source', true ) );
	}
}
add_action( 'manage_ld_subscriber_posts_custom_column', 'livingdraft_core_subscriber_cell', 10, 2 );

/**
 * An export button above the list.
 */
function livingdraft_core_export_button() {
	$screen = get_current_screen();

	if ( ! $screen || 'edit-ld_subscriber' !== $screen->id ) {
		return;
	}

	$url = wp_nonce_url(
		admin_url( 'edit.php?post_type=ld_subscriber&ld_export=1' ),
		'livingdraft_export',
		'ld_export_nonce'
	);

	printf(
		'<a href="%s" class="button button-primary" style="margin:8px 0">%s</a>',
		esc_url( $url ),
		esc_html__( 'Download all as CSV', 'livingdraft-core' )
	);
}
add_action( 'admin_notices', 'livingdraft_core_export_button' );

/**
 * Send the CSV.
 */
function livingdraft_core_export() {
	if ( ! isset( $_GET['ld_export'], $_GET['ld_export_nonce'] ) ) {
		return;
	}

	$nonce = sanitize_text_field( wp_unslash( $_GET['ld_export_nonce'] ) );
	if ( ! wp_verify_nonce( $nonce, 'livingdraft_export' ) || ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'livingdraft-core' ) );
	}

	$rows = get_posts(
		array(
			'post_type'      => 'ld_subscriber',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'ASC',
		)
	);

	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=subscribers-' . gmdate( 'Y-m-d' ) . '.csv' );

	$out = fopen( 'php://output', 'w' );

	/*
	 * The $escape argument is passed explicitly. PHP 8.4 deprecates relying
	 * on its default, and PHP 8.5 will change the default outright, so an
	 * export that works today would start emitting notices onto the top of
	 * the downloaded file. Empty string is also the correct value: it turns
	 * off PHP's non-standard backslash escaping and produces CSV that matches
	 * RFC 4180, which is what Excel and Google Sheets actually expect.
	 */
	fputcsv( $out, array( 'email', 'signed_up', 'source' ), ',', '"', '' );

	foreach ( $rows as $row ) {
		fputcsv(
			$out,
			array_map(
				'livingdraft_csv_safe',
				array(
					$row->post_title,
					$row->post_date,
					(string) get_post_meta( $row->ID, '_ld_source', true ),
				)
			),
			',',
			'"',
			''
		);
	}

	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}
add_action( 'admin_init', 'livingdraft_core_export' );

/**
 * Neutralise CSV formula-injection. sanitize_email allows '=' in the local
 * part, so a subscriber address like =1+1@example.com becomes an active Excel
 * formula when the export is opened. Prefix any cell whose first character
 * would trigger a formula with a single quote — spreadsheets treat that as
 * "this is literal text", and the leading quote is not shown.
 *
 * @param string $value Raw cell value.
 * @return string
 */
/*
 * Guarded because redirects-admin.php defines the same helper. Today
 * newsletter.php loads first (bootstrap line 92) and redirects-admin.php
 * second, so its own function_exists check catches the collision and nothing
 * breaks. But only one of the two was guarded, which meant the site stayed
 * up purely because of load order — reorder the requires, or make either
 * file load conditionally, and WordPress fatals with "cannot redeclare".
 * Guarding both sides removes the dependency on something nobody would think
 * to check.
 */
if ( ! function_exists( 'livingdraft_csv_safe' ) ) {
	function livingdraft_csv_safe( $value ) {
		$value = (string) $value;

		if ( '' === $value ) {
			return $value;
		}

		if ( in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}
}


/**
 * How many people are on the list.
 *
 * @return int
 */
function livingdraft_subscriber_count() {
	$counts = wp_count_posts( 'ld_subscriber' );
	return isset( $counts->publish ) ? (int) $counts->publish : 0;
}
