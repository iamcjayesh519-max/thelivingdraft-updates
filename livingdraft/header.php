<?php
/**
 * Header: masthead, dateline, navigation, index line.
 *
 * @package LivingDraft
 */

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="profile" href="https://gmpg.org/xfn/11">
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="skip-link" href="#main"><?php esc_html_e( 'Skip to stories', 'livingdraft' ); ?></a>

<?php $ld_edition = livingdraft_edition(); ?>

<div class="masthead-shell">
	<header class="masthead wrap">
		<div class="masthead-side">
			<?php
			printf(
				/* translators: 1: volume number in Roman numerals. 2: issue number. */
				esc_html__( 'Vol. %1$s · No. %2$s', 'livingdraft' ),
				esc_html( $ld_edition['volume'] ),
				esc_html( number_format_i18n( $ld_edition['number'] ) )
			);
			?>
		</div>

		<?php
		// The paper's name is the page heading on the front, and a link everywhere else.
		$ld_title_tag = ( is_front_page() || is_home() ) ? 'h1' : 'p';
		?>
		<?php if ( has_custom_logo() ) : ?>
			<?php
			/*
			 * Before 4.0 the logo went into a plain <div>, which meant a site
			 * using a logo had no <h1> anywhere on its front page. Every other
			 * template carries its own heading, so only the front page was
			 * affected — and only when a logo was uploaded, which is why it
			 * went unnoticed. The logo now uses the same h1/p logic as the
			 * text masthead below it.
			 */
			?>
			<<?php echo esc_html( $ld_title_tag ); ?> class="masthead-title">
				<?php the_custom_logo(); ?>
			</<?php echo esc_html( $ld_title_tag ); ?>>
		<?php elseif ( display_header_text() ) : ?>
			<<?php echo esc_html( $ld_title_tag ); ?> class="masthead-title">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home"><?php bloginfo( 'name' ); ?></a>
			</<?php echo esc_html( $ld_title_tag ); ?>>
		<?php endif; ?>

		<div class="masthead-side is-right">
			<?php
			$ld_edition_line = get_theme_mod( 'livingdraft_edition_line', '' );
			if ( $ld_edition_line ) {
				echo esc_html( $ld_edition_line );
			}

			$ld_x  = get_theme_mod( 'livingdraft_x_url', '' );
			$ld_ig = get_theme_mod( 'livingdraft_instagram_url', '' );

			if ( $ld_x || $ld_ig ) :
				?>
				<span class="edition-social">
					<?php if ( $ld_x ) : ?>
						<a href="<?php echo esc_url( $ld_x ); ?>" rel="noopener noreferrer" target="_blank">
							<span class="screen-reader-text"><?php esc_html_e( 'X', 'livingdraft' ); ?></span>
							<?php echo livingdraft_icon( 'x' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</a>
					<?php endif; ?>
					<?php if ( $ld_ig ) : ?>
						<a href="<?php echo esc_url( $ld_ig ); ?>" rel="noopener noreferrer" target="_blank">
							<span class="screen-reader-text"><?php esc_html_e( 'Instagram', 'livingdraft' ); ?></span>
							<?php echo livingdraft_icon( 'instagram' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</a>
					<?php endif; ?>
				</span>
			<?php endif; ?>
		</div>
	</header>
</div>

<div class="dateline-shell">
	<div class="dateline wrap">
		<?php echo esc_html( wp_date( 'l, j F Y' ) ); ?>
		<?php
		// One shared resolver, so the front end and the Customizer preview agree.
		$ld_strapline = livingdraft_strapline_text();
		if ( $ld_strapline ) :
			?>
			<span class="sep">&mdash;</span><?php echo esc_html( $ld_strapline ); ?>
		<?php endif; ?>
	</div>
</div>

<div class="nav-shell">
	<div class="wrap">
		<button class="menu-toggle" aria-expanded="false" aria-controls="nav-panel">
			<?php echo livingdraft_icon( 'menu' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<span><?php esc_html_e( 'Sections', 'livingdraft' ); ?></span>
		</button>

		<nav id="nav-panel" class="nav-panel" aria-label="<?php esc_attr_e( 'Primary', 'livingdraft' ); ?>">
			<?php
			wp_nav_menu(
				array(
					'theme_location' => 'primary',
					'menu_class'     => 'primary-menu',
					'container'      => '',
					'depth'          => 2,
					'fallback_cb'    => 'wp_page_menu',
				)
			);
			?>
		</nav>

		<div class="nav-search">
			<?php livingdraft_scheme_control(); ?>

			<button class="search-toggle" aria-expanded="false" aria-controls="search-drawer">
				<span class="screen-reader-text"><?php esc_html_e( 'Search', 'livingdraft' ); ?></span>
				<?php echo livingdraft_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</button>
		</div>
	</div>

	<div id="search-drawer" class="search-drawer">
		<div class="wrap"><?php get_search_form(); ?></div>
	</div>
</div>

<?php
$ld_tags = livingdraft_top_tags( 8 );
if ( ! empty( $ld_tags ) ) :
	?>
	<nav class="index-strip" aria-label="<?php esc_attr_e( 'Index', 'livingdraft' ); ?>">
		<div class="wrap">
			<span class="label"><?php esc_html_e( 'Index', 'livingdraft' ); ?></span>
			<?php foreach ( $ld_tags as $ld_tag ) : ?>
				<a href="<?php echo esc_url( $ld_tag['url'] ); ?>"><?php echo esc_html( $ld_tag['name'] ); ?></a>
			<?php endforeach; ?>
		</div>
	</nav>
<?php endif; ?>

<main id="main" tabindex="-1">
