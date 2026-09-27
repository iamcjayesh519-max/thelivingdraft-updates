<?php
/**
 * Footer: newsletter, brand, policy links, colophon.
 *
 * @package LivingDraft
 */

?>
	</main>

	<?php get_template_part( 'template-parts/newsletter-bar' ); ?>

	<footer class="site-footer">
		<div class="wrap">
			<div class="footer-grid">

				<div class="footer-brand">
					<?php if ( has_custom_logo() ) : ?>
						<div class="footer-logo"><?php the_custom_logo(); ?></div>
					<?php else : ?>
						<p class="site-title">
							<a href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home"><?php bloginfo( 'name' ); ?></a>
						</p>
					<?php endif; ?>

					<?php
					$ld_strap = get_theme_mod( 'livingdraft_strapline', get_bloginfo( 'description', 'display' ) );
					if ( $ld_strap ) :
						?>
						<p class="footer-strap"><?php echo esc_html( $ld_strap ); ?></p>
					<?php endif; ?>

					<?php
					$ld_x  = get_theme_mod( 'livingdraft_x_url', '' );
					$ld_ig = get_theme_mod( 'livingdraft_instagram_url', '' );
					if ( $ld_x || $ld_ig ) :
						?>
						<div class="footer-social">
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
						</div>
					<?php endif; ?>
				</div>

				<?php if ( has_nav_menu( 'footer' ) ) : ?>
					<nav class="footer-nav" aria-label="<?php esc_attr_e( 'The paper', 'livingdraft' ); ?>">
						<h2 class="footer-head"><?php esc_html_e( 'The Paper', 'livingdraft' ); ?></h2>
						<?php
						wp_nav_menu(
							array(
								'theme_location' => 'footer',
								'menu_class'     => 'footer-menu',
								'container'      => false,
								'depth'          => 1,
							)
						);
						?>
					</nav>
				<?php endif; ?>

				<?php if ( is_active_sidebar( 'footer-1' ) ) : ?>
					<div class="footer-widgets">
						<?php dynamic_sidebar( 'footer-1' ); ?>
					</div>
				<?php endif; ?>

			</div>

			<div class="colophon">
				<span>
					<?php
					$ld_note = get_theme_mod( 'livingdraft_footer_note', '' );

					if ( $ld_note ) {
						echo esc_html( $ld_note );
					} else {
						printf(
							/* translators: 1: year. 2: site name. */
							esc_html__( '© %1$s %2$s. All rights reserved.', 'livingdraft' ),
							esc_html( wp_date( 'Y' ) ),
							esc_html( get_bloginfo( 'name', 'display' ) )
						);
					}
					?>
				</span>
				<span><?php esc_html_e( 'Corrections are published, not quietly made.', 'livingdraft' ); ?></span>
			</div>
		</div>
	</footer>

	<?php wp_footer(); ?>
	</body>
</html>
