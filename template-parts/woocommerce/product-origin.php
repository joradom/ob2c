<?php 
	global $product;
	
	$partners = $args['partners'];
	$partners_with_quote = array_filter( $partners, function( $partner ) {
		return ! empty( $partner['partner_quote']['rendered'] );
	} );
	
	if ( current_user_can('update_core') ) {
		var_dump_pre( $partners_with_quote );
	}
?>

<div class="product-origin">
	<?php if ( count( $partners ) > 0 ) : ?>
		<p class="partners">
			<?php
				$terms = array();
				foreach ( $partners as $partner ) {
					// Niet alle partners bevatten de eigenschap 'link' naar de externe partnerpagina (o.a. alle C-partners)
					if ( ! empty( $partner['link'] ) ) {
						$output = '<a href="'.esc_url( $partner['link'] ).'">' . $partner['name'] . '</a>';
					} else {
						$output = $partner['name'];
					}
					$terms[] = $output . ' (' . $partner['country'] . ')';
				}
				echo _n( 'Producent', 'Producenten', count( $terms ) ) . ': ' . implode( ', ', $terms );
			?>
		</p>
	<?php elseif ( $product->get_attribute('countries') !== '' ) : ?>
		<p class="countries">
			Herkomst: <?= $product->get_attribute('countries'); ?>
		</p>
	<?php endif; ?>
</div>