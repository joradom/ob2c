<?php
	if ( ! defined('ABSPATH') ) exit;
?>

<div class="wrap">
	<h1>Handige tooltjes</h1>
	
	<?php if ( isset( $_GET['updated'] ) ) : ?>
		<div id="message" class="updated notice is-dismissible">
			<p><?php esc_html_e( 'Instellingen bewaard!', 'oxfam-webshop' ); ?></p>
		</div>
	<?php endif; ?>
	
	<p>Hieronder vind je wat interessante tooltjes die Frederik gebruikte om de webshops in de gaten te houden. De MultiSite-setup betekent immers dat je je zeer vaak naar scriptjes moet teruggrijpen die door alle subsites loopen om bv. snel te zien of de nieuwe producten al goed geadopteerd zijn door de webshopbeheerders.<br/>In de code kunnen de parameters eventueel aangepast worden (zie <i>/pages/get-swiss-knife.php</i>).</p>
	
	<h2>Voorradigheid nieuwe producten</h2>
	<?php
		$sites = get_sites( array( 'path__not_in' => array('/'), 'site__not_in' => get_site_option('oxfam_blocked_sites') ) );
		$start_date = date_i18n( 'Y-m-d', strtotime('-30 days') );
		$end_date = date_i18n('Y-m-d');
		check_local_stocks( array( 21055, 22034 ), $sites );
	?>
	
	<h2>Recente verkopen van nieuwe producten</h2>
	<?php report_sales_by_product( array( 21055, 22034 ), $sites, $start_date, $end_date ); ?>
	
	<h2>Recente inruilingen van kortingsbonnen</h2>
	<?php check_coupons_on_recent_orders( $start_date, $sites ); ?>
	
	<h2>Postcodeverdeling</h2>
	<?php list_shops_per_postcode( $sites ); ?>
	
	<form action="edit.php?action=woonet-woocommerce-swiss-knife-update" method="POST">
		<table class="form-table">
			<?php
				// submit_button();
			?>
		</table>
	</form>
	
	<h2>Activiteitenlogs</h2>
	<?php
		// Let op dat de logs niet te snel groeien en de pagina vertragen!
		$file_path = dirname( ABSPATH, 1 ) . '/activity.log';
		
		if ( ( $handle = fopen( $file_path, 'r' ) ) !== false ) {
			echo '<table style="width: 100%;">';
			while ( $line = fgetcsv( $handle, 0, "\t" ) ) {
				// Reset variabele
				$row = '';
				foreach ( $line as $column ) {
					$row .= '<td>'.$column.'</td>';
				}
				echo '<tr>'.$row.'</tr>';
			}
			fclose( $handle );
			echo '</table>';
		} else {
			echo '<p>Nog geen logs beschikbaar.</p>';
		}
	?>
</div>

