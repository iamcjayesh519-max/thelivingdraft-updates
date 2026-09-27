<?php
/**
 * Analytics — the screens.
 *
 *   The Living Draft → Analytics    the full report
 *   Dashboard widget                today / week / month / year at a glance
 *   Post editor box                 readership for the story being edited
 *   Settings → Analytics            what is counted, and for how long
 *
 * @package LivingDraftCore
 * @since   4.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ==================================================================
 * 1. MENU
 * ================================================================== */

function livingdraft_an_admin_menu() {
	add_submenu_page(
		'livingdraft-overview',
		__( 'Analytics', 'livingdraft-core' ),
		__( 'Analytics', 'livingdraft-core' ),
		'edit_posts',
		'livingdraft-analytics',
		'livingdraft_an_render_page',
		1
	);
}
add_action( 'admin_menu', 'livingdraft_an_admin_menu', 11 );

/* ==================================================================
 * 2. FORMATTING HELPERS
 * ================================================================== */

/**
 * @param int $secs Seconds.
 * @return string
 */
function livingdraft_an_fmt_time( $secs ) {
	$secs = (int) $secs;
	if ( $secs < 60 ) {
		return $secs . 's';
	}
	$m = intdiv( $secs, 60 );
	$s = $secs % 60;
	if ( $m < 60 ) {
		return $m . 'm ' . sprintf( '%02d', $s ) . 's';
	}
	return intdiv( $m, 60 ) . 'h ' . sprintf( '%02d', $m % 60 ) . 'm';
}

/**
 * Change against a previous value, as a small labelled delta.
 *
 * @param int|float $now  Current.
 * @param int|float $then Previous.
 * @param string    $vs   What it is compared with.
 * @return string HTML.
 */
function livingdraft_an_delta( $now, $then, $vs ) {
	if ( ! $then ) {
		return $now ? '<span class="tld-num-delta">' . esc_html( $vs ) . ': —</span>' : '';
	}
	$pct   = round( 100 * ( $now - $then ) / $then );
	$class = $pct > 0 ? 'is-up' : ( $pct < 0 ? 'is-down' : '' );
	$arrow = $pct > 0 ? '▲' : ( $pct < 0 ? '▼' : '•' );
	return sprintf(
		'<span class="tld-num-delta %s">%s %s%% <span style="color:var(--tld-ink-4)">%s</span></span>',
		esc_attr( $class ),
		esc_html( $arrow ),
		esc_html( number_format_i18n( abs( $pct ) ) ),
		esc_html( $vs )
	);
}

/**
 * Country code to flag + code.
 *
 * @param string $cc ISO code.
 * @return string
 */
function livingdraft_an_flag( $cc ) {
	$cc = strtoupper( (string) $cc );
	if ( ! preg_match( '/^[A-Z]{2}$/', $cc ) || ! function_exists( 'mb_chr' ) ) {
		return $cc;
	}
	return mb_chr( 0x1F1E6 + ord( $cc[0] ) - 65 ) . mb_chr( 0x1F1E6 + ord( $cc[1] ) - 65 ) . ' ' . $cc;
}

/**
 * The report range chosen in the query string.
 *
 * @return array{key:string,from:string,to:string,grain:string,label:string,prev_from:string,prev_to:string}
 */
function livingdraft_an_admin_range() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$key   = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : '30d';
	$grain = isset( $_GET['grain'] ) ? sanitize_key( wp_unslash( $_GET['grain'] ) ) : '';
	$cf    = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
	$ct    = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
	// phpcs:enable

	$now   = current_time( 'timestamp' );
	$today = gmdate( 'Y-m-d', $now );

	switch ( $key ) {
		case 'today':
			$from  = $today;
			$to    = $today;
			$label = __( 'Today', 'livingdraft-core' );
			$def   = 'day';
			break;
		case '7d':
			$from  = gmdate( 'Y-m-d', $now - 6 * DAY_IN_SECONDS );
			$to    = $today;
			$label = __( 'Last 7 days', 'livingdraft-core' );
			$def   = 'day';
			break;
		case '90d':
			$from  = gmdate( 'Y-m-d', $now - 89 * DAY_IN_SECONDS );
			$to    = $today;
			$label = __( 'Last 90 days', 'livingdraft-core' );
			$def   = 'week';
			break;
		case 'month':
			$from  = gmdate( 'Y-m-01', $now );
			$to    = $today;
			$label = __( 'This month', 'livingdraft-core' );
			$def   = 'day';
			break;
		case 'lastmonth':
			$pm    = strtotime( '-1 month', strtotime( gmdate( 'Y-m-01', $now ) ) );
			$from  = gmdate( 'Y-m-01', $pm );
			$to    = gmdate( 'Y-m-t', $pm );
			$label = __( 'Last month', 'livingdraft-core' );
			$def   = 'day';
			break;
		case 'year':
			$from  = gmdate( 'Y-01-01', $now );
			$to    = $today;
			$label = __( 'This year', 'livingdraft-core' );
			$def   = 'month';
			break;
		case '12m':
			$from  = gmdate( 'Y-m-01', strtotime( '-11 months', strtotime( gmdate( 'Y-m-01', $now ) ) ) );
			$to    = $today;
			$label = __( 'Last 12 months', 'livingdraft-core' );
			$def   = 'month';
			break;
		case 'all':
			global $wpdb;
			$s_tbl = livingdraft_an_stats_table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$old   = (string) $wpdb->get_var( "SELECT MIN(pkey) FROM {$s_tbl} WHERE period = 'd'" );
			$since = (string) livingdraft_an_all_time()['since'];
			$from  = ( $old && ( ! $since || $old < $since ) ) ? $old : $since;
			$from  = $from ? $from : $today;
			$to    = $today;
			$label = __( 'All time', 'livingdraft-core' );
			$def   = 'year';
			break;
		case 'custom':
			$valid = static function ( $d ) {
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d : '';
			};
			$from  = $valid( $cf ) ? $cf : gmdate( 'Y-m-d', $now - 29 * DAY_IN_SECONDS );
			$to    = $valid( $ct ) ? $ct : $today;
			if ( $from > $to ) {
				list( $from, $to ) = array( $to, $from );
			}
			$label = date_i18n( 'j M Y', strtotime( $from ) ) . ' – ' . date_i18n( 'j M Y', strtotime( $to ) );
			$days  = ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS;
			$def   = $days > 400 ? 'month' : ( $days > 92 ? 'week' : 'day' );
			break;
		case '30d':
		default:
			$key   = '30d';
			$from  = gmdate( 'Y-m-d', $now - 29 * DAY_IN_SECONDS );
			$to    = $today;
			$label = __( 'Last 30 days', 'livingdraft-core' );
			$def   = 'day';
	}

	$grain = in_array( $grain, array( 'day', 'week', 'month', 'year' ), true ) ? $grain : $def;

	// Same-length period immediately before, for comparison.
	$len       = (int) round( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1;
	$prev_to   = gmdate( 'Y-m-d', strtotime( $from ) - DAY_IN_SECONDS );
	$prev_from = gmdate( 'Y-m-d', strtotime( $prev_to ) - ( $len - 1 ) * DAY_IN_SECONDS );

	return array(
		'key'       => $key,
		'from'      => $from,
		'to'        => $to,
		'grain'     => $grain,
		'label'     => $label,
		'prev_from' => $prev_from,
		'prev_to'   => $prev_to,
	);
}

/**
 * Datetime bounds for a date range. "Today" ends now, not at midnight.
 *
 * @param string $from Y-m-d.
 * @param string $to   Y-m-d.
 * @return array{0:string,1:string}
 */
function livingdraft_an_bounds( $from, $to ) {
	$end = ( $to >= current_time( 'Y-m-d' ) ) ? current_time( 'mysql' ) : $to . ' 23:59:59';
	return array( $from . ' 00:00:00', $end );
}

