<?php
	if ( ! defined('ABSPATH') ) exit;
?>

<div class="wrap">
	<h1>Stel de voorraad van je lokale webshop in</h1>

	<p>Vink een product aan om het op de homepage te plaatsen of selecteer de juiste voorraadstatus om het in of uit de online verkoop te halen. Je aanpassing wordt onmiddellijk opgeslagen! Een bevestigingsvenster behoedt je voor onbedoelde wijzigingen. Tip: met Ctrl+F kun je snel zoeken naar een product. We voegen nog een compactere weergave toe (zonder foto's en pop-ups) om sneller te kunnen werken. Later wordt het misschien mogelijk om voorraadlijsten in te lezen uit bv. ShopPlus.</p>

	<p>Nieuwe producten, die in de loop van de voorbije twee maanden beschikbaar werden op BestelWeb, zullen een blauwe achtergrond hebben. Ze verschijnen aanvankelijk als 'niet op voorraad' in jullie lokale webshop, zodat je alle tijd hebt om te beslissen of je het product zal inkopen en online wil aanbieden.</p>

	<p>Producten die momenteel onbeschikbaar zijn op BestelWeb krijgen een gele achtergrond, zodat het duidelijk is dat dit product misschien op zijn laatste benen loopt. Oude producten die definitief niet meer te bestellen zijn bij Oxfam Fair Trade worden pas na 6 maanden uit de moederdatabank verwijderd (en dus uit jullie webshop), zodat we er zeker kunnen van zijn dat er geen lokale voorraden meer bestaan. Dit zal ook aangekondigd worden op het dashboard.</p>

	<div id="oxfam-products">
		<?php
			// Query alle gepubliceerde producten en stel voorraadstatus + uitlichting in
			// Ordenen op artikelnummer, nieuwe producten van de afgelopen maand rood markeren?
			$args = array(
				'post_type'			=> 'product',
				'post_status'		=> array( 'publish' ),
				'posts_per_page'	=> -1,
				'meta_key'			=> '_sku',
				'orderby'			=> 'meta_value_num',
				'order'				=> 'ASC',
			);

			$products = new WP_Query( $args );
			
			if ( $products->have_posts() ) {
				$i = 0;
				$instock_cnt = 0;
				$featured_cnt = 0;
				$content = '<div style="display: table; width: 100%;">';
				while ( $products->have_posts() ) {
					$products->the_post();
					$product = wc_get_product( get_the_ID() );
					// Verhinder dat leeggoed ook opduikt
					if ( is_numeric( $product->get_sku() ) ) {
						if ( $product->is_in_stock() ) {
							$class = 'border-color-green';
							$instock_cnt++;
						} else {
							$class = 'border-color-red';
						}
						if ( $product->is_featured() ) {
							$featured_cnt++;
						}
						$content .= '<div id="'.get_the_ID().'" class="compact';
						if ( get_the_date('U') > strtotime('-2 months') ) $content .= ' new';
						// CHECK STOCK VAN MOEDER PRODUCT, NIET VAN DEZE!
						// $main_product_id = get_post_meta( get_the_ID(), '_woonet_network_is_child_product_id', true );
						// switch_to_blog(1);
						// $main_product = wc_get_product( $main_product_id );
						// restore_current_blog();
						// if ( ! $main_product->is_in_stock() ) echo ' old';
						$content .= '">';
							$content .= '<div class="cell" style="padding: 0.25em; width: 3%; text-align: center;"><a href="'.get_permalink().'" target="_blank">'.$product->get_image( 'wc_order_status_icon', null, false ).'</a></div>';
							$content .= '<div class="cell '.$class.'" style="width: 40%; text-align: left;"><span class="title">'.$product->get_sku().': '.$product->get_title().'</span></div>';
							$content .= '<div class="cell"><select id="'.get_the_ID().'-stockstatus">';
								$content .= '<option value="instock" '.selected( $product->is_in_stock(), true, false ).'>Op voorraad</option>';
								$content .= '<option value="outofstock" '.selected( $product->is_in_stock(), false, false ).'>Uitverkocht</option>';
							$content .= '</select></div>';
							$content .= '<div class="cell"><input type="checkbox" id="'.get_the_ID().'-featured" '.checked( $product->is_featured(), true, false ).'>';
							$content .= ' <label for="'.get_the_ID().'-featured">In de kijker?</label></div>';
						$content .= '<div class="cell output"></div>';
						$content .= '</div>';
						$i++;
					}
				}
				$content .= '</div>';
				wp_reset_postdata();
				echo '<p style="text-align: right; width: 100%;">Deze pagina toont '.$i.' producten, waarvan er momenteel <span id="instock-cnt">'.$instock_cnt.'</span> voorradig zijn en <span id="featured-cnt">'.$featured_cnt.'</span> in de kijker staan.</p>';
				echo $content;
				echo '<p style="text-align: right; width: 100%;">Deze pagina toont '.$i.' producten, waarvan er momenteel <span id="instock-cnt">'.$instock_cnt.'</span> voorradig zijn en <span id="featured-cnt">'.$featured_cnt.'</span> in de kijker staan.</p>';
				
			}

			add_action('admin_footer', 'oxfam_action_javascript');

			function oxfam_action_javascript() { ?>
				<script type="text/javascript">
					jQuery(document).ready(function() {
						jQuery("#oxfam-products input[type=checkbox], #oxfam-products select").on( 'change', function() {
							var parts = jQuery(this).attr('id').split("-");
							var id = parts[0];
							var meta = parts[1];
							if ( meta == 'featured' ) {
								ajaxCall(id, meta, jQuery(this).is(":checked"));
							}
							if ( meta == 'stockstatus' ) {
								var value = jQuery(this).find(":selected").val();
								ajaxCall( id, meta, value );
							}
						});

						var tries = 0;

						function ajaxCall(id, meta, value) {
							jQuery("#"+id).find(".output").html("Aan het opslaan ...");

							var input = {
								'action': 'oxfam_stock_action',
								'id': id,
								'meta': meta,
								'value': value,
							};
				    		
				    		jQuery.ajax({
				    			type: 'POST',
	  							url: ajaxurl,
				    			data: input,
				    			dataType: 'html',
				    			success: function(msg) {
							    	tries = 0;
									
									if ( value == 'outofstock' ) {
										jQuery("#"+id).find('.border-color-green').addClass('border-color-red').removeClass('border-color-green');
									} else if ( value == 'instock' ) {
										jQuery("#"+id).find('.border-color-red').addClass('border-color-green').removeClass('border-color-red');
									}
									jQuery("#instock-cnt").html(jQuery("#oxfam-products").find(".border-color-green").length);
									jQuery("#featured-cnt").html(jQuery("#oxfam-products").find("input[type=checkbox]:checked").length);
							    	
							    	jQuery("#"+id).find(".output").html("Wijzigingen opgeslagen!").delay(1500).animate({
							    		opacity: 0,
							    	}, 1000, function(){
										jQuery(this).html("&nbsp;").css('opacity', 1);
									});
								},
								error: function(jqXHR, statusText, errorThrown) {
									tries++;
									if ( tries < 5 ) {
										ajaxCall(id, meta, value);
									} else {
										tries = 0;
										jQuery("#"+id).find(".output").html("Wijzigingen mislukt!").delay(15000).animate({
								    		opacity: 0,
								    	}, 1000, function(){
											jQuery(this).html("&nbsp;").css('opacity', 1);
										});
									}
								},
							});
						}
					});
				</script>
			<?php }
		?>
	</div>
</div>