<?php
	function check_local_stocks( $skus, $sites ) {
		$partner_slugs = array();
		$orders_in_delete_list = array();
		$orders_deleted = 0;
		
		foreach ( $sites as $site ) {
			switch_to_blog( $site->blog_id );
			echo '<strong>'.get_bloginfo('name').':</strong> ';
			
			$in_stock = array();
			foreach ( $skus as $sku ) {
				$product_id = wc_get_product_id_by_sku( $sku );
				$product = wc_get_product( $product_id );
				if ( $product !== false ) {
					if ( $product->get_stock_status() === 'instock' ) {
						$in_stock[] = $sku.' '.$product->get_name();
					}
				}
			}
			
			if ( count( $in_stock ) === 0 ) {
				echo '<span style="color: red;">geen enkel product op voorraad!</span>';
			} else {
				echo implode( ' / ', $in_stock );
			}
			echo '<br/>';
		}
	}
	
	function report_sales_by_product( $skus_to_check, $sites, $start_date, $end_date = false ) {
		if ( $end_date === false ) {
			$end_date = $start_date;
		}
		
		$skus_sold = array();
		foreach ( $skus_to_check as $sku_to_check ) {
			$skus_sold[ $sku_to_check ] = array();
			$date = $start_date;
			while ( $date <= $end_date ) {
				$skus_sold[ $sku_to_check ][ $date ] = 0;
				$date = date( 'Y-m-d', strtotime( '+1 day', strtotime( $date ) ) );
			}
		}
		
		foreach ( $sites as $site ) {
			switch_to_blog( $site->blog_id );
			
			$args = array(
				'type' => 'shop_order',
				'status' => array('wc-completed'),
				'date_created' => $start_date.'...'.$end_date,
				'limit' => -1,
			);
			$orders = wc_get_orders( $args );
			
			$before = $skus_sold[ $skus_to_check[0] ][ $start_date ];
			
			foreach ( $orders as $order ) {
				$order_date = $order->get_date_created()->date_i18n('Y-m-d');
				$line_items = $order->get_items();
				foreach ( $line_items as $order_item_product ) {
					$local_product = $order_item_product->get_product();
					if ( $local_product !== false and in_array( $local_product->get_sku(), $skus_to_check ) ) {
						// Houdt geen rekening met eventuele terugbetalingen
						$skus_sold[ $local_product->get_sku() ][ $order_date ] += $order_item_product->get_quantity();
					}
					unset( $local_product );
				}
			}
			
			$after = $skus_sold[ $skus_to_check[0] ][ $start_date ];
			// Print ter info de verkopen op de eerste dag van het eerste product in de lijst
			echo '<strong>'.get_bloginfo('name').':</strong> '.( $after - $before ).'x '.$skus_to_check[0].' op '.$start_date.'<br/>';
		}
		
		foreach ( $skus_sold as $sku => $value ) {
			echo $sku.': '.array_sum( $skus_sold[ $sku ] ).' in totaal<br/>';
			foreach ( $value as $date => $sold ) {
				echo $date.';'.$sold.'<br/>';
			}
			echo '<br/>';
		}
	}
	
	function check_coupons_on_recent_orders( $since, $sites ) {
		$args = array(
			'type' => 'shop_order',
			'date_created' => '>='.$since,
			'limit' => -1,
		);
		$orders = array();
		
		foreach ( $sites as $site ) {
			switch_to_blog( $site->blog_id );
			$all_orders = wc_get_orders( $args );
			
			foreach ( $all_orders as $wc_order ) {
				$output = '<a href="'.$wc_order->get_edit_order_url().'" target="_blank">'.$wc_order->get_order_number().'</a> op '.$wc_order->get_date_created()->date_i18n('d/m/Y').' à '.wc_price( $wc_order->get_total() );
				$extras = array();
				
				foreach ( $wc_order->get_coupons() as $coupon ) {
					$extras[] = $coupon->get_code().' toegepast';
				}
				
				if ( ( $amount = ob2c_get_total_voucher_amount( $wc_order ) ) > 0 ) {
					$extras[] = 'bevat digicheque t.w.v. '.wc_price( $amount );
				}
				
				if ( count( $extras ) > 0 ) {
					$output .= ': '.implode( ', ', $extras );
				}
				$orders[ $wc_order->get_order_number() ] = $output;
			}
			
			restore_current_blog();
		}
		
		echo '<ul>';
		ksort( $orders );
		foreach ( $orders as $string ) {
			echo '<li>'.$string.'</li>';
		}
		echo '</ul>';
	}
	
	function list_shops_per_postcode( $sites ) {
		$postcodes = get_site_option('oxfam_flemish_zip_codes');
		$list = array();
		
		foreach ( $sites as $site ) {
			switch_to_blog( $site->blog_id );
			foreach ( get_oxfam_covered_zips() as $zip ) {
				if ( array_key_exists( $zip, $list ) ) {
					$list[ $zip ][] = get_webshop_name(true);
				} else {
					$list[ $zip ] = array( get_webshop_name(true) );
				}
			}
			restore_current_blog();
		}
		ksort( $list, SORT_NUMERIC );
		foreach ( $list as $postcode => $webshops ) {
			echo $postcode.' '.$postcodes[ $postcode ].': '.'<span style="color: '.( count( $webshops ) > 1 ? 'orange' : '' ).'">'.implode( ', ', $webshops ).'</span><br/>';
			unset( $postcodes[ $postcode ] );
		}
		
		if ( count( $postcodes ) > 0 ) {
			echo '<p style="color: red;">Opgelet: postcodes '.implode( ', ', array_keys( $postcodes ) ).' zijn nog niet gelinkt aan een webshop!</p>';
		}
	}
?>