/* ==================================================================
 * 3. THE CHART — server-rendered SVG, no library
 * ================================================================== */

/**
 * @param array[] $series From livingdraft_an_series().
 * @return string SVG markup.
 */
function livingdraft_an_chart_svg( $series ) {
	$n = count( $series );
	if ( ! $n ) {
		return '';
	}

	$w  = 1000;
	$h  = 260;
	$pl = 48;
	$pr = 12;
	$pt = 14;
	$pb = 30;
	$cw = $w - $pl - $pr;
	$ch = $h - $pt - $pb;

	$max = 1;
	foreach ( $series as $p ) {
		$max = max( $max, (int) $p['pageviews'], (int) $p['visitors'] );
	}
	// Round the axis up to a tidy number.
	$mag  = pow( 10, max( 0, (int) floor( log10( $max ) ) ) );
	$max  = (int) ( ceil( $max / $mag ) * $mag );
	$step = $cw / $n;
	$bw   = max( 2, min( 38, $step * 0.62 ) );

	$out  = '<svg class="tld-an-chart" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img" aria-label="' . esc_attr__( 'Pageviews and visitors over time', 'livingdraft-core' ) . '">';

	for ( $i = 0; $i <= 4; $i++ ) {
		$y    = $pt + $ch - ( $ch * $i / 4 );
		$val  = (int) round( $max * $i / 4 );
		$out .= '<line x1="' . $pl . '" x2="' . ( $w - $pr ) . '" y1="' . round( $y, 1 ) . '" y2="' . round( $y, 1 ) . '" class="g"/>';
		$out .= '<text x="' . ( $pl - 8 ) . '" y="' . round( $y + 4, 1 ) . '" class="yl">' . esc_html( number_format_i18n( $val ) ) . '</text>';
	}

	$every = max( 1, (int) ceil( $n / 12 ) );
	$pts   = array();

	foreach ( $series as $i => $p ) {
		$cx = $pl + $step * $i + $step / 2;
		$bh = $ch * ( (int) $p['pageviews'] / $max );
		$vy = $pt + $ch - $ch * ( (int) $p['visitors'] / $max );

		$tip = sprintf(
			/* translators: 1: period label, 2: pageviews, 3: visitors, 4: sessions */
			__( '%1$s — %2$s pageviews, %3$s visitors, %4$s sessions', 'livingdraft-core' ),
			$p['label'],
			number_format_i18n( $p['pageviews'] ),
			number_format_i18n( $p['visitors'] ),
			number_format_i18n( $p['sessions'] )
		);

		$out  .= '<g><title>' . esc_html( $tip ) . '</title>';
		$out  .= '<rect x="' . round( $cx - $bw / 2, 1 ) . '" y="' . round( $pt + $ch - $bh, 1 ) . '" width="' . round( $bw, 1 ) . '" height="' . round( max( 0, $bh ), 1 ) . '" class="b"/>';
		$out  .= '<rect x="' . round( $pl + $step * $i, 1 ) . '" y="' . $pt . '" width="' . round( $step, 1 ) . '" height="' . $ch . '" class="hit"/></g>';
		$pts[] = round( $cx, 1 ) . ',' . round( $vy, 1 );

		if ( 0 === $i % $every ) {
			$out .= '<text x="' . round( $cx, 1 ) . '" y="' . ( $h - 8 ) . '" class="xl">' . esc_html( $p['label'] ) . '</text>';
		}
	}

	$out .= '<polyline points="' . implode( ' ', $pts ) . '" class="l"/>';
	if ( $n <= 62 ) {
		foreach ( $pts as $pt_xy ) {
			list( $x, $y ) = explode( ',', $pt_xy );
			$out .= '<circle cx="' . $x . '" cy="' . $y . '" r="3" class="d"/>';
		}
	}
	$out .= '</svg>';

	return $out;
}

/* ==================================================================
 * 4. THE PAGE
 * ================================================================== */

/**
 * One breakdown table.
 *
 * @param string  $title   Heading.
 * @param string  $eyebrow Eyebrow.
 * @param array   $rows    Rows.
 * @param array   $cols    key => label; first key is the label column.
 * @param string  $empty   Empty text.
 * @param int     $total   For share bars; 0 for none.
 * @param string  $bar_key Column the bar is drawn from.
 */
