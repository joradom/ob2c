<?php
	
	// WordPress volledig inladen, zodat we alle helper functies en constanten kunnen aanspreken
	// Gebruik van absoluut pad vereist om ook te werken als cron job met PHP i.p.v. WGET
	// dirname() enkel geldig vanuit subfolder in subfolder van themamap
	require_once dirname( __FILE__, 6 ) . '/wp-load.php';
	
	if ( ( isset( $_GET['import_key'] ) and $_GET['import_key'] === IMPORT_KEY ) or ( isset( $argv ) and $argv[1] === 'RUN_FROM_CRON' ) ) {
		// Vraag alle huidige winkels in de OBE-site op
		$obe_stores = get_external_wpsl_stores();
		
		// Ga enkel verder als we effectief resultaten doorkregen
		if ( count( $obe_stores ) < 1 ) {
			die("No stores retrieved!");
		}
		
		// Verplaats alle WP Stores naar de prullenbak
		$all_store_args = array(
			'post_type'	=> 'wpsl_stores',
			'post_status' => 'publish',
			'posts_per_page' => -1,
		);
		$trashers = new WP_Query( $all_store_args );
		
		if ( $trashers->have_posts() ) {
			while ( $trashers->have_posts() ) {
				$trashers->the_post();
				wp_trash_post( get_the_ID() );
			}
			wp_reset_postdata();
		}
		
		// Leeg de 'store data'-cache zodat openingsuren onmiddellijk bijgewerkt worden, ook als er nog een transient bestaat van vòòr de wijzigingen
		global $wpdb;
		$wpdb->query( "DELETE FROM `$wpdb->sitemeta` WHERE `meta_key` LIKE ('%_store_data')" );
		
		// Sluit hoofdniveau + afgeschermde en niet-openbare webshops uit
		// Dit is de eerste stap in het uitfaseren van een webshop, archiveren gebeurt later
		$sites = get_sites( array( 'path__not_in' => array('/'), 'site__not_in' => get_site_option('oxfam_blocked_sites'), 'public' => 1 ) );
		$site_ids_vs_blog_ids = array();
		
		foreach ( $sites as $site ) {
			switch_to_blog( $site->blog_id );
			
			if ( ! is_main_site() ) {
				// Maak de lokale kaart aan met alle deelnemende winkelpunten, exclusief externen
				$locations = ob2c_get_pickup_locations();
				if ( count( $locations ) > 0 ) {
					$local_file = fopen( WP_CONTENT_DIR . '/maps/site-'.$site->blog_id.'.kml', 'w' );
					$txt = "<?xml version='1.0' encoding='UTF-8'?><kml xmlns='http://www.opengis.net/kml/2.2'><Document>";
					// Icon upscalen boven 32x32 pixels werkt helaas niet, <BalloonStyle><bgColor>ffffffbb</bgColor></BalloonStyle> evenmin
					$txt .= "<Style id='pickup'><IconStyle><w>32</w><h>32</h><Icon><href>".get_stylesheet_directory_uri()."/images/markers/placemarker-afhaling.png</href></Icon></IconStyle></Style>";
					
					foreach ( $locations as $shop_node => $shop_name ) {
						// Want get_shop_address() en get_oxfam_shop_data('ll') enkel gedefinieerd voor Wereldwinkels!
						if ( $shop_node > 0 ) {
							$txt .= "<Placemark>";
							$txt .= "<name><![CDATA[".$shop_name."]]></name>";
							$txt .= "<styleUrl>#pickup</styleUrl>";
							$oww_store_data = get_external_wpsl_store( $shop_node );
							$txt .= "<description><![CDATA[<p>".get_shop_address( array( 'node' => $shop_node ) )."</p><p><a href='https://oxfambelgie.be/winkels".$oww_store_data['slug']."' target='_blank'>Naar de winkelpagina »</a></p>]]></description>";
							// Longitude/Latitude (= coördinaten omkeren!)
							$ll = explode( ',', get_oxfam_shop_data( 'll', $shop_node, false ) );
							$txt .= "<Point><coordinates>".$ll[1].",".$ll[0]."</coordinates></Point>";
							$txt .= "</Placemark>";
							
							// Maak een handige lijst met alle shop-ID's en hun bijbehorende blog-ID
							// Ook als we de kaarten volledig zouden uitschakelen blijft deze stap nodig voor de rest van het script!
							$site_ids_vs_blog_ids[ $shop_node ] = array(
								'blog_id' => get_current_blog_id(),
								// Haal expliciet de HTTPS-link op (ook als we via cron job werken!)
								'blog_url' => get_site_url( get_current_blog_id(), '/', 'https' ),
								'home_delivery' => does_home_delivery(),
							);
						}
					}
					
					$txt .= "</Document></kml>";
					fwrite( $local_file, $txt );
					fclose( $local_file );
				}
				
				delete_transient('oxfam_neighbouring_webshops');
			}
			
			restore_current_blog();
		}
		
		var_dump_pre( $site_ids_vs_blog_ids );
		
		$oww_stores = array();
		$oww_stores_imported = 0;
		
		foreach ( $obe_stores as $obe_store ) {
			// Neem enkel Wereldwinkels op in store selector (o.b.v. titel of assortiment)
			// Sluit Dinant uit (biedt ook voeding aan ...)
			$forbidden_nodes = array( 433 );
			if ( ( stristr( $obe_store['title'], 'Oxfam-Wereldwinkel' ) or in_array( 'Voeding', $obe_store['assortment'] ) ) and ! in_array( $obe_store['id'], $forbidden_nodes ) ) {
				$oww_stores[] = $obe_store;
			}
		}
		
		foreach ( $oww_stores as $oww_store_data ) {
			// Zoek op de hoofdsite de zonet verwijderde WP Store op die past bij de post-ID
			$post_args = array(
				'post_type'	=> 'wpsl_stores',
				'post_status' => 'trash',
				'posts_per_page' => 1,
				'meta_key' => 'wpsl_oxfam_shop_node',
				'meta_value' => $oww_store_data['id'],
			);
			$wpsl_stores = new WP_Query( $post_args );
			
			if ( $wpsl_stores->have_posts() ) {
				$wpsl_stores->the_post();
				$wpsl_store_id = get_the_ID();
				wp_reset_postdata();
			} else {
				// Maak nieuwe store aan door de ID op 0 te zetten
				$wpsl_store_id = 0;
			}
			
			// Latitude/Longitude
			$ll = explode( ',', $oww_store_data['location']['ll'] );
			sort( $oww_store_data['closing_days'] );
			
			if ( array_key_exists( $oww_store_data['id'], $site_ids_vs_blog_ids ) ) {
				// Dit moét uit de lijst met sites komen (data in OWW-site kan vervuild zijn met andere webshops!)
				$webshop_url = $site_ids_vs_blog_ids[ $oww_store_data['id'] ]['blog_url'];
				$webshop_blog_id = $site_ids_vs_blog_ids[ $oww_store_data['id'] ]['blog_id'];
				$home_delivery = $site_ids_vs_blog_ids[ $oww_store_data['id'] ]['home_delivery'];
			} else {
				$webshop_url = '';
				$webshop_blog_id = '';
				$home_delivery = false;
			}
			
			$store_args = array(
				'ID' =>	$wpsl_store_id,
				'post_title' => str_replace( 'Oxfam-Wereldwinkel ', '', $oww_store_data['title'] ),
				'post_status' => 'publish',
				'post_author' => 1,
				'post_type' => 'wpsl_stores',
				'meta_input' => array(
					'wpsl_oxfam_shop_node' => $oww_store_data['id'],
					'wpsl_address' => $oww_store_data['location']['place'],
					'wpsl_city' => $oww_store_data['location']['city'],
					'wpsl_zip' => $oww_store_data['location']['zipcode'],
					'wpsl_country' => 'België',
					'wpsl_lat' => $ll[0],
					'wpsl_lng' => $ll[1],
					'wpsl_phone' => $oww_store_data['location']['telephone'],
					'wpsl_url' => $oww_store_data['link'],
					'wpsl_webshop' => $webshop_url,
					'wpsl_webshop_blog_id' => $webshop_blog_id,
					// Vul hier bewust het algemene mailadres in (ook voor winkels mét webshop)
					'wpsl_email' => $oww_store_data['location']['mail'],
					// Openingsuren en sluitingsdagen ook opslaan in WPSL-object (ter info)
					'wpsl_hours' => $oww_store_data['opening_hours'],
					'wpsl_holidays' => implode( ', ', $oww_store_data['closing_days'] ),
				),
			);
			
			if ( $webshop_blog_id !== '' ) {
				// Sluitingsdagen opslaan als winkelspecifieke site optie
				if ( count( $oww_store_data['closing_days'] ) > 0 ) {
					update_site_option( 'oxfam_holidays_'.$oww_store_data['id'], $oww_store_data['closing_days'] );
				} else {
					// Verwijder de optie zodat we géén lege array achterlaten die de default waardes blokkeert
					delete_site_option( 'oxfam_holidays_'.$oww_store_data['id'] );
				}
			}
			
			$result_post_id = wp_insert_post( $store_args );
			if ( ! is_wp_error( $result_post_id ) ) {
				// Verwijder de trash keys die elke dag toegevoegd worden door wp_insert_post() te gebruiken
				if ( delete_post_meta( $result_post_id, '_wp_old_date' ) ) {
					var_dump_pre( "Vorige publicatiedatums gewist op post-ID ".$result_post_id );
				}
				if ( delete_post_meta( $result_post_id, '_wp_old_slug' ) ) {
					var_dump_pre( "Vorige URL's gewist op post-ID ".$result_post_id );
				}
				if ( delete_post_meta( $result_post_id, '_wp_trash_meta_time' ) ) {
					var_dump_pre( "Datums van verwijdering gewist op post-ID ".$result_post_id );
				}
				if ( delete_post_meta( $result_post_id, '_wp_trash_meta_status' ) ) {
					var_dump_pre( "Statussen voor verwijdering gewist op post-ID ".$result_post_id );
				}
				
				// Winkelcategorie instellen (ter info)
				wp_set_object_terms( $result_post_id, 'afhaling', 'wpsl_store_category', false );
				if ( $home_delivery ) {
					// Tweede categorie instellen indien niet enkel afhaling
					wp_set_object_terms( $result_post_id, 'levering', 'wpsl_store_category', true );
				}
				
				$oww_stores_imported++;
			}
		}
		
		write_log( $oww_stores_imported." winkels via API geïmporteerd uit oxfambelgie.be" );
		
		if ( $oww_stores_imported <= 150 ) {
			$headers = array();
			$headers[] = 'From: "Helpdesk E-Commerce" <'.get_site_option('admin_email').'>';
			$headers[] = 'Content-Type: text/html';
			$body = '<p>Er werden slechts '.$oww_stores_imported.' winkels geïmporteerd tijdens de dagelijkse synchronisatie met <a href="https://oxfambelgie.be/api/v1/stores" target="_blank">oxfambelgie.be</a>. Controleer <a href="'.admin_url('edit.php?post_type=wpsl_stores').'" target="_blank">het winkeloverzicht</a> om te zien of er geen winkels achterbleven in de prullenbak.</p><p><i>Dit is een automatisch bericht.</i></p>';
			wp_mail( array( 'info@fullstackahead.be' ), 'Mogelijk probleem met winkelimport', '<html>'.$body.'</html>', $headers );
		}
	} else {
		die("Access prohibited!");
	}
	
?>