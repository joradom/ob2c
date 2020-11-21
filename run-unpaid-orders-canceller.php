<html>

<head></head>

<body>
	<?php
		// Laad de WordPress-omgeving (relatief pad geldig vanuit elk thema)
		require_once '../../../wp-load.php';
		
		if ( isset( $_GET['import_key'] ) and $_GET['import_key'] === IMPORT_KEY ) {
			// Sluit afgeschermde en gearchiveerde webshops uit
			$sites = get_sites( array( 'site__not_in' => get_site_option('oxfam_blocked_sites'), 'public' => 1, ) );
			$logger = wc_get_logger();
			$context = array( 'source' => 'Clean & Tidy' );
			
			// Let op met SPF-verificatie nu dit een '@oft.be'-adres geworden is!
			$headers[] = 'From: "Helpdesk E-Commerce" <'.get_site_option('admin_email').'>';
			$headers[] = 'Bcc: e-commerce@oft.be';
			$headers[] = 'Content-Type: text/html';
				
			foreach ( $sites as $site ) {
				switch_to_blog( $site->blog_id );
				echo get_bloginfo('name').'<br/>';
				
				$unpaid_args = array(
					'type' => 'shop_order',
					'status' => 'pending',
					'date_created' => '<'.strtotime('-3 days'),
					'limit' => -1,
				);
				$unpaid_orders = wc_get_orders( $unpaid_args );
				
				if ( count( $unpaid_orders ) > 0 ) {
					// Als we dit meteen in dezelfde run doen werkt het niet, dus 2de cron job 5 minuten later met extra parameter
					if ( isset( $_GET['fix_mollie_bug'] ) ) { 
						echo 'UNPAID ORDERS FIX<br/>';
						foreach ( $unpaid_orders as $order ) {
							echo $order->get_order_number().'<br/>';
							$order->update_status( 'cancelled', 'Fix heropenen van bestelling na 1ste annulatie door bug in Mollie-plugin.');
						}
					} else {
						echo 'UNPAID ORDERS<br/>';
						foreach ( $unpaid_orders as $order ) {
							echo $order->get_order_number().'<br/>';
							if ( $order->update_status( 'cancelled', 'Automatisch geannuleerd wegens niet betaald na 3 dagen.' ) ) {
								$logger->info( $order->get_order_number().": geannuleerd wegens onbetaald", $context );
							} else {
								$logger->warning( $order->get_order_number().": annulatie mislukt", $context );
							}
						}
					}
				}

				$unfinished_args = array(
					'type' => 'shop_order',
					'status' => 'processing',
					'date_paid' => '<'.strtotime('-3 weekdays'),
					'limit' => -1,
				);
				$unfinished_orders = wc_get_orders( $unfinished_args );

				// Bij custom statussen moeten we de 'wc'-prefix blijven toevoegen, anders vinden we gewoon alle orders! 
				$unfinished_args['status'] = 'wc-claimed';
				$unfinished_orders = array_merge( $unfinished_orders, wc_get_orders( $unfinished_args ) );

				if ( count( $unfinished_orders ) > 0 ) {
					echo 'LATE ORDERS<br/>';
					foreach ( $unfinished_orders as $order ) {
						// Sluit B2B-orders (die geen gegarandeerde doorlooptijd hebben) uit
						if ( $order->get_meta('is_b2b_sale') !== 'yes' and $order->get_meta('estimated_delivery') !== '' ) {
							// Check of de deadline al gepasseerd is
							if ( current_time('timestamp') > $order->get_meta('estimated_delivery') ) {
								// Verstuur meldingen slechts om de 2 werkdagen
								if ( $order->get_meta('_overdue_reminder_sent') === '' or $order->get_meta('_overdue_reminder_sent') < strtotime('-2 weekdays') ) {
									echo $order->get_order_number().'<br/>';
									$attachments[] = WP_CONTENT_DIR.'/uploads/xlsx/'.$order->get_meta('_excel_file_name');
									$body = '<html><p>Opgelet: bestelling '.$order->get_order_number().' zou tegen '.date_i18n( 'd/m/Y H:i', $order->get_meta('estimated_delivery') ).' geleverd worden maar het order is nog niet als afgerond gemarkeerd in de webshop! Hierdoor blijft de klant online in het ongewisse. Gelieve actie te ondernemen.</p><p><a href="'.$order->get_edit_order_url().'" target="_blank">Bekijk het order in de back-end (inloggen vereist) &raquo;</a></p><p>&nbsp;</p><p><i>Dit is een automatisch bericht.</i></p></html>';
									if ( wp_mail( get_webshop_email(), $order->get_order_number().' wacht op verwerking', $body, $headers, $attachments ) ) {
										$logger->warning( $order->get_order_number().": waarschuwing verstuurd over laattijdige afwerking", $context );
										$order->add_order_note( 'Bestelling nog niet afgewerkt! Automatische herinnering verstuurd naar webshopmailbox.' );
										$order->update_meta_data( '_overdue_reminder_sent', current_time('timestamp') );
										$order->save();
									} else {
										$logger->warning( $order->get_order_number().": waarschuwing versturen mislukt", $context );
									}
									// Voorkom dat we de Excel ook naar de volgende bestemmeling sturen!
									unset( $attachments );
								}
							}
						}
					}
				}

				echo '<br/>';
				restore_current_blog();
			}
		} else {
			die("Access prohibited!");
		}
	?>
</body>

</html>