function livingdraft_an_table_card( $title, $eyebrow, $rows, $cols, $empty = '', $total = 0, $bar_key = '' ) {
	?>
	<div class="tld-card tld-an-card">
		<div class="tld-card-header">
			<div>
				<span class="tld-card-eyebrow"><?php echo esc_html( $eyebrow ); ?></span>
				<h2 class="tld-card-title"><?php echo esc_html( $title ); ?></h2>
			</div>
		</div>
		<?php if ( empty( $rows ) ) : ?>
			<p class="tld-help"><?php echo esc_html( $empty ? $empty : __( 'Nothing recorded in this period.', 'livingdraft-core' ) ); ?></p>
		<?php else : ?>
			<div class="tld-table-scroll">
				<table class="tld-table tld-an-table">
					<thead><tr>
						<?php foreach ( $cols as $label ) : ?>
							<th><?php echo esc_html( $label ); ?></th>
						<?php endforeach; ?>
					</tr></thead>
					<tbody>
						<?php foreach ( $rows as $r ) : ?>
							<tr>
								<?php
								$first = true;
								foreach ( $cols as $k => $label ) :
									$v = $r[ $k ] ?? '';
									if ( $first ) :
										$first = false;
										$pct   = ( $total && $bar_key ) ? min( 100, 100 * (int) ( $r[ $bar_key ] ?? 0 ) / $total ) : 0;
										?>
										<td class="tld-an-label">
											<?php if ( $pct ) : ?><span class="tld-an-bar" style="width:<?php echo esc_attr( round( $pct, 1 ) ); ?>%"></span><?php endif; ?>
											<span class="tld-an-text"><?php echo wp_kses_post( $v ); ?></span>
										</td>
									<?php else : ?>
										<td class="tld-an-n"><?php echo esc_html( $v ); ?></td>
										<?php
									endif;
								endforeach;
								?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Render the Analytics screen.
 */
function livingdraft_an_render_page() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'livingdraft-core' ) );
	}

	$settings = livingdraft_an_settings();
	$range    = livingdraft_an_admin_range();

	$export = wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'ld_an_export',
				'from'   => $range['from'],
				'to'     => $range['to'],
			),
			admin_url( 'admin-post.php' )
		),
		'ld_an_export'
	);

	livingdraft_admin_render_header(
		array(
			'eyebrow' => __( 'The Living Draft Core · Analytics', 'livingdraft-core' ),
			'title'   => __( 'Readership', 'livingdraft-core' ),
			'desc'    => __( 'Every visit to this site, counted on your own server. Cache-proof, cookie-free, bots excluded.', 'livingdraft-core' ),
			'actions' => array_filter(
				array(
					array(
						'label' => __( 'Export CSV', 'livingdraft-core' ),
						'url'   => $export,
					),
					current_user_can( 'manage_options' ) ? array(
						'label' => __( 'Settings', 'livingdraft-core' ),
						'url'   => admin_url( 'admin.php?page=livingdraft-settings&section=analytics' ),
					) : null,
				)
			),
		)
	);

	if ( ! livingdraft_an_ready() ) {
		echo '<div class="tld-notice"><p>' . esc_html__( 'The analytics tables are being created. Reload this page in a moment.', 'livingdraft-core' ) . '</p></div>';
		livingdraft_admin_render_footer();
		return;
	}

	if ( ! $settings['enabled'] ) {
		echo '<div class="tld-notice"><p>' . esc_html__( 'Tracking is switched off in Settings → Analytics. The figures below stop at the moment it was switched off.', 'livingdraft-core' ) . '</p></div>';
	}

	$live = livingdraft_an_realtime( 5 );

	// ---- At a glance ----------------------------------------------------
	$periods = array( 'today', 'yesterday', 'week', 'month', 'year' );
	?>
	<div class="tld-section-rule">
		<h2><?php esc_html_e( 'At a glance', 'livingdraft-core' ); ?></h2>
		<span class="tld-section-eyebrow">
			<span class="tld-an-live-dot"></span>
			<?php
			printf(
				/* translators: %s: number of readers */
				esc_html( _n( '%s reader on the site right now', '%s readers on the site right now', $live['visitors'], 'livingdraft-core' ) ),
				'<strong>' . esc_html( number_format_i18n( $live['visitors'] ) ) . '</strong>'
			);
			?>
		</span>
	</div>

	<div class="tld-numbers tld-an-glance">
		<?php
		foreach ( $periods as $pk ) :
			$p    = livingdraft_an_named_period( $pk );
			$cur  = livingdraft_an_totals( $p['from'], $p['to'] );
			$prev = livingdraft_an_totals( $p['prev_from'], $p['prev_to'] );
			$vs   = array(
				'today'     => __( 'vs yesterday so far', 'livingdraft-core' ),
				'yesterday' => __( 'vs day before', 'livingdraft-core' ),
				'week'      => __( 'vs last week so far', 'livingdraft-core' ),
				'month'     => __( 'vs last month so far', 'livingdraft-core' ),
				'year'      => __( 'vs last year so far', 'livingdraft-core' ),
			)[ $pk ];
			?>
			<div class="tld-num">
				<p class="tld-num-label"><?php echo esc_html( $p['label'] ); ?></p>
				<p class="tld-num-value"><?php echo esc_html( number_format_i18n( $cur['visitors'] ) ); ?></p>
				<p class="tld-an-sub">
					<?php
					printf(
						/* translators: 1: pageviews, 2: sessions */
						esc_html__( 'visitors · %1$s pageviews · %2$s sessions', 'livingdraft-core' ),
						esc_html( number_format_i18n( $cur['pageviews'] ) ),
						esc_html( number_format_i18n( $cur['sessions'] ) )
					);
					?>
				</p>
				<?php echo livingdraft_an_delta( $cur['visitors'], $prev['visitors'], $vs ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>
		<?php endforeach; ?>
		<?php $all = livingdraft_an_all_time(); ?>
		<div class="tld-num">
			<p class="tld-num-label"><?php esc_html_e( 'All time', 'livingdraft-core' ); ?></p>
			<p class="tld-num-value"><?php echo esc_html( number_format_i18n( $all['pageviews'] ) ); ?></p>
			<p class="tld-an-sub"><?php esc_html_e( 'pageviews', 'livingdraft-core' ); ?></p>
			<p class="tld-num-delta">
				<?php
				echo $all['since']
					? esc_html( sprintf( /* translators: %s: date */ __( 'since %s', 'livingdraft-core' ), date_i18n( 'j M Y', strtotime( $all['since'] ) ) ) )
					: esc_html__( 'counting starts with the next visit', 'livingdraft-core' );
				?>
			</p>
		</div>
	</div>

	<?php
	// ---- Range picker ---------------------------------------------------
	$ranges = array(
		'today'     => __( 'Today', 'livingdraft-core' ),
		'7d'        => __( '7 days', 'livingdraft-core' ),
		'30d'       => __( '30 days', 'livingdraft-core' ),
		'90d'       => __( '90 days', 'livingdraft-core' ),
		'month'     => __( 'This month', 'livingdraft-core' ),
		'lastmonth' => __( 'Last month', 'livingdraft-core' ),
		'year'      => __( 'This year', 'livingdraft-core' ),
		'12m'       => __( '12 months', 'livingdraft-core' ),
		'all'       => __( 'All time', 'livingdraft-core' ),
	);
	$grains = array(
		'day'   => __( 'Daily', 'livingdraft-core' ),
		'week'  => __( 'Weekly', 'livingdraft-core' ),
		'month' => __( 'Monthly', 'livingdraft-core' ),
		'year'  => __( 'Yearly', 'livingdraft-core' ),
	);
	$base = admin_url( 'admin.php?page=livingdraft-analytics' );
	?>
	<div class="tld-an-toolbar">
		<nav class="tld-subtabs tld-an-ranges">
			<?php foreach ( $ranges as $rk => $rl ) : ?>
				<a class="<?php echo $rk === $range['key'] ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'range', $rk, $base ) ); ?>"><?php echo esc_html( $rl ); ?></a>
			<?php endforeach; ?>
		</nav>
		<form method="get" class="tld-an-custom">
			<input type="hidden" name="page" value="livingdraft-analytics">
			<input type="hidden" name="range" value="custom">
			<input type="date" name="from" class="tld-input" value="<?php echo esc_attr( $range['from'] ); ?>" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
			<span>–</span>
			<input type="date" name="to" class="tld-input" value="<?php echo esc_attr( $range['to'] ); ?>" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
			<select name="grain" class="tld-select">
				<?php foreach ( $grains as $gk => $gl ) : ?>
					<option value="<?php echo esc_attr( $gk ); ?>" <?php selected( $gk, $range['grain'] ); ?>><?php echo esc_html( $gl ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="tld-btn" type="submit"><?php esc_html_e( 'Apply', 'livingdraft-core' ); ?></button>
		</form>
	</div>

	<?php
	list( $from_dt, $to_dt )   = livingdraft_an_bounds( $range['from'], $range['to'] );
	list( $pfrom_dt, $pto_dt ) = livingdraft_an_bounds( $range['prev_from'], $range['prev_to'] );

	$tot    = livingdraft_an_totals( $from_dt, $to_dt );
	$ptot   = livingdraft_an_totals( $pfrom_dt, $pto_dt );
	$series = livingdraft_an_series( $range['grain'], $range['from'], $range['to'] );
	$vs     = __( 'vs previous period', 'livingdraft-core' );
	?>

	<div class="tld-card tld-an-chart-card">
		<div class="tld-card-header">
			<div>
				<span class="tld-card-eyebrow"><?php echo esc_html( $range['label'] . ' · ' . $grains[ $range['grain'] ] ); ?></span>
				<h2 class="tld-card-title"><?php esc_html_e( 'Traffic over time', 'livingdraft-core' ); ?></h2>
			</div>
			<div class="tld-an-legend">
				<span><i class="k-b"></i><?php esc_html_e( 'Pageviews', 'livingdraft-core' ); ?></span>
				<span><i class="k-l"></i><?php esc_html_e( 'Visitors', 'livingdraft-core' ); ?></span>
				<?php foreach ( array( 'day', 'week', 'month', 'year' ) as $gk ) : ?>
					<a class="tld-an-grain <?php echo $gk === $range['grain'] ? 'is-active' : ''; ?>"
						href="<?php echo esc_url( add_query_arg( array( 'range' => $range['key'], 'grain' => $gk, 'from' => $range['from'], 'to' => $range['to'] ), $base ) ); ?>">
						<?php echo esc_html( $grains[ $gk ] ); ?>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		<div class="tld-an-chart-wrap"><?php echo livingdraft_an_chart_svg( $series ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?></div>
	</div>

	<div class="tld-numbers tld-an-range">
		<?php
		$figs = array(
			array( __( 'Visitors', 'livingdraft-core' ), number_format_i18n( $tot['visitors'] ), $tot['visitors'], $ptot['visitors'] ),
			array( __( 'Pageviews', 'livingdraft-core' ), number_format_i18n( $tot['pageviews'] ), $tot['pageviews'], $ptot['pageviews'] ),
			array( __( 'Sessions', 'livingdraft-core' ), number_format_i18n( $tot['sessions'] ), $tot['sessions'], $ptot['sessions'] ),
			array( __( 'Pages / session', 'livingdraft-core' ), number_format_i18n( $tot['pages_per_session'], 2 ), $tot['pages_per_session'], $ptot['pages_per_session'] ),
			array( __( 'Engaged / session', 'livingdraft-core' ), livingdraft_an_fmt_time( $tot['avg_engaged'] ), $tot['avg_engaged'], $ptot['avg_engaged'] ),
			array( __( 'Bounce rate', 'livingdraft-core' ), number_format_i18n( $tot['bounce_rate'], 1 ) . '%', null, null ),
			array( __( 'New visitors', 'livingdraft-core' ), number_format_i18n( $tot['new_visitors'] ), $tot['new_visitors'], $ptot['new_visitors'] ),
		);
		foreach ( $figs as $f ) :
			?>
			<div class="tld-num">
				<p class="tld-num-label"><?php echo esc_html( $f[0] ); ?></p>
				<p class="tld-num-value"><?php echo esc_html( $f[1] ); ?></p>
				<?php
				if ( null !== $f[2] ) {
					echo livingdraft_an_delta( $f[2], $f[3], $vs ); // phpcs:ignore WordPress.Security.EscapeOutput
				} else {
					$bd = $tot['bounce_rate'] - $ptot['bounce_rate'];
					if ( $ptot['sessions'] ) {
						printf(
							'<span class="tld-num-delta %s">%s %s pts <span style="color:var(--tld-ink-4)">%s</span></span>',
							esc_attr( $bd > 0 ? 'is-down' : ( $bd < 0 ? 'is-up' : '' ) ),
							esc_html( $bd > 0 ? '▲' : ( $bd < 0 ? '▼' : '•' ) ),
							esc_html( number_format_i18n( abs( $bd ), 1 ) ),
							esc_html( $vs )
						);
					}
				}
				?>
			</div>
		<?php endforeach; ?>
	</div>

	<?php livingdraft_an_render_ai_card( $range ); ?>

	<?php
	// ---- Breakdowns -----------------------------------------------------
	$pages = array();
	foreach ( livingdraft_an_breakdown( 'pages', $from_dt, $to_dt, 20 ) as $r ) {
		$title   = livingdraft_an_page_title( (int) $r['post_id'], (string) $r['path'] );
		$link    = home_url( $r['path'] );
		$edit    = $r['post_id'] ? get_edit_post_link( (int) $r['post_id'] ) : '';
		$pages[] = array(
			'label'       => '<a href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . esc_html( $title ) . '</a>'
				. ( $edit ? ' <a class="tld-an-edit" href="' . esc_url( $edit ) . '">' . esc_html__( 'edit', 'livingdraft-core' ) . '</a>' : '' )
				. '<br><span class="tld-an-path">' . esc_html( $r['path'] ) . '</span>',
			'pageviews'   => number_format_i18n( $r['pageviews'] ),
			'pv'          => (int) $r['pageviews'],
			'visitors'    => number_format_i18n( $r['visitors'] ),
			'avg_engaged' => $r['avg_engaged'] ? livingdraft_an_fmt_time( $r['avg_engaged'] ) : '—',
			'avg_scroll'  => $r['avg_scroll'] ? $r['avg_scroll'] . '%' : '—',
		);
	}

	$channels_raw = livingdraft_an_breakdown( 'channels', $from_dt, $to_dt, 20 );
	$labels       = livingdraft_an_channels();
	$channels     = array();
	$ch_total     = 0;
	foreach ( $channels_raw as $r ) {
		$ch_total += (int) $r['sessions'];
	}
	foreach ( $channels_raw as $r ) {
		$channels[] = array(
			'label'    => esc_html( $labels[ $r['channel'] ] ?? ucfirst( $r['channel'] ) ),
			'sessions' => number_format_i18n( $r['sessions'] ),
			's'        => (int) $r['sessions'],
			'share'    => $ch_total ? round( 100 * $r['sessions'] / $ch_total, 1 ) . '%' : '',
			'visitors' => number_format_i18n( $r['visitors'] ),
		);
	}

	$refs = array();
	foreach ( livingdraft_an_breakdown( 'referrers', $from_dt, $to_dt, 15 ) as $r ) {
		$refs[] = array(
			'label'    => esc_html( $r['ref_host'] ),
			'sessions' => number_format_i18n( $r['sessions'] ),
			's'        => (int) $r['sessions'],
			'visitors' => number_format_i18n( $r['visitors'] ),
		);
	}

	$camps = array();
	foreach ( livingdraft_an_breakdown( 'campaigns', $from_dt, $to_dt, 15 ) as $r ) {
		$camps[] = array(
			'label'    => esc_html( ( $r['utm_campaign'] ? $r['utm_campaign'] : '(no campaign)' ) ) . '<br><span class="tld-an-path">' . esc_html( trim( $r['utm_source'] . ' / ' . $r['utm_medium'], ' /' ) ) . '</span>',
			'sessions' => number_format_i18n( $r['sessions'] ),
			'visitors' => number_format_i18n( $r['visitors'] ),
		);
	}

	$simple = static function ( $dim, $key, $fmt = null ) use ( $from_dt, $to_dt, $tot ) {
		$rows = array();
		foreach ( livingdraft_an_breakdown( $dim, $from_dt, $to_dt, 12 ) as $r ) {
			$label  = $fmt ? call_user_func( $fmt, $r[ $key ] ) : ucfirst( (string) $r[ $key ] );
			$rows[] = array(
				'label'     => esc_html( $label ),
				'visitors'  => number_format_i18n( $r['visitors'] ),
				'v'         => (int) $r['visitors'],
				'share'     => $tot['visitors'] ? round( 100 * $r['visitors'] / $tot['visitors'], 1 ) . '%' : '',
				'pageviews' => number_format_i18n( $r['pageviews'] ),
			);
		}
		return $rows;
	};

	$pv_max = 0;
	foreach ( $pages as $p ) {
		$pv_max = max( $pv_max, $p['pv'] );
	}
	?>

	<div class="tld-section-rule">
		<h2><?php esc_html_e( 'What was read', 'livingdraft-core' ); ?></h2>
		<span class="tld-section-eyebrow"><?php echo esc_html( $range['label'] ); ?></span>
	</div>

	<?php
	livingdraft_an_table_card(
		__( 'Top pages', 'livingdraft-core' ),
		__( 'By pageviews', 'livingdraft-core' ),
		$pages,
		array(
			'label'       => __( 'Page', 'livingdraft-core' ),
			'pageviews'   => __( 'Pageviews', 'livingdraft-core' ),
			'visitors'    => __( 'Visitors', 'livingdraft-core' ),
			'avg_engaged' => __( 'Avg. engaged', 'livingdraft-core' ),
			'avg_scroll'  => __( 'Avg. scroll', 'livingdraft-core' ),
		),
		'',
		$pv_max,
		'pv'
	);
	?>

	<div class="tld-section-rule">
		<h2><?php esc_html_e( 'Where readers came from', 'livingdraft-core' ); ?></h2>
		<span class="tld-section-eyebrow"><?php esc_html_e( 'Counted once per session', 'livingdraft-core' ); ?></span>
	</div>

	<div class="tld-an-grid">
		<?php
		livingdraft_an_table_card( __( 'Channels', 'livingdraft-core' ), __( 'Sessions', 'livingdraft-core' ), $channels, array( 'label' => __( 'Channel', 'livingdraft-core' ), 'sessions' => __( 'Sessions', 'livingdraft-core' ), 'share' => __( 'Share', 'livingdraft-core' ), 'visitors' => __( 'Visitors', 'livingdraft-core' ) ), '', $ch_total, 's' );
		livingdraft_an_table_card( __( 'Referring sites', 'livingdraft-core' ), __( 'Sessions', 'livingdraft-core' ), $refs, array( 'label' => __( 'Site', 'livingdraft-core' ), 'sessions' => __( 'Sessions', 'livingdraft-core' ), 'visitors' => __( 'Visitors', 'livingdraft-core' ) ), __( 'No referring sites yet — readers typed the address, used a bookmark, or came from an app that hides the referrer.', 'livingdraft-core' ), $ch_total, 's' );
		livingdraft_an_table_card( __( 'Campaigns', 'livingdraft-core' ), __( 'UTM tags', 'livingdraft-core' ), $camps, array( 'label' => __( 'Campaign', 'livingdraft-core' ), 'sessions' => __( 'Sessions', 'livingdraft-core' ), 'visitors' => __( 'Visitors', 'livingdraft-core' ) ), __( 'No tagged links used. Add ?utm_source=…&utm_medium=…&utm_campaign=… to links you share to see them here.', 'livingdraft-core' ) );
		?>
	</div>

	<div class="tld-section-rule">
		<h2><?php esc_html_e( 'Who was reading', 'livingdraft-core' ); ?></h2>
		<span class="tld-section-eyebrow">
			<?php
			printf(
				/* translators: 1: new, 2: returning */
				esc_html__( '%1$s new · %2$s returning', 'livingdraft-core' ),
				esc_html( number_format_i18n( $tot['new_visitors'] ) ),
				esc_html( number_format_i18n( $tot['returning'] ) )
			);
			?>
		</span>
	</div>

	<div class="tld-an-grid">
		<?php
		$vcols = array( 'label' => '', 'visitors' => __( 'Visitors', 'livingdraft-core' ), 'share' => __( 'Share', 'livingdraft-core' ) );
		livingdraft_an_table_card( __( 'Devices', 'livingdraft-core' ), __( 'Visitors', 'livingdraft-core' ), $simple( 'devices', 'device' ), array_merge( $vcols, array( 'label' => __( 'Device', 'livingdraft-core' ) ) ), '', $tot['visitors'], 'v' );
		livingdraft_an_table_card( __( 'Browsers', 'livingdraft-core' ), __( 'Visitors', 'livingdraft-core' ), $simple( 'browsers', 'browser' ), array_merge( $vcols, array( 'label' => __( 'Browser', 'livingdraft-core' ) ) ), '', $tot['visitors'], 'v' );
		livingdraft_an_table_card( __( 'Operating systems', 'livingdraft-core' ), __( 'Visitors', 'livingdraft-core' ), $simple( 'os', 'os' ), array_merge( $vcols, array( 'label' => __( 'System', 'livingdraft-core' ) ) ), '', $tot['visitors'], 'v' );
		livingdraft_an_table_card( __( 'Countries', 'livingdraft-core' ), __( 'Visitors', 'livingdraft-core' ), $simple( 'countries', 'country', 'livingdraft_an_flag' ), array_merge( $vcols, array( 'label' => __( 'Country', 'livingdraft-core' ) ) ), __( 'Countries appear when the site is behind Cloudflare (or another CDN that sends a country header). No lookup service is used.', 'livingdraft-core' ), $tot['visitors'], 'v' );
		?>
	</div>

	<?php
	// ---- Hours heat strip ---------------------------------------------
	$hours = array_fill( 0, 24, 0 );
	foreach ( livingdraft_an_breakdown( 'hours', $from_dt, $to_dt ) as $r ) {
		$hours[ (int) $r['hour'] ] = (int) $r['pageviews'];
	}
	$hmax = max( 1, max( $hours ) );
	?>
	<div class="tld-card">
		<div class="tld-card-header">
			<div>
				<span class="tld-card-eyebrow"><?php echo esc_html( sprintf( /* translators: %s: timezone */ __( 'Pageviews by hour · %s', 'livingdraft-core' ), wp_timezone_string() ) ); ?></span>
				<h2 class="tld-card-title"><?php esc_html_e( 'When readers read', 'livingdraft-core' ); ?></h2>
			</div>
		</div>
		<div class="tld-an-hours">
			<?php foreach ( $hours as $hr => $n ) : ?>
				<div class="tld-an-hour" title="<?php echo esc_attr( sprintf( '%02d:00 — %s', $hr, number_format_i18n( $n ) ) ); ?>">
					<span style="height:<?php echo esc_attr( max( 2, round( 100 * $n / $hmax ) ) ); ?>%"></span>
					<em><?php echo esc_html( sprintf( '%02d', $hr ) ); ?></em>
				</div>
			<?php endforeach; ?>
		</div>
		<p class="tld-help"><?php esc_html_e( 'Publish and share just before the tallest bars.', 'livingdraft-core' ); ?></p>
	</div>

	<p class="tld-help" style="margin-top:24px">
		<?php esc_html_e( 'How this is counted: a visitor is one browser; a session ends after 30 minutes without activity; engaged time counts only while the tab is visible and in use; a bounce is a one-page session under 10 seconds. Logged-in staff, bots and excluded IPs are never counted.', 'livingdraft-core' ); ?>
	</p>

	<?php
	livingdraft_admin_render_footer();
}

/* ==================================================================
 * 5. AI INSIGHTS
 * ================================================================== */

/**
 * The AI card on the Analytics page.
 *
 * @param array $range Range.
 */
function livingdraft_an_render_ai_card( $range ) {
	$has_ai = function_exists( 'livingdraft_ai_available_models' ) && ! empty( livingdraft_ai_available_models() );
	$cached = get_transient( 'ld_an_ai_' . md5( $range['from'] . $range['to'] ) );
	?>
	<div class="tld-card tld-an-ai">
		<div class="tld-card-header">
			<div>
				<span class="tld-card-eyebrow"><?php esc_html_e( 'AI analyst', 'livingdraft-core' ); ?></span>
				<h2 class="tld-card-title"><?php esc_html_e( 'What the numbers say', 'livingdraft-core' ); ?></h2>
			</div>
			<?php if ( $has_ai ) : ?>
				<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
					<?php livingdraft_ai_render_model_picker( array( 'storage_key' => 'livingdraft.ai.analytics' ) ); ?>
					<button type="button" class="tld-btn is-primary" id="ld-an-ai-run"
						data-from="<?php echo esc_attr( $range['from'] ); ?>"
						data-to="<?php echo esc_attr( $range['to'] ); ?>"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'ld_an_ai' ) ); ?>">
						<?php echo $cached ? esc_html__( 'Regenerate', 'livingdraft-core' ) : esc_html__( 'Analyse this period', 'livingdraft-core' ); ?>
					</button>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( ! $has_ai ) : ?>
			<p class="tld-help">
				<?php esc_html_e( 'Add an OpenAI, Gemini or Grok key under Settings → AI and this card will explain the period in plain words: what grew, what fell, which stories and sources drove it, and what to do next.', 'livingdraft-core' ); ?>
			</p>
		<?php else : ?>
			<div id="ld-an-ai-out" class="tld-an-ai-out"><?php echo $cached ? wp_kses_post( $cached ) : '<p class="tld-help">' . esc_html__( 'Sends a summary of these figures — totals, top pages, channels, referrers, devices, hours; never anything about individual readers — to your AI provider and returns a short editorial briefing with next steps.', 'livingdraft-core' ) . '</p>'; ?></div>
			<script>
			(function () {
				var btn = document.getElementById('ld-an-ai-run');
				var out = document.getElementById('ld-an-ai-out');
				if (!btn) { return; }
				btn.addEventListener('click', function () {
					btn.disabled = true;
					var old = btn.textContent;
					btn.textContent = '<?php echo esc_js( __( 'Reading the figures…', 'livingdraft-core' ) ); ?>';
					var fd = new FormData();
					fd.append('action', 'ld_an_ai');
					fd.append('nonce', btn.dataset.nonce);
					fd.append('from', btn.dataset.from);
					fd.append('to', btn.dataset.to);
					if (window.livingdraftAiCurrentModel) { fd.append('ai_model', window.livingdraftAiCurrentModel('livingdraft.ai.analytics')); }
					fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
						.then(function (r) { return r.json(); })
						.then(function (res) {
							if (res && res.success) { out.innerHTML = res.data.html; btn.textContent = '<?php echo esc_js( __( 'Regenerate', 'livingdraft-core' ) ); ?>'; }
							else { out.innerHTML = '<p class="tld-help" style="color:var(--tld-bad)">' + ((res && res.data && res.data.message) || 'Failed.') + '</p>'; btn.textContent = old; }
						})
						.catch(function (e) { out.innerHTML = '<p class="tld-help" style="color:var(--tld-bad)">' + e.message + '</p>'; btn.textContent = old; })
						.finally(function () { btn.disabled = false; });
				});
			})();
			</script>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Minimal, safe Markdown → HTML for model output (headings, bullets,
 * numbered lists, bold). Everything is escaped first.
 *
 * @param string $md Text.
 * @return string
 */
function livingdraft_an_md( $md ) {
	$lines = preg_split( '/\r?\n/', trim( (string) $md ) );
	$html  = '';
	$list  = '';

	$close = static function () use ( &$list, &$html ) {
		if ( $list ) {
			$html .= '</' . $list . '>';
			$list  = '';
		}
	};
	$inline = static function ( $t ) {
		$t = esc_html( $t );
		$t = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $t );
		return preg_replace( '/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/', '<em>$1</em>', $t );
	};

	foreach ( $lines as $line ) {
		$line = rtrim( $line );
		if ( '' === trim( $line ) ) {
			$close();
			continue;
		}
		if ( preg_match( '/^#{1,6}\s+(.*)$/', $line, $m ) ) {
			$close();
			$html .= '<h4>' . $inline( $m[1] ) . '</h4>';
		} elseif ( preg_match( '/^\s*[-*•]\s+(.*)$/', $line, $m ) ) {
			if ( 'ul' !== $list ) {
				$close();
				$html .= '<ul>';
				$list  = 'ul';
			}
			$html .= '<li>' . $inline( $m[1] ) . '</li>';
		} elseif ( preg_match( '/^\s*\d+[.)]\s+(.*)$/', $line, $m ) ) {
			if ( 'ol' !== $list ) {
				$close();
				$html .= '<ol>';
				$list  = 'ol';
			}
			$html .= '<li>' . $inline( $m[1] ) . '</li>';
		} else {
			$close();
			$html .= '<p>' . $inline( $line ) . '</p>';
		}
	}
	$close();

	return $html;
}

