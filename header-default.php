<?php
	global $nm_theme_options, $nm_globals;
	
	// Ubermenu
	if ( function_exists( 'ubermenu' ) ) {
		$ubermenu = true;
		$ubermenu_wrap_open = '<div class="nm-ubermenu-wrap clear">';
		$ubermenu_wrap_close = '</div>';
	} else {
		$ubermenu = false;
		$ubermenu_wrap_open = $ubermenu_wrap_close = '';
	}
	
	// Layout class
	$header_class = $nm_globals['header_layout'];
	
	// Scroll class
	$header_scroll_class = apply_filters( 'nm_header_on_scroll_class', 'resize-on-scroll' ); // Note: Use "static-on-scroll" class to prevent header top/bottom spacing from resizing on-scroll
	$header_class .= ( strlen( $header_scroll_class ) > 0 ) ? ' ' . $header_scroll_class : '';

	// Alternative logo class
	$header_class .= ( $nm_theme_options['alt_logo_config'] != '0' ) ? ' ' . $nm_theme_options['alt_logo_config'] : '';
?>
	
	<!-- header -->
	<header id="nm-header" class="nm-header <?php echo esc_attr( $header_class ); ?> clear">

		<div class="winter-is-coming"> 
			<div class="snow snow--near"></div>
			<div class="snow snow--near snow--alt"></div>
			<div class="snow snow--mid"></div>
			<div class="snow snow--mid snow--alt"></div>
			<div class="snow snow--far"></div>
			<div class="snow snow--far snow--alt"></div>
		</div>

		<div class="nm-header-inner">
			<div class="nm-header-row nm-row">
				<div class="nm-header-col col-xs-12">
					<?php echo $ubermenu_wrap_open; ?>
					
					<?php 
						// Header part: Logo
						get_header( 'part-logo' );
					?>
					
					<?php if ( $ubermenu ) : ?>
						<?php ubermenu( 'main', array( 'theme_location' => 'main-menu' ) ); ?>
					<?php else : ?>
						<nav class="nm-main-menu">
							<!-- GEWIJZIGD: Centrale titel toevoegen op portaal, en anders het centrale menu -->
							<?php if ( is_main_site() ) : ?>
								<p class="hoofdtitel"><?php echo print_portal_title(); ?></p>
							<?php else : ?>
								<ul id="nm-main-menu-ul" class="nm-menu">
									<?php
										wp_nav_menu( array(
											'theme_location'	=> 'main-menu',
											'container'       	=> false,
											'fallback_cb'     	=> false,
											'items_wrap'      	=> '%3$s'
										) );
									?>
								</ul>
							<?php endif; ?>
						</nav>
					<?php endif; ?>
					
					<nav class="nm-right-menu">
						<!-- GEWIJZIGD: Rechterlogo toevoegen op portaal, en anders het rechtermenu -->
						<?php if ( is_main_site() ) : ?>
							<img src="<?php echo get_stylesheet_directory_uri(); ?>/images/tekstballon.png" class="nm-logo">
						<?php else : ?>
							<ul id="nm-right-menu-ul" class="nm-menu">
								<?php
									wp_nav_menu( array(
										'theme_location'	=> 'right-menu',
										'container'       	=> false,
										'fallback_cb'     	=> false,
										'items_wrap'      	=> '%3$s'
									) );
									
									if ( nm_woocommerce_activated() && $nm_theme_options['menu_login'] ) :
								?>
								<li class="nm-menu-account menu-item">
									<?php echo nm_get_myaccount_link( true ); ?>
								</li>
								<?php 
									endif;
									
									if ( $nm_globals['cart_link'] ) :
										
										$cart_menu_class = ( $nm_theme_options['menu_cart_icon'] ) ? 'has-icon' : 'no-icon';
										$cart_url = ( $nm_globals['cart_panel'] ) ? '#' : wc_get_cart_url();
								?>
								<li class="nm-menu-cart menu-item <?php echo esc_attr( $cart_menu_class ); ?>">
									<a href="<?php echo esc_url( $cart_url ); ?>" id="nm-menu-cart-btn">
										<?php echo nm_get_cart_title(); ?>
										<?php echo nm_get_cart_contents_count(); ?>
									</a>
								</li>
								<?php 
									endif; 
									
									if ( $nm_globals['shop_search_header'] ) :
								?>
								<li class="nm-menu-search menu-item"><a href="#" id="nm-menu-search-btn"><i class="nm-font nm-font-search-alt flip"></i></a></li>
								<?php endif; ?>
								<li class="nm-menu-offscreen menu-item">
									<?php 
										if ( nm_woocommerce_activated() ) {
											echo nm_get_cart_contents_count();
										}
									?>
									
									<a href="#" id="nm-mobile-menu-button" class="clicked">
										<div class="nm-menu-icon">
											<span class="line-1"></span><span class="line-2"></span><span class="line-3"></span>
										</div>
									</a>
								</li>
							</ul>
						<?php endif; ?>
					</nav>
					
					<?php echo $ubermenu_wrap_close; ?>
				</div>
			</div>
		</div>
		
		<?php
			// Shop search-form
			if ( $nm_globals['shop_search_header'] ) {
				//wc_get_template( 'product-searchform_nm.php' );
				get_template_part( 'woocommerce/product', 'searchform_nm' ); // Note: Don't use "wc_get_template()" here in case default checkout is enabled
			}
		?>
		
	</header>
	<!-- /header -->
					