/**
 * AJAX: build the aggregate picture and ask the model.
 */
function livingdraft_an_ajax_ai() {
	check_ajax_referer( 'ld_an_ai', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permission.', 'livingdraft-core' ) ) );
	}

	$valid = static function ( $d ) {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $d ) ? $d : '';
	};
	$from = $valid( sanitize_text_field( wp_unslash( $_POST['from'] ?? '' ) ) );
	$to   = $valid( sanitize_text_field( wp_unslash( $_POST['to'] ?? '' ) ) );
	if ( ! $from || ! $to ) {
		wp_send_json_error( array( 'message' => __( 'Bad date range.', 'livingdraft-core' ) ) );
	}

	$len   = (int) round( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1;
	$pto   = gmdate( 'Y-m-d', strtotime( $from ) - DAY_IN_SECONDS );
	$pfrom = gmdate( 'Y-m-d', strtotime( $pto ) - ( $len - 1 ) * DAY_IN_SECONDS );

	list( $f, $t )   = livingdraft_an_bounds( $from, $to );
	list( $pf, $pt ) = livingdraft_an_bounds( $pfrom, $pto );

	$pages = array();
	foreach ( livingdraft_an_breakdown( 'pages', $f, $t, 15 ) as $r ) {
		$pages[] = array(
			'title'         => livingdraft_an_page_title( (int) $r['post_id'], $r['path'] ),
			'path'          => $r['path'],
			'published'     => $r['post_id'] ? get_the_date( 'Y-m-d', (int) $r['post_id'] ) : '',
			'pageviews'     => (int) $r['pageviews'],
			'visitors'      => (int) $r['visitors'],
			'avg_engaged_s' => (int) $r['avg_engaged'],
			'avg_scroll'    => (int) $r['avg_scroll'],
		);
	}

	$data = array(
		'site'            => get_bloginfo( 'name' ),
		'period'          => array( 'from' => $from, 'to' => $to, 'days' => $len ),
		'totals'          => livingdraft_an_totals( $f, $t ),
		'previous_period' => array( 'from' => $pfrom, 'to' => $pto, 'totals' => livingdraft_an_totals( $pf, $pt ) ),
		'series'          => array_map(
			static function ( $p ) {
				return array( $p['key'], $p['pageviews'], $p['visitors'] );
			},
			livingdraft_an_series( $len > 92 ? 'week' : 'day', $from, $to )
		),
		'top_pages'       => $pages,
		'channels'        => livingdraft_an_breakdown( 'channels', $f, $t, 10 ),
		'referrers'       => livingdraft_an_breakdown( 'referrers', $f, $t, 10 ),
		'campaigns'       => livingdraft_an_breakdown( 'campaigns', $f, $t, 8 ),
		'devices'         => livingdraft_an_breakdown( 'devices', $f, $t, 5 ),
		'countries'       => livingdraft_an_breakdown( 'countries', $f, $t, 8 ),
		'hours'           => livingdraft_an_breakdown( 'hours', $f, $t ),
		'published_posts' => (int) count(
			get_posts(
				array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'date_query'     => array( array( 'after' => $from, 'before' => $to . ' 23:59:59', 'inclusive' => true ) ),
					'fields'         => 'ids',
					'posts_per_page' => 500,
					'no_found_rows'  => true,
				)
			)
		),
	);

	$system = 'You are the audience and SEO analyst for a small independent news website. You are given first-party analytics as JSON. '
		. 'Be concrete, numeric and brief. Never invent numbers that are not in the data. Refer to stories by title. '
		. 'Channel meanings: search = web search engines, news = Google News/Discover and news apps, ai = AI assistants such as ChatGPT, Perplexity, Gemini, Grok; '
		. 'engaged time is active reading seconds; a bounce is a single-page session under 10 seconds.';

	$prompt = "Write a briefing for the editor with these sections, using markdown headings and bullets:\n"
		. "## Headline\nOne or two sentences: the period in numbers versus the previous period.\n"
		. "## What drove it\n3–5 bullets: stories, channels and referrers that moved the numbers.\n"
		. "## Warning signs\n1–3 bullets (falling channels, low engagement, high bounce on important pages). Skip if none.\n"
		. "## Do this next\n5 numbered, specific actions covering SEO, distribution timing (use the hours data), internal linking and follow-up stories.\n"
		. "## Story ideas\n3 follow-up or explainer ideas grounded in what readers engaged with most.\n\n"
		. 'DATA: ' . wp_json_encode( $data );

	$args = array(
		'task'        => 'analytics_insights',
		'system'      => $system,
		'max_tokens'  => 1400,
		'temperature' => 0.4,
	);
	if ( function_exists( 'livingdraft_ai_read_request_override' ) ) {
		$args = array_merge( $args, livingdraft_ai_read_request_override() );
	}

	$out = livingdraft_ai_complete( $prompt, $args );

	if ( is_wp_error( $out ) ) {
		wp_send_json_error( array( 'message' => $out->get_error_message() ) );
	}

	$html = livingdraft_an_md( $out )
		. '<p class="tld-help" style="margin-top:12px">' . esc_html(
			sprintf(
				/* translators: %s: time */
				__( 'Generated %s. AI can misread figures — check anything you act on against the tables below.', 'livingdraft-core' ),
				current_time( 'j M Y, H:i' )
			)
		) . '</p>';

	set_transient( 'ld_an_ai_' . md5( $from . $to ), $html, 6 * HOUR_IN_SECONDS );

	wp_send_json_success( array( 'html' => $html ) );
}
add_action( 'wp_ajax_ld_an_ai', 'livingdraft_an_ajax_ai' );

/* ==================================================================
 * 6. CSV EXPORT
 * ================================================================== */

function livingdraft_an_export() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'Insufficient permission.', 'livingdraft-core' ) );
	}
	check_admin_referer( 'ld_an_export' );

	$valid = static function ( $d ) {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $d ) ? $d : '';
	};
	$from = $valid( sanitize_text_field( wp_unslash( $_GET['from'] ?? '' ) ) );
	$to   = $valid( sanitize_text_field( wp_unslash( $_GET['to'] ?? '' ) ) );
	if ( ! $from || ! $to ) {
		wp_die( esc_html__( 'Bad date range.', 'livingdraft-core' ) );
	}

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="livingdraft-analytics-' . $from . '-to-' . $to . '.csv"' );

	$fh = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	fputcsv( $fh, array( 'Daily totals' ) );
	fputcsv( $fh, array( 'date', 'pageviews', 'visitors', 'sessions' ) );
	foreach ( livingdraft_an_series( 'day', $from, $to ) as $p ) {
		fputcsv( $fh, array( $p['key'], $p['pageviews'], $p['visitors'], $p['sessions'] ) );
	}

	list( $f, $t ) = livingdraft_an_bounds( $from, $to );

	fputcsv( $fh, array() );
	fputcsv( $fh, array( 'Top pages' ) );
	fputcsv( $fh, array( 'title', 'path', 'pageviews', 'visitors', 'avg_engaged_seconds', 'avg_scroll_percent' ) );
	foreach ( livingdraft_an_breakdown( 'pages', $f, $t, 500 ) as $r ) {
		fputcsv( $fh, array( livingdraft_an_page_title( (int) $r['post_id'], $r['path'] ), $r['path'], $r['pageviews'], $r['visitors'], $r['avg_engaged'], $r['avg_scroll'] ) );
	}

	fputcsv( $fh, array() );
	fputcsv( $fh, array( 'Channels' ) );
	fputcsv( $fh, array( 'channel', 'sessions', 'visitors' ) );
	foreach ( livingdraft_an_breakdown( 'channels', $f, $t, 50 ) as $r ) {
		fputcsv( $fh, array( $r['channel'], $r['sessions'], $r['visitors'] ) );
	}

	fputcsv( $fh, array() );
	fputcsv( $fh, array( 'Referrers' ) );
	fputcsv( $fh, array( 'host', 'sessions', 'visitors' ) );
	foreach ( livingdraft_an_breakdown( 'referrers', $f, $t, 500 ) as $r ) {
		fputcsv( $fh, array( $r['ref_host'], $r['sessions'], $r['visitors'] ) );
	}

	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}
add_action( 'admin_post_ld_an_export', 'livingdraft_an_export' );

/* ==================================================================
 * 7. WP DASHBOARD WIDGET
 * ================================================================== */

function livingdraft_an_dashboard_widget_register() {
	if ( ! current_user_can( 'edit_posts' ) || ! livingdraft_an_ready() ) {
		return;
	}
	wp_add_dashboard_widget( 'livingdraft_analytics', __( 'Site visits — The Living Draft', 'livingdraft-core' ), 'livingdraft_an_dashboard_widget' );
}
add_action( 'wp_dashboard_setup', 'livingdraft_an_dashboard_widget_register' );

function livingdraft_an_dashboard_widget() {
	$live = livingdraft_an_realtime( 5 );
	?>
	<style>
		.ld-an-w table{width:100%;border-collapse:collapse;font-variant-numeric:tabular-nums}
		.ld-an-w th,.ld-an-w td{padding:6px 4px;border-bottom:1px solid #eee;text-align:right}
		.ld-an-w th:first-child,.ld-an-w td:first-child{text-align:left}
		.ld-an-w .live{display:inline-block;width:8px;height:8px;border-radius:50%;background:#2f7a3a;margin-right:6px}
	</style>
	<div class="ld-an-w">
		<p><span class="live"></span><?php echo esc_html( sprintf( /* translators: %s: count */ _n( '%s reader on the site right now', '%s readers on the site right now', $live['visitors'], 'livingdraft-core' ), number_format_i18n( $live['visitors'] ) ) ); ?></p>
		<table>
			<thead><tr><th></th><th><?php esc_html_e( 'Visitors', 'livingdraft-core' ); ?></th><th><?php esc_html_e( 'Pageviews', 'livingdraft-core' ); ?></th><th><?php esc_html_e( 'Sessions', 'livingdraft-core' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( array( 'today', 'yesterday', 'week', 'month', 'year', 'all' ) as $pk ) : ?>
					<?php
					$s     = livingdraft_site_stats( $pk );
					$label = 'all' === $pk ? __( 'All time', 'livingdraft-core' ) : livingdraft_an_named_period( $pk )['label'];
					?>
					<tr>
						<td><?php echo esc_html( $label ); ?></td>
						<td><strong><?php echo esc_html( number_format_i18n( $s['visitors'] ) ); ?></strong></td>
						<td><?php echo esc_html( number_format_i18n( $s['pageviews'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $s['sessions'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p style="margin-bottom:0"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=livingdraft-analytics' ) ); ?>"><?php esc_html_e( 'Full report →', 'livingdraft-core' ); ?></a></p>
	</div>
	<?php
}

/* ==================================================================
 * 8. POST EDITOR BOX
 * ================================================================== */

function livingdraft_an_post_box_register() {
	if ( ! livingdraft_an_ready() ) {
		return;
	}
	add_meta_box( 'livingdraft_an_post', __( 'Readership', 'livingdraft-core' ), 'livingdraft_an_post_box', 'post', 'side', 'low' );
}
add_action( 'add_meta_boxes', 'livingdraft_an_post_box_register' );

/**
 * @param WP_Post $post Post.
 */
function livingdraft_an_post_box( $post ) {
	if ( 'publish' !== $post->post_status ) {
		echo '<p>' . esc_html__( 'Figures appear once the story is published.', 'livingdraft-core' ) . '</p>';
		return;
	}

	global $wpdb;
	$t = livingdraft_an_table();
	$rows = array(
		__( 'Last 24 hours', 'livingdraft-core' ) => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - DAY_IN_SECONDS ),
		__( 'Last 7 days', 'livingdraft-core' )   => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - WEEK_IN_SECONDS ),
		__( 'Last 30 days', 'livingdraft-core' )  => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS ),
	);

	echo '<table style="width:100%;font-variant-numeric:tabular-nums"><tr><th style="text-align:left"></th><th style="text-align:right">' . esc_html__( 'Visitors', 'livingdraft-core' ) . '</th><th style="text-align:right">' . esc_html__( 'Engaged', 'livingdraft-core' ) . '</th></tr>';
	foreach ( $rows as $label => $since ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(DISTINCT visitor) v, ROUND(AVG(NULLIF(engaged,0))) e FROM {$t} WHERE post_id = %d AND created >= %s", $post->ID, $since ), ARRAY_A );
		printf(
			'<tr><td>%s</td><td style="text-align:right"><strong>%s</strong></td><td style="text-align:right">%s</td></tr>',
			esc_html( $label ),
			esc_html( number_format_i18n( (int) ( $r['v'] ?? 0 ) ) ),
			esc_html( ! empty( $r['e'] ) ? livingdraft_an_fmt_time( (int) $r['e'] ) : '—' )
		);
	}
	echo '</table>';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$src = $wpdb->get_results( $wpdb->prepare( "SELECT channel, COUNT(*) n FROM {$t} WHERE post_id = %d AND is_entry = 1 GROUP BY channel ORDER BY n DESC LIMIT 4", $post->ID ), ARRAY_A );
	if ( $src ) {
		$labels = livingdraft_an_channels();
		$bits   = array();
		foreach ( $src as $s ) {
			$bits[] = esc_html( ( $labels[ $s['channel'] ] ?? $s['channel'] ) . ' ' . number_format_i18n( $s['n'] ) );
		}
		echo '<p style="margin:10px 0 0;color:#646970">' . esc_html__( 'Arrived via:', 'livingdraft-core' ) . ' ' . implode( ' · ', $bits ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	if ( function_exists( 'livingdraft_views_total' ) ) {
		echo '<p style="margin:6px 0 0;color:#646970">' . esc_html( sprintf( /* translators: %s: count */ __( 'Lifetime reads: %s', 'livingdraft-core' ), number_format_i18n( livingdraft_views_total( $post->ID ) ) ) ) . '</p>';
	}
}

/* ==================================================================
 * 9. SETTINGS
 * ================================================================== */

function livingdraft_an_handle_save() {
	if ( ! isset( $_POST['ld_an_save'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to change these settings.', 'livingdraft-core' ) );
	}
	check_admin_referer( 'ld_an_save' );

	update_option(
		LIVINGDRAFT_AN_OPTION,
		array(
			'enabled'       => ! empty( $_POST['ld_an_enabled'] ),
			'exclude_staff' => ! empty( $_POST['ld_an_exclude_staff'] ),
			'exclude_ips'   => sanitize_textarea_field( wp_unslash( $_POST['ld_an_exclude_ips'] ?? '' ) ),
			'retention'     => max( 400, min( 3650, absint( $_POST['ld_an_retention'] ?? 760 ) ) ),
			'scope'         => ( isset( $_POST['ld_an_scope'] ) && 'singular' === $_POST['ld_an_scope'] ) ? 'singular' : 'all',
		),
		false
	);

	if ( function_exists( 'livingdraft_core_purge_caches' ) ) {
		livingdraft_core_purge_caches(); // The tracker tag is part of cached HTML.
	}
	livingdraft_an_flush_cache();

	wp_safe_redirect( admin_url( 'admin.php?page=livingdraft-settings&section=analytics&ld-an=saved' ) );
	exit;
}
add_action( 'admin_init', 'livingdraft_an_handle_save' );

function livingdraft_an_render_settings() {
	$s = livingdraft_an_settings();

	if ( isset( $_GET['ld-an'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="tld-notice"><p>' . esc_html__( 'Analytics settings saved. Page caches were cleared.', 'livingdraft-core' ) . '</p></div>';
	}

	$ip = livingdraft_an_client_ip();
	?>
	<div class="tld-card">
		<div class="tld-section-rule"><h2><?php esc_html_e( 'Analytics', 'livingdraft-core' ); ?></h2></div>
		<p class="tld-help"><?php esc_html_e( 'First-party visit counting on your own database. It runs alongside Google Analytics (Site Kit) — use GA for long-term marketing reports and this for fast, exact newsroom figures.', 'livingdraft-core' ); ?></p>

		<form method="post" action="">
			<?php wp_nonce_field( 'ld_an_save' ); ?>

			<div class="tld-field">
				<label class="tld-check">
					<input type="checkbox" name="ld_an_enabled" value="1" <?php checked( $s['enabled'] ); ?>>
					<span><?php esc_html_e( 'Count visits', 'livingdraft-core' ); ?></span>
				</label>
			</div>

			<div class="tld-field">
				<span class="tld-label"><?php esc_html_e( 'Which pages', 'livingdraft-core' ); ?></span>
				<label class="tld-check"><input type="radio" name="ld_an_scope" value="all" <?php checked( 'all', $s['scope'] ); ?>><span><?php esc_html_e( 'Every public page (home, sections, articles, search, tags)', 'livingdraft-core' ); ?></span></label>
				<label class="tld-check"><input type="radio" name="ld_an_scope" value="singular" <?php checked( 'singular', $s['scope'] ); ?>><span><?php esc_html_e( 'Articles and pages only', 'livingdraft-core' ); ?></span></label>
			</div>

			<div class="tld-field">
				<label class="tld-check">
					<input type="checkbox" name="ld_an_exclude_staff" value="1" <?php checked( $s['exclude_staff'] ); ?>>
					<span><?php esc_html_e( 'Do not count staff', 'livingdraft-core' ); ?></span>
				</label>
				<p class="tld-help"><?php esc_html_e( 'Any browser that has been logged in as an author, editor or admin is remembered and excluded, even when it later reads the site logged out. To exclude a phone or any other browser by hand, open the site once with ?ld_optout=1 on the end of the address (?ld_optout=0 undoes it).', 'livingdraft-core' ); ?></p>
			</div>

			<div class="tld-field">
				<label class="tld-label" for="ld_an_exclude_ips"><?php esc_html_e( 'Excluded IP addresses', 'livingdraft-core' ); ?></label>
				<textarea id="ld_an_exclude_ips" name="ld_an_exclude_ips" rows="3" class="tld-input is-mono" placeholder="203.0.113.7&#10;198.51.100."><?php echo esc_textarea( $s['exclude_ips'] ); ?></textarea>
				<p class="tld-help">
					<?php esc_html_e( 'One per line. End with a dot to exclude a whole range. Addresses are only compared, never stored.', 'livingdraft-core' ); ?>
					<?php if ( $ip ) : ?>
						<?php echo esc_html( sprintf( /* translators: %s: IP */ __( 'Your address right now: %s', 'livingdraft-core' ), $ip ) ); ?>
					<?php endif; ?>
				</p>
			</div>

			<div class="tld-field">
				<label class="tld-label" for="ld_an_retention"><?php esc_html_e( 'Keep detailed pageviews for (days)', 'livingdraft-core' ); ?></label>
				<input id="ld_an_retention" type="number" min="400" max="3650" name="ld_an_retention" class="tld-input" style="max-width:140px" value="<?php echo esc_attr( $s['retention'] ); ?>">
				<p class="tld-help"><?php esc_html_e( 'After this, individual pageviews are deleted but the daily, weekly, monthly and yearly totals are kept forever. 760 days (about 25 months) lets you compare any month with the same month last year.', 'livingdraft-core' ); ?></p>
			</div>

			<div class="tld-btn-row">
				<button type="submit" name="ld_an_save" value="1" class="tld-btn is-primary"><?php esc_html_e( 'Save', 'livingdraft-core' ); ?></button>
			</div>
		</form>
	</div>
	<?php
}

/**
 * @param array $sections Sections.
 * @return array
 */
function livingdraft_an_register_settings_section( $sections ) {
	$sections['analytics'] = array(
		'label'  => __( 'Analytics', 'livingdraft-core' ),
		'desc'   => __( 'What is counted as a visit, who is excluded, and how long detail is kept.', 'livingdraft-core' ),
		'render' => 'livingdraft_an_render_settings',
		'cap'    => 'manage_options',
	);
	return $sections;
}
add_filter( 'livingdraft_settings_panels', 'livingdraft_an_register_settings_section', 25 );
