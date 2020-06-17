<?php

	if ( ! defined('ABSPATH') ) exit;

	use Automattic\WooCommerce\Client;
	use Automattic\WooCommerce\HttpClient\HttpClientException;

	// Alle subsites opnieuw indexeren m.b.v. WP-CLI: wp site list --field=url | xargs -n1 -I % wp --url=% relevanssi index
	
	// Sitemap van afbeeldingen uitschakelen
	add_filter( 'jetpack_sitemap_image_skip_post', '__return_true' );

	// Verhinder bekijken van site door mensen die geen beheerder zijn van deze webshop
	add_action( 'init', 'force_user_login' );
	
	function force_user_login() {
		$url = get_current_url();
		
		// Demosite tijdelijk openstellen: or get_current_site()->domain !== 'shop.oxfamwereldwinkels.be'
		if ( in_array( get_current_blog_id(), get_site_option('oxfam_blocked_sites') ) ) {
			if ( ! is_user_logged_in() ) {
				// Nooit redirecten: inlog-, reset-, activatiepagina en WC API calls
				if ( preg_replace( '/\?.*/', '', $url ) != preg_replace( '/\?.*/', '', wp_login_url() ) and preg_replace( '/\?.*/', '', $url ) != preg_replace( '/\?.*/', '', wc_lostpassword_url() ) and ! strpos( $url, 'activate.php' ) and ! strpos( $url, 'wc-api' ) ) {
					// Stuur gebruiker na inloggen terug naar huidige pagina
					wp_safe_redirect( wp_login_url($url) );
					exit();
				}
			} elseif ( ! is_user_member_of_blog( get_current_user_id(), get_current_blog_id() ) ) {
				// Toon tijdelijke boodschap, het heeft geen zin om deze gebruiker naar de inlogpagina te sturen!
				wp_safe_redirect( network_site_url('/wp-content/blog-suspended.php') );
				exit();
			}
		}

		// Stuur Digizine-lezers meteen door op basis van postcode in hun profiel
		if ( is_main_site() ) {
			$suffix = '';
			if ( isset( $_GET['addSku'] ) and ! empty( $_GET['addSku'] ) ) {
				$suffix = 'addSku='.$_GET['addSku'];
			}
			if ( isset( $_GET['landingZip'] ) ) {
				$zip = str_replace( ',', '', str_replace( '%2C', '', $_GET['landingZip'] ) );
				$global_zips = get_shops();
				if ( strlen( $zip ) === 4 ) {
					if ( array_key_exists( $zip, $global_zips ) ) {
						wp_safe_redirect( $global_zips[$zip].'?referralZip='.$zip.'&'.$suffix );
						exit();
					}
				}
			}
			if ( isset( $_GET['addSku'] ) ) {
				if ( isset( $_COOKIE['latest_subsite'] ) ) {
					$destination_blog = get_blog_details( $_COOKIE['latest_subsite'], false );
					if ( $destination_blog->path !== '/' ) {
						wp_safe_redirect( network_site_url($destination_blog->path.'?'.$suffix) );
						exit();
					}
				} else {
					// Vermijd dubbele output (door heen-en-weer navigeren?)
					wc_clear_notices();
					wc_add_notice( __( 'Vooraleer we dit product in je winkelmandje kunnen leggen, dien je hieronder nog even je favoriete winkel / postcode te kiezen. We bewaren je keuze in deze browser maar via de knop rechtsboven kun je steeds een andere webshop selecteren.', 'oxfam-webshop' ), 'error' );
				}
			}
		} else {
			// Dit updatet de cookie bij elke pageview!
			setcookie( 'latest_subsite', get_current_blog_id(), time() + 30*DAY_IN_SECONDS, '/' );
			if ( isset( $_GET['addSku'] ) and ! empty( $_GET['addSku'] ) ) {
				add_action( 'template_redirect', 'add_product_to_cart_by_get_parameter' );
			}
		}
	}

	function get_current_url() {
		$url = ( isset( $_SERVER['HTTPS'] ) and 'on' === $_SERVER['HTTPS'] ) ? 'https' : 'http';
		$url .= '://' . $_SERVER['SERVER_NAME'];
		$url .= in_array( $_SERVER['SERVER_PORT'], array( '80', '443' ) ) ? '' : ':' . $_SERVER['SERVER_PORT'];
		$url .= $_SERVER['REQUEST_URI'];
		return $url;
	}

	function add_product_to_cart_by_get_parameter() {
		if ( ! is_admin() ) {
			$skus = explode( ',', $_GET['addSku'] );
			$already_added = 0;

			foreach ( $skus as $sku ) {
				$product_to_add = wc_get_product( wc_get_product_id_by_sku( $sku ) );
				
				// Enkel proberen toevoegen indien het artikelnummer bestaat
				if ( $product_to_add !== false ) {
					// Voorkom opnieuw toevoegen bij het terugkeren!
					
					// VIA WINKELMANDJE
					$found = false;
					if ( WC()->session->has_session() ) {
						foreach ( WC()->cart->get_cart() as $cart_item_key => $values ) {
							$product_in_cart = $values['data'];
							if ( $product_in_cart->get_id() == $product_to_add->get_id() ) {
								$found = true;
								break;
							}
						}
					}

					// VIA SESSIEPARAMETER
					// WC()->session->set( 'addsku_action_'.$_GET['addSku'].'_executed', 0 );
					// if ( WC()->session->has_session() ) {
					// 	$already_added = WC()->session->get( 'addsku_action_'.$_GET['addSku'].'_executed', 0 );
					// } else {
					// 	$already_added = 0;
					// }
					
					if ( ! $found ) {
						if ( WC()->cart->add_to_cart( $product_to_add->get_id(), 1 ) === false ) {
							// Ga naar de productdetailpagina indien de poging mislukte (wegens geen voorraad)
							// Notice over uitgeputte voorraad verschijnt automatisch!
							wp_safe_redirect( $product_to_add->get_permalink() );
							exit();
						} else {
							$already_added++;

							// PHP-functie add_to_cart() veroorzaakt niet automatisch een GA-event!
							// $parameters = array();
							// $parameters['category'] = "'" . __( 'Products', 'woocommerce-google-analytics-integration' ) . "'";
							// $parameters['action'] = "'" . __( 'Add to Cart', 'woocommerce-google-analytics-integration' ) . "'";
							// $parameters['label'] = $product_to_add->get_sku();
							// $code = "" . WC_Google_Analytics_JS::get_instance()->tracker_var() . "( 'ec:addProduct', {";
							// $code .= "'id': ".$product_to_add->get_sku().",";
							// $code .= "'quantity': 1,";
							// $code .= "} );";
							// $parameters['enhanced'] = $code;
							// " . $parameters['enhanced'] . "
							// " . self::tracker_var() . "( 'ec:setAction', 'add' );
							// " . self::tracker_var() . "( 'send', 'event', 'UX', 'click', 'add to cart' );
							// Makkelijker om gewoon klik op 'add_to_cart'-button te simuleren?
						}
					}
				} else {
					wc_add_notice( sprintf( __( 'Sorry, artikelnummer %s is nog niet beschikbaar voor online verkoop.', 'oxfam-webshop' ), $sku ), 'error' );
					wp_safe_redirect( wc_get_page_permalink('shop') );
					exit();
				}
			}

			if ( $already_added === count( $skus ) and $already_added > 1 ) {
				// Redirect naar het winkelmandje, zodat kortingsbonnen op combinatiepromo's zeker verschijnen
				wp_safe_redirect( wc_get_cart_url() );
				exit();
			}
		}
	}
	
	// Vuile truc om te verhinderen dat WordPress de afmeting van 'large'-afbeeldingen verkeerd weergeeft
	$content_width = 1500;

	// Google Analytics wordt standaard uitgeschakeld voor users met de rechten 'manage_options' (= enkel superadmins)
	add_filter( 'woocommerce_ga_disable_tracking', 'disable_ga_tracking_for_certain_users', 10, 2 );

	function disable_ga_tracking_for_certain_users( $disable, $type ) {
		// Parameter $type bevat het soort GA-tracking
		if ( current_user_can('manage_woocommerce') or ! cn_cookies_accepted() ) {
			return true;
		} else {
			return false;
		}
	}

	// Activeer Google Tag Manager (JS)
	add_action( 'wp_head', 'add_google_tag_manager_js', 100 );

	function add_google_tag_manager_js() {
		echo '<link href="https://fonts.googleapis.com/css2?family=Pacifico&display=swap" rel="stylesheet">';
		if ( ! current_user_can('manage_woocommerce') and cn_cookies_accepted() ) {
			?>
			<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
			new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
			j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
			'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
			})(window,document,'script','dataLayer','GTM-KMKZ7HH');</script>
			<?php
		}
	}

	// Activeer Google Tag Manager (no JS)
	add_action( 'wp_head', 'add_google_tag_manager_no_js', 100 );

	function add_google_tag_manager_no_js() {
		if ( ! current_user_can('manage_woocommerce') and cn_cookies_accepted() ) {
			?>
			<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-KMKZ7HH"
			height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
			<?php
		}
	}

	// Activeer Facebook Pixel (JS)
	add_action( 'wp_footer', 'add_facebook_pixel_js', 200 );

	function add_facebook_pixel_js() {
		if ( ! current_user_can('manage_woocommerce') and cn_cookies_accepted() ) {
			?>
			<script>!function(f,b,e,v,n,t,s)
			{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
			n.callMethod.apply(n,arguments):n.queue.push(arguments)};
			if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
			n.queue=[];t=b.createElement(e);t.async=!0;
			t.src=v;s=b.getElementsByTagName(e)[0];
			s.parentNode.insertBefore(t,s)}(window, document,'script',
			'https://connect.facebook.net/en_US/fbevents.js');
			fbq('init', '1964131620531187');
			fbq('track', 'PageView');</script>
			<?php
		}
	}

	// Activeer Facebook Pixel (no JS)
	add_action( 'wp_footer', 'add_facebook_pixel_no_js', 200 );

	function add_facebook_pixel_no_js() {
		if ( ! current_user_can('manage_woocommerce') and cn_cookies_accepted() ) {
			?>
			<noscript><img height="1" width="1" style="display:none"
			src="https://www.facebook.com/tr?id=1964131620531187&ev=PageView&noscript=1"
			/></noscript>
			<?php
		}
	}

	// Activeer Facebook Messenger
	add_action( 'wp_footer', 'add_facebook_messenger', 300 );

	function add_facebook_messenger() {
		if ( ! current_user_can('manage_woocommerce') and cn_cookies_accepted() and ( is_main_site() or is_front_page() or is_cart() ) ) {
			?>
			<div id='fb-root'></div>
			<script>(function(d, s, id) {
				var js, fjs = d.getElementsByTagName(s)[0];
				js = d.createElement(s); js.id = id;
				js.src = 'https://connect.facebook.net/nl_NL/sdk/xfbml.customerchat.js#xfbml=1&version=v2.12&autoLogAppEvents=1';
				fjs.parentNode.insertBefore(js, fjs);
				}(document, 'script', 'facebook-jssdk'));</script>
			<div class='fb-customerchat' attribution="wordpress" page_id='116000561802704' theme_color='#61A534' logged_in_greeting='Is er nog iets onduidelijk? Vraag het ons!'logged_out_greeting='Is er nog iets onduidelijk? Log in via Facebook en vraag het ons!'></div>
			<?php
		}
	}

	// Sta HTML-attribuut 'target' toe in beschrijvingen van taxonomieën
	add_action( 'init', 'allow_target_tag', 20 );

	function allow_target_tag() { 
		global $allowedtags;
		$allowedtags['a']['target'] = 1;
	}

	// Voeg extra CSS-klasses toe aan body
	add_filter( 'body_class', 'add_main_site_class' );

	function add_main_site_class( $classes ) {
		if ( is_main_site() ) {
			$classes[] = 'portal';
		}
		if ( is_b2b_customer() ) {
			$classes[] = 'is_b2b_customer';
		}
		return $classes;
	}

	// Voeg klasse toe indien recent product
	// MIGREER NAAR add_filter( 'woocommerce_post_class', $classes, $product ) VANAF WC 3.6+
	add_filter( 'post_class', 'add_recent_product_class' );

	function add_recent_product_class( $classes ) {
		global $post;
		
		if ( get_the_date( 'Y-m-d', $post->ID ) > date_i18n( 'Y-m-d', strtotime('-3 months') ) ) {
			$classes[] = 'newbee';
		}
		
		// Label producten met 1+1 / 2de -50% / 3+1 / -25% promotie (verschijnt enkel indien de 'promotie'-tag ook actief is, dus kan op voorhand al ingesteld worden!)
		// Zou eventueel ook in template sale-flash.php kunnen, maar dit is - ondanks de omweg via product-ID - toch handiger
		$one_plus_one_products = array( '24531', '24302', '24547', '25310', '21003', '21011', '25010', '26014' );
		foreach ( $one_plus_one_products as $sku ) {
			if ( wc_get_product_id_by_sku( $sku ) == $post->ID ) {
				$classes[] = 'one-plus-one';
			}	
		}
		$fifty_percent_off_second_products = array( '20070', '20076' );
		foreach ( $fifty_percent_off_second_products as $sku ) {
			if ( wc_get_product_id_by_sku( $sku ) == $post->ID ) {
				$classes[] = 'fifty-percent-off';
			}
		}
		$three_plus_one_products = array( '20808', '20810', '20812' );
		foreach ( $three_plus_one_products as $sku ) {
			if ( wc_get_product_id_by_sku( $sku ) == $post->ID ) {
				$classes[] = 'three-plus-one';
			}
		}
		$twentyfive_percent_off_products = array( '20807', '20809', '20811' );
		foreach ( $twentyfive_percent_off_products as $sku ) {
			if ( wc_get_product_id_by_sku( $sku ) == $post->ID ) {
				$classes[] = 'twenty-five-percent-off';
			}
		}
		
		return $classes;
	}

	// Laad het child theme
	add_action( 'wp_enqueue_scripts', 'load_child_theme' );

	function load_child_theme() {
		wp_enqueue_style( 'oxfam-webshop', get_stylesheet_uri(), array( 'nm-core' ), '1.6.9' );
		// In de languages map van het child theme zal dit niet werken (checkt enkel nl_NL.mo) maar fallback is de algemene languages map (inclusief textdomain)
		load_child_theme_textdomain( 'oxfam-webshop', get_stylesheet_directory().'/languages' );
		wp_enqueue_script( 'jquery-ui-autocomplete' );
		wp_enqueue_script( 'jquery-ui-datepicker' );
		// WordPress 4.9 gebruikt nog jQuery UI 1.11.4
		wp_register_style( 'jquery-ui', 'https://code.jquery.com/ui/1.11.4/themes/smoothness/jquery-ui.css' );
		wp_enqueue_style( 'jquery-ui' );
	}

	// Voeg custom styling toe aan de adminomgeving (voor Relevanssi en Voorraadbeheer)
	add_action( 'admin_enqueue_scripts', 'load_admin_css' );

	function load_admin_css() {
		wp_enqueue_style( 'oxfam-admin', get_stylesheet_directory_uri().'/admin.css', array(), '1.2.5' );
	}

	// Fixes i.v.m. cURL
	add_action( 'http_api_curl', 'custom_curl_timeout', 10, 3 );
	
	function custom_curl_timeout( $handle, $r, $url ) {
		// Fix error 28 - Operation timed out after 10000 milliseconds with 0 bytes received (bij het connecteren van Jetpack met Wordpress.com)
		curl_setopt( $handle, CURLOPT_TIMEOUT, 180 );
		// Fix error 60 - SSL certificate problem: unable to get local issuer certificate (bij het downloaden van een CSV in WP All Import)
		curl_setopt( $handle, CURLOPT_SSL_VERIFYPEER, false );
	}

	// Jetpack-tags uitschakelen op homepages om dubbel werk te vermijden
	if ( is_front_page() ) {
		add_filter( 'jetpack_enable_open_graph', '__return_false' );
	}



	####################
	# WP STORE LOCATOR #
	####################

	// Laad onze custom markers (zowel in front-end als back-end)
	define( 'WPSL_MARKER_URI', dirname( get_bloginfo('stylesheet_url') ).'/markers/' );
	add_filter( 'wpsl_admin_marker_dir', 'custom_admin_marker_dir' );

	function custom_admin_marker_dir() {
		$admin_marker_dir = get_stylesheet_directory().'/markers/';
		return $admin_marker_dir;
	}

	// Wijzig de weergave van de zoekresultaten
	add_filter( 'wpsl_listing_template', 'custom_listing_template' );

	function custom_listing_template() {
		global $wpsl, $wpsl_settings;

		$listing_template = '<li data-store-id="<%= id %>">' . "\r\n";
		$listing_template .= "\t\t" . '<div class="wpsl-store-location">' . "\r\n";
		$listing_template .= "\t\t\t" . '<p><%= thumb %>' . "\r\n";
		$listing_template .= "\t\t\t\t" . append_get_parameter_to_href( wpsl_store_header_template('listing'), 'addSku' ) . "\r\n";
		$listing_template .= "\t\t\t\t" . '<span class="wpsl-street"><%= address %></span>' . "\r\n";
		$listing_template .= "\t\t\t\t" . '<% if ( address2 ) { %>' . "\r\n";
		$listing_template .= "\t\t\t\t" . '<span class="wpsl-street"><%= address2 %></span>' . "\r\n";
		$listing_template .= "\t\t\t\t" . '<% } %>' . "\r\n";
		$listing_template .= "\t\t\t\t" . '<span>' . wpsl_address_format_placeholders() . '</span>' . "\r\n";
		$listing_template .= "\t\t\t" . '</p>' . "\r\n";

		// Show the phone and email data if they exist
		if ( $wpsl_settings['show_contact_details'] ) {
			$listing_template .= "\t\t\t" . '<p class="wpsl-contact-details">' . "\r\n";
			$listing_template .= "\t\t\t" . '<% if ( phone ) { %>' . "\r\n";
			$listing_template .= "\t\t\t" . '<span><strong>' . esc_html( $wpsl->i18n->get_translation( 'phone_label', __( 'Phone', 'wpsl' ) ) ) . '</strong>: <%= formatPhoneNumber( phone ) %></span>' . "\r\n";
			$listing_template .= "\t\t\t" . '<% } %>' . "\r\n";
			$listing_template .= "\t\t\t" . '<% if ( email ) { %>' . "\r\n";
			$listing_template .= "\t\t\t" . '<span><strong>' . esc_html( $wpsl->i18n->get_translation( 'email_label', __( 'Email', 'wpsl' ) ) ) . '</strong>: <%= email %></span>' . "\r\n";
			$listing_template .= "\t\t\t" . '<% } %>' . "\r\n";
			$listing_template .= "\t\t\t" . '</p>' . "\r\n";
		}

		$listing_template .= "\t\t\t" . wpsl_more_info_template() . "\r\n"; // Check if we need to show the 'More Info' link and info
		$listing_template .= "\t" . '</li>';

		return $listing_template;
	}

	// Wijzig de weergave van het infovenster
	add_filter( 'wpsl_info_window_template', 'custom_info_window_template' );

	function custom_info_window_template() { 
		$info_window_template = '<div data-store-id="<%= id %>" class="wpsl-info-window">' . "\r\n";
		$info_window_template .= "\t\t" . '<p>' . "\r\n";
		$info_window_template .= "\t\t\t" . append_get_parameter_to_href( wpsl_store_header_template(), 'addSku' ) . "\r\n";  
		$info_window_template .= "\t\t\t" . '<span><%= address %></span>' . "\r\n";
		$info_window_template .= "\t\t\t" . '<% if ( address2 ) { %>' . "\r\n";
		$info_window_template .= "\t\t\t" . '<span><%= address2 %></span>' . "\r\n";
		$info_window_template .= "\t\t\t" . '<% } %>' . "\r\n";
		$info_window_template .= "\t\t\t" . '<span>' . wpsl_address_format_placeholders() . '</span>' . "\r\n";
		$info_window_template .= "\t\t" . '</p>' . "\r\n";
		// Routebeschrijving e.d. uitschakelen
		// $info_window_template .= "\t\t" . '<%= createInfoWindowActions( id ) %>' . "\r\n";
		$info_window_template .= "\t" . '</div>' . "\r\n";

		return $info_window_template;
	}

	// Voeg post-ID toe als extra metadata op winkel
	add_filter( 'wpsl_meta_box_fields', 'custom_meta_box_fields' );

	function custom_meta_box_fields( $meta_fields ) {
		$meta_fields[__( 'Additional Information', 'wpsl' )] = array(
			'phone' => array(
				'label' => 'Telefoon'
			),
			'email' => array(
				'label' => 'E-mail'
			),
			'url' => array(
				'label' => 'Webshop-URL'
			),
			'oxfam_shop_post_id' => array(
				'label' => 'Post-ID in OWW-site'
			),
			'alternate_marker_url' => array(
            	'label' => 'Afwijkende marker (indien enkel afhaling)'
        	)
		);

		return $meta_fields;
	}

	// Geef de extra metadata mee in de JSON-response
	add_filter( 'wpsl_frontend_meta_fields', 'custom_frontend_meta_fields' );

	function custom_frontend_meta_fields( $store_fields ) {
		$store_fields['wpsl_oxfam_shop_post_id'] = array( 'name' => 'oxfamShopPostId' );
		$store_fields['wpsl_alternate_marker_url'] = array( 'name' => 'alternateMarkerUrl' );
		return $store_fields;
	}

	function append_get_parameter_to_href( $str, $get_param ) {
		if ( isset( $_GET[$get_param] ) ) {
			// Check inbouwen op reeds aanwezige parameters in $2-fragment? 
			$str = preg_replace( '/<a(.*)href="([^"]*)"(.*)>/','<a$1href="$2?'.$get_param.'='.$_GET[$get_param].'"$3>', $str );
		}
		return $str;
	}



	############
	# SECURITY #
	############

	// Toon het blokje 'Additional Capabilities' op de profielpagina nooit
	add_filter( 'ure_show_additional_capabilities_section', '__return_false' );

	// Schakel de sterkte-indicator voor paswoorden uit
	add_action( 'wp_print_scripts', 'remove_password_strength', 100 );
	
	function remove_password_strength() {
		if ( wp_script_is( 'wc-password-strength-meter', 'enqueued' ) ) {
			wp_dequeue_script( 'wc-password-strength-meter' );
		}
	}

	// Fix probleem waarbij wc_modify_editable_roles() in wc-user-functions.php admins en speciale rollen enkel nog toegang geeft tot gewone klanten
	// Zie o.a. https://github.com/woocommerce/woocommerce/pull/21555
	add_filter( 'woocommerce_shop_manager_editable_roles', 'give_custom_user_roles_access_to_role_edit_capabilities' );

	function give_custom_user_roles_access_to_role_edit_capabilities( $roles ) {
		// Toestaan dat lokale beheerders ook lokale assistenten bewerken
		if ( current_user_can('manage_network_users') ) {
			$roles[] = 'local_helper';
		}

		// Superadmins toegang geven tot alle rollen uit het netwerk
		if ( current_user_can('create_sites') ) {
			global $wp_roles;
			if ( ! isset( $wp_roles ) ) {
				$wp_roles = new WP_Roles();
			}
			$roles = array_keys( $wp_roles->role_names );
		}
		
		return $roles;
	}

	// Zorg ervoor dat lokale beheerders toch al hun gearchiveerde site kunnen bekijken HEEFT DIT NOG ZIN?
	add_filter( 'ms_site_check', 'allow_local_manager_on_archived' );

	function allow_local_manager_on_archived() {
		if ( current_user_can('manage_woocommerce') ) {
			return true;
		}
		// Terug te plaatsen winkelboodschap: "We zijn vandaag uitzonderlijk gesloten. Bestellingen worden opnieuw verwerkt vanaf de eerstvolgende openingsdag. De geschatte leverdatum houdt hiermee rekening."
	}

	add_action( 'woocommerce_order_status_pending_to_completed', 'warn_if_invalid_status_change', 10, 2 );
	add_action( 'woocommerce_order_status_refunded_to_completed', 'warn_if_invalid_status_change', 10, 2 );
	add_action( 'woocommerce_order_status_completed_to_processing', 'warn_if_invalid_status_change', 10, 2 );
	add_action( 'woocommerce_order_status_completed_to_claimed', 'warn_if_invalid_status_change', 10, 2 );
	add_action( 'woocommerce_order_status_cancelled_to_pending', 'warn_if_invalid_status_change', 10, 2 );
	add_action( 'woocommerce_order_status_cancelled_to_processing', 'warn_if_invalid_status_change', 10, 2 );
	add_action( 'woocommerce_order_status_cancelled_to_claimed', 'warn_if_invalid_status_change', 10, 2 );
	add_action( 'woocommerce_order_status_cancelled_to_completed', 'warn_if_invalid_status_change', 10, 2 );
	
	function warn_if_invalid_status_change( $order_id, $order ) {
		// TEGENHOUDEN VAN TRANSITIE M.B.V. EXCEPTION THROW IN 'WOOCOMMERCE_ORDER_STATUS_COMPLETED'-ACTIE KAN NOG NIET IN WC3.0
		// $order->get_edit_order_url() pas beschikbaar vanaf WC3.3+
		wp_mail( get_site_option('admin_email'), 'Bestelling '.$order->get_order_number().' onderging een ongeoorloofde statuswijziging naar '.$order->get_status(), 'Gelieve te checken!' );
	}

	// Functie is niet gebaseerd op eigenschappen van gebruikers en dus al zeer vroeg al bepaald (geen 'init' nodig)
	if ( is_regional_webshop() ) {
		// Definieer een profielveld in de back-end waarin we kunnen bijhouden van welke winkel de gebruiker lid is
		add_action( 'show_user_profile', 'add_member_of_shop_user_field' );
		add_action( 'edit_user_profile', 'add_member_of_shop_user_field' );
		// Zorg ervoor dat het ook bewaard wordt
		add_action( 'personal_options_update', 'save_member_of_shop_user_field' );
		add_action( 'edit_user_profile_update', 'save_member_of_shop_user_field' );
		
		// Voeg de claimende winkel toe aan de ordermetadata van zodra iemand op het winkeltje klikt (en verwijder indien we teruggaan)
		add_action( 'woocommerce_order_status_processing_to_claimed', 'register_claiming_member_shop', 10, 2 );
		// Veroorzaakt probleem indien volgorde niet 100% gerespecteerd wordt
		// add_action( 'woocommerce_order_status_claimed_to_processing', 'delete_claiming_member_shop' );

		// Deze transities zullen in principe niet voorkomen, maar voor alle zekerheid ...
		add_action( 'woocommerce_order_status_on-hold_to_claimed', 'register_claiming_member_shop', 10, 2 );
		// Veroorzaakt probleem indien volgorde niet 100% gerespecteerd wordt
		// add_action( 'woocommerce_order_status_claimed_to_on-hold', 'delete_claiming_member_shop' );

		// Laat succesvol betaalde afhalingen automatisch claimen door de gekozen winkel
		add_action( 'woocommerce_thankyou', 'auto_claim_local_pickup' );
		
		// Creëer bovenaan de orderlijst een dropdown met de deelnemende winkels uit de regio
		add_action( 'restrict_manage_posts', 'add_claimed_by_filtering' );
		
		// Voer de filtering uit tijdens het bekijken van orders in de admin
		add_action( 'pre_get_posts', 'filter_orders_by_owner', 15 );

		// Voeg ook een kolom toe aan het besteloverzicht in de back-end
		add_filter( 'manage_edit-shop_order_columns', 'add_claimed_by_column', 11 );

		// Maak sorteren op deze nieuwe kolom mogelijk
		add_filter( 'manage_edit-shop_order_sortable_columns', 'make_claimed_by_column_sortable' );

		// Toon de data van elk order in de kolom
		add_action( 'manage_shop_order_posts_custom_column', 'get_claimed_by_value', 10, 2 );

		// Laat de custom statusfilter verschijnen volgens de normale flow van de verwerking
		add_filter( 'views_edit-shop_order', 'put_claimed_after_processing' );

		// Maak de boodschap om te filteren op winkel beschikbaar bij de rapporten
		add_filter( 'woocommerce_reports_get_order_report_data_args', 'limit_reports_to_member_shop', 10, 2 );
	}

	function add_member_of_shop_user_field( $user ) {
		if ( user_can( $user, 'manage_woocommerce' ) ) {
			$key = 'blog_'.get_current_blog_id().'_member_of_shop';
			?>
			<h3>Regiosamenwerking</h3>
			<table class="form-table">
				<tr>
					<th><label for="<?php echo $key; ?>">Ik bevestig orders voor ...</label></th>
					<td>
						<?php
							echo '<select name="'.$key.'" id="'.$key.'">';
								$member_of = get_the_author_meta( $key, $user->ID );
								$shops = get_option( 'oxfam_member_shops' );
								$selected = empty( $member_of ) ? ' selected' : '';
								echo '<option value=""'.$selected.'>(selecteer)</option>';
								foreach ( $shops as $shop ) {
									$selected = ( $shop === $member_of ) ? ' selected' : '';
									echo '<option value="'.$shop.'"'.$selected.'>'.trim_and_uppercase( $shop ).'</option>';
								}
							echo '</select>';
						?>
						<span class="description">Opgelet: deze keuze bepaalt aan welke winkel de bestellingen die jij bevestigt toegekend worden!</span>
					</td>
				</tr>
			</table>
			<?php
		}
	}

	function save_member_of_shop_user_field( $user_id ) {
		if ( ! current_user_can( 'edit_users', $user_id ) ) {
			return false;
		}

		// Usermeta is netwerkbreed, dus ID van blog toevoegen aan de key!
		$member_key = 'blog_'.get_current_blog_id().'_member_of_shop';
		// Check of het veld wel bestaat voor deze gebruiker
		if ( isset($_POST[$member_key]) ) {
			update_user_meta( $user_id, $member_key, $_POST[$member_key] );
		}
	}

	function auto_claim_local_pickup( $order_id ) {
		if ( ! $order_id ) {
			return;
		}
		$order = wc_get_order( $order_id );
		// Check of de betaling wel succesvol was door enkel te claimen indien status reeds op 'In behandeling' staat
		if ( $order->has_shipping_method('local_pickup_plus') and $order->get_status() === 'processing' ) {
			$order->update_status( 'claimed' );
		}
	}

	function register_claiming_member_shop( $order_id, $order ) {
		if ( get_current_user_id() > 1 ) {
			// Een gewone klant heeft deze eigenschap niet en retourneert dus sowieso 'false'
			$owner = get_the_author_meta( 'blog_'.get_current_blog_id().'_member_of_shop', get_current_user_id() );
		} else {
			// Indien het order rechtstreeks afgerond wordt vanuit Sendcloud gebeurt het onder de user met ID 1 (= Frederik)
			if ( get_current_blog_id() == 24 ) {
				$owner = 'antwerpen';
				write_log("Ongeclaimde bestelling ".$order->get_order_number()." afgewerkt vanuit Sendcloud en gekoppeld aan Antwerpen!");
			}
		}
		
		if ( $order->has_shipping_method('local_pickup_plus') ) {
			// Koppel automatisch aan de winkel waar de afhaling zal gebeuren
			$methods = $order->get_shipping_methods();
			$method = reset($methods);
			$meta_data = $method->get_meta_data();
			$pickup_data = reset($meta_data);
			$city = mb_strtolower( trim( str_replace( 'Oxfam-Wereldwinkel', '', $pickup_data->value['shipping_company'] ) ) );
			if ( in_array( $city, get_option( 'oxfam_member_shops' ) ) ) {
				// Dubbelcheck of deze stad wel tussen de deelnemende winkels zit
				$owner = $city;
			} elseif ( strpos( $city, 'boortmeerbeek' ) !== false ) {
				$owner = 'boortmeerbeek';
			}
		}

		if ( ! isset( $owner ) ) {
			wp_mail( get_site_option('admin_email'), 'Geen eigenaar gevonden voor te claimen bestelling '.$order->get_order_number().'!', '' );
			// Koppel als laatste redmiddel aan de locatie van de hoofdwinkel
			$owner = mb_strtolower( get_oxfam_shop_data('city') );
		}

		update_post_meta( $order_id, 'claimed_by', $owner );
	}

	function delete_claiming_member_shop( $order_id ) {
		delete_post_meta( $order_id, 'claimed_by' );
	}

	function add_claimed_by_filtering() {
		global $pagenow, $post_type;
		if ( $pagenow === 'edit.php' and $post_type === 'shop_order' ) {
			$shops = get_option( 'oxfam_member_shops' );
			echo '<select name="claimed_by" id="claimed_by">';
				$all = ( ! empty($_GET['claimed_by']) and sanitize_text_field($_GET['claimed_by']) === 'all' ) ? ' selected' : '';
				echo '<option value="all" '.$all.'>Alle winkels uit de regio</option>';
				foreach ( $shops as $shop ) {
					$selected = ( ! empty($_GET['claimed_by']) and sanitize_text_field($_GET['claimed_by']) === $shop ) ? ' selected' : '';
					echo '<option value="'.$shop.'" '.$selected.'>Enkel '.trim_and_uppercase( $shop ).'</option>';
				}
			echo '</select>';
		}
	}

	function filter_orders_by_owner( $query ) {
		global $pagenow, $post_type;
		if ( $pagenow === 'edit.php' and $post_type === 'shop_order' and $query->query['post_type'] === 'shop_order' ) {
			if ( ! empty( $_GET['claimed_by'] ) and $_GET['claimed_by'] !== 'all' ) {
				$meta_query_args = array(
					'relation' => 'AND',
					array(
						'key' => 'claimed_by',
						'value' => $_GET['claimed_by'],
						'compare' => '=',
					),
				);
				$query->set( 'meta_query', $meta_query_args );
			} elseif ( 1 < 0 ) {
				// Eventueel AUTOMATISCH filteren op eigen winkel (tenzij expliciet anders aangegeven)
				$owner = get_the_author_meta( 'blog_'.get_current_blog_id().'_member_of_shop', get_current_user_id() );
				if ( ! $owner ) {
					$meta_query_args = array(
						'relation' => 'AND',
						array(
							'key' => 'claimed_by',
							'value' => $owner,
							'compare' => '=',
						),
					);
					$query->set( 'meta_query', $meta_query_args );
				}
			}
		}
	}

	function add_claimed_by_column( $columns ) {
		$columns['claimed_by'] = 'Behandeling door';
		// Eventueel bepaalde kolommen volledig verwijderen?
		// unset( $columns['order_notes'] );
		// unset( $columns['order_actions'] );
		return $columns;
	}

	function make_claimed_by_column_sortable( $columns ) {
		$columns['claimed_by'] = 'claimed_by';
		return $columns;
	}

	function get_claimed_by_value( $column ) {
		global $the_order;
		if ( $column === 'claimed_by' ) { 
			if ( $the_order->get_status() === 'pending' ) {
				echo '<i>nog niet betaald</i>';
			} elseif ( $the_order->get_status() === 'processing' ) {
				echo '<i>nog niet bevestigd</i>';
			} elseif ( $the_order->get_status() === 'cancelled' ) {
				echo '<i>geannuleerd</i>';
			} else {
				if ( $the_order->get_meta('claimed_by') !== '' ) {
					echo 'OWW '.trim_and_uppercase( $the_order->get_meta('claimed_by') );
				} else {
					// Reeds verderop in het verwerkingsproces maar geen winkel? Dat zou niet mogen zijn!
					echo '<i>ERROR</i>';
				}
			}
		}
	}

	// Voeg ook een kolom toe aan het besteloverzicht in de back-end
	add_filter( 'manage_edit-shop_order_columns', 'add_estimated_delivery_column', 12 );

	// Maak sorteren op deze nieuwe kolom mogelijk
	add_filter( 'manage_edit-shop_order_sortable_columns', 'make_estimated_delivery_column_sortable' );

	// Toon de data van elk order in de kolom
	add_action( 'manage_shop_order_posts_custom_column' , 'get_estimated_delivery_value', 10, 2 );

	// Voer de sortering uit tijdens het bekijken van orders in de admin (voor alle zekerheid NA filteren uitvoeren)
	add_action( 'pre_get_posts', 'sort_orders_on_custom_column', 20 );
	
	// Zorg ervoor dat links naar Google Maps meteen in het juiste formaat staan
	add_filter( 'woocommerce_shipping_address_map_url_parts', 'ob2c_shuffle_google_maps_address', 10, 1 );
	add_filter( 'woocommerce_shipping_address_map_url', 'ob2c_add_starting_point_to_google_maps', 10, 2 );

	function ob2c_shuffle_google_maps_address( $address ) {
		$address['city'] = $address['postcode'].' '.$address['city'];
		unset($address['address_2']);
		unset($address['state']);
		unset($address['postcode']);
		return $address;
	}

	function ob2c_add_starting_point_to_google_maps( $url, $order ) {
		// Neem als default de hoofdwinkel
		$shop_address = get_company_address();
		
		if ( $order->get_meta('claimed_by') !== '' ) {
			if ( $locations = get_option('woocommerce_pickup_locations') ) {
				foreach ( $locations as $location ) {
					if ( stristr( $location['shipping_company'], $order->get_meta('claimed_by') ) ) {
						$parts = explode( 'id=', $location['note'] );
						if ( isset( $parts[1] ) ) {
							// Het heeft geen zin om het adres van niet-numerieke ID's op te vragen (= uitzonderingen)
							$shop_post_id = intval( str_replace( ']', '', $parts[1] ) );
							if ( $shop_post_id > 0 ) {
								// Toon route vanaf de winkel die de thuislevering zal uitvoeren a.d.h.v. de post-ID in de openingsuren
								$shop_address = get_company_address( $shop_post_id );
							}
						}
						break;
					}
				}
			}
		}

		// Zet locatielink om in routelink, voeg landencode en eindslash toe en vervang fixed zoomniveau door fietsnavigatie
		// Tip: meerdere stops zijn mogelijk, blijf adressen gewoon chainen met slashes!
		return str_replace( 'https://maps.google.com/maps?&q=', 'https://www.google.com/maps/dir/' . rawurlencode( str_replace( '<br/>', ', ', $shop_address ) ) . ',+BE/', str_replace( '&z=16', '/data=!4m2!4m1!3e1', $url ) );

		// Overige dataparameters
		// Car 			/data=!4m2!4m1!3e0
		// Bicycling 	/data=!4m2!4m1!3e1
		// Walking 		/data=!4m2!4m1!3e2
	}

	// Maak bestellingen vindbaar o.b.v. ordernummer en behandelende winkel
	add_filter( 'woocommerce_shop_order_search_fields', 'ob2c_add_shop_order_search_fields' );

	function ob2c_add_shop_order_search_fields( $fields ) {
		$fields[] = '_order_number';
		$fields[] = 'claimed_by';
		return $fields;
	}

	function sort_orders_on_custom_column( $query ) {
		global $pagenow, $post_type;
		if ( $pagenow === 'edit.php' and $post_type === 'shop_order' and $query->query['post_type'] === 'shop_order' ) {
			// Check of we moeten sorteren op één van onze custom kolommen
			if ( $query->get( 'orderby' ) === 'estimated_delivery' ) {
				$query->set( 'meta_key', 'estimated_delivery' );
				$query->set( 'orderby', 'meta_value_num' );
			}
			if ( $query->get( 'orderby' ) === 'claimed_by' ) {
				$query->set( 'meta_key', 'claimed_by' );
				$query->set( 'orderby', 'meta_value' );
			}
		}
	}

	function add_estimated_delivery_column( $columns ) {
		$columns['estimated_delivery'] = 'Uiterste leverdag';
		$columns['excel_file_name'] = 'Picklijst';
		unset($columns['billing_address']);
		unset($columns['order_notes']);
		return $columns;
	}

	function make_estimated_delivery_column_sortable( $columns ) {
		$columns['estimated_delivery'] = 'estimated_delivery';
		return $columns;
	}

	function get_estimated_delivery_value( $column ) {
		global $the_order;
		if ( $column === 'estimated_delivery' ) {
			$processing_statusses = array( 'processing', 'claimed' );
			$completed_statusses = array( 'completed' );
			if ( $the_order->meta_exists('estimated_delivery') ) {
				$delivery = date( 'Y-m-d H:i:s', intval( $the_order->get_meta('estimated_delivery') ) );
				if ( in_array( $the_order->get_status(), $processing_statusses ) ) {
					if ( get_date_from_gmt( $delivery, 'Y-m-d' ) < date_i18n( 'Y-m-d' ) ) {
						$color = 'red';
					} elseif ( get_date_from_gmt( $delivery, 'Y-m-d' ) === date_i18n( 'Y-m-d' ) ) {
						$color = 'orange';
					} else {
						$color = 'green';
					}
					echo '<span style="color: '.$color.';">'.get_date_from_gmt( $delivery, 'd-m-Y' ).'</span>';
				} elseif ( in_array( $the_order->get_status(), $completed_statusses ) ) {
					// Veroorzaakt fatale error indien get_date_completed() niet ingesteld
					if ( $the_order->get_date_completed()->date_i18n( 'Y-m-d H:i:s' ) < $delivery ) {
						echo '<i>op tijd geleverd</i>';
					} else {
						echo '<i>te laat geleverd</i>';
					}
				}
			} else {
				if ( $the_order->get_status() === 'cancelled' ) {
					echo '<i>geannuleerd</i>';
				} elseif ( $the_order->get_meta('is_b2b_sale') === 'yes' ) {
					echo '<i>B2B-bestelling</i>';
				} else {
					echo '<i>niet beschikbaar</i>';
				}
			}
		} elseif ( $column === 'excel_file_name' ) {
			if ( strpos( $the_order->get_meta('_excel_file_name'), '.xlsx' ) > 10 ) {
				$file = content_url( '/uploads/xlsx/'.$the_order->get_meta('_excel_file_name') );
				echo '<a href="'.$file.'" target="_blank">Download</a>';
			} else {
				echo '<i>niet beschikbaar</i>';
			}
		}
	}

	function put_claimed_after_processing( $array ) {
		// Check eerst of de statusknop wel aanwezig is op dit moment!
		if ( array_key_exists( 'wc-claimed', $array ) ) {
			$cnt = 1;
			$stored_value = $array['wc-claimed'];
			unset($array['wc-claimed']);
			foreach ( $array as $key => $value ) {
				if ( $key === 'wc-processing' ) {
					$array = array_slice( $array, 0, $cnt ) + array( 'wc-claimed' => $stored_value ) + array_slice( $array, $cnt, count($array) - $cnt );
					// Zorg ervoor dat de loop stopt!
					break;
				} elseif ( $key === 'wc-completed' ) {
					$array = array_slice( $array, 0, $cnt-1 ) + array( 'wc-claimed' => $stored_value ) + array_slice( $array, $cnt-1, count($array) - ($cnt-1) );
					break;
				}
				$cnt++;
			}
		}
		return $array;
	}	

	// Bevat geen WooCommerce-acties want die worden wegens een bug ingeladen via JavaScript!
	// add_filter( 'bulk_actions-edit-shop_order', 'my_custom_bulk_actions', 1, 10000 );

	function my_custom_bulk_actions( $actions ){
		var_dump_pre( $actions );
		return $actions;
	}

	// Poging om de actie die de JavaScript toe te voegen weer uitschakelt
	// add_action( 'plugins_loaded', 'disable_wc_actions' );

	function disable_wc_actions() {
		remove_action( 'bulk_actions-edit-shop_order', array( WC_Admin_CPT_Shop_Order::getInstance(), 'admin_footer' ), 10 );
	}

	// Global om ervoor te zorgen dat de boodschap enkel in de eerste loop geëchood wordt
	$warning_shown = false;

	function limit_reports_to_member_shop( $args ) {
		global $pagenow, $warning_shown;
		if ( $pagenow === 'admin.php' and $_GET['page'] === 'wc-reports' ) {
			if ( ! empty( $_GET['claimed_by'] ) ) {
				$new_args['where_meta'] = array(
					'relation' => 'AND',
					array(
						'meta_key'   => 'claimed_by',
						'meta_value' => $_GET['claimed_by'],
						'operator'   => '=',
					),
				);

				// Nette manier om twee argumenten te mergen (in het bijzonder voor individuele productraportage, anders blijft enkel de laatste meta query bewaard)
				$args['where_meta'] = array_key_exists( 'where_meta', $args ) ? wp_parse_args( $new_args['where_meta'], $args['where_meta'] ) : $new_args['where_meta'];
				
				if ( ! $warning_shown ) {
					echo "<div style='background-color: red; color: white; padding: 0.25em 1em;'>";
						echo "<p>Opgelet: momenteel bekijk je een gefilterd rapport met enkel de bestellingen die verwerkt werden door <b>OWW ".trim_and_uppercase( $_GET['claimed_by'] )."</b>.</p>";
						echo "<p style='text-align: right;'>";
							$members = get_option( 'oxfam_member_shops' );
							foreach ( $members as $member ) {
								if ( $member !== $_GET['claimed_by'] ) {
									echo "<a href='".esc_url( add_query_arg( 'claimed_by', $member ) )."' style='color: black;'>Bekijk ".trim_and_uppercase( $member )." »</a><br/>";
								}
							}
							echo "<br/><a href='".esc_url( remove_query_arg( 'claimed_by' ) )."' style='color: black;'>Terug naar volledige regio »</a>";
						echo "</p>";
					echo "</div>";
				}
			} else {
				if ( ! $warning_shown ) {
					echo "<div style='background-color: green; color: white; padding: 0.25em 1em;'>";
						echo "<p>Momenteel bekijk je het rapport met de bestellingen van alle winkels uit de regio. Klik hieronder om de omzet te filteren op een bepaalde winkel.</p>";
						echo "<p style='text-align: right;'>";
							$members = get_option( 'oxfam_member_shops' );
							foreach ( $members as $member ) {
								echo "<a href='".esc_url( add_query_arg( 'claimed_by', $member ) )."' style='color: black;'>Bekijk enkel ".trim_and_uppercase( $member )." »</a><br/>";
							}
						echo "</p>";
					echo "</div>";
				}
			}
			$warning_shown = true;
		}
		return $args;
	}

	// Voeg gewicht en volume toe op orderdetail
	add_action( 'woocommerce_admin_order_data_after_shipping_address', 'ob2c_add_logistic_parameters', 10, 1 );

	function ob2c_add_logistic_parameters( $order ) {
		echo '<p><strong>Logistieke info:</strong><br/>';
		$logistics = get_logistic_params( $order );
		echo number_format( $logistics['volume'], 1, ',', '.' ).' liter / '.number_format( $logistics['weight'], 1, ',', '.' ).' kg';
		echo '</p>';
	}

	// Voeg acties toe op orderdetailscherm om status te wijzigen (want keuzemenu bestelstatus geblokkeerd!)
	add_action( 'woocommerce_order_actions', 'add_order_status_changing_actions' );

	function add_order_status_changing_actions( $actions ) {
		global $theorder;
		
		if ( $theorder->has_shipping_method('local_pickup_plus') ) {
			$completed_label = 'Markeer als klaargezet in de winkel';
		} else {
			$completed_label = 'Markeer als ingepakt voor verzending';
		}

		if ( ! is_regional_webshop() ) {
			if ( $theorder->get_status() === 'processing' ) {
				$actions['oxfam_mark_completed'] = $completed_label;
			}
		} else {
			if ( $theorder->get_status() === 'claimed' ) {
				$actions['oxfam_mark_completed'] = $completed_label;
			} elseif ( $theorder->get_status() === 'processing' ) {
				$actions['oxfam_mark_claimed'] = 'Markeer als geclaimd';
			}
		}

		if ( $theorder->get_meta('is_b2b_sale') === 'yes' ) {
			// $actions['oxfam_mark_invoiced'] = 'Markeer als factuur opgesteld';
		}

		// Pas vanaf WC 3.1+ toegang tot alle statussen
		// write_log( wc_print_r( $actions, true ) );
		// unset($actions['send_order_details']);
		// unset($actions['regenerate_download_permissions']);
		
		return $actions;
	}

	add_action( 'woocommerce_order_action_oxfam_mark_completed', 'proces_oxfam_mark_completed' );
	add_action( 'woocommerce_order_action_oxfam_mark_claimed', 'proces_oxfam_mark_claimed' );

	function proces_oxfam_mark_completed( $order ) {
		$order->set_status('completed');
		$order->save();
	}

	function proces_oxfam_mark_claimed( $order ) {
		$order->set_status('claimed');
		$order->save();
	}

	// Voer shortcodes ook uit in widgets, titels en e-mailfooters
	add_filter( 'widget_text', 'do_shortcode' );
	add_filter( 'the_title', 'do_shortcode' );
	add_filter( 'woocommerce_email_footer_text', 'do_shortcode' );

	// Zorg ervoor dat het Return-Path gelijk is aan de afzender (= webshop.gemeente@oxfamwereldwinkels.be, met correct ingesteld MX-record)
	// add_action( 'phpmailer_init', 'fix_bounce_address' );

	function fix_bounce_address( $phpmailer ) {
		$phpmailer->Sender = $phpmailer->From;
	}
	
	// Pas het onderwerp van de mails aan naargelang de gekozen levermethode
	add_filter( 'woocommerce_email_subject_customer_processing_order', 'change_processing_order_subject', 10, 2 );
	add_filter( 'woocommerce_email_subject_customer_completed_order', 'change_completed_order_subject', 10, 2 );
	add_filter( 'woocommerce_email_subject_customer_refunded_order', 'change_refunded_order_subject', 10, 2 );
	add_filter( 'woocommerce_email_subject_customer_note', 'change_note_subject', 10, 2 );

	function change_processing_order_subject( $subject, $order ) {
		$subject = sprintf( __( 'Onderwerp van de 1ste bevestigingsmail inclusief besteldatum (%s)', 'oxfam-webshop' ), $order->get_date_created()->date_i18n('d/m/Y') );
		if ( get_current_site()->domain !== 'shop.oxfamwereldwinkels.be' ) { 
			$subject = 'DEMO '.$subject;
		}
		return $subject;
	}

	function change_completed_order_subject( $subject, $order ) {
		if ( $order->has_shipping_method('local_pickup_plus') ) {
			$subject = sprintf( __( 'Onderwerp van de 2de bevestigingsmail (indien afhaling) inclusief besteldatum (%s)', 'oxfam-webshop' ), $order->get_date_created()->date_i18n('d/m/Y') );
		} else {
			$subject = sprintf( __( 'Onderwerp van de 2de bevestigingsmail (indien thuislevering) inclusief besteldatum (%s)', 'oxfam-webshop' ), $order->get_date_created()->date_i18n('d/m/Y') );
		}
		if ( get_current_site()->domain !== 'shop.oxfamwereldwinkels.be' ) { 
			$subject = 'DEMO '.$subject;
		}
		return $subject;
	}

	function change_refunded_order_subject( $subject, $order ) {
		if ( $order->get_total_refunded() == $order->get_total() ) {
			$subject = sprintf( __( 'Onderwerp van de terugbetalingsmail (volledig) inclusief besteldatum (%s)', 'oxfam-webshop' ), $order->get_date_created()->date_i18n('d/m/Y') );
		} else {
			$subject = sprintf( __( 'Onderwerp van de terugbetalingsmail (gedeeltelijk) inclusief besteldatum (%s)', 'oxfam-webshop' ), $order->get_date_created()->date_i18n('d/m/Y') );
		}
		if ( get_current_site()->domain !== 'shop.oxfamwereldwinkels.be' ) { 
			$subject = 'DEMO '.$subject;
		}
		return $subject;
	}

	function change_note_subject( $subject, $order ) {
		$subject = sprintf( __( 'Onderwerp van de opmerkingenmail inclusief besteldatum (%s)', 'oxfam-webshop' ), $order->get_date_created()->date_i18n('d/m/Y') );
		if ( get_current_site()->domain !== 'shop.oxfamwereldwinkels.be' ) { 
			$subject = 'DEMO '.$subject;
		}
		return $subject;
	}

	// Pas de header van de mails aan naargelang de gekozen levermethode
	add_filter( 'woocommerce_email_heading_new_order', 'change_new_order_email_heading', 10, 2 );
	add_filter( 'woocommerce_email_heading_customer_processing_order', 'change_processing_email_heading', 10, 2 );
	add_filter( 'woocommerce_email_heading_customer_completed_order', 'change_completed_email_heading', 10, 2 );
	add_filter( 'woocommerce_email_heading_customer_refunded_order', 'change_refunded_email_heading', 10, 2 );
	add_filter( 'woocommerce_email_heading_customer_note', 'change_note_email_heading', 10, 2 );
	add_filter( 'woocommerce_email_heading_customer_new_account', 'change_new_account_email_heading', 10, 2 );

	function change_new_order_email_heading( $email_heading, $order ) {
		$email_heading = __( 'Heading van de mail aan de webshopbeheerder', 'oxfam-webshop' );
		return $email_heading;
	}

	function change_processing_email_heading( $email_heading, $order ) {
		$email_heading = __( 'Heading van de 1ste bevestigingsmail', 'oxfam-webshop' );
		return $email_heading;
	}

	function change_completed_email_heading( $email_heading, $order ) {
		if ( $order->has_shipping_method('local_pickup_plus') ) {
			$email_heading = __( 'Heading van de 2de bevestigingsmail (indien afhaling)', 'oxfam-webshop' );
		} else {
			$email_heading = __( 'Heading van de 2de bevestigingsmail (indien thuislevering)', 'oxfam-webshop' );
		}
		return $email_heading;
	}

	function change_refunded_email_heading( $email_heading, $order ) {
		if ( $order->get_total_refunded() == $order->get_total() ) {
			$email_heading = __( 'Heading van de terugbetalingsmail (volledig)', 'oxfam-webshop' );
		} else {
			$email_heading = __( 'Heading van de terugbetalingsmail (gedeeltelijk)', 'oxfam-webshop' );
		}
		return $email_heading;
	}

	function change_note_email_heading( $email_heading, $order ) {
		$email_heading = __( 'Heading van de opmerkingenmail', 'oxfam-webshop' );
		return $email_heading;
	}

	function change_new_account_email_heading( $email_heading, $email ) {
		$email_heading = __( 'Heading van de welkomstmail', 'oxfam-webshop' );
		return $email_heading;
	}

	// Schakel autosaves uit
	add_action( 'wp_print_scripts', function() { wp_deregister_script('autosave'); } );

	if ( is_main_site() ) {
		// Zorg ervoor dat productrevisies bijgehouden worden op de hoofdsite
		add_filter( 'woocommerce_register_post_type_product', 'add_product_revisions' );
		// Log wijzigingen aan metadata na het succesvol bijwerken
		add_action( 'updated_post_metadata', 'log_product_changes', 100, 4 );
		// Toon de lokale webshops die het product nog op voorraad hebben
		add_action( 'woocommerce_product_options_inventory_product_data', 'add_inventory_fields', 5 );
	}
	
	function add_product_revisions( $vars ) {
		$vars['supports'][] = 'revisions';
		return $vars;
	}

	function log_product_changes( $meta_id, $post_id, $meta_key, $new_meta_value ) {
		// Alle overige interessante data zitten in het algemene veld '_product_attributes' dus daarvoor best een ander filtertje zoeken
		$watched_metas = array( '_price', '_stock_status', '_tax_class', '_weight', '_length', '_width', '_height', '_thumbnail_id', '_force_sell_synced_ids', '_product_attributes' );
		// Deze actie vuurt bij 'single value meta keys' enkel indien er een wezenlijke wijziging was, dus check hoeft niet meer
		if ( get_post_type($post_id) === 'product' and in_array( $meta_key, $watched_metas ) ) {
			// Schrijf weg in log per weeknummer (zonder leading zero's)
			$user = wp_get_current_user();
			$str = date_i18n('d/m/Y H:i:s') . "\t" . get_post_meta( $post_id, '_sku', true ) . "\t" . $user->user_firstname . "\t" . $meta_key . " gewijzigd in " . serialize($new_meta_value) . "\t" . get_the_title( $post_id ) . "\n";
			file_put_contents( WP_CONTENT_DIR."/changelog-week-".intval( date_i18n('W') ).".csv", $str, FILE_APPEND );
		}
	}

	function add_inventory_fields() {
		global $product_object;
		echo '<div class="options_group oft"><p class="form-field">';
			$shops_instock = array();
			$sites = get_sites( array( 'site__not_in' => get_site_option('oxfam_blocked_sites'), 'public' => 1, 'orderby' => 'path' ) );
			foreach ( $sites as $site ) {
				if ( $site->blog_id != 1 ) {
					switch_to_blog( $site->blog_id );
					$local_product = wc_get_product( wc_get_product_id_by_sku( $product_object->get_sku() ) );
					if ( $local_product !== false and $local_product->is_in_stock() ) {
						$shops_instock[] = get_company_name();
					}
					restore_current_blog();
				}
			}
			echo '<label>Op voorraad? ('.count($shops_instock).'/'.(count($sites)-1).')</label>';
			if ( count($shops_instock) > 0 ) {
				foreach ($shops_instock as $shop_name ) {
					echo $shop_name.'<br/>';
				}
			}
		echo '</p></div>';
	}

	// Verberg alle koopknoppen op het hoofddomein (ook reeds geblokkeerd via .htaccess but better be safe than sorry)
	add_filter( 'woocommerce_get_price_html' , 'no_orders_on_main', 10, 2 );
	
	function no_orders_on_main( $price, $product ) {
		if ( ! is_admin() ) {
			if ( is_main_site() ) {
				remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart' );
				remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
				return "<i>Geen verkoop vanuit nationaal</i>";
			}
			if ( is_b2b_customer() ) {
				$price .= ' per stuk';
			}
		}
		return $price;
	}

	// Doorstreepte adviesprijs en badge uitschakelen (meestal geen rechtsreekse productkorting)
	add_filter( 'woocommerce_sale_flash', '__return_false' );
	add_filter( 'woocommerce_format_sale_price', 'format_sale_as_regular_price', 10, 3 );

	function format_sale_as_regular_price( $price, $regular_price, $sale_price ) {
		return wc_price($regular_price);
	}

	// Zorg ervoor dat winkelbeheerders na bv. het opslaan van feestdagen of het filteren in regiorapporten niet teruggedwongen worden naar het dashboard
	add_filter( 'ure_admin_menu_access_allowed_args', 'ure_allow_args_for_oxfam_options', 10, 1 );

	function ure_allow_args_for_oxfam_options( $args ) {
		$args['edit.php'][''][] = 'claimed_by';
		$args['admin.php']['wc-reports'] = array(
			'tab',
			'report',
			'range',
			'claimed_by',
			'product_ids',
			'show_categories',
			'coupon_codes',
			'start_date',
			'end_date',
			'wc_reports_nonce',
		);
		$args['admin.php']['oxfam-options'] = array(
			'page',
			'settings-updated',
		);
		$args['admin.php']['wpml_plugin_log'] = array(
			'page',
			'paged',
			'action',
			'action2',
			's',
			'wpml-list_table_nonce',
			'_wp_http_referer',
			'_wpnonce',
		);
		$args['admin.php']['pmxe-admin-export'] = array(
			'id',
			'action',
			'pmxe_nt',
			'warnings',
		);
		$args['admin.php']['pmxe-admin-manage'] = array(
			'id',
			'action',
			'pmxe_nt',
			'warnings',
		);
		$args['profile.php'][''] = array(
			'updated',
		);
		return $args;
	}


	
	###############
	# WOOCOMMERCE #
	###############

	// Voeg allerlei checks toe net na het inladen van WordPress
	add_action( 'init', 'woocommerce_parameter_checks_after_loading' );
	
	function woocommerce_parameter_checks_after_loading() {
		// Uniformeer de gebruikersdata net voor we ze opslaan in de database STAAT GEEN WIJZIGINGEN TOE
		// add_filter( 'update_user_metadata', 'sanitize_woocommerce_customer_fields', 10, 5 );

		if ( isset( $_GET['referralZip'] ) ) {
			// Dit volstaat ook om de variabele te creëren indien nog niet beschikbaar
			WC()->customer->set_billing_postcode( intval( $_GET['referralZip'] ) );
			WC()->customer->set_shipping_postcode( intval( $_GET['referralZip'] ) );
		}

		if ( isset( $_GET['referralCity'] ) ) {
			WC()->customer->set_billing_city( $_GET['referralCity'] );
			WC()->customer->set_shipping_city( $_GET['referralCity'] );
		}
		
		if ( isset( $_GET['emptyCart'] ) ) {
			WC()->cart->empty_cart();
		}
		
		// Zet korte beschrijving meer naar onder
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_sharing', 50 );
		add_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_sharing', 100 );
	}

	// Verhoog het aantal producten per winkelpagina
	add_filter( 'loop_shop_per_page', create_function( '$cols', 'return 20;' ), 20 );

	// Orden items in bestellingen volgens stijgend productnummer
	add_filter( 'woocommerce_order_get_items', 'sort_order_by_sku', 10, 2 );

	function sort_order_by_sku( $items, $order ) {
		uasort( $items, function( $a, $b ) {
			// Verhinder dat we ook tax- en verzendlijnen shufflen
			if ( $a->get_type() === 'line_item' and $b->get_type() === 'line_item' ) {
				$product_a = $a->get_product();
				$product_b = $b->get_product();
				// Zorg ervoor dat variabelen altijd gedefinieerd zijn!
				if ( $product_a !== false ) {
					$sku_a = $product_a->get_sku();
				} else {
					$sku_a = 'error';
				}
				if ( $product_b !== false ) {
					$sku_b = $product_b->get_sku();
				} else {
					$sku_b = 'error';
				}
				// Deze logica plaatst niet-numerieke referenties (en dus ook inmiddels ter ziele gegane producten) onderaan
				if ( is_numeric( $sku_a ) ) {
					if ( is_numeric( $sku_b ) ) {
						return ( intval( $sku_a ) < intval( $sku_b ) ) ? -1 : 1;	
					} else {
						return -1;
					}
				} else {
					if ( is_numeric( $sku_b ) ) {
						return 1;	
					} else {
						return -1;
					}
				}
			} else {
				return 0;
			}
		} );
		return $items;
	}

	// Zorg ervoor dat slechts bepaalde statussen bewerkbaar zijn
	add_filter( 'wc_order_is_editable', 'limit_editable_orders', 20, 2 );

	function limit_editable_orders( $editable, $order ) {
		// Slugs van alle extra orderstatussen (zonder 'wc'-prefix) die bewerkbaar moeten zijn
		// Opmerking: standaard zijn 'pending', 'on-hold' en 'auto-draft' bewerkbaar
		$editable_custom_statuses = array( 'on-hold' );
		if ( in_array( $order->get_status(), $editable_custom_statuses ) ) {
			$editable = true;
		} else {
			$editable = false;
		}
		return $editable;
	}
	
	// Voeg sorteren op artikelnummer toe aan de opties op cataloguspagina's
	add_filter( 'woocommerce_get_catalog_ordering_args', 'add_sku_sorting' );
	add_filter( 'woocommerce_catalog_orderby', 'sku_sorting_orderby' );
	add_filter( 'woocommerce_default_catalog_orderby_options', 'sku_sorting_orderby' );

	function add_sku_sorting( $args ) {
		$orderby_value = isset( $_GET['orderby'] ) ? wc_clean( $_GET['orderby'] ) : apply_filters( 'woocommerce_default_catalog_orderby', get_option( 'woocommerce_default_catalog_orderby' ) );

		if ( 'alpha' === $orderby_value ) {
			$args['orderby'] = 'title';
			$args['order'] = 'ASC';
		}

		if ( 'alpha-desc' === $orderby_value ) {
			$args['orderby'] = 'title';
			$args['order'] = 'DESC';
		}

		return $args;
	}
	
	function sku_sorting_orderby( $sortby ) {
		unset( $sortby['menu_order'] );
		unset( $sortby['rating'] );
		// $sortby['popularity'] = 'Best verkocht';
		// $sortby['date'] = 'Laatst toegevoegd';
		$sortby['alpha'] = 'Van A tot Z';
		$sortby['alpha-desc'] = 'Van Z tot A';
		// $sortby['price'] = 'Stijgende prijs';
		// $sortby['price-desc'] = 'Dalende prijs';
		// $sortby['sku'] = 'Stijgend artikelnummer';
		// $sortby['reverse_sku'] = 'Dalend artikelnummer';
		return $sortby;
	}

	// Maak B2B-producten enkel zichtbaar voor B2B-klanten (cataloguspagina's)
	add_action( 'woocommerce_product_query', 'ob2c_constrain_assortment_to_b2b' );
	
	function ob2c_constrain_assortment_to_b2b( $query ) {
		// Sta ook toe dat medewerkers de B2B-producten te zien krijgen
		if ( ! is_b2b_customer() and ! current_user_can('manage_woocommerce') ) {
			$tax_query = (array) $query->get('tax_query');
			// Voeg query toe die alle producten uit de 'Grootverbruik'-categorie uitsluit
			$tax_query[] = array(
				'taxonomy' => 'product_cat',
				'field' => 'name',
				'terms' => array( 'Grootverbruik' ),
				'operator' => 'NOT IN',
			);
			$query->set( 'tax_query', $tax_query );
		}
	}

	// Maak B2B-producten enkel zichtbaar voor B2B-klanten (shortcodes)
	add_filter( 'woocommerce_shortcode_products_query', 'ob2c_shortcode_constrain_assortment_to_b2b' );
	
	function ob2c_shortcode_constrain_assortment_to_b2b( $query_args ) {
		// Sta ook toe dat medewerkers de B2B-producten te zien krijgen
		if ( ! is_b2b_customer() and ! current_user_can('manage_woocommerce') ) {
			// Voeg query toe die alle producten uit de 'Grootverbruik'-categorie uitsluit
			$query_args['tax_query'][] = array(
				'taxonomy' => 'product_cat',
				'field' => 'name',
				'terms' => array( 'Grootverbruik' ),
				'operator' => 'NOT IN',
			);
		}
		return $query_args;
	}

	// Doet de koopknop verdwijnen bij verboden producten én zwiert reeds toegevoegde producten uit het winkelmandje DUS NIET GEBRUIKEN OM HANDMATIG TOEVOEGEN VAN LEEGGOED TE VERHINDEREN
	add_filter( 'woocommerce_is_purchasable', 'ob2c_disable_products_not_in_assortment', 10, 2 );

	function ob2c_disable_products_not_in_assortment( $purchasable, $product ) {
		return apply_filters( 'ob2c_product_is_available', $product->get_id(), is_b2b_customer(), $purchasable );
	}

	// Filter wordt enkel doorlopen bij de 1ste toevoeging van een product!
	add_filter( 'woocommerce_add_to_cart_validation', 'ob2c_disallow_products_not_in_assortment', 10, 2 );

	function ob2c_disallow_products_not_in_assortment( $passed, $product_id ) {
		$passed_extra_conditions = apply_filters( 'ob2c_product_is_available', $product_id, is_b2b_customer(), $passed );

		if ( $passed and ! $passed_extra_conditions ) {
			wc_add_notice( sprintf( __( 'Foutmelding indien een gewone klant een B2B-product probeert te bestellen.', 'oxfam-webshop' ), is_b2b_customer() ), 'error' );
		}
		
		return $passed_extra_conditions;
	}

	// Maak de detailpagina van verboden producten volledig onbereikbaar
	add_action( 'template_redirect', 'ob2c_prevent_access_to_product_page' );
	
	function ob2c_prevent_access_to_product_page() {
		if ( is_product() ) {
			$product = wc_get_product( get_the_ID() );
			$available = apply_filters( 'ob2c_product_is_available', get_the_ID(), is_b2b_customer(), true );
			
			if ( ! $available or strpos( $product->get_sku(), 'W' ) === 0 ) {
				// Als de klant nog niets in het winkelmandje zitten heeft, is er nog geen sessie om notices aan toe te voegen!
				if ( ! WC()->session->has_session() ) {
					WC()->session->set_customer_session_cookie(true);
				}

				// Gebruik deze boodschap voorlopig ook als foutmelding bij leeggoed
				wc_add_notice( sprintf( __( 'Foutmelding indien een gewone klant het B2B-product %s probeert te bekijken.', 'oxfam-webshop' ), get_the_title() ), 'error' );
				
				if ( wp_get_referer() ) {
					// Keer terug naar de vorige pagina
					wp_safe_redirect( wp_get_referer() );
				} else {
					// Ga naar de hoofdpagina van de winkel
					wp_safe_redirect( get_permalink( wc_get_page_id('shop') ) );
				}
				exit;
			}
		}
	}

	// Definieer een eigen filter zodat we de voorwaarden slecht één keer centraal hoeven in te geven
	add_filter( 'ob2c_product_is_available', 'ob2c_check_product_availability_for_customer', 10, 3 );

	function ob2c_check_product_availability_for_customer( $product_id, $is_b2b_customer, $available ) {
		// Sta ook toe dat medewerkers de B2B-producten te zien krijgen
		if ( ! $is_b2b_customer and ! current_user_can('manage_woocommerce') ) {
			if ( has_term( 'Grootverbruik', 'product_cat', $product_id ) ) {
				$product = wc_get_product( $product_id );
				write_log( "DISABLED PRODUCT ".$product->get_sku()." VIEW / PURCHASE / ADD TO CART FOR NON B2B CLIENT" );
				$available = false;
			}
		}

		return $available;
	}

	// Herlaad winkelmandje automatisch na aanpassing en zorg dat postcode altijd gecheckt wordt (en activeer live search indien plugin geactiveerd)
	add_action( 'wp_footer', 'cart_update_qty_script' );
	
	function cart_update_qty_script() {
		if ( is_cart() ) {
			?>
				<script>
					var wto;
					jQuery('div.woocommerce').on( 'change', '.qty', function() {
						clearTimeout(wto);
						// Time-out net iets groter dan buffertijd zodat we bij ingedrukt houden van de spinner niet gewoon +1/-1 doen
						wto = setTimeout(function() {
							jQuery("[name='update_cart']").trigger('click');
						}, 500);

					});
				</script>
			<?php
		} elseif ( is_main_site() and is_front_page() ) {
			?>
				<script type="text/javascript">
					jQuery(document).ready( function() {
						var wto;
						jQuery('#oxfam-zip-user').on( 'input change', function() {
							clearTimeout(wto);
							var zip = jQuery(this).val();
							var button = jQuery('#do_oxfam_redirect');
							var zips = <?php echo json_encode( get_site_option('oxfam_flemish_zip_codes') ); ?>;
							if ( zip.length == 4 && /^\d{4}$/.test(zip) && (zip in zips) ) {
								button.prop( 'disabled', false ).parent().addClass('is-valid');
								wto = setTimeout( function() {
									button.find('i').addClass('loading');
									wto = setTimeout( function() {
										button.trigger('click');
									}, 750);
								}, 250);
							} else {
								button.prop( 'disabled', true ).parent().removeClass('is-valid');
							}
						});
						
						jQuery('#oxfam-zip-user').keyup( function(event) {
							if ( event.which == 13 ) {
								jQuery('#do_oxfam_redirect').trigger('click');
							}
						});
						
						jQuery('#do_oxfam_redirect').on( 'click', function() {
							jQuery(this).prop( 'disabled', true );
							var input = jQuery('#oxfam-zip-user');
							var zip = input.val();
							var url = jQuery('#'+zip+'.oxfam-zip-value').val();
							var all_cities = <?php echo json_encode( get_site_option('oxfam_flemish_zip_codes') ) ?>;
							// Indien er meerdere plaatsnamen zijn, knippen we ze op en gebruiken we de eerste (= hoofdgemeente)
							var cities_for_zip = all_cities[zip].split(' / ');
							if ( typeof url !== 'undefined' ) {
								if ( url.length > 10 ) {
									var suffix = '';
									<?php if ( isset( $_GET['addSku'] ) ) : ?>
										suffix = '&addSku=<?php echo $_GET['addSku']; ?>';
									<?php endif; ?>
									window.location.href = url+'?referralZip='+zip+'&referralCity='+cities_for_zip[0]+suffix;
								} else {
									alert("<?php _e( 'Foutmelding na het ingeven van een Vlaamse postcode waar Oxfam-Wereldwinkels nog geen thuislevering voorziet.', 'oxfam-webshop' ); ?>");
									jQuery(this).parent().removeClass('is-valid').find('i').removeClass('loading');
									input.val('');
								}
							} else {
								alert("<?php _e( 'Foutmelding na het ingeven van een onbestaande Vlaamse postcode.', 'oxfam-webshop' ); ?> Tip: je kunt ook de naam van je gemeente beginnen te typen en de juiste postcode selecteren uit de suggesties die verschijnen.");
								jQuery(this).parent().removeClass('is-valid').find('i').removeClass('loading');
								input.val('');
							}
						});

						jQuery( function() {
							var zips = <?php echo json_encode( get_flemish_zips_and_cities() ); ?>;
							jQuery( '#oxfam-zip-user' ).autocomplete({
								source: zips,
								minLength: 1,
								autoFocus: true,
								position: { my : "right+20 top", at: "right bottom" },
								close: function(event,ui) {
									// Opgelet: dit wordt uitgevoerd vòòr het standaardevent (= invullen van de postcode in het tekstvak)
									jQuery( '#oxfam-zip-user' ).trigger('change');
								}
							});
						});
					});
				</script>
			<?php
		} elseif ( is_account_page() and is_user_logged_in() ) {
			$current_user = wp_get_current_user();
			$user_meta = get_userdata($current_user->ID);
			$user_roles = $user_meta->roles;
			if ( in_array( 'local_manager', $user_roles ) and $current_user->user_email === get_company_email() ) {
				?>
					<script type="text/javascript">
						jQuery(document).ready( function() {
							jQuery("form.woocommerce-EditAccountForm").find('input[name=account_email]').prop('readonly', true);
							jQuery("form.woocommerce-EditAccountForm").find('input[name=account_email]').after('<span class="description">De lokale beheerder dient altijd gekoppeld te blijven aan de webshopmailbox, dus dit veld kun je niet bewerken.</span>');
						});
					</script>
				<?php
			}
		}

		?>
			<script type="text/javascript">
				jQuery(document).ready( function() {
					function hidePlaceholder( dateText, inst ) {
						// Placeholder onmiddellijk verwijderen
						jQuery(this).attr('placeholder', '');
						// Datum is sowieso geldig, verwijder de eventuele foutmelding
						jQuery('#datepicker_field').removeClass('woocommerce-invalid woocommerce-invalid-required-field');
					}

					jQuery("#datepicker").datepicker({
						dayNamesMin: [ "Zo", "Ma", "Di", "Wo", "Do", "Vr", "Za" ],
						monthNamesShort: [ "Jan", "Feb", "Maart", "April", "Mei", "Juni", "Juli", "Aug", "Sep", "Okt", "Nov", "Dec" ],
						changeMonth: true,
						changeYear: true,
						yearRange: "c-50:c+32",
						defaultDate: "-50y",
						maxDate: "-18y",
						onSelect: hidePlaceholder,
					});
				});
			</script>
		<?php
	}

	// Verhinder bepaalde selecties in de back-end
	add_action( 'admin_footer', 'disable_custom_checkboxes' );

	function disable_custom_checkboxes() {
		?>
		<script>
			/* Disable hoofdcategorieën */
			jQuery( '#in-product_cat-200' ).prop( 'disabled', true );
			jQuery( '#in-product_cat-204' ).prop( 'disabled', true );
			jQuery( '#in-product_cat-210' ).prop( 'disabled', true );
			jQuery( '#in-product_cat-213' ).prop( 'disabled', true );
			jQuery( '#in-product_cat-224' ).prop( 'disabled', true );
			
			/* Disable continenten */
			jQuery( '#in-product_partner-162' ).prop( 'disabled', true );
			jQuery( '#in-product_partner-163' ).prop( 'disabled', true );
			jQuery( '#in-product_partner-164' ).prop( 'disabled', true );
			jQuery( '#in-product_partner-165' ).prop( 'disabled', true );
			
			/* Disable bovenliggende landen/continenten van alle aangevinkte partners/landen */
			jQuery( '#taxonomy-product_partner' ).find( 'input[type=checkbox]:checked' ).closest( 'ul.children' ).siblings( 'label.selectit' ).find( 'input[type=checkbox]' ).prop( 'disabled', true );

			/* Disable/enable het bovenliggende land bij aan/afvinken van een partner */
			jQuery( '#taxonomy-product_partner' ).find( 'input[type=checkbox]' ).on( 'change', function() {
				jQuery(this).closest( 'ul.children' ).siblings( 'label.selectit' ).find( 'input[type=checkbox]' ).prop( 'disabled', jQuery(this).is(":checked") );
			});

			/* Disbable prijswijzigingen bij terugbetalingen */
			jQuery( '#order_line_items' ).find( '.refund_line_total.wc_input_price' ).prop( 'disabled', true );
			jQuery( '#order_line_items' ).find( '.refund_line_tax.wc_input_price' ).prop( 'disabled', true );
			jQuery( '.wc-order-totals' ).find ( '#refund_amount' ).prop( 'disabled', true );
			jQuery( 'label[for=restock_refunded_items]' ).closest( 'tr' ).hide();
		</script>
		<?php
		if ( ! current_user_can('manage_options') ) {
			?>
			<script>
				/* Orderstatus vastzetten */
				jQuery( '#order_data' ).find( '#order_status' ).prop( 'disabled', true );
			</script>
			<?php
		}
	}

	// Label en layout de factuurgegevens ENKEL GEBRUIKEN OM NON-CORE-FIELDS TE BEWERKEN OF VELDEN TE UNSETTEN
	add_filter( 'woocommerce_billing_fields', 'format_checkout_billing', 10, 1 );

	function format_checkout_billing( $address_fields ) {
		$address_fields['billing_email'] = array_merge(
			$address_fields['billing_email'],
			array(
				'label' => 'E-mailadres',
				'placeholder' => 'luc@gmail.com',
				'class' => array('form-row-first'),
				'clear' => false,
				'required' => true,
				'priority' => 12,
			)
		);

		$address_fields['billing_birthday'] = array(
			'id' => 'datepicker',
			'label' => 'Geboortedatum',
			'placeholder' => '16/03/1988',
			'class' => array('form-row-last'),
			'clear' => true,
			'required' => true,
			'priority' => 13,
		);

		if ( ! in_array( '', WC()->cart->get_cart_item_tax_classes() ) ) {
			// Als er geen producten à 21% BTW in het mandje zitten (= standaardtarief) wordt er geen alcohol gekocht en wordt het veld optioneel
			// Met het oog op toevoegen non-food wellicht beter af te handelen via verzendklasses (maar get_shipping_class() moet loopen over alle items ...)
			$address_fields['billing_birthday']['required'] = false;
		}
		
		if ( is_b2b_customer() ) {
			$address_fields['billing_vat'] = array(
				'label' => 'BTW-nummer',
				'placeholder' => 'BE 0453.066.016',
				'class' => array('form-row-last'),
				// Want verenigingen hebben niet noodzakelijk een BTW-nummer!
				'required' => false,
				'priority' => 21,
			);
		} else {
			unset( $address_fields['billing_company'] );
		}

		$address_fields['billing_phone'] = array_merge(
			$address_fields['billing_phone'],
			array(
				'label' => 'Telefoonnummer',
				'placeholder' => get_oxfam_shop_data('telephone'),
				'class' => array('form-row-last'),
				'clear' => true,
				'required' => true,
				'priority' => 31,
			)
		);
		
		// Verbergen indien reeds geabonneerd?
		$address_fields['digizine'] = array(
			'id' => 'digizine',
			'type' => 'checkbox',
			// <span> is nodig om lay-out van checkbox in overeenstemming te brengen met andere
			'label' => '<span>Abonneer mij op <a href="https://us3.campaign-archive.com/home/?u=d66c099224e521aa1d87da403&id=5cce3040aa" target="_blank">de maandelijkse nieuwsbrief van Oxfam-Wereldwinkels</a></span>',
			'class' => array('form-row-wide no-margin-bottom'),
			'label_class' => array('woocommerce-form__label woocommerce-form__label-for-checkbox'),
			'input_class' => array('woocommerce-form__input woocommerce-form__input-checkbox'),
			'clear' => true,
			'required' => false,
			'priority' => 101,
		);
		$address_fields['marketing'] = array(
			'id' => 'marketing',
			'type' => 'checkbox',
			// <span> is nodig om lay-out van checkbox in overeenstemming te brengen met andere
			'label' => '<span>Stuur mij commerciële mails (promoties, nieuwigheden, ...)</span>',
			'class' => array('form-row-wide no-margin-bottom'),
			'label_class' => array('woocommerce-form__label woocommerce-form__label-for-checkbox'),
			'input_class' => array('woocommerce-form__input woocommerce-form__input-checkbox'),
			'clear' => true,
			'required' => false,
			'priority' => 102,
		);

		return $address_fields;
	}

	// Label en layout de verzendgegevens ENKEL GEBRUIKEN OM NON-CORE-FIELDS TE BEWERKEN OF VELDEN TE UNSETTEN
	add_filter( 'woocommerce_shipping_fields', 'format_checkout_shipping', 10, 1 );
	
	function format_checkout_shipping( $address_fields ) {
		$address_fields['shipping_address_1']['class'] = array('form-row-wide');
		$address_fields['shipping_address_1']['clear'] = true;
		unset($address_fields['shipping_company']);
		return $address_fields;
	}

	// Verduidelijk de labels en layout van de basisvelden KOMT NA ALLE ANDERE ADRESFILTERS
	add_filter( 'woocommerce_default_address_fields', 'format_addresses_frontend', 100, 1 );

	function format_addresses_frontend( $address_fields ) {
		$address_fields['first_name'] = array_merge(
			$address_fields['first_name'],
			array(
				'label' => 'Voornaam',
				'placeholder' => 'Luc',
				'class' => array('form-row-first'),
				'clear' => false,
				'priority' => 10,
			)
		);

		$address_fields['last_name'] = array_merge(
			$address_fields['last_name'],
			array(
				'label' => 'Familienaam',
				'placeholder' => 'Willems',
				'class' => array('form-row-last'),
				'clear' => true,
				'priority' => 11,
			)
		);

		$address_fields['company'] = array_merge(
			$address_fields['company'],
			array(
				'label' => 'Bedrijf of vereniging',
				'placeholder' => 'Oxfam Fair Trade cvba',
				'class' => array('form-row-first'),
				'clear' => false,
				'required' => true,
				'priority' => 20,
			)
		);

		$address_fields['address_1'] = array_merge(
			$address_fields['address_1'],
			array(
				'label' => 'Straat en huisnummer',
				'placeholder' => 'Stationstraat 16',
				'class' => array('form-row-first'),
				'clear' => false,
				'required' => true,
				'priority' => 30,
			)
		);

		unset($address_fields['address_2']);

		$address_fields['postcode'] = array_merge(
			$address_fields['postcode'],
			array(
				'label' => 'Postcode',
				'placeholder' => get_oxfam_shop_data('zipcode'),
				// Zorg ervoor dat de totalen automatisch bijgewerkt worden na aanpassen van de postcode
				// Werkt enkel indien de voorgaande verplichte velden niet-leeg zijn, zie maybe_update_checkout() in woocommerce/assets/js/frontend/checkout.js 
				'class' => array('form-row-first update_totals_on_change'),
				'clear' => false,
				'required' => true,
				// Wordt door een andere plugin naar 65 geforceerd
				'priority' => 70,
			)
		);

		$address_fields['city'] = array_merge(
			$address_fields['city'],
			array(
				'label' => 'Gemeente',
				'placeholder' => get_oxfam_shop_data('city'),
				'class' => array('form-row-last'),
				'clear' => true,
				'required' => true,
				'priority' => 71,
			)
		);

		// Onzichtbaar gemaakt via CSS maar absoluut nodig voor service points!
		$address_fields['country']['priority'] = 100;

		return $address_fields;
	}

	// Vul andere placeholders in, naar gelang de gekozen verzendmethode op de winkelwagenpagina (wordt NIET geüpdatet bij verandering in checkout)
	add_filter( 'woocommerce_checkout_fields' , 'format_checkout_notes' );

	function format_checkout_notes( $fields ) {
		$fields['account']['account_username']['label'] = "Kies een gebruikersnaam:";
		$fields['account']['account_password']['label'] = "Kies een wachtwoord:";
		
		$shipping_methods = WC()->session->get('chosen_shipping_methods');
		$shipping_id = reset($shipping_methods);
		switch ( $shipping_id ) {
			case stristr( $shipping_id, 'local_pickup' ):
				$placeholder = __( 'Voorbeeldnotitie op afrekenpagina (indien afhaling).', 'oxfam-webshop' );
				break;
			default:
				$placeholder = __( 'Voorbeeldnotitie op afrekenpagina (indien thuislevering).', 'oxfam-webshop' );
				break;
		}
		$fields['order']['order_comments']['placeholder'] = $placeholder;
		// HTML wordt helaas niet uitgevoerd, dus link naar FAQ niet mogelijk!
		$fields['order']['order_comments']['description'] = sprintf( __( 'Boodschap onder de notities op de afrekenpagina, inclusief telefoonnummer van de hoofdwinkel (%s).', 'oxfam-webshop' ), get_oxfam_shop_data('telephone') );

		return $fields;
	}

	// Voeg tooltip toe achter het label van bepaalde velden
	add_filter( 'woocommerce_form_field_text', 'add_tooltips_after_woocommerce_label', 10, 4 );
	add_filter( 'woocommerce_form_field_tel', 'add_tooltips_after_woocommerce_label', 10, 4 );
	add_filter( 'woocommerce_form_field_checkbox', 'add_tooltips_after_woocommerce_label', 10, 4 );

	function add_tooltips_after_woocommerce_label( $field, $key, $args, $value ) {
		if ( $key === 'billing_birthday' ) {
			$field = str_replace( '</label>', '<span class="dashicons dashicons-editor-help tooltip"><span class="tooltiptext">Omdat we ook alcohol verkopen zijn we verplicht om je leeftijd te controleren. We gebruiken deze info nooit voor andere doeleinden.</span></span></label>', $field );
		}

		if ( $key === 'billing_phone' ) {
			$field = str_replace( '</label>', '<span class="dashicons dashicons-editor-help tooltip"><span class="tooltiptext">We bellen je enkel op indien dit nodig is voor een vlotte verwerking van je bestelling. We gebruiken het nummer nooit voor andere doeleinden.</span></span></label>', $field );
		}

		if ( $key === 'marketing' ) {
			$field = str_replace( '</span></label>', '<span class="dashicons dashicons-editor-help tooltip"><span class="tooltiptext">We mailen je hooguit 1x per week. Je kunt je voorkeuren op elk ogenblik aanpassen.</span></span></span></label>', $field );
		}

		return $field;
	}

	// Acties om uit te voeren AAN BEGIN VAN ELKE POGING TOT CHECKOUT
	// add_action( 'woocommerce_checkout_process', 'verify_min_max_age_postcode_vat' );

	function verify_min_max_age_postcode_vat() {
		// Stel een bestelminimum (en fictief -maximum) in
		$min = 10;
		$max = 10000;
		// WC()->cart->get_cart_subtotal() geeft het subtotaal vòòr korting, verzending en (indien B2B) BTW
		// WC()->cart->cart_contents_total geeft het subtotaal vòor BTW en verzending maar nà korting
		// Gebruik in de toekomst WC()->cart->get_total('edit') om het totaalbedrag als float op te vragen (WC3.2+)
		if ( floatval( WC()->cart->cart_contents_total ) < $min ) {
			wc_add_notice( sprintf( __( 'Foutmelding bij te kleine bestellingen, inclusief minimumbedrag in euro (%d).', 'oxfam-webshop' ), $min ), 'error' );
		} elseif ( floatval( WC()->cart->cart_contents_total ) > $max ) {
			wc_add_notice( sprintf( __( 'Foutmelding bij te grote bestellingen, inclusief maximumbedrag in euro (%d).', 'oxfam-webshop' ), $max ), 'error' );
		}
	}

	// Validaties om uit te voeren NA FORMATTERING DATA door 'woocommerce_process_checkout_field_...'-filters in get_posted_data()
	add_action( 'woocommerce_after_checkout_validation', 'do_age_housenumber_vat_validation', 10, 2 );

	function do_age_housenumber_vat_validation( $fields, $errors ) {
		// Check op het invullen van verplichte velden gebeurt reeds eerder door WooCommerce
		// Als er een waarde meegegeven wordt, checken we wel steeds of de klant meerderjarig is
		if ( ! empty( $fields['billing_birthday'] ) ) {
			if ( $fields['billing_birthday'] === '31/12/2100' ) {
				$errors->add( 'validation', __( 'Foutmelding na het invullen van slecht geformatteerde datum.', 'oxfam-webshop' ) );
			} else {
				// Opletten met de Amerikaanse interpretatie DD/MM/YYYY!
				if ( strtotime( str_replace( '/', '-', $fields['billing_birthday'] ) ) > strtotime('-18 years') ) {
					$errors->add( 'validation', __( 'Foutmelding na het invullen van een geboortedatum die minder dan 18 jaar in het verleden ligt.', 'oxfam-webshop' ) );
				}
			}
		}

		// Check of het huisnummer ingevuld is (behalve bij afhalingen)
		if ( isset( $fields['shipping_method'][0] ) and $fields['shipping_method'][0] !== 'local_pickup_plus' ) {
			if ( $fields['ship_to_different_address'] ) {
				// Er werd een afwijkend verzendadres ingevuld, check die waarde
				$key_to_check = 'shipping_address_1';
			} else {
				// Check enkel 'billing_address_1' want de gegevens die in 'shipping_address_1' doorgegeven worden zijn niet altijd up-to-date!
				// Indien je een wijziging doet aan 'billing_address_1' wordt dit pas na een page refresh gekopieerd naar 'shipping_address_1'
				$key_to_check = 'billing_address_1';
			}

			if ( ! empty( $fields[ $key_to_check ] ) ) {
				// Indien er echt geen huisnummer is, moet Z/N ingevuld worden
				if ( preg_match( '/([0-9]+|ZN)/i', $fields[ $key_to_check ] ) === 0 ) {
					$str = date_i18n('d/m/Y H:i:s')."\t\t".get_home_url()."\t\tHuisnummer ontbreekt in '".$fields[ $key_to_check ]."'\n";
					file_put_contents( "../housenumber_errors.csv", $str, FILE_APPEND );
					$errors->add( 'validation', __( 'Foutmelding na het invullen van een straatnaam zonder huisnummer.', 'oxfam-webshop' ) );
				}
			}
		}

		// Check of het BTW-nummer geldig is
		if ( ! empty( $fields['billing_vat'] ) ) {
			if ( strpos( format_tax( $fields['billing_vat'] ), 'INVALID' ) !== false ) {
				$errors->add( 'validation', __( 'Foutmelding na het ingeven van een ongeldig BTW-nummer.', 'oxfam-webshop' ) );
			}
		}
	}

	// Acties om uit te voeren NA SUCCESVOLLE CHECKOUT (order reeds aangemaakt) 
	add_action( 'woocommerce_checkout_update_order_meta', 'save_b2b_order_fields', 10, 2 );

	function save_b2b_order_fields( $order_id, $data ) {
		// write_log( print_r( $data, true ) );

		if ( $data['digizine'] === 1 or $data['marketing'] === 1 ) {
			$post_data = array(
				// Naam en e-mailadres zijn reeds geformatteerd!
				'fname' => $data['billing_first_name'],
				'lname' => $data['billing_last_name'],
				'email' => $data['billing_email'],
				'source' => 'webshop',
				'newsletter' => 'yes',
				'shop' => get_company_name(),
			);

			if ( $data['digizine'] === 1 ) {
				// Zet marketing_permission_id 496c25fb49 aan (ID blijft bewaard als tekst wijzigt)
				$post_data['digizine'] = 'yes';
				$str = date_i18n('d/m/Y H:i:s')."\t\t".$data['billing_email']."\t\tEnable marketing permission 496c25fb49\n";
				file_put_contents( "../mailchimp_instructions.csv", $str, FILE_APPEND );
			} else {
				$post_data['digizine'] = 'no';
			}

			if ( $data['marketing'] === 1 ) {
				// Zet marketing_permission_id c1cbf23458 aan (ID blijft bewaard als tekst wijzigt)
				$post_data['marketing'] = 'yes';
				$str = date_i18n('d/m/Y H:i:s')."\t\t".$data['billing_email']."\t\tEnable marketing permission c1cbf23458\n";
				file_put_contents( "../mailchimp_instructions.csv", $str, FILE_APPEND );
			}

			$settings = array(
				'timeout' => 10,
				'body' => $post_data,
			);
			// BIJ VOORKEUR ASYNCHROON DOEN ZODAT HET CHECKOUT NIET VERTRAAGT
			$response = wp_remote_post( 'https://www.oxfamwereldwinkels.be/wp-content/themes/oxfam/mailchimp/subscribe.php', $settings );
			$result = json_decode( wp_remote_retrieve_body( $response ) );
			file_put_contents( "../mailchimp_instructions.csv", date_i18n('d/m/Y H:i:s')."\t\t".$data['billing_email']."\t\t".$result['status'], FILE_APPEND );
		}

		// Registreer of het een B2B-verkoop is of niet
		if ( is_b2b_customer() ) {
			$value = 'yes';
			// Extra velden met 'billing'-prefix worden automatisch opgeslagen (maar niet getoond), geen actie nodig
		} else {
			$value = 'no';
		}
		update_post_meta( $order_id, 'is_b2b_sale', $value );
	}

	// Wanneer het order BETAALD wordt, slaan we de geschatte leverdatum op
	add_action( 'woocommerce_order_status_pending_to_processing', 'save_estimated_delivery' );

	function save_estimated_delivery( $order_id ) {
		$order = wc_get_order($order_id);
		$shipping = $order->get_shipping_methods();
		$shipping = reset($shipping);

		if ( $order->get_meta('is_b2b_sale') !== 'yes' ) {
			$timestamp = estimate_delivery_date( $shipping['method_id'], $order_id );
			$order->add_meta_data( 'estimated_delivery', $timestamp, true );
			$order->save_meta_data();
		}
	}

	// Herschrijf bepaalde klantendata naar standaardformaten tijdens afrekenen én bijwerken vanaf accountpagina
	add_filter( 'woocommerce_process_checkout_field_billing_first_name', 'trim_and_uppercase', 10, 1 );
	add_filter( 'woocommerce_process_myaccount_field_billing_first_name', 'trim_and_uppercase', 10, 1 );
	add_filter( 'woocommerce_process_checkout_field_billing_last_name', 'trim_and_uppercase', 10, 1 );
	add_filter( 'woocommerce_process_myaccount_field_billing_last_name', 'trim_and_uppercase', 10, 1 );
	add_filter( 'woocommerce_process_checkout_field_billing_company', 'trim_and_uppercase', 10, 1 );
	add_filter( 'woocommerce_process_myaccount_field_billing_company', 'trim_and_uppercase', 10, 1 );
	add_filter( 'woocommerce_process_checkout_field_billing_vat', 'format_tax', 10, 1 );
	add_filter( 'woocommerce_process_myaccount_field_billing_vat', 'format_tax', 10, 1 );
	add_filter( 'woocommerce_process_checkout_field_billing_address_1', 'format_place', 10, 1 );
	add_filter( 'woocommerce_process_myaccount_field_billing_address_1', 'format_place', 10, 1 );
	add_filter( 'woocommerce_process_checkout_field_billing_postcode', 'format_zipcode', 10, 1 );
	add_filter( 'woocommerce_process_myaccount_field_billing_postcode', 'format_zipcode', 10, 1 );
	add_filter( 'woocommerce_process_checkout_field_billing_city', 'format_city', 10, 1 );
	add_filter( 'woocommerce_process_myaccount_field_billing_city', 'format_city', 10, 1 );
	add_filter( 'woocommerce_process_checkout_field_billing_phone', 'format_telephone', 10, 1 );
	add_filter( 'woocommerce_process_myaccount_field_billing_phone', 'format_telephone', 10, 1 );
	add_filter( 'woocommerce_process_checkout_field_billing_email', 'format_mail', 10, 1 );
	add_filter( 'woocommerce_process_myaccount_field_billing_email', 'format_mail', 10, 1 );
	add_filter( 'woocommerce_process_checkout_field_billing_birthday', 'format_date', 10, 1 );
	add_filter( 'woocommerce_process_checkout_field_shipping_first_name', 'trim_and_uppercase', 10, 1 );
	add_filter( 'woocommerce_process_myaccount_field_shipping_first_name', 'trim_and_uppercase', 10, 1 );
	add_filter( 'woocommerce_process_checkout_field_shipping_last_name', 'trim_and_uppercase', 10, 1 );
	add_filter( 'woocommerce_process_myaccount_field_shipping_last_name', 'trim_and_uppercase', 10, 1 );
	add_filter( 'woocommerce_process_checkout_field_shipping_address_1', 'format_place', 10, 1 );
	add_filter( 'woocommerce_process_myaccount_field_shipping_address_1', 'format_place', 10, 1 );
	add_filter( 'woocommerce_process_checkout_field_shipping_postcode', 'format_zipcode', 10, 1 );
	add_filter( 'woocommerce_process_myaccount_field_shipping_postcode', 'format_zipcode', 10, 1 );
	add_filter( 'woocommerce_process_checkout_field_shipping_city', 'format_city', 10, 1 );
	add_filter( 'woocommerce_process_myaccount_field_shipping_city', 'format_city', 10, 1 );
	
	function trim_and_uppercase( $value ) {
		return str_replace( 'Oww ', 'OWW ', implode( '.', array_map( 'ucwords', explode( '.', implode( '(', array_map( 'ucwords', explode( '(', implode( '-', array_map( 'ucwords', explode( '-', mb_strtolower( trim($value) ) ) ) ) ) ) ) ) ) ) );
	}

	function format_tax( $value ) {
		$value = str_replace( 'BE', '', $value );
		$value = preg_replace( '/[\s\-\.\/]/', '', $value );
		if ( mb_strlen($value) === 9 ) {
			$value = '0'.$value;
		}

		if ( mb_strlen($value) === 10 ) {
			$digit_8 = intval( substr( $value, 0, 8 ) );
			$checksum = 97 - ( $digit_8 - intval( $digit_8 / 97 ) * 97 );
			if ( $checksum === intval( substr( $value, 8, 2 ) ) ) {
				return 'BE '.substr( $value, 0, 4 ).".".substr( $value, 4, 3 ).".".substr( $value, 7, 3 );
			} else {
				return 'INVALID CHECKSUM';
			}
		} elseif ( mb_strlen($value) >= 1 ) {
			return 'INVALID LENGTH';
		} else {
			return '';
		}
	}

	function format_account( $iban ) {
		$countries = array( 'BE' => 16, 'NL' => 18 );
		$translate_chars = array( 
			'A' => 10,
			'B' => 11,
			'C' => 12,
			'D' => 13,
			'E' => 14,
			'F' => 15,
			'G' => 16,
			'H' => 17,
			'I' => 18,
			'J' => 19,
			'K' => 20,
			'L' => 21,
			'M' => 22,
			'N' => 23,
			'O' => 24,
			'P' => 25,
			'Q' => 26,
			'R' => 27,
			'S' => 28,
			'T' => 29,
			'U' => 30,
			'V' => 31,
			'W' => 32,
			'X' => 33,
			'Y' => 34,
			'Z' => 35,
		);

		$iban = str_replace( 'IBAN', '', mb_strtoupper($iban) );
		$iban = preg_replace( '/[\s\-\.\/]/', '', $iban );

		if ( array_key_exists( substr( $iban, 0, 2 ), $countries ) and strlen($iban) === $countries[substr( $iban, 0, 2 )] ) {
			$moved_char = substr( $iban, 4 ).substr( $iban, 0, 4 );
			$moved_char_array = str_split($moved_char);
			$controll_string = '';

			foreach ( $moved_char_array as $key => $value ) {
				if ( ! is_numeric($moved_char_array[$key]) ) {
					$moved_char_array[$key] = $translate_chars[$moved_char_array[$key]];
				}
				$controll_string .= $moved_char_array[$key];
			}

			if ( intval($controll_string) % 97 === 1 ) {
				return substr( $iban, 0, 4 )." ".substr( $iban, 4, 4 )." ".substr( $iban, 8, 4 )." ".substr( $iban, 12, 4 );
			} else {
				return 'INVALID CHECKSUM';
			}
		} else {
			return 'INVALID LENGTH';
		}
	}

	function format_place( $value ) {
		return trim_and_uppercase( $value );
	}

	function format_zipcode( $value ) {
		// Verwijder alle tekens die geen cijfer zijn
		return preg_replace( '/\D/', '', trim($value) );
	}

	function format_city( $value ) {
		return trim_and_uppercase( $value );
	}

	function format_mail( $value ) {
		return mb_strtolower( trim($value) );
	}

	function format_headquarter( $value ) {
		return trim_and_uppercase( $value );
	}
	
	// Sta een optionele parameter toe om puntjes te zetten in plaats van spaties (maar: wordt omgezet in streepjes door wc_format_phone() dus niet gebruiken in verkoop!)
	function format_telephone( $value, $delim = ' ' ) {
		if ( $delim === '.' ) {
			$slash = '/';
		} else {
			$slash = $delim;
		}
		// Wis alle spaties, leestekens en landcodes
		$temp_tel = preg_replace( '/[\s\-\.\/]/', '', $value );
		$temp_tel = str_replace( '+32', '0', $temp_tel );
		$temp_tel = preg_replace( '/(^|\s)0032/', '0', $temp_tel );
		
		// Formatteer vaste telefoonnummers
		if ( mb_strlen($temp_tel) === 9 ) {
			if ( intval($temp_tel[1]) === 2 or intval($temp_tel[1]) === 3 or intval($temp_tel[1]) === 4 or intval($temp_tel[1]) === 9 ) {
				$value = substr( $temp_tel, 0, 2 ) . $slash . substr( $temp_tel, 2, 3 ) . $delim . substr( $temp_tel, 5, 2 ) . $delim . substr( $temp_tel, 7, 2 );
			} else {
				$value = substr( $temp_tel, 0, 3 ) . $slash . substr( $temp_tel, 3, 2 ) . $delim . substr( $temp_tel, 5, 2 ) . $delim . substr( $temp_tel, 7, 2 );
			}
		}

		// Formatteer mobiele telefoonnummers
		if ( mb_strlen($temp_tel) === 10 ) {
			$value = substr($temp_tel, 0, 4) . $slash . substr($temp_tel, 4, 2) . $delim . substr($temp_tel, 6, 2) . $delim . substr($temp_tel, 8, 2);
		}
		
		return $value;
	}

	function format_hour( $value ) {
		if ( strlen($value) === 5 ) {
			// Wordpress: geen wijzigingen meer nodig!
			return $value;
		} elseif ( strlen($value) === 4 ) {
			// Drupal: voeg dubbele punt toe in het midden
			return substr( $value, 0, 2 ) . ':' . substr( $value, 2, 2 );
		} else {
			// Drupal: voeg nul toe vooraan bij ochtenduren
			return '0'.substr( $value, 0, 1 ) . ':' . substr( $value, 1, 2 );
		}
	}

	function format_date( $value ) {
		$new_value = preg_replace( '/[\s\-\.\/]/', '', $value );
		if ( strlen($new_value) === 8 ) {
			return substr( $new_value, 0, 2 ) . '/' . substr( $new_value, 2, 2 ) . '/' . substr( $new_value, 4, 4 );
		} elseif ( strlen($new_value) === 0 ) {
			// Ontbrekende datum
			return '';
		} else {
			// Ongeldige datum (dit laat ons toe om het onderscheid te maken!)
			return '31/12/2100';
		}
	}

	// Voeg de bestel-Excel toe aan de adminmail 'nieuwe bestelling'
	add_filter( 'woocommerce_email_attachments', 'attach_picklist_to_email', 10, 3 );

	function attach_picklist_to_email( $attachments, $status , $order ) {
		// Excel altijd bijwerken wanneer de mail opnieuw verstuurd wordt, ook bij refunds
		$create_statuses = array( 'new_order', 'customer_refunded_order' );
		
		if ( isset($status) and in_array( $status, $create_statuses ) ) {

			// Sla de besteldatum op
			$order_number = $order->get_order_number();
			// LEVERT UTC-TIMESTAMP OP, DUS VERGELIJKEN MET GLOBALE TIME() 
			$order_timestamp = $order->get_date_created()->getTimestamp();
			
			// Laad PHPExcel en het bestelsjabloon in, en selecteer het eerste werkblad
			require_once WP_CONTENT_DIR.'/plugins/phpexcel/PHPExcel.php';
			$objPHPExcel = PHPExcel_IOFactory::load( get_stylesheet_directory().'/picklist.xlsx' );
			$objPHPExcel->setActiveSheetIndex(0);

			// Sla de levermethode op
			$shipping_methods = $order->get_shipping_methods();
			$shipping_method = reset($shipping_methods);
			
			// Bestelgegevens invullen
			$objPHPExcel->getActiveSheet()->setTitle( $order_number )->setCellValue( 'F2', $order_number )->setCellValue( 'F3', PHPExcel_Shared_Date::PHPToExcel( $order_timestamp ) );
			$objPHPExcel->getActiveSheet()->getStyle( 'F3' )->getNumberFormat()->setFormatCode( PHPExcel_Style_NumberFormat::FORMAT_DATE_DMYSLASH );

			// Factuuradres invullen
			$objPHPExcel->getActiveSheet()->setCellValue( 'A2', $order->get_billing_phone() )->setCellValue( 'B1', $order->get_billing_first_name().' '.$order->get_billing_last_name() )->setCellValue( 'B2', $order->get_billing_address_1() )->setCellValue( 'B3', $order->get_billing_postcode().' '.$order->get_billing_city() );

			// Logistieke gegevens invullen
			$logistics = get_logistic_params( $order );
			$objPHPExcel->getActiveSheet()->setCellValue( 'A5', number_format( $logistics['volume'], 1, ',', '.' ).' liter / '.number_format( $logistics['weight'], 1, ',', '.' ).' kg' )->setCellValue( 'A6', 'max. '.$logistics['maximum'].' cm' );

			$i = 8;
			// Vul de artikeldata item per item in vanaf rij 8
			foreach ( $order->get_items() as $order_item_id => $item ) {
				$product = $order->get_product_from_item($item);
				switch ( $product->get_tax_class() ) {
					case 'voeding':
						$tax = '0.06';
						break;
					case 'vrijgesteld':
						$tax = '0.00';
						break;
					default:
						$tax = '0.21';
						break;
				}
				$product_price = $product->get_price();
				$line_total = $item['line_subtotal'];

				if ( $order->get_meta('is_b2b_sale') === 'yes' ) {
					// Stukprijs exclusief BTW bij B2B-bestellingen
					$product_price /= 1+$tax;
				} else {
					// BTW erbij tellen bij particulieren
					$line_total += $item['line_subtotal_tax'];
				}
				$objPHPExcel->getActiveSheet()->setCellValue( 'A'.$i, $product->get_attribute('shopplus') )->setCellValue( 'B'.$i, $product->get_title() )->setCellValue( 'C'.$i, $item['qty'] )->setCellValue( 'D'.$i, $product_price )->setCellValue( 'E'.$i, $tax )->setCellValue( 'F'.$i, $line_total )->setCellValue( 'H'.$i, $product->get_attribute('ean') );
				$i++;
			}

			$pickup_text = 'Afhaling in winkel';
			// Deze $order->get_meta() is hier reeds beschikbaar!
			if ( $order->get_meta('is_b2b_sale') === 'yes' ) {
				// Switch suffix naar 'excl. BTW'
				$label = $objPHPExcel->getActiveSheet()->getCell('D5')->getValue();
				$objPHPExcel->getActiveSheet()->setCellValue( 'D5', str_replace( 'incl', 'excl', $label ) );
			} else {
				// Haal geschatte leverdatum op VIA GET_POST_META() WANT $ORDER->GET_META() OP DIT MOMENT NOG NIET BEPAALD
				$delivery_timestamp = get_post_meta( $order->get_id(), 'estimated_delivery', true );
				$pickup_text .= ' vanaf '.date_i18n( 'j/n/y \o\m H:i', $delivery_timestamp );
			} 

			switch ( $shipping_method['method_id'] ) {
				case stristr( $shipping_method['method_id'], 'flat_rate' ):
					
					// Leveradres invullen (is in principe zeker beschikbaar!)
					$objPHPExcel->getActiveSheet()->setCellValue( 'B4', $order->get_shipping_first_name().' '.$order->get_shipping_last_name() )->setCellValue( 'B5', $order->get_shipping_address_1() )->setCellValue( 'B6', $order->get_shipping_postcode().' '.$order->get_shipping_city() )->setCellValue( 'D1', mb_strtoupper( str_replace( 'Oxfam-Wereldwinkel ', '', get_company_name() ) ) );

					// Verzendkosten vermelden
					foreach ( $order->get_items('shipping') as $order_item_id => $shipping ) {
						$total_tax = floatval( $shipping->get_total_tax() );
						$total_excl_tax = floatval( $shipping->get_total() );
						// Enkel printen indien nodig
						if ( $total_tax > 0.01 ) {
							// TE VERALGEMENEN MAAR WERKT OOK BIJ VERZENDKOST VAN 4,95 EURO
							if ( $total_tax < 1.00 ) {
								$tax = 0.06;
							} else {
								$tax = 0.21;
							}
							$objPHPExcel->getActiveSheet()->setCellValue( 'A'.$i, 'WEB'.intval(100*$tax) )->setCellValue( 'B'.$i, 'Thuislevering' )->setCellValue( 'C'.$i, 1 )->setCellValue( 'D'.$i, $total_excl_tax+$total_tax )->setCellValue( 'E'.$i, $tax )->setCellValue( 'F'.$i, $total_excl_tax+$total_tax );
						}
					}
					break;

				case stristr( $shipping_method['method_id'], 'free_shipping' ):
				// KAN IN DE TOEKOMST OOK BETALEND ZIJN
				case stristr( $shipping_method['method_id'], 'b2b_home_delivery' ):

					// Leveradres invullen (is in principe zeker beschikbaar!)
					$objPHPExcel->getActiveSheet()->setCellValue( 'B4', $order->get_shipping_first_name().' '.$order->get_shipping_last_name() )->setCellValue( 'B5', $order->get_shipping_address_1() )->setCellValue( 'B6', $order->get_shipping_postcode().' '.$order->get_shipping_city() )->setCellValue( 'D1', mb_strtoupper( str_replace( 'Oxfam-Wereldwinkel ', '', get_company_name() ) ) );
					break;

				case stristr( $shipping_method['method_id'], 'service_point_shipping_method' ):

					// Verwijzen naar postpunt
					$service_point = $order->get_meta('sendcloudshipping_service_point_meta');
					$service_point_info = explode ( '|', $service_point['extra'] );
					$objPHPExcel->getActiveSheet()->setCellValue( 'B4', 'Postpunt '.$service_point_info[0] )->setCellValue( 'B5', $service_point_info[1].', '.$service_point_info[2] )->setCellValue( 'B6', 'Etiket verplicht aan te maken via SendCloud!' )->setCellValue( 'D1', mb_strtoupper( str_replace( 'Oxfam-Wereldwinkel ', '', get_company_name() ) ) );

					// Verzendkosten vermelden
					foreach ( $order->get_items('shipping') as $order_item_id => $shipping ) {
						$total_tax = floatval( $shipping->get_total_tax() );
						$total_excl_tax = floatval( $shipping->get_total() );
						// Enkel printen indien nodig
						if ( $total_tax > 0.01 ) {
							// TE VERALGEMENEN MAAR WERKT OOK BIJ VERZENDKOST VAN 4,95 EURO
							if ( $total_tax < 1.00 ) {
								$tax = 0.06;
							} else {
								$tax = 0.21;
							}
							$objPHPExcel->getActiveSheet()->setCellValue( 'A'.$i, 'WEB'.intval(100*$tax) )->setCellValue( 'B'.$i, 'Thuislevering' )->setCellValue( 'C'.$i, 1 )->setCellValue( 'D'.$i, $total_excl_tax+$total_tax )->setCellValue( 'E'.$i, $tax )->setCellValue( 'F'.$i, $total_excl_tax+$total_tax );
						}
					}
					break;

				default:
					$meta_data = $shipping_method->get_meta_data();
					$pickup_data = reset($meta_data);
					$objPHPExcel->getActiveSheet()->setCellValue( 'B4', $pickup_text )->setCellValue( 'D1', mb_strtoupper( trim( str_replace( 'Oxfam-Wereldwinkel', '', $pickup_data->value['shipping_company'] ) ) ) );
			}

			// Vermeld de totale korting (inclusief/exclusief BTW)
			// Kortingsbedrag per coupon apart vermelden is lastig: https://stackoverflow.com/questions/44977174/get-coupon-discount-type-and-amount-in-woocommerce-orders
			$used_coupons = $order->get_used_coupons();
			if ( count( $used_coupons ) >= 1 ) {
				$discount = $order->get_discount_total();
				if ( $order->get_meta('is_b2b_sale') !== 'yes' ) {
					$discount += $order->get_discount_tax();
				}
				$i++;
				$objPHPExcel->getActiveSheet()->setCellValue( 'A'.$i, 'Kortingen' )->setCellValue( 'B'.$i, mb_strtoupper( implode( ', ', $used_coupons ) ) )->setCellValue( 'F'.$i, '-'.$discount );
				$i++;
			}

			// Druk eventuele opmerkingen af
			if ( strlen( $order->get_customer_note() ) > 5 ) {
				$i++;
				$customer_text = $order->get_customer_note();
				$objPHPExcel->getActiveSheet()->setCellValue( 'A'.$i, 'Opmerking' )->setCellValue( 'B'.$i, $customer_text );
				$objPHPExcel->getActiveSheet()->getStyle('A'.$i)->getAlignment()->setVertical(PHPExcel_Style_Alignment::VERTICAL_TOP);
				$objPHPExcel->getActiveSheet()->getStyle('B'.$i)->getAlignment()->setVertical(PHPExcel_Style_Alignment::VERTICAL_CENTER);
				// Merge resterende kolommen en wrap tekst in opmerkingenvak 
				$objPHPExcel->getActiveSheet()->mergeCells('B'.$i.':G'.$i);
				$objPHPExcel->getActiveSheet()->getStyle('B'.$i)->getAlignment()->setWrapText(true);

				// setRowHeight(-1) voor autoheight werkt niet, dus probeer goeie hoogte te berekenen bij lange teksten
				// if ( strlen( $customer_text ) > 125 ) {
				// 	$row_padding = 4;
				// 	$row_height = $objPHPExcel->getActiveSheet()->getRowDimension($i)->getRowHeight() - $row_padding;
				// 	$objPHPExcel->getActiveSheet()->getRowDimension($i)->setRowHeight( ceil( strlen( $customer_text ) / 120 ) * $row_height + $row_padding );
				// }

				// Bovenstaande houdt geen rekening met line breaks, dus gebruik voorlopig vaste (ruime) hoogte
				$objPHPExcel->getActiveSheet()->getRowDimension($i)->setRowHeight(80);
				$i++;
			}

			// Bereken en selecteer het totaalbedrag
			$objPHPExcel->getActiveSheet()->setSelectedCell('F5')->setCellValue( 'F5', $objPHPExcel->getActiveSheet()->getCell('F5')->getCalculatedValue() );

			// Check of we een nieuwe file maken of een bestaande overschrijven
			$filename = $order->get_meta('_excel_file_name');
			if ( $filename === false or strlen($filename) < 10 ) {
				$folder = generate_pseudo_random_string();
				mkdir( WP_CONTENT_DIR.'/uploads/xlsx/'.$folder, 0755 );
				$filename = $folder.'/'.$order_number.'.xlsx';
				
				// Bewaar de locatie van de file (random file!) als metadata
				$order->add_meta_data( '_excel_file_name', $filename, true );
				$order->save_meta_data();
			}

			$objWriter = PHPExcel_IOFactory::createWriter( $objPHPExcel, 'Excel2007' );
			$objWriter->save( WP_CONTENT_DIR.'/uploads/xlsx/'.$filename );
			
			// Bijlage enkel meesturen in 'new_order'-mail aan admin
			if ( $status === 'new_order' ) {
				$attachments[] = WP_CONTENT_DIR.'/uploads/xlsx/'.$filename;
			}
		}

		return $attachments;
	}

	// Verduidelijk de profiellabels in de back-end	
	add_filter( 'woocommerce_customer_meta_fields', 'modify_user_admin_fields', 10, 1 );

	function modify_user_admin_fields( $profile_fields ) {
		if ( ! is_b2b_customer() ) {
			$billing_title = 'Klantgegevens';
		} else {
			$billing_title = 'Factuurgegevens';
		}
		$profile_fields['billing']['title'] = $billing_title;
		$profile_fields['billing']['fields']['billing_company']['label'] = 'Bedrijf of vereniging';
		// Klasse slaat op tekstveld, niet op de hele rij
		$profile_fields['billing']['fields']['billing_company']['class'] = 'show-if-b2b-checked important-b2b-field';
		$profile_fields['billing']['fields']['billing_vat']['label'] = 'BTW-nummer';
		$profile_fields['billing']['fields']['billing_vat']['description'] = 'Geldig Belgisch ondernemingsnummer van 9 of 10 cijfers (optioneel).';
		$profile_fields['billing']['fields']['billing_vat']['class'] = 'show-if-b2b-checked important-b2b-field';
		$profile_fields['billing']['fields']['billing_first_name']['label'] = 'Voornaam';
		$profile_fields['billing']['fields']['billing_last_name']['label'] = 'Familienaam';
		$profile_fields['billing']['fields']['billing_email']['label'] = 'Bestelcommunicatie naar';
		$profile_fields['billing']['fields']['billing_email']['description'] = 'E-mailadres waarop de klant zijn/haar bevestigingsmails ontvangt.';
		$profile_fields['billing']['fields']['billing_phone']['label'] = 'Telefoonnummer';
		$profile_fields['billing']['fields']['billing_address_1']['label'] = 'Straat en huisnummer';
		$profile_fields['billing']['fields']['billing_postcode']['label'] = 'Postcode';
		$profile_fields['billing']['fields']['billing_postcode']['maxlength'] = 4;
		$profile_fields['billing']['fields']['billing_city']['label'] = 'Gemeente';
		unset( $profile_fields['billing']['fields']['billing_address_2'] );
		unset( $profile_fields['billing']['fields']['billing_state'] );
		
		$profile_fields['shipping']['title'] = 'Verzendgegevens';
		$profile_fields['shipping']['fields']['shipping_first_name']['label'] = 'Voornaam';
		$profile_fields['shipping']['fields']['shipping_last_name']['label'] = 'Familienaam';
		$profile_fields['shipping']['fields']['shipping_address_1']['label'] = 'Straat en huisnummer';
		$profile_fields['shipping']['fields']['shipping_postcode']['label'] = 'Postcode';
		$profile_fields['shipping']['fields']['shipping_city']['label'] = 'Gemeente';
		unset( $profile_fields['shipping']['fields']['shipping_address_2'] );
		unset( $profile_fields['shipping']['fields']['shipping_company'] );
		unset( $profile_fields['shipping']['fields']['shipping_state'] );

		$profile_fields['shipping']['fields'] = array_swap_assoc( 'shipping_city', 'shipping_postcode', $profile_fields['shipping']['fields'] );

		$billing_field_order = array(
			'billing_company',
			'billing_vat',
			'billing_first_name',
			'billing_last_name',
			'billing_email',
			'billing_phone',
			'billing_address_1',
			'billing_postcode',
			'billing_city',
			'billing_country',
		);

		foreach ( $billing_field_order as $field ) {
			$ordered_billing_fields[$field] = $profile_fields['billing']['fields'][$field];
		}

		$profile_fields['billing']['fields'] = $ordered_billing_fields;
		return $profile_fields;
	}

	// Verberg bepaalde profielvelden (en niet verwijderen, want dat reset sommige waardes!)
	add_action( 'admin_footer-profile.php', 'hide_own_profile_fields' );
	add_action( 'admin_footer-user-edit.php', 'hide_others_profile_fields' );
	
	function hide_own_profile_fields() {
		if ( ! current_user_can('manage_options') ) {
			?>
			<script type="text/javascript">
				jQuery("tr.user-rich-editing-wrap").css( 'display', 'none' );
				jQuery("tr.user-comment-shortcuts-wrap").css( 'display', 'none' );
				jQuery("tr.user-language-wrap").css( 'display', 'none' );
				/* Zeker niét verwijderen -> breekt opslaan van pagina! */
				jQuery("tr.user-nickname-wrap").css( 'display', 'none' );
				jQuery("tr.user-url-wrap").css( 'display', 'none' );
				jQuery("h2:contains('Over jezelf')").next('.form-table').css( 'display', 'none' );
				jQuery("h2:contains('Over jezelf')").css( 'display', 'none' );
				jQuery("h2:contains('Over de gebruiker')").next('.form-table').css( 'display', 'none' );
				jQuery("h2:contains('Over de gebruiker')").css( 'display', 'none' );
				/* Wordt enkel toegevoegd indien toegelaten dus hoeft niet verborgen te worden */
				// jQuery("tr[class$='member_of_shop-wrap']").css( 'display', 'none' );
			</script>
			<?php
		}
		
		$current_user = wp_get_current_user();
		$user_meta = get_userdata($current_user->ID);
		$user_roles = $user_meta->roles;
		if ( in_array( 'local_manager', $user_roles ) and $current_user->user_email === get_company_email() ) {
			?>
			<script type="text/javascript">
				/* Verhinder dat lokale webbeheerders het e-mailadres aanpassen van hun hoofdaccount */
				jQuery("tr.user-email-wrap").find('input[type=email]').prop('readonly', true);
				jQuery("tr.user-email-wrap").find('input[type=email]').after('<span class="description">&nbsp;De lokale beheerder dient altijd gekoppeld te blijven aan de webshopmailbox, dus dit veld kun je niet bewerken.</span>');
			</script>
			<?php
		}
	}

	function hide_others_profile_fields() {
		if ( ! current_user_can('manage_options') ) {
		?>
			<script type="text/javascript">
				jQuery("tr.user-rich-editing-wrap").css( 'display', 'none' );
				jQuery("tr.user-admin-color-wrap").css( 'display', 'none' );
				jQuery("tr.user-comment-shortcuts-wrap").css( 'display', 'none' );
				jQuery("tr.user-admin-bar-front-wrap").css( 'display', 'none' );
				jQuery("tr.user-language-wrap").css( 'display', 'none' );
				/* Zeker niét verwijderen -> breekt opslaan van pagina! */
				jQuery("tr.user-nickname-wrap").css( 'display', 'none' );
				jQuery("tr.user-url-wrap").css( 'display', 'none' );
				jQuery("h2:contains('Over de gebruiker')").next('.form-table').css( 'display', 'none' );
				jQuery("h2:contains('Over de gebruiker')").css( 'display', 'none' );
			</script>
		<?php
		}
	}



	################
	# B2B FUNCTIES #
	################

	// Nooit e-mailconfirmatie versturen bij aanmaken nieuwe account
	add_action( 'user_new_form', 'check_disable_confirm_new_user' );
	
	function check_disable_confirm_new_user() {
		?>
		<script type="text/javascript">
			jQuery(document).ready(function() {
				jQuery("#noconfirmation").prop( 'checked', true );
				jQuery("#noconfirmation").parents('tr').hide();
			} );
		</script>
		<?php
	}

	// Algemene functie die retourneert of de gebruiker een B2B-klant is van de huidige webshop
	function is_b2b_customer( $user_id = false ) {
		if ( intval($user_id) < 1 ) {
			// Val terug op de momenteel ingelogde gebruiker
			$current_user = wp_get_current_user();
			$user_id = $current_user->ID;
			
			if ( is_admin() ) {
				// Extra checks op speciale gevallen in de back-end
				if ( isset($_GET['user_id']) ) {
					// Zijn we het profiel van iemand anders aan het bekijken?
					$user_id = $_GET['user_id'];
				} elseif ( isset($_POST['user_id']) ) {
					// Zijn we het profiel van iemand anders aan het updaten?
					$user_id = $_POST['user_id'];
				}
			}
		}
		if ( get_user_meta( intval($user_id), 'blog_'.get_current_blog_id().'_is_b2b_customer', true ) === 'yes' ) {
			return true;
		} else {
			return false;
		}
	}

	// Toon de 'is_b2b_customer'-checkbox in de back-end
	add_action( 'show_user_profile', 'add_b2b_customer_fields' );
	add_action( 'edit_user_profile', 'add_b2b_customer_fields' );
	// Zorg ervoor dat het ook geformatteerd / bewaard wordt (inhaken vòòr 'save_customer_meta_fields'-actie van WooCommerce met default priority)
	add_action( 'personal_options_update', 'save_b2b_customer_fields', 5 );
	add_action( 'edit_user_profile_update', 'save_b2b_customer_fields', 5 );

	function add_b2b_customer_fields( $user ) {
		$check_key = 'blog_'.get_current_blog_id().'_is_b2b_customer';
		$is_b2b_customer = get_the_author_meta( $check_key, $user->ID );
		$select_key = 'blog_'.get_current_blog_id().'_has_b2b_coupon';
		$has_b2b_coupon = get_the_author_meta( $select_key, $user->ID );
		?>
		<h3>B2B-verkoop</h3>
		<table class="form-table">
			<tr>
				<th><label for="<?php echo $check_key; ?>">Geverifieerde bedrijfsklant</label></th>
				<td>
					<input type="checkbox" class="important-b2b-field" name="<?php echo $check_key; ?>" id="<?php echo $check_key; ?>" value="yes" <?php checked( $is_b2b_customer, 'yes' ); ?> />
					<span class="description">Indien aangevinkt moet (en kan) de klant niet op voorhand online betalen. Je maakt zelf een factuur op met de effectief geleverde goederen en volgt achteraf de betaling op. <a href="https://github.com/OxfamFairTrade/ob2c/wiki/8.-B2B-verkoop" target="_blank">Raadpleeg de handleiding.</a></span>
				</td>
			</tr>
			<tr class="show-if-b2b-checked">
				<th><label for="<?php echo $select_key; ?>">Kortingspercentage</label></th>
				<td>
					<select class="important-b2b-field" name="<?php echo $select_key; ?>" id="<?php echo $select_key; ?>">;
					<?php	
						$b2b_payment_method = array('cod');
						$args = array(
							'posts_per_page' => -1,
							'post_type' => 'shop_coupon',
							'post_status' => 'publish',
							'meta_key' => 'coupon_amount',
							'orderby' => 'meta_value_num',
							'order' => 'ASC',
							'meta_query' => array(
								array(
									'key' => '_wjecf_payment_methods',
									'value' => serialize($b2b_payment_method),
									'compare' => 'LIKE',
								)
							),
						);

						$b2b_coupons = get_posts($args);
						echo '<option value="">Geen</option>';
						foreach ( $b2b_coupons as $b2b_coupon ) {
							echo '<option value="'.$b2b_coupon->ID.'" '.selected( $b2b_coupon->ID, $has_b2b_coupon ).'>'.number_format( $b2b_coupon->coupon_amount, 1, ',', '.' ).'%</option>';
						}
					?>
					</select>
					<span class="description">Pas automatisch deze korting toe op het volledige winkelmandje (met uitzondering van leeggoed).</span>
				</td>
			</tr>
			<tr class="show-if-b2b-checked">
				<th><label for="send_invitation">Uitnodiging</label></th>
				<td>
					<?php
						$disabled = '';
						if ( strlen( get_the_author_meta( 'billing_company', $user->ID ) ) < 2 ) {
							$disabled = ' disabled';
						}
						echo '<button type="button" class="button disable-on-b2b-change" id="send_invitation" style="min-width: 600px;"'.$disabled.'>Verstuur welkomstmail naar accounteigenaar</button>';
						echo '<p class="send_invitation description">';
						if ( ! empty( get_the_author_meta( 'blog_'.get_current_blog_id().'_b2b_invitation_sent', $user->ID ) ) ) {
							printf( 'Laatste uitnodiging verstuurd: %s.', date( 'd-n-Y H:i:s', strtotime( get_the_author_meta( 'blog_'.get_current_blog_id().'_b2b_invitation_sent', $user->ID ) ) ) );
						}
						echo '</p>';
					?>
					
					<script type="text/javascript">
						jQuery(document).ready(function() {
							if ( ! jQuery('#<?php echo $check_key; ?>').is(':checked') ) {
								jQuery('.show-if-b2b-checked').closest('tr').hide();
							}

							jQuery('#<?php echo $check_key; ?>').on( 'change', function() {
								jQuery('.show-if-b2b-checked').closest('tr').toggle();
							});

							jQuery('.important-b2b-field').on( 'change', function() {
								disableInvitation();
							});

							function disableInvitation() {
								jQuery('.disable-on-b2b-change').text("Klik op 'Gebruiker bijwerken' vooraleer je de uitnodiging verstuurt").prop( 'disabled', true );
							}

							jQuery('button#send_invitation').on( 'click', function() {
								if ( confirm("Weet je zeker dat je dit wil doen?") ) {
									jQuery(this).prop( 'disabled', true ).text( 'Aan het verwerken ...' );
									sendB2bWelcome( <?php echo $user->ID; ?> );
								}
							});

							function sendB2bWelcome( customer_id ) {
								var input = {
									'action': 'oxfam_invitation_action',
									'customer_id': customer_id,
								};
								
								jQuery.ajax({
									type: 'POST',
									url: ajaxurl,
									data: input,
									dataType: 'html',
									success: function( msg ) {
										jQuery( 'button#send_invitation' ).text( msg );
										var today = new Date();
										jQuery( 'p.send_invitation.description' ).html( 'Laatste actie ondernomen: '+today.toLocaleString('nl-NL')+'.' );
									},
									error: function( jqXHR, statusText, errorThrown ) {
										jQuery( 'button#send_invitation' ).text( 'Asynchroon laden van PHP-file mislukt!' );
										jQuery( 'p.send_invitation.description' ).html( 'Herlaad de pagina en probeer het eens opnieuw.' );
									},
								});
							}
						});
					</script>
				</td>
			</tr>
		</table>
		<?php
	}

	function save_b2b_customer_fields( $user_id ) {
		if ( ! current_user_can( 'edit_users', $user_id ) ) {
			return false;
		}

		$names = array( 'billing_company', 'billing_first_name', 'billing_last_name', 'billing_address_1', 'billing_city', 'shipping_first_name', 'shipping_last_name', 'shipping_address_1', 'shipping_city' );
		foreach ( $names as $name ) {
			if ( isset($_POST[$name]) ) {
				$_POST[$name] = trim_and_uppercase($_POST[$name]);
			}
		}
		$logger = wc_get_logger();
		$context = array( 'source' => 'WP User' );
		$logger->debug( wc_print_r( $_POST, true ), $context );

		if ( isset($_POST['billing_email']) ) {
			// Retourneert false indien ongeldig e-mailformaat
			$_POST['billing_email'] = is_email( format_mail($_POST['billing_email']) );
		}
		if ( isset($_POST['billing_phone']) ) {
			$_POST['billing_phone'] = format_telephone($_POST['billing_phone']);
		}
		if ( isset($_POST['billing_vat']) ) {
			$_POST['billing_vat'] = format_tax($_POST['billing_vat']);
		}
		if ( isset($_POST['billing_postcode']) ) {
			$_POST['billing_postcode'] = format_zipcode($_POST['billing_postcode']);
		}
		if ( isset($_POST['shipping_postcode']) ) {
			$_POST['shipping_postcode'] = format_zipcode($_POST['shipping_postcode']);
		}

		// Usermeta is netwerkbreed, dus ID van blog toevoegen aan de key!
		$check_key = 'blog_'.get_current_blog_id().'_is_b2b_customer';
		// Check of het veld wel bestaat voor deze gebruiker
		if ( isset($_POST[$check_key]) ) {
			update_user_meta( $user_id, $check_key, $_POST[$check_key] );
		} else {
			update_user_meta( $user_id, $check_key, 'no' );
			// 'billing_company' en 'billing_vat' laten we gewoon staan, niet expliciet ledigen!
		}
		
		// Voeg de ID van de klant toe aan de overeenstemmende kortingsbon, op voorwaarde dat B2B aangevinkt is
		$select_key = 'blog_'.get_current_blog_id().'_has_b2b_coupon';
		if ( get_user_meta( $user_id, $check_key, true ) !== 'yes' ) {
			// Ledig het eventueel geselecteerde kortingstarief
			$_POST[$select_key] = '';
		}

		if ( isset($_POST[$select_key]) ) {
			$new_coupon_id = intval( $_POST[$select_key] );
			$previous_coupon_id = intval( get_user_meta( $user_id, $select_key, true ) );

			if ( $new_coupon_id !== $previous_coupon_id ) {
				// Haal de rechthebbenden op van de vroegere coupon
				$previous_users_string = trim( get_post_meta( $previous_coupon_id, '_wjecf_customer_ids', true ) );
				if ( strlen( $previous_users_string ) > 0 ) {
					$previous_users = explode( ',', $previous_users_string );	
				} else {
					// Want anders retourneert explode() een leeg element
					$previous_users = array();
				}

				// Verwijder de user-ID van de vorige coupon, tenzij het user-ID 1 is (= admin)
				if ( $user_id !== 1 and ( $match_key = array_search( $user_id, $previous_users ) ) !== false ) {
					unset($previous_users[$match_key]);
				}
				update_post_meta( $previous_coupon_id, '_wjecf_customer_ids', implode( ',', $previous_users ) );

				// Haal de huidige rechthebbenden op van de nu geselecteerde coupon
				$current_users_string = trim( get_post_meta( $new_coupon_id, '_wjecf_customer_ids', true ) );
				if ( strlen( $current_users_string ) > 0 ) {
					$current_users = explode( ',', $current_users_string );	
				} else {
					// Want anders retourneert explode() een leeg element
					$current_users = array();
				}

				// Koppel de coupon altijd aan user-ID 1 om te vermijden dat de restricties wegvallen indien er geen enkele échte klant aan gekoppeld is!
				if ( ! in_array( 1, $current_users ) ) {
					$current_users[] = 1;
				}
				// Voeg de user-ID toe aan de geselecteerde coupon
				if ( ! in_array( $user_id, $current_users ) ) {
					$current_users[] = $user_id;
				}
				update_post_meta( $new_coupon_id, '_wjecf_customer_ids', implode( ',', $current_users ) );
			}

			// Nu pas de coupon-ID op de gebruiker bijwerken
			update_user_meta( $user_id, $select_key, $_POST[$select_key] );
		}
	}

	// Zorg ervoor dat wijzigingen aan klanten in kortingsbonnen ook gesynct worden met die profielen
	// add_action( 'woocommerce_update_coupon', 'sync_reductions_with_users', 10, 1 );
	// GEVAARLIJK, LOGS LOPEN VOL
	// add_action( 'threewp_broadcast_broadcasting_after_switch_to_blog', 'check_broadcast_data', 5 );

	function sync_reductions_with_users( $post_id ) {
		write_log( get_post_meta( $post_id, 'exclude_product_ids', true ) );
		write_log( "COUPON ".$post_id." WORDT BIJGEWERKT IN BLOG ".get_current_blog_id() );
	}

	function check_broadcast_data( $action ) {
		$logger = wc_get_logger();
		$context = array( 'source' => 'Broadcast' );
		$logger->debug( wc_print_r( $action, true ), $context );
		write_log( "GESWITCHED NAAR BLOG ".get_current_blog_id() );
	}

	// Functie geeft blijkbaar zeer vroeg al een zinnig antwoord
	add_action( 'init', 'activate_b2b_functions' );

	function activate_b2b_functions() {
		if ( ! is_admin() and is_b2b_customer() ) {
			// Zorg ervoor dat de spinners overal per ompak omhoog/omlaag gaan
			add_filter( 'woocommerce_quantity_input_args', 'suggest_order_unit_multiple', 10, 2 );
	
			// Geen BTW tonen bij producten en in het winkelmandje
			add_filter( 'pre_option_woocommerce_tax_display_shop', 'override_tax_display_setting' );
			add_filter( 'pre_option_woocommerce_tax_display_cart', 'override_tax_display_setting' );

			// Vervang alle prijssuffixen
			add_filter( 'woocommerce_get_price_suffix', 'b2b_price_suffix', 10, 2 );

			// Voeg 'excl. BTW' toe bij stukprijzen en subtotalen in winkelmandje en orderdetail (= ook mails!)
			add_filter( 'woocommerce_cart_subtotal', 'add_ex_tax_label_price', 10, 3 );
			add_filter( 'woocommerce_order_formatted_line_subtotal', 'add_ex_tax_label_price', 10, 3 );

			// Verwijder '(excl. BTW)' bij subtotalen
			add_filter( 'woocommerce_countries_ex_tax_or_vat', 'remove_ex_tax_label_subtotals' );
			
			// Limiteer niet-B2B-kortingsbonnen tot particulieren
			add_filter( 'wjecf_coupon_can_be_applied', 'restrain_coupons_to_b2c', 10, 2 );
		}

		function suggest_order_unit_multiple( $args, $product ) {
			$multiple = intval( $product->get_attribute('ompak') );
			if ( $multiple < 2 ) {
				$multiple = 1;
			} else {
				// Eventuele bestellimiet instellen
				// $args['max_value'] = 4*$multiple;
			}

			if ( is_cart() or ( array_key_exists( 'nm_mini_cart_quantity', $args ) and $args['nm_mini_cart_quantity'] === true ) ) {
				// Step enkel overrulen indien er op dit moment een veelvoud van de ompakhoeveelheid in het winkelmandje zit!
				// In de mini-cart wordt dit niet tijdens page-load bepaald omdat AJAX niet de hele blok refresht
				if ( $args['input_value'] % $multiple === 0 ) {
					$args['step'] = $multiple;
				}
			} else {
				// Input value enkel overrulen buiten het winkelmandje!
				$args['input_value'] = $multiple;
				$args['step'] = $multiple;
			}
			return $args;
		}

		function override_tax_display_setting() {
			return 'excl';
		}

		function b2b_price_suffix( $suffix, $product ) {
			return str_replace( 'incl', 'excl', $suffix );
		}

		function remove_ex_tax_label_subtotals() {
			return '';
		}

		function restrain_coupons_to_b2c( $can_be_applied, $coupon ) {
			if ( strpos( $coupon->get_code(), 'b2b' ) === false ) {
				return false;
			} else {
				return $can_be_applied;
			}
		}
	}

	// Voeg 'incl. BTW' of 'excl. BTW' toe bij stukprijzen in winkelmandje
	add_filter( 'woocommerce_cart_item_price', 'add_ex_tax_label_price', 10, 3 );

	function add_ex_tax_label_price( $price, $cart_item, $cart_item_key ) {
		if ( is_b2b_customer() ) {
			$type = 'excl.';
		} else {
			$type = 'incl.';
		}
		return $price.' <small class="woocommerce-price-suffix">'.$type.' BTW</small>';
	}

	// Schakel BTW-berekeningen op productniveau uit voor geverifieerde bedrijfsklanten MAG ENKEL VOOR BUITENLANDSE KLANTEN
	// add_filter( 'woocommerce_product_get_tax_class', 'zero_rate_for_companies', 1, 2 );

	function zero_rate_for_companies( $tax_class, $product ) {
		$current_user = wp_get_current_user();
		if ( ! empty( get_user_meta( $current_user->ID, 'is_vat_exempt', true ) ) ) {
			$tax_class = 'vrijgesteld';
		}
		return $tax_class;
	}

	// Geef hint om B2B-klant te worden TIJDELIJK UITSCHAKELEN
	// add_action( 'woocommerce_just_before_checkout_form', 'show_b2b_account_hint', 10 );

	function show_b2b_account_hint() {
		// Niet tonen bij Brugge
		if ( ! is_b2b_customer() and get_current_blog_id() !== 25 ) {
			wc_print_notice( 'Wil je als bedrijf of vereniging aankopen op factuur doen? Vraag dan een B2B-account aan via <a href="mailto:'.get_company_email().'?subject=Aanvraag B2B-webshopaccount">'.get_company_email().'</a>.', 'notice' );
		}
	}
	
	// Toon enkel overschrijving als betaalmethode indien B2B-klant
	add_filter( 'woocommerce_available_payment_gateways', 'b2b_restrict_to_bank_transfer' );

	function b2b_restrict_to_bank_transfer( $gateways ) {
		if ( is_b2b_customer() ) {
			unset( $gateways['mollie_wc_gateway_mistercash'] );
			unset( $gateways['mollie_wc_gateway_creditcard'] );
			unset( $gateways['mollie_wc_gateway_kbc'] );
			unset( $gateways['mollie_wc_gateway_belfius'] );
			unset( $gateways['mollie_wc_gateway_ideal'] );
		} else {
			unset( $gateways['cod'] );
		}
		return $gateways;
	}

	// Toon aantal stuks dat toegevoegd zal worden aan het winkelmandje
	add_filter( 'woocommerce_product_add_to_cart_text', 'add_multiple_to_add_to_cart_text', 10, 2 );
	add_filter( 'woocommerce_product_single_add_to_cart_text', 'change_single_add_to_cart_text', 10, 2 );
	
	function add_multiple_to_add_to_cart_text( $text, $product ) {
		if ( is_b2b_customer() ) {
			$multiple = intval( $product->get_attribute('ompak') );
			if ( $multiple < 2 ) {
				$text = 'Voeg 1 stuk toe aan mandje';
			} else {
				$text = 'Voeg '.$multiple.' stuks toe aan mandje';
			}
		} else {
			$text = 'Voeg toe aan winkelmandje';
		}
		return $text;
	}

	function change_single_add_to_cart_text( $text, $product ) {
		$text = 'Voeg toe aan mandje';
		return $text;
	}

	// Verberg onnuttige adresvelden tijdens het bewerken op het orderdetailscherm in de back-end
	add_filter( 'woocommerce_admin_billing_fields', 'custom_admin_billing_fields' );
	add_filter( 'woocommerce_admin_shipping_fields', 'custom_admin_shipping_fields' );
	add_action( 'woocommerce_admin_order_data_after_billing_address', 'show_custom_billing_fields', 10, 1 );

	function custom_admin_billing_fields( $address_fields ) {
		unset($address_fields['first_name']);
		unset($address_fields['last_name']);
		unset($address_fields['address_2']);
		unset($address_fields['state']);
		return $address_fields;
	}

	function custom_admin_shipping_fields( $address_fields ) {
		unset($address_fields['first_name']);
		unset($address_fields['last_name']);
		unset($address_fields['company']);
		unset($address_fields['address_2']);
		unset($address_fields['state']);
		return $address_fields;
	}

	function show_custom_billing_fields( $order ) {
		if ( $order->get_meta('_billing_vat') !== '' ) {
			echo '<p><strong>'.__( 'BTW-nummer', 'oxfam-webshop' ).':</strong><br/>'.$order->get_meta('_billing_vat').'</p>';
		}
	}

	// Geef de adresregels binnen 'Mijn account' een logische volgorde
	add_action( 'woocommerce_my_account_my_address_formatted_address', 'show_custom_address_fields', 10, 3 );

	function show_custom_address_fields( $address, $customer_id, $type ) {
		if ( $type === 'billing' ) {
			if ( is_b2b_customer() and get_user_meta( $customer_id, 'billing_vat', true ) ) {
				$address['first_name'] = '';
				$address['last_name'] = '';
				$address['address_2'] = $address['address_1'];
				$address['address_1'] = get_user_meta( $customer_id, 'billing_vat', true );
			}
		}
		return $address;
	}

	// Toon extra klantendata onder de contactgegevens (net boven de adressen)
	add_action( 'woocommerce_order_details_after_customer_details', 'shuffle_account_address', 100, 1 );

	function shuffle_account_address( $order ) {
		// Let op de underscore, wordt verwerkt als een intern veld!
		if ( $order->get_meta('_billing_vat') !== '' ) {
			?>
			<li>
				<h3>BTW-nummer</h3>
				<div><?php echo esc_html( $order->get_meta('_billing_vat') ); ?></div>
			</li>
			<?php
		}
	}

	// Zet webshopbeheerder in BCC bij versturen uitnodigingsmails
	// add_filter( 'woocommerce_email_headers', 'put_shop_manager_in_bcc', 10, 3 );

	function put_shop_manager_in_bcc( $headers, $type, $object ) {
		$logger = wc_get_logger();
		$context = array( 'source' => 'WooCommerce' );
		$logger->debug( 'Mail van type '.$type.' getriggerd', $context );
		
		$extra_recipients = array();
		$extra_recipients[] = 'Helpdesk E-Commerce <'.get_site_option('admin_email').'>';
		
		// We hernoemden de 'customer_new_account'-template maar het type blijft ongewijzigd!
		if ( $type === 'customer_reset_password' ) {
			// Bij dit type mogen we ervan uit gaan dat $oject een WP_User bevat met de property ID
			if ( is_b2b_customer( $object->ID ) ) {
				// Hoe voorkomen we dat kopie verstuurd wordt bij échte wachtwoordreset van B2B-gebruiker?
				$extra_recipients[] = get_company_name().' <'.get_company_email().'>';
			}
		}

		$headers .= 'BCC: '.implode( ',', $extra_recipients ).'\r\n';
		return $headers;
	}

	// Check tijdelijk de verstuurde bevestigingsmails door mezelf in BCC te zetten
	// add_filter( 'woocommerce_email_headers', 'put_administrator_in_bcc', 10, 2 );

	function put_administrator_in_bcc( $headers, $object ) {
		if ( $object === 'customer_processing_order' or $object === 'customer_completed_order' ) {
			$headers .= 'BCC: "Developer" <'.get_site_option('admin_email').'>\r\n';
		}
		return $headers;
	}



	###################
	# HELPER FUNCTIES #
	###################

	// Print de geschatte leverdatums onder de beschikbare verzendmethodes 
	add_filter( 'woocommerce_cart_shipping_method_full_label', 'print_estimated_delivery', 10, 2 );
	
	function print_estimated_delivery( $label, $method ) {
		$descr = '<small style="color: #61a534">';
		$timestamp = estimate_delivery_date( $method->id );
		
		switch ( $method->id ) {
			// Nummers achter method_id slaan op de (unieke) instance_id binnen DEZE subsite!
			// Alle instances van de 'Gratis afhaling in winkel'-methode
			case stristr( $method->id, 'local_pickup' ):
				// Check of de winkel wel openingsuren heeft!
				if ( $timestamp ) {
					$descr .= sprintf( __( 'Dag (%1$s) en uur (%2$s) vanaf wanneer de bestelling klaarstaat voor afhaling', 'oxfam-webshop' ), date_i18n( 'l d/m/Y', $timestamp ), date_i18n( 'G\ui', $timestamp ) );
				}
				$label .= ':'.wc_price(0);
				break;
			// Alle instances van postpuntlevering
			case stristr( $method->id, 'service_point_shipping_method' ):
				$descr .= sprintf( __( 'Uiterste dag (%s) waarop het pakje beschikbaar zal zijn in postpunt / automaat', 'oxfam-webshop' ),  date_i18n( 'l d/m/Y', $timestamp ) );
				if ( floatval( $method->cost ) == 0 ) {
					$label = str_replace( 'Afhaling', 'Gratis afhaling', $label );
					$label .= ':'.wc_price(0);
				}
				break;
			// Alle instances van thuislevering
			case stristr( $method->id, 'flat_rate' ):
				$descr .= sprintf( __( 'Uiterste dag (%s) waarop de levering zal plaatsvinden', 'oxfam-webshop' ),  date_i18n( 'l d/m/Y', $timestamp ) );
				break;
			// Alle instances van gratis thuislevering
			case stristr( $method->id, 'free_shipping' ):
				$descr .= sprintf( __( 'Uiterste dag (%s) waarop de levering zal plaatsvinden', 'oxfam-webshop' ),  date_i18n( 'l d/m/Y', $timestamp ) );
				$label .= ':'.wc_price(0);
				break;
			// Alle instances van B2B-levering
			case stristr( $method->id, 'b2b_home_delivery' ):
				if ( floatval( $method->cost ) == 0 ) {
					$label .= ':'.wc_price(0);
				}
				break;
			default:
				$descr .= __( 'Boodschap indien schatting leverdatum niet beschikbaar', 'oxfam-webshop' );
				break;
		}
		$descr .= '</small>';
		// Geen schattingen tonen aan B2B-klanten
		if ( ! is_b2b_customer() ) {
			return $label.'<br/>'.$descr;
		} else {
			return $label;
		}
	}

	// Haal de openingsuren van de node voor een bepaalde dag op (werkt met dagindexes van 0 tot 6)
	function get_office_hours_for_day( $day, $node = 0, $shop_post_id = 0 ) {
		if ( $shop_post_id === 0 ) $shop_post_id = get_option('oxfam_shop_post_id');

		$oww_store_data = get_external_wpsl_store( $shop_post_id );
		if ( $oww_store_data !== false ) {
			// Bestaat in principe altijd
			$opening_hours = $oww_store_data['opening_hours'];
			
			$i = 0;
			$hours = array();
			$weekdays = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
			
			foreach ( $opening_hours[ $weekdays[$day] ] as $block ) {
				$parts = explode( ',', $block );
				if ( count($parts) === 2 ) {
					$hours[$i]['start'] = format_hour( $parts[0] );
					$hours[$i]['end'] = format_hour( $parts[1] );
				}
				$i++;
			}
			return $hours;
		}

		return false;
	}

	// Stop de openingsuren in een logische array (werkt met dagindices van 1 tot 7)
	function get_office_hours( $node = 0, $shop_post_id = 0 ) {
		if ( $node !== 0 ) {
			// write_log("get_office_hours() was invoked with deprecated node parameter! (value: ".$node.")");
		}

		if ( $shop_post_id === 0 ) $shop_post_id = get_option('oxfam_shop_post_id');
		
		if ( ! is_numeric( $shop_post_id ) ) {
			$hours = get_site_option( 'oxfam_opening_hours_'.$shop_post_id );
		} else {
			for ( $day = 0; $day <= 6; $day++ ) {
				// Forceer 'natuurlijke' nummering
				$hours[$day+1] = get_office_hours_for_day( $day, $node, $shop_post_id );
			}
		}

		return $hours;
	}

	// Stop de uitzonderlijke sluitingsdagen in een array 
	function get_closing_days( $shop_post_id = 0 ) {
		if ( $shop_post_id === 0 ) $shop_post_id = get_option('oxfam_shop_post_id');
		
		if ( ! is_numeric( $shop_post_id ) ) {
			// Retourneert ook false indien onbestaande
			return get_site_option( 'oxfam_closing_days_'.$shop_post_id );
		} elseif ( intval( $shop_post_id ) > 0 ) {
			$oww_store_data = get_external_wpsl_store( $shop_post_id );
			if ( $oww_store_data !== false ) {
				// Bevat datums in 'Y-m-d'-formaat
				$closing_days = $oww_store_data['closing_days'];
				if ( count( $closing_days ) > 0 ) {
					return $closing_days;
				}
			}
		}

		return false;
	}

	// Bereken de eerst mogelijke leverdatum voor de opgegeven verzendmethode (retourneert een timestamp) 
	function estimate_delivery_date( $shipping_id, $order_id = false ) {
		// We gebruiken het geregistreerde besteltijdstip OF het live tijdstip voor schattingen van de leverdatum
		if ( $order_id === false ) {
			$from = current_time('timestamp');
		} else {
			$order = wc_get_order($order_id);
			// We hebben de timestamp van de besteldatum nodig in de huidige tijdzone, dus pas get_date_from_gmt() toe die het formaat 'Y-m-d H:i:s' vereist!
			$from = strtotime( get_date_from_gmt( date_i18n( 'Y-m-d H:i:s', strtotime( $order->get_date_created() ) ) ) );
		}
		
		$timestamp = $from;
		
		// Standaard: bereken a.d.h.v. de hoofdwinkel
		$shop_post_id = get_option('oxfam_shop_post_id');
		
		switch ( $shipping_id ) {
			// Alle instances van winkelafhalingen
			case stristr( $shipping_id, 'local_pickup' ):

				if ( $locations = get_option('woocommerce_pickup_locations') ) {
					if ( $order_id === false ) {
						$pickup_locations = WC()->session->get('chosen_pickup_locations');
						if ( isset( $pickup_locations ) ) {
							$pickup_id = reset($pickup_locations);
						} else {
							$pickup_id = 'ERROR';
						}
					} else {
						$methods = $order->get_shipping_methods();
						$method = reset($methods);
						$pickup_location = $method->get_meta('pickup_location');
						$pickup_id = $pickup_location['id'];
					}
					foreach ( $locations as $location ) {
						if ( $location['id'] == $pickup_id ) {
							$parts = explode( 'id=', $location['note'] );
							if ( isset( $parts[1] ) ) {
								// Afwijkend punt geselecteerd: bereken a.d.h.v. de post-ID in de openingsuren
								$shop_post_id = str_replace( ']', '', $parts[1] );
							}
							break;
						}
					}
				}

				if ( $shop_post_id === 'tuincentrum' ) {
					if ( date_i18n( 'N', $from ) > 4 or ( date_i18n( 'N', $from ) == 4 and date_i18n( 'G', $from ) >= 12 ) ) {
						// Na de deadline van donderdag 12u00: begin pas bij volgende werkdag, kwestie van zeker op volgende week uit te komen
						$from = strtotime( '+1 weekday', $from );
					}

					// Zoek de eerste vrijdag na de volgende middagdeadline
					$timestamp = strtotime( 'next Friday', $from );
				} elseif ( $shop_post_id === 'vorselaar' or $shop_post_id === 'roeselare' ) {
					if ( date_i18n( 'N', $from ) > 4 ) {
						// Na de deadline van donderdag 23u59: begin pas bij volgende werkdag, kwestie van zeker op volgende week uit te komen
						$from = strtotime( '+1 weekday', $from );
					}

					// Zoek de eerste vrijdag na de volgende middagdeadline (wordt wegens openingsuren automatisch zaterdagochtend)
					$timestamp = strtotime( 'next Friday', $from );

					// Skip check op uitzonderlijke sluitingsdagen
					return find_first_opening_hour( get_office_hours( NULL, $shop_post_id ), $timestamp );
				} elseif ( $shop_post_id === 'evergem' ) {
					if ( date_i18n( 'N', $from ) > 2 ) {
						// Na de deadline van dinsdag 23u59: begin pas bij 3de werkdag, kwestie van zeker op volgende week uit te komen
						$from = strtotime( '+3 weekdays', $from );
					}

					// Zoek de eerste donderdag na de volgende middagdeadline (wordt wegens openingsuren automatisch vrijdagochtend)
					$timestamp = strtotime( 'next Thursday', $from );

					// Skip check op uitzonderlijke sluitingsdagen
					return find_first_opening_hour( get_office_hours( NULL, $shop_post_id ), $timestamp );
				} elseif ( intval( $shop_post_id ) === 3478 ) {
					// Meer marge voor Hoogstraten
					if ( date_i18n( 'N', $from ) < 4 or ( date_i18n( 'N', $from ) == 7 and date_i18n( 'G', $from ) >= 22 ) ) {
						// Na de deadline van zondag 22u00: begin pas bij 4de werkdag, kwestie van zeker op volgende week uit te komen
						$from = strtotime( '+4 weekdays', $from );
					}

					// Zoek de eerste donderdag na de volgende middagdeadline (wordt wegens openingsuren automatisch vrijdagochtend)
					$timestamp = strtotime( 'next Thursday', $from );
				} else {
					$timestamp = get_first_working_day( $from );

					// Geef nog twee extra werkdagen voor afhaling in niet-OWW-punten
					if ( ! is_numeric( $shop_post_id ) ) {
						$timestamp = strtotime( '+2 weekdays', $timestamp );
					}
				}

				// Check of de winkel op deze dag effectief nog geopend is na 12u (tel er indien nodig dagen bij)
				$timestamp = find_first_opening_hour( get_office_hours( NULL, $shop_post_id ), $timestamp );

				// Tel alle sluitingsdagen die in de verwerkingsperiode vallen (inclusief de eerstkomende openingsdag!) erbij
				$timestamp = move_date_on_holidays( $from, $timestamp );

				// Check of de winkel ook op de nieuwe dag effectief nog geopend is na 12u
				$timestamp = find_first_opening_hour( get_office_hours( NULL, $shop_post_id ), $timestamp );

				break;

			// Alle (gratis/betalende) instances van postpuntlevering en thuislevering
			default:
				if ( intval( $shop_post_id ) === 3338 ) {
					// Voorlopig enkel thuislevering op vrijdag bij Brussel 
					if ( date_i18n( 'N', $from ) > 4 ) {
						// Na de deadline van donderdag 23u59: begin pas bij volgende werkdag, kwestie van zeker op volgende week uit te komen
						$from = strtotime( '+1 weekday', $from );
					}

					// Zoek de eerste vrijdag
					$timestamp = strtotime( 'next Friday', $from );
				} elseif ( intval( $shop_post_id ) === 3409 ) {
					// Voorlopig enkel thuislevering op vrijdag bij Evergem 
					if ( date_i18n( 'N', $from ) > 2 ) {
						// Na de deadline van dinsdag 23u59: begin pas bij 3de werkdag, kwestie van zeker op volgende week uit te komen
						$from = strtotime( '+3 weekdays', $from );
					}

					// Zoek de eerste vrijdag
					$timestamp = strtotime( 'next Friday', $from );
				} else {
					// Zoek de eerste werkdag na de volgende middagdeadline
					$timestamp = get_first_working_day( $from );

					// Geef nog twee extra werkdagen voor de thuislevering
					$timestamp = strtotime( '+2 weekdays', $timestamp );

					// Tel feestdagen die in de verwerkingsperiode vallen erbij
					$timestamp = move_date_on_holidays( $from, $timestamp );
				}
		}

		return $timestamp;
	}

	// Ontvangt een timestamp en antwoordt met eerste werkdag die er toe doet
	function get_first_working_day( $from ) {
		if ( date_i18n( 'N', $from ) < 6 and date_i18n( 'G', $from ) < 12 ) {
			// Geen actie nodig
		} else {
			// We zitten al na de deadline van een werkdag, begin pas vanaf volgende werkdag te tellen
			$from = strtotime( '+1 weekday', $from );
		}

		// Bepaal de eerstvolgende werkdag
		$timestamp = strtotime( '+1 weekday', $from );
		
		return $timestamp;
	}

	// Check of er feestdagen in een bepaalde periode liggen, en zo ja: tel die dagen bij de einddag
	// Neemt een begin- en eindpunt en retourneert het nieuwe eindpunt (allemaal in timestamps)
	function move_date_on_holidays( $from, $till ) {
		// Check of de startdag ook nog in beschouwing genomen moet worden
		if ( date_i18n( 'N', $from ) < 6 and date_i18n( 'G', $from ) >= 12 ) {
			$first = date_i18n( 'Y-m-d', strtotime( '+1 weekday', $from ) );
		} else {
			$first = date_i18n( 'Y-m-d', $from );
		}
		// In dit formaat zijn datum- en tekstsortering equivalent!
		// Tel er een halve dag bij om tijdzoneproblemen te vermijden
		$last = date_i18n( 'Y-m-d', $till+12*60*60 );
		
		// Neem de wettelijke feestdagen indien er geen enkele gedefinieerd zijn TIJDELIJK UITSCHAKELEN
		// foreach ( get_option( 'oxfam_holidays', get_site_option('oxfam_holidays') ) as $holiday ) {
		// 	// Enkel de feestdagen die niet in het weekend vallen moeten we in beschouwing nemen!
		// 	if ( date_i18n( 'N', strtotime($holiday) ) < 6 and ( $holiday > $first ) and ( $holiday <= $last ) ) {
		// 		// TO DO: Enkel werkdag bijtellen indien de winkel niet sowieso al gesloten is op deze weekdag?
		// 		$till = strtotime( '+1 weekday', $till );
		// 		$last = date_i18n( 'Y-m-d', $till+12*60*60 );
		// 	}
		// }
		
		return $till;
	}

	// Zoek het eerstvolgende openeningsuur op een dag (indien $afternoon: pas vanaf 12u)
	function find_first_opening_hour( $hours, $from, $afternoon = true, $tried = 0 ) {
		// Argument 'N' want get_office_hours() werkt van 1 tot 7!
		$i = date_i18n( 'N', $from );
		if ( $hours[$i] ) {
			$day_part = $hours[$i][0];
			$start = intval( substr( $day_part['start'], 0, -2 ) );
			$end = intval( substr( $day_part['end'], 0, -2 ) );
			if ( $afternoon ) {
				if ( $end > 12 ) {
					if ( intval( substr( $day_part['start'], 0, -2 ) ) >= 12 ) {
						// Neem het openingsuur van het eerste deel
						$timestamp = strtotime( date_i18n( 'Y-m-d', $from )." ".$day_part['start'] );
					} else {
						// Toon pas mogelijk vanaf 12u
						$timestamp = strtotime( date_i18n( 'Y-m-d', $from )." 12:00" );
					}
				} else {
					unset( $day_part );
					// Ga naar het tweede dagdeel (we gaan er van uit dat er nooit drie zijn!)
					$day_part = $hours[$i][1];
					$start = intval( substr( $day_part['start'], 0, -2 ) );
					$end = intval( substr( $day_part['end'], 0, -2 ) );
					if ( $end > 12 ) {
						if ( intval( substr( $day_part['start'], 0, -2 ) ) >= 12 ) {
							// Neem het openingsuur van dit deel
							$timestamp = strtotime( date_i18n( 'Y-m-d', $from )." ".$day_part['start'] );
						} else {
							// Toon pas mogelijk vanaf 12u
							$timestamp = strtotime( date_i18n( 'Y-m-d', $from )." 12:00" );
						}
					} else {
						// Het mag ook een dag in het weekend zijn, de wachttijd is vervuld!
						$timestamp = find_first_opening_hour( $hours, strtotime( 'tomorrow', $from ), false );
					}
				}
			} else {
				// Neem sowieso het openingsuur van het eerste dagdeel
				$timestamp = strtotime( date_i18n( 'Y-m-d', $from )." ".$day_part['start'] );
			}
		} else {
			// Indien alle openingsuren weggehaald zijn (elke dag in $hours === false): stop na 7 pogingen
			if ( $tried < 7 ) {
				// Vandaag zijn we gesloten, probeer het morgen opnieuw
				// Het mag nu ook een dag in het weekend zijn, de wachttijd is vervuld!
				$timestamp = find_first_opening_hour( $hours, strtotime( 'tomorrow', $from ), false, $tried+1 );
			} else {
				// Beëindig de loop
				$timestamp = false;
			}
		}
		return $timestamp;
	}

	// Bewaar het verzendadres niet tijdens het afrekenen indien het om een afhaling gaat WEL BIJ SERVICE POINT, WANT NODIG VOOR IMPORT
	add_filter( 'woocommerce_cart_needs_shipping_address', 'skip_shipping_address_on_pickups' ); 
	
	function skip_shipping_address_on_pickups( $needs_shipping_address ) {
		$chosen_methods = WC()->session->get('chosen_shipping_methods');
		// Deze vergelijking zoekt naar methodes die beginnen met deze string
		if ( strpos( reset($chosen_methods), 'local_pickup' ) !== false ) {
			$needs_shipping_address = false;
		}
		return $needs_shipping_address;
	}

	// Verberg het verzendadres na het bestellen ook bij een postpuntlevering in de front-end
	add_filter( 'woocommerce_order_hide_shipping_address', 'hide_shipping_address_on_pickups' ); 
	
	function hide_shipping_address_on_pickups( $hide_on_methods ) {
		// Bevat 'local_pickup' reeds via core en 'local_pickup_plus' via filter in plugin
		// Instances worden er afgeknipt bij de check dus achterwege laten
		$hide_on_methods[] = 'service_point_shipping_method';
		return $hide_on_methods;
	}

	function validate_zip_code( $zip ) {
		if ( does_home_delivery() and $zip !== 0 ) {
			// Eventueel enkel tonen op de winkelmandpagina m.b.v. is_cart()
			if ( ! array_key_exists( $zip, get_site_option( 'oxfam_flemish_zip_codes' ) ) and $zip !== 6941 ) {
				wc_add_notice( __( 'Foutmelding na het ingeven van een onbestaande Vlaamse postcode.', 'oxfam-webshop' ), 'error' );
				return false;
			} else {
				// NIET get_option('oxfam_zip_codes') gebruiken om onterechte foutmeldingen bij overlap te vermijden
				if ( ! in_array( $zip, get_oxfam_covered_zips() ) ) {
					$str = date_i18n('d/m/Y H:i:s')."\t\t".get_home_url()."\t\tPostcode ".$zip."\t\tGeen verzending georganiseerd door deze winkel\n";
					if ( ! current_user_can('update_core') ) {
						file_put_contents( "../shipping_errors.csv", $str, FILE_APPEND );
					}
					
					if ( WC()->customer->get_billing_postcode() !== WC()->customer->get_shipping_postcode() ) {
						// Zet de verzendpostcode gelijk aan de factuurpostcode BETER LETTERLIJK IN FRONTEND DOEN?
						WC()->customer->set_shipping_postcode( WC()->customer->get_billing_postcode() );
						write_log("SHIPPING POSTCODE FORCED TO BILLING (ERROR PROCEDURE)");
						$current_user = wp_get_current_user();
						write_log( "SHIPPING POSTCODE FOR CUSTOMER ".$current_user->user_login." (ERROR PROCEDURE): ".WC()->customer->get_shipping_postcode() );
					}

					// Toon de foutmelding slechts één keer
					if ( WC()->session->get( 'no_zip_delivery_in_'.get_current_blog_id().'_for_'.$zip ) !== 'SHOWN' ) {
						$global_zips = get_shops();
						if ( isset( $global_zips[$zip] ) ) {
							$url = $global_zips[$zip];
							// TO DO: COMMANDO TOEVOEGEN OM WINKELMANDJE OVER TE ZETTEN
							$skus = array();
							if ( WC()->session->has_session() and current_user_can('update_core') ) {
								foreach ( WC()->cart->get_cart() as $cart_item_key => $values ) {
									$product_in_cart = $values['data'];
									$skus[] = $product_in_cart->get_sku();
								}
							}
							if ( count( $skus ) > 0 ) {
								$url .= '?addSku='.implode( ',', $skus );
							}
							// Check eventueel of de boodschap al niet in de pijplijn zit door alle values van de array die wc_get_notices('error') retourneert te checken
							wc_add_notice( sprintf( __('Foutmelding na het ingeven van postcode %1$s waar deze webshop geen thuislevering voor organiseert, inclusief URL %2$s van webshop die dat wel doet.', 'oxfam-webshop' ), $zip, $url ), 'error' );
							WC()->session->set( 'no_zip_delivery_in_'.get_current_blog_id().'_for_'.$zip, 'SHOWN' );
						}
					}
				}
			}
		}
	}

	// Zet een maximum op het aantal items dat je kunt toevoegen CHECKT NIET OP REEDS AANWEZIGE ITEMS, NIET INTERESSANT
	// add_action( 'woocommerce_add_to_cart_validation', 'maximum_item_quantity_validation' );

	function maximum_item_quantity_validation( $passed, $product_id, $quantity, $variation_id, $variations ) {
		if ( $quantity > 10 ) {
			wc_add_notice( 'Je kunt maximum 10 exemplaren van een product toevoegen aan je winkelmandje.', 'error' );
		} else {
			return true;
		}
	}

	// Moedig aan om naar 100 euro te gaan (gratis thuislevering)
	add_action( 'woocommerce_before_cart', 'show_almost_free_shipping_notice' );

	function show_almost_free_shipping_notice() {
		if ( is_cart() and ! is_b2b_customer() ) {
			// $threshold = 100;
			$threshold = 50;
			// Subtotaal = winkelmandje inclusief belasting, exclusief verzending
			$current = WC()->cart->subtotal;
			if ( $current > ( 0.7 * $threshold ) ) {
				if ( $current < $threshold ) {
					// Probeer de boodschap slechts af en toe te tonen via sessiedata
					$cnt = WC()->session->get( 'go_to_100_message_count', 0 );
					// Opgelet: WooCoomerce moet actief zijn, we moeten in de front-end zitten én er moet al een winkelmandje aangemaakt zijn!
					WC()->session->set( 'go_to_100_message_count', $cnt+1 );
					$msg = WC()->session->get( 'no_home_delivery' );
					// Enkel tonen indien thuislevering effectief beschikbaar is voor het huidige winkelmandje
					if ( $cnt % 7 === 0 and $msg !== 'SHOWN' ) {
						wc_print_notice( 'Tip: als je nog '.wc_price( $threshold - $current ).' toevoegt, kom je in aanmerking voor gratis thuislevering.', 'success' );
					}
				}
			} else {
				WC()->session->set( 'go_to_100_message_count', 0 );
			}
		}
	}
	
	// Definieer een globale B2B-levermethode zonder support voor verzendzones
	add_filter( 'woocommerce_shipping_methods', 'add_b2b_home_delivery_method' );
	add_action( 'woocommerce_shipping_init', 'create_b2b_home_delivery_method' );

	function add_b2b_home_delivery_method( $methods ) {
		$methods['b2b_home_delivery'] = 'WC_B2B_Home_Delivery_Method';
		return $methods;
	}
	
	function create_b2b_home_delivery_method() {
		class WC_B2B_Home_Delivery_Method extends WC_Shipping_Method {
			public function __construct() {
				$this->id = 'b2b_home_delivery';
				$this->method_title = __( 'B2B-leveringen', 'oxfam-webshop' );
				$this->init_form_fields();
				$this->init_settings();
				$this->enabled = $this->get_option('enabled');
				$this->title = $this->get_option('title');
				$this->cost = $this->get_option('cost');
				add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
			}

			public function init_form_fields() {
				$this->form_fields = array(
					'enabled' => array(
						'title' => 'Actief?',
						'type' => 'checkbox',
						'label' => 'Schakel levering op locatie in voor bedrijfsklanten',
						'default' => 'yes',
					),
					'title' => array(
						'title' => 'Label?',
						'type' => 'text',
						'description' => 'Dit is de naam waarmee de verzendmethode onder het winkelmandje verschijnt.',
						'default' => 'Levering op locatie (timing af te spreken)',
					),
					'cost' => array(
						'title' => 'Kostprijs?',
						'type' => 'number',
						'custom_attributes' => array(
							'step' => '0.05',
							'min' => '0',
							'max' => '20',
						),
						'default' => '0',
					),
					// TO DO: Verificatie toevoegen op bereiken van bestelminimum (ofwel in woocommerce_package_rates, ofwel in woocommerce_checkout_process)
					'limit' => array(
						'title' => 'Bestelminimum?',
						'type' => 'number',
						'description' => 'De verzendmethode is enkel beschikbaar indien er voor minimum dit bedrag besteld wordt.',
						'custom_attributes' => array(
							'step' => '1',
							'min' => '10',
							'max' => '1000',
						),
						'default' => '100',
					),
				);
			}

			public function is_available( $package ) {
				if ( $this->enabled === 'yes' and is_b2b_customer() ) {
					return true;
				} else {
					return false;
				}
			}

			public function calculate_shipping( $package = array() ) {
				$rate = array(
					'id' => $this->id,
					'label' => $this->title,
					'cost' => $this->cost,
					// Laat de BTW automatisch variëren volgens inhoud winkelmandje
					'taxes' => '',
					'calc_tax' => 'per_order',
				);
				$this->add_rate($rate);
			}
		}
	}

	// Handig filtertje om het JavaScript-conflict op de checkout te debuggen
	// add_filter( 'woocommerce_ship_to_different_address_checked', '__return_true' );

	// Fix voor verborgen verzendadressen die aanpassen leverpostcode verhinderen
	// add_filter( 'woocommerce_package_rates', 'fix_shipping_postcode', 100, 2 );

	function fix_shipping_postcode( $rates, $package ) {
		// GEWIJZIGD: Zorg dat er altijd al een postcode ingevuld is, zodat de verzendmethodes niet verdwijnen bij het uitklappen
		if ( intval( WC()->customer->get_shipping_postcode() ) < 1000 ) {
			if ( intval( WC()->customer->get_billing_postcode() ) >= 1000 ) {
				// Initialiseer op factuurpostcode
				WC()->customer->set_shipping_postcode( WC()->customer->get_billing_postcode() );
			} else {
				// Initialiseer op winkelpostcode
				WC()->customer->set_shipping_postcode( get_oxfam_shop_data('zipcode') );
			}
		}

		// $current_user = wp_get_current_user();
		// write_log( "SHIPPING POSTCODE FOR CUSTOMER ".$current_user->user_login.": ".WC()->customer->get_shipping_postcode() );
		// if ( ! apply_filters( 'woocommerce_ship_to_different_address_checked', 'shipping' === get_option( 'woocommerce_ship_to_destination' ) ) and WC()->customer->get_billing_postcode() !== WC()->customer->get_shipping_postcode() ) {
		// 	// Zet de verzendpostcode gelijk aan de factuurpostcode
		// 	WC()->customer->set_shipping_postcode( WC()->customer->get_billing_postcode() );
		// 	write_log("SHIPPING POSTCODE FORCED TO BILLING (GENERAL)");
		// 	write_log( "SHIPPING POSTCODE FOR CUSTOMER ".$current_user->user_login." (GENERAL): ".WC()->customer->get_shipping_postcode() );
		// }

		return $rates;
	}

	// Disable sommige verzendmethoden onder bepaalde voorwaarden
	add_filter( 'woocommerce_package_rates', 'hide_shipping_recalculate_taxes', 10, 2 );
	
	function hide_shipping_recalculate_taxes( $rates, $package ) {
		if ( ! is_b2b_customer() ) {
			validate_zip_code( intval( WC()->customer->get_shipping_postcode() ) );

			// Check of er een gratis levermethode beschikbaar is => uniform minimaal bestedingsbedrag!
			$free_home_available = false;
			foreach ( $rates as $rate_key => $rate ) {
				if ( $rate->method_id === 'free_shipping' ) {
					$free_home_available = true;	
					break;
				}
			}

			if ( $free_home_available ) {
				// Verberg alle betalende methodes indien er een gratis thuislevering beschikbaar is
				foreach ( $rates as $rate_key => $rate ) {
					if ( floatval( $rate->cost ) > 0.0 ) {
						unset( $rates[$rate_key] );
					}
				}
			} else {
				// Verberg alle gratis methodes die geen afhaling zijn
				foreach ( $rates as $rate_key => $rate ) {
					if ( $rate->method_id !== 'local_pickup_plus' and floatval( $rate->cost ) === 0.0 ) {
						// IS DIT WEL NODIG, WORDEN TOCH AL VERBORGEN DOOR WOOCOMMERCE?
						// unset( $rates[$rate_key] );
					}
				}
			}

			if ( ! does_risky_delivery() ) {
				// Verhinder alle externe levermethodes indien er een product aanwezig is dat niet thuisgeleverd wordt
				$glass_cnt = 0;
				$plastic_cnt = 0;
				foreach ( WC()->cart->cart_contents as $item_key => $item_value ) {
					if ( $item_value['data']->get_shipping_class() === 'breekbaar' ) {
						// Omwille van de icoontjes is niet alleen het leeggoed maar ook het product als breekbaar gemarkeerd!
						if ( $item_value['product_id'] === wc_get_product_id_by_sku('WLFSG') ) {
							$glass_cnt += intval($item_value['quantity']);
						}
						if ( $item_value['product_id'] === wc_get_product_id_by_sku('WLBS6') or $item_value['product_id'] === wc_get_product_id_by_sku('WLBS24') or $item_value['product_id'] === wc_get_product_id_by_sku('W29917') ) {
							$plastic_cnt += intval($item_value['quantity']);
						}
					} 
				}
				
				if ( $glass_cnt + $plastic_cnt > 0 ) {
					foreach ( $rates as $rate_key => $rate ) {
						// Blokkeer alle methodes behalve afhalingen
						if ( $rate->method_id !== 'local_pickup_plus' ) {
							unset( $rates[$rate_key] );
						}
					}
					// Boodschap heeft enkel zin als thuislevering aangeboden wordt!
					if ( does_home_delivery() ) {
						$msg = WC()->session->get('no_home_delivery');
						// Toon de foutmelding slechts één keer
						if ( $msg !== 'SHOWN' ) {
							if ( $glass_cnt > 0 and $plastic_cnt > 0 ) {
								wc_add_notice( 'Je winkelmandje bevat '.sprintf( _n( '%d grote fles', '%d grote flessen', $glass_cnt, 'oxfam-webshop' ), $glass_cnt ).' fruitsap en '.sprintf( _n( '%d krat', '%d kratten', $plastic_cnt, 'oxfam-webshop' ), $plastic_cnt ).' leeggoed. Deze producten zijn te onhandig om op te sturen. Kom je bestelling afhalen in de winkel, of verwijder ze uit je winkelmandje om thuislevering weer mogelijk te maken.', 'error' );
							} elseif ( $glass_cnt > 0 ) {
								wc_add_notice( 'Je winkelmandje bevat '.sprintf( _n( '%d grote fles', '%d grote flessen', $glass_cnt, 'oxfam-webshop' ), $glass_cnt ).' fruitsap. Deze producten zijn te onhandig om op te sturen. Kom je bestelling afhalen in de winkel, of verwijder ze uit je winkelmandje om thuislevering weer mogelijk te maken.', 'error' );
							} elseif ( $plastic_cnt > 0 ) {
								wc_add_notice( 'Je winkelmandje bevat '.sprintf( _n( '%d krat', '%d kratten', $plastic_cnt, 'oxfam-webshop' ), $plastic_cnt ).' leeggoed. Dit is te onhandig om op te sturen. Kom je bestelling afhalen in de winkel, of verminder het aantal kleine flesjes in je winkelmandje om thuislevering weer mogelijk te maken.', 'error' );
							}
							WC()->session->set( 'no_home_delivery', 'SHOWN' );
						}
					}
				} else {
					WC()->session->set( 'no_home_delivery', 'RESET' );
				}
			}
			
			// Verhinder alle externe levermethodes indien totale brutogewicht > 29 kg (neem 1 kg marge voor verpakking)
			// $cart_weight = wc_get_weight( WC()->cart->get_cart_contents_weight(), 'kg' );
			// if ( $cart_weight > 29 ) {
			// 	foreach ( $rates as $rate_key => $rate ) {
			// 		// Blokkeer alle methodes behalve afhalingen
			// 		if ( $rate->method_id !== 'local_pickup_plus' ) {
			// 			unset( $rates[$rate_key] );
			// 		}
			// 	}
			// 	wc_add_notice( sprintf( __( 'Foutmelding bij bestellingen boven de 30 kg, inclusief het huidige gewicht in kilogram (%s).', 'oxfam-webshop' ), number_format( $cart_weight, 1, ',', '.' ) ), 'error' );
			// }

			$low_vat_slug = 'voeding';
			$low_vat_rates = WC_Tax::get_rates_for_tax_class( $low_vat_slug );
			$low_vat_rate = reset( $low_vat_rates );
			
			// Slug voor 'standard rate' is een lege string!
			$standard_vat_rates = WC_Tax::get_rates_for_tax_class( '' );
			$standard_vat_rate = reset( $standard_vat_rates );
			
			$tax_classes = WC()->cart->get_cart_item_tax_classes();
			if ( ! in_array( $low_vat_slug, $tax_classes ) ) {
				// Brutoprijs verlagen om te compenseren voor hoger BTW-tarief
				// $cost = 5.7438;
				$cost = 4.0909;
				// Ook belastingen expliciet herberekenen!
				$taxes = $cost*0.21;
				$tax_id_free = $low_vat_rate->tax_rate_id;
				$tax_id_cost = $standard_vat_rate->tax_rate_id;
			} else {
				// $cost = 6.5566;
				$cost = 4.6698;
				// Deze stap doen we vooral omwille van het wispelturige gedrag van deze tax
				$taxes = $cost*0.06;
				$tax_id_free = $standard_vat_rate->tax_rate_id;
				$tax_id_cost = $low_vat_rate->tax_rate_id;
			}
			
			// Overschrijf alle verzendprijzen (dus niet enkel in 'uitsluitend 21%'-geval -> te onzeker) indien betalende thuislevering
			// DIT VOLSTAAT OM ALLE PRIJZEN DYNAMISCH TE WIJZIGEN
			if ( ! $free_home_available ) {
				foreach ( $rates as $rate_key => $rate ) {
					switch ( $rate_key ) {
						case in_array( $rate->method_id, array( 'flat_rate', 'service_point_shipping_method' ) ):
							$rate->cost = $cost;
							// Unset i.p.v. op nul te zetten
							unset($rate->taxes[$tax_id_free]);
							$rate->taxes[$tax_id_cost] = $taxes;
							break;
						default:
							// Dit zijn de gratis pick-ups (+ eventueel thuisleveringen), niets mee doen
							break;
					}
				}
			}
		} else {
			foreach ( $rates as $rate_key => $rate ) {
				$shipping_zones = WC_Shipping_Zones::get_zones();
				foreach ( $shipping_zones as $shipping_zone ) {
					// Alle niet-B2B-levermethodes uitschakelen
					$non_b2b_methods = $shipping_zone['shipping_methods'];
					foreach ( $non_b2b_methods as $shipping_method ) {
						// Behalve afhalingen en B2B-leveringen maar die vallen niet onder een zone!
						$method_key = $shipping_method->id.':'.$shipping_method->instance_id;
						unset($rates[$method_key]);
					}
				}
			}
		}
		
		return $rates;
	}

	// Zorg dat afhalingen in de winkel als standaard levermethode geselecteerd worden
	// Nodig omdat Local Pickup Plus geen verzendzones gebruikt maar alles overkoepelt
	// Documentatie in class-wc-shipping.php: "If not set, not available, or available methods have changed, set to the DEFAULT option"
	add_filter( 'woocommerce_shipping_chosen_method', 'set_pickup_as_default_shipping', 10, 3 );

	function set_pickup_as_default_shipping( $default, $rates, $chosen_method ) {
		return 'local_pickup_plus';
	}

	// Eventueel kunnen we ook 'woocommerce_after_shipping_rate' gebruiken (na elke verzendmethode) WORDT NETJES BIJGEWERKT BIJ AJAX-ACTIE UPDATE_SHIPPING
	add_action( 'woocommerce_review_order_before_shipping', 'explain_why_shipping_option_is_lacking' );
	add_action( 'woocommerce_cart_totals_before_shipping', 'explain_why_shipping_option_is_lacking' );
	
	function explain_why_shipping_option_is_lacking() {
		// if ( current_user_can('update_core') ) {
			// Als er slechts één methode beschikbaar is, moet het wel afhaling zijn!
			if ( count( WC()->shipping->packages[0]['rates'] ) < 2 ) {
				echo '<tr><td colspan="2" class="shipping-explanation">Waarom is verzending niet beschikbaar? <span class="dashicons dashicons-editor-help tooltip"><span class="tooltiptext">';
				if ( ! does_home_delivery() ) {
					echo 'Deze winkel organiseert geen thuislevering. Ga naar de webshop die voor jouw postcode aan huis levert.';
				} elseif ( WC()->session->get('no_home_delivery') === 'SHOWN' ) {
					// Dit werkt enkel indien blokkage omwille van leeggoed reeds getoond
					echo 'Omdat er producten in je winkelmandje zitten die niet beschikbaar zijn voor thuislevering.';
				} elseif ( strlen( WC()->customer->get_shipping_postcode() ) < 4 ) {
					// WC()->customer->has_calculated_shipping() werkt niet zoals verwacht
					echo 'Omdat de postcode nog niet ingevuld is.';
				} else {
					echo 'Omdat deze webshop niet thuislevert in de huidige postcode.';
				}
				echo '</span></span></td></tr>';
			}
		// }
	}

	// Voeg instructietekst toe boven de locaties
	// add_action( 'woocommerce_review_order_before_local_pickup_location', 'add_local_pickup_instructions' );
	
	function add_local_pickup_instructions() {
		echo '<br/><p style="width: 350px; float: right;">Je kunt kiezen uit volgende winkels ...</p>';
	}

	// Verberg de 'kortingsbon invoeren'-boodschap bij het afrekenen
	add_filter( 'woocommerce_checkout_coupon_message', 'remove_msg_filter' );

	function remove_msg_filter( $msg ) {
		if ( is_checkout() ) {
			return '';
		}
		return $msg;
	}

	// Voeg bakken leeggoed enkel toe per 6 of 24 flessen
	add_filter( 'wc_force_sell_add_to_cart_product', 'check_plastic_empties_quantity', 10, 2 );

	function check_plastic_empties_quantity( $empties_array, $product_item ) {
		// $empties_array bevat geen volwaardig cart_item, enkel array met de keys id / quantity / variation_id / variation!
		$empties_product = wc_get_product( $empties_array['id'] );
		$do_not_count_for_crates = false;

		if ( $empties_product !== false ) {
			$empties_sku = $empties_product->get_sku();
			
			if ( $empties_sku === 'W19916' or $empties_sku === 'W29917' ) {
				// Vermenigvuldig de flesjes bij samengestelde producten (= eleganter dan een extra leeggoedartikel aan te maken)
				// We kunnen dit niet in de switch verderop doen, aangezien ook de berekening voor W29917 deze gemanipuleerde hoeveelheden nodig heeft
				$product = wc_get_product( $product_item['product_id'] );
				if ( $product !== false ) {
					switch ( $product->get_sku() ) {
						case '20807':
						case '20809':
						case '20811':
							// Voeg 4 flesjes leeggoed toe bij clips
							$empties_array['quantity'] = 4 * intval( $product_item['quantity'] );
							// OVERRULE OOK PRODUCTHOEVEELHEID MET HET OOG OP ONDERSTAANDE LOGICA
							$product_item['quantity'] = 4 * intval( $product_item['quantity'] );
							break;

						case '19236':
						case '19237':
						case '19238':
						case '19239':
							// Voeg 3 flesjes leeggoed toe bij geschenksets
							$empties_array['quantity'] = 3 * intval( $product_item['quantity'] );
							// OVERRULE OOK PRODUCTHOEVEELHEID MET HET OOG OP ONDERSTAANDE LOGICA
							$product_item['quantity'] = 3 * intval( $product_item['quantity'] );
							// Hou met deze flesjes geen rekening bij berekenen van aantal plastic bakken
							$do_not_count_for_crates = true;
							break;
					}
				}
			}
			
			switch ( $empties_sku ) {
				case 'WLBS6':
					$empties_array['quantity'] = floor( intval( $product_item['quantity'] ) / 6 );
					break;

				case 'WLBS24':
				case 'W29917':
					// Door round() voegen we automatisch een bak toe vanaf 13 flesjes
					$empties_array['quantity'] = round( intval( $product_item['quantity'] ) / 24, 0, PHP_ROUND_HALF_DOWN );
					break;

				case 'WLFSG':
				case 'WLFSK':
				case 'W19916':
					// Definieer de koppelingen tussen glas en plastic
					switch ( $empties_sku ) {
						case 'WLFSG':
							$plastic_sku = 'WLBS6';
							$plastic_step = 6;
							break;

						case 'WLFSK':
							$plastic_sku = 'WLBS24';
							$plastic_step = 13;
							break;

						case 'W19916':
							$plastic_sku = 'W29917';
							$plastic_step = 13;
							break;
					}

					if ( ! $do_not_count_for_crates ) {
						$plastic_product_id = wc_get_product_id_by_sku( $plastic_sku );
						foreach( WC()->cart->get_cart() as $cart_item_key => $values ) {
							if ( $values['product_id'] == $product_item['product_id'] ) {
								// Indien er gratis producten toegevoegd worden, kan het product twee keer voorkomen in het winkelmandje!
								add_matching_plastic_crate( $cart_item_key, $plastic_product_id, $product_item, $plastic_step, $empties_array );
							}
						}
					}

					break;
			}
		}

		return $empties_array;
	}

	// Zorg ervoor dat het basisproduct toch gekocht kan worden als het krat omwille van functie hierboven nog niet toevoegd mag worden
	add_filter( 'wc_force_sell_disallow_no_stock', '__return_false' );
	
	// Check bij de bakken leeggoed of we al aan een volledige set van 6/24 flessen zitten 
	add_filter( 'wc_force_sell_update_quantity', 'update_plastic_empties_quantity', 10, 2 );

	function update_plastic_empties_quantity( $quantity, $empties_item ) {
		// Filter wordt per definitie enkel doorlopen bij het updaten van leeggoed
		$product_item = WC()->cart->get_cart_item( $empties_item['forced_by'] );
		$empties_product = wc_get_product( $empties_item['product_id'] );
		$do_not_count_for_crates = false;

		if ( $empties_product !== false ) {
			$empties_sku = $empties_product->get_sku();
			
			if ( $empties_sku === 'W19916' or $empties_sku === 'W29917' ) {
				// Vermenigvuldig de flesjes bij samengestelde producten (= eleganter dan een extra leeggoedartikel aan te maken)
				// We kunnen dit niet in de switch verderop doen, aangezien ook de berekening voor W29917 deze gemanipuleerde hoeveelheden nodig heeft
				$product = wc_get_product( $product_item['product_id'] );
				if ( $product !== false ) {
					switch ( $product->get_sku() ) {
						case '20807':
						case '20809':
						case '20811':
							// Voeg 4 flesjes leeggoed toe bij clips
							$quantity = 4 * intval( $product_item['quantity'] );
							// OVERRULE OOK PRODUCTHOEVEELHEID MET HET OOG OP ONDERSTAANDE LOGICA
							$product_item['quantity'] = 4 * intval( $product_item['quantity'] );
							break;

						case '19236':
						case '19237':
						case '19238':
						case '19239':
							// Voeg 3 flesjes leeggoed toe bij geschenksets
							$quantity = 3 * intval( $product_item['quantity'] );
							// OVERRULE OOK PRODUCTHOEVEELHEID MET HET OOG OP ONDERSTAANDE LOGICA
							$product_item['quantity'] = 3 * intval( $product_item['quantity'] );
							// Hou met deze flesjes geen rekening bij berekenen van aantal plastic bakken
							$do_not_count_for_crates = true;
							break;
					}
				}
			}
			
			switch ( $empties_sku ) {
				case 'WLBS6':
					$quantity = floor( intval( $product_item['quantity'] ) / 6 );
					break;

				case 'WLBS24':
				case 'W29917':
					// Door round() voegen we automatisch een bak toe vanaf 13 flesjes
					$quantity = round( intval( $product_item['quantity'] ) / 24, 0, PHP_ROUND_HALF_DOWN );
					break;

				case 'WLFSG':
				case 'WLFSK':
				case 'W19916':
					// Definieer de koppelingen tussen glas en plastic
					switch ( $empties_sku ) {
						case 'WLFSG':
							$plastic_sku = 'WLBS6';
							$plastic_step = 6;
							break;

						case 'WLFSK':
							$plastic_sku = 'WLBS24';
							$plastic_step = 13;
							break;

						case 'W19916':
							$plastic_sku = 'W29917';
							$plastic_step = 13;
							break;
					}

					if ( ! $do_not_count_for_crates ) {
						$plastic_product_id = wc_get_product_id_by_sku( $plastic_sku );
						foreach( WC()->cart->get_cart() as $cart_item_key => $values ) {
							if ( $values['product_id'] == $product_item['product_id'] ) {
								add_matching_plastic_crate( $cart_item_key, $plastic_product_id, $product_item, $plastic_step, $empties_item );
							}
						}
					}

					// Reset eventueel met het aantal van het hoofdproduct indien $quantity naar 1 zou terugvallen
					// $quantity = $product_item['quantity'];
					break;
			}
		}

		return $quantity;
	}

	function add_matching_plastic_crate( $product_item_key, $plastic_product_id, $product_item, $plastic_step, $empties_item ) {
		$plastic_in_cart = false;
		foreach( WC()->cart->get_cart() as $cart_item_key => $values ) {
			if ( intval( $values['product_id'] ) === $plastic_product_id and $values['forced_by'] === $product_item_key ) {
				// We hebben een krat gevonden dat gelinkt is aan de fles
				$plastic_in_cart = true;
				break;
			}
		}

		if ( ! $plastic_in_cart and floor( intval( $product_item['quantity'] ) / $plastic_step ) >= 1 ) {
			$main_product = wc_get_product( $product_item['product_id'] );
			// Voeg het eerste krat handmatig toe en zorg ervoor dat deze cart_item gelinkt wordt aan het product waaraan de fles al gelinkt was
			$result = WC()->cart->add_to_cart( $plastic_product_id, floor( intval( $product_item['quantity'] ) / $plastic_step ), $empties_item['variation_id'], $empties_item['variation'], array( 'forced_by' => $product_item_key ) );
		}
	}

	// Toon bij onzichtbaar leeggoed het woord 'flessen' na het productaantal
	add_filter( 'woocommerce_cart_item_quantity', 'add_bottles_to_quantity', 10, 3 );
	
	function add_bottles_to_quantity( $product_quantity, $cart_item_key, $cart_item ) {
		$product = wc_get_product( $cart_item['product_id'] );
		if ( $product !== false ) {
			if ( $product->get_sku() === 'GIFT' ) {
				return __( 'Oxfam pakt (voor) je in!', 'oxfam-webshop' );
			}

			if ( strpos( $product->get_sku(), 'W' ) === 0 ) {
				$qty = intval( $product_quantity );
				switch ( $product->get_sku() ) {
					case 'WLFSK':
					case 'W19916':
						return sprintf( _n( '%d flesje', '%d flesjes', $qty ), $qty );
					case 'WLBS6':
						return sprintf( _n( '%d krat', '%d kratten', $qty ), $qty ).' (per 6 flessen)';
					case 'WLBS24':
					case 'W29917':
						return sprintf( _n( '%d krat', '%d kratten', $qty ), $qty ).' (per 24 flesjes)';
					default:
						return sprintf( _n( '%d fles', '%d flessen', $qty ), $qty );
				}
			}
		}

		return $product_quantity;
	}

	// Zet leeggoed en cadeauverpakking onderaan
	add_action( 'woocommerce_cart_loaded_from_session', 'reorder_cart_items' );

	function reorder_cart_items( $cart ) {
		// Niets doen bij leeg winkelmandje
		if ( empty( $cart->cart_contents ) ) {
			return;
		}

		$cart_sorted = $cart->cart_contents;
		$glass_items = array();
		$plastic_items = array();

		foreach ( $cart->cart_contents as $cart_item_key => $cart_item ) {
			if ( $cart_item['data']->get_sku() === 'GIFT' ) {
				// Sla het item van de cadeauverpakking op en verwijder het
				$gift_item = $cart_item;
				unset($cart_sorted[$cart_item_key]);
			}

			if ( strpos( $cart_item['data']->get_sku(), 'WLF' ) === 0 or $cart_item['data']->get_sku() === 'W19916' ) {
				$glass_items[$cart_item_key] = $cart_item;
				unset($cart_sorted[$cart_item_key]);
			}

			if ( strpos( $cart_item['data']->get_sku(), 'WLB' ) === 0 or $cart_item['data']->get_sku() === 'W29917' ) {
				$plastic_items[$cart_item_key] = $cart_item;
				unset($cart_sorted[$cart_item_key]);
			}
		}

		$cart_sorted = array_merge( $cart_sorted, $glass_items, $plastic_items );

		if ( isset($gift_item) ) {
			// Voeg de cadeauverpakking opnieuw toe helemaal achteraan (indien het voorkwam)
			$cart_sorted[$cart_item_key] = $gift_item;
		}

		// Vervang de itemlijst door de nieuwe array
		$cart->cart_contents = $cart_sorted;
		// Vanaf WC 3.2+ gebruiken
		// $cart->set_cart_contents($cart_sorted);
	}

	// Toon leeggoed en cadeauverpakking niet in de mini-cart (maar wordt wel meegeteld in subtotaal!)
	add_filter( 'woocommerce_widget_cart_item_visible', 'wc_cp_cart_item_visible', 10, 3 );

	function wc_cp_cart_item_visible( $visible, $cart_item, $cart_item_key ) {
		if ( ! is_numeric( $cart_item['data']->get_sku() ) ) {
			$visible = false;
		}
		return $visible;
	}

	// Tel leeggoed niet mee bij aantal items in winkelmandje
	add_filter( 'woocommerce_cart_contents_count', 'exclude_empties_from_cart_count' );
	
	function exclude_empties_from_cart_count( $count ) {
		$cart = WC()->cart->get_cart();
		
		$subtract = 0;
		foreach ( $cart as $key => $value ) {
			if ( isset( $value['forced_by'] ) ) {
				$subtract += $value['quantity'];
			}
		}

		return $count - $subtract;
	}

	// Toon het totaalbedrag van al het leeggoed onderaan
	// add_action( 'woocommerce_widget_shopping_cart_before_buttons', 'show_empties_subtotal' );

	function show_empties_subtotal() {
		echo 'waarvan XX euro leeggoed';
	}
	


	############
	# SETTINGS #
	############

	// Voeg optievelden toe
	add_action( 'admin_init', 'register_oxfam_settings' );

	// Let op: $option_group = $page in de oude documentatie!
	function register_oxfam_settings() {
		register_setting( 'oxfam-options-global', 'oxfam_shop_post_id', 'absint' );
		register_setting( 'oxfam-options-global', 'oxfam_mollie_partner_id', 'absint' );
		register_setting( 'oxfam-options-global', 'oxfam_zip_codes', array( 'sanitize_callback' => 'comma_string_to_numeric_array' ) );
		register_setting( 'oxfam-options-global', 'oxfam_member_shops', array( 'sanitize_callback' => 'comma_string_to_array' ) );
		// register_setting( 'oxfam-options-local', 'oxfam_holidays', array( 'sanitize_callback' => 'comma_string_to_array' ) );
	}

	// Zorg ervoor dat je lokale opties ook zonder 'manage_options'-rechten opgeslagen kunnen worden
	add_filter( 'option_page_capability_oxfam-options-local', 'lower_manage_options_capability' );
	
	function lower_manage_options_capability( $cap ) {
		return 'manage_woocommerce';
	}

	function comma_string_to_array( $values ) {
		$values = preg_replace( '/\s/', '', $values );
		$values = preg_replace( '/\//', '-', $values );
		$array = (array)preg_split( '/(,|;|&)/', $values, -1, PREG_SPLIT_NO_EMPTY );

		foreach ( $array as $key => $value ) {
			$array[$key] = mb_strtolower( trim($value) );
			// Verwijder datums uit het verleden (woorden van toevallig 10 tekens kunnen niet voor een datum komen!)
			if ( strlen( $array[$key] ) === 10 and $array[$key] < date_i18n('Y-m-d') ) {
				unset( $array[$key] );
			}
		}
		return $array;
	}

	function comma_string_to_numeric_array( $values ) {
		$values = preg_replace( '/\s/', '', $values );
		$values = preg_replace( '/\//', '-', $values );
		$array = (array)preg_split( '/(,|;|&)/', $values, -1, PREG_SPLIT_NO_EMPTY );

		foreach ( $array as $key => $value ) {
			$array[$key] = intval( $value );
		}
		sort( $array, SORT_NUMERIC );
		return $array;
	}

	// Voeg een custom pagina toe onder de algemene opties
	add_action( 'admin_menu', 'custom_oxfam_options' );

	function custom_oxfam_options() {
		add_menu_page( 'Stel de voorraad van je lokale webshop in', 'Voorraadbeheer', 'manage_network_users', 'oxfam-products-list', 'oxfam_products_list_callback', 'dashicons-admin-settings', '56' );
		add_submenu_page( 'oxfam-products-list', 'Stel de voorraad van je lokale webshop in', 'Lijstweergave', 'manage_network_users', 'oxfam-products-list', 'oxfam_products_list_callback' );
		add_submenu_page( 'oxfam-products-list', 'Stel de voorraad van je lokale webshop in', 'Fotoweergave', 'manage_network_users', 'oxfam-products-photos', 'oxfam_products_photos_callback' );
		add_menu_page( 'Handige gegevens voor je lokale webshop', 'Winkelgegevens', 'manage_network_users', 'oxfam-options', 'oxfam_options_callback', 'dashicons-megaphone', '58' );
		if ( is_main_site() ) {
			add_media_page( 'Productfoto\'s', 'Productfoto\'s', 'create_sites', 'oxfam-photos', 'oxfam_photos_callback' );
		}
	}

	function oxfam_photos_callback() {
		include get_stylesheet_directory().'/register-bulk-images.php';
	}

	function oxfam_options_callback() {
		include get_stylesheet_directory().'/update-options.php';
	}

	function oxfam_products_photos_callback() {
		include get_stylesheet_directory().'/update-stock-photos.php';
	}

	function oxfam_products_list_callback() {
		include get_stylesheet_directory().'/update-stock-list.php';
	}
	
	// Vervang onnutige links in netwerkmenu door Oxfam-pagina's
	add_action( 'wp_before_admin_bar_render', 'oxfam_admin_bar_render' );

	function oxfam_admin_bar_render() {
		global $wp_admin_bar;
		if ( current_user_can('create_sites') ) {
			$toolbar_nodes = $wp_admin_bar->get_nodes();
			$sites = get_sites( array( 'public' => 1 ) );
			foreach ( $sites as $site ) {
				$node_n = $wp_admin_bar->get_node('blog-'.$site->blog_id.'-n');
				if ( $node_n ) {
					$new_node = $node_n;
					$wp_admin_bar->remove_node('blog-'.$site->blog_id.'-n');
					$new_node->title = 'Winkelgegevens';
					$new_node->href = network_site_url( $site->path.'wp-admin/admin.php?page=oxfam-options' );
					$wp_admin_bar->add_node( $new_node );
				}
				$node_c = $wp_admin_bar->get_node('blog-'.$site->blog_id.'-c');
				if ( $node_c ) {
					$new_node = $node_c;
					$wp_admin_bar->remove_node('blog-'.$site->blog_id.'-c');
					$new_node->title = 'Voorraadbeheer';
					$new_node->href = network_site_url( $site->path.'wp-admin/admin.php?page=oxfam-products-list' );
					$wp_admin_bar->add_node( $new_node );
				}
			}
		}
	}

	// Registreer de AJAX-acties
	add_action( 'wp_ajax_oxfam_stock_action', 'oxfam_stock_action_callback' );
	add_action( 'wp_ajax_oxfam_photo_action', 'oxfam_photo_action_callback' );
	add_action( 'wp_ajax_oxfam_invitation_action', 'oxfam_invitation_action_callback' );

	function oxfam_stock_action_callback() {
		echo save_local_product_details($_POST['id'], $_POST['meta'], $_POST['value']);
		wp_die();
	}

	function save_local_product_details( $id, $meta, $value ) {			
		$msg = "";
		$product = wc_get_product($id);
		if ( $meta === 'stockstatus' ) {
			$product->set_stock_status($value);
			$msg .= "Voorraadstatus opgeslagen!";
		} elseif ( $meta === 'featured' ) {
			$product->set_featured($value);
			$msg .= "Uitlichting opgeslagen!";
		}
		// Retourneert product-ID on success?
		$product->save();
		return $msg;
	}

	function oxfam_photo_action_callback() {
		// Wordt standaard op ID geordend, dus creatie op hoofdsite gebeurt als eerste (= noodzakelijk!)
		// NIET IN LOKALE BIBLIOTHEKEN REGISTREREN, PUBLICATIE NAAR CHILD SITE GEBEURT VANZELF BIJ EERSTVOLGENDE SYNC (ID'S UPDATEN)
		echo register_photo( $_POST['name'], $_POST['timestamp'], $_POST['path'] );
		wp_die();
	}

	function oxfam_invitation_action_callback() {
		$new_account_path = get_stylesheet_directory() . '/woocommerce/emails/customer-new-account.php';
		$reset_password_path = get_stylesheet_directory() . '/woocommerce/emails/customer-reset-password.php';
		$temporary_path = get_stylesheet_directory() . '/woocommerce/emails/temporary.php';
		// Beter: check of $reset_password_path wel bestaat (= template werd overschreven)
		rename( $reset_password_path, $temporary_path );
		rename( $new_account_path, $reset_password_path );
		$user = get_user_by( 'id', $_POST['customer_id'] );
		if ( retrieve_password_for_customer( $user ) ) {
			printf( 'Succesvol uitgenodigd, kopie verstuurd naar %s!', get_company_email() );
			update_user_meta( $user->ID, 'blog_'.get_current_blog_id().'_b2b_invitation_sent', current_time('mysql') );
		} else {
			printf( 'Uitnodigen eigenaar \'%s\' mislukt, herlaad pagina en probeer eens opnieuw!', $user->user_login );
		}
		rename( $reset_password_path, $new_account_path );
		rename( $temporary_path, $reset_password_path );
		wp_die();
	}

	// Laat de wachtwoordlinks in de resetmails langer leven dan 1 dag (= standaard)
	add_filter( 'password_reset_expiration', function( $expiration ) {
		return 2*WEEK_IN_SECONDS;
	});

	function oxfam_get_attachment_id_by_file_name( $post_title ) {
		$args = array(
			// We gaan ervan uit dat ons proces waterdicht is en er dus maar één foto met dezelfde titel kan bestaan
			'posts_per_page' => 1,
			'post_type'	=> 'attachment',
			// Moet erbij, want anders wordt de default 'publish' gebruikt en die bestaat niet voor attachments!
			'post_status' => 'inherit',
			// De titel is NA DE IMPORT NIET MEER gelijk aan de bestandsnaam, dus zoek op basis van het gekoppelde bestand
			'meta_key' => '_wp_attached_file',
			'meta_value' => trim($post_title).'.jpg',
		);

		$attachment_id = false;
		$attachments = new WP_Query($args);
		if ( $attachments->have_posts() ) {
			while ( $attachments->have_posts() ) {
				$attachments->the_post();
				$attachment_id = get_the_ID();
			}
			wp_reset_postdata();
		}

		return $attachment_id;
	}

	function register_photo( $filename, $filestamp, $main_filepath ) {			
		// Parse de fototitel
		$filetitle = explode( '.jpg', $filename );
		$filetitle = $filetitle[0];
		$product_id = 0;

		// Check of er al een vorige versie bestaat
		$updated = false;
		$deleted = false;
		$old_id = oxfam_get_attachment_id_by_file_name( $filetitle );
		if ( $old_id ) {
			// Bewaar de post_parent van het originele attachment
			$product_id = wp_get_post_parent_id( $old_id );
			// Check of de uploadlocatie op dit punt al ingegeven is!
			if ( $product_id ) {
				$product = wc_get_product( $product_id );
			}

			// Stel het originele high-res bestand veilig
			rename( $main_filepath, WP_CONTENT_DIR.'/uploads/temporary.jpg' );
			// Verwijder de geregistreerde foto (en alle aangemaakte thumbnails!)
			if ( wp_delete_attachment( $old_id, true ) ) {
				// Extra check op het succesvol verwijderen
				$deleted = true;
			}
			$updated = true;
			// Hernoem opnieuw zodat de links weer naar de juiste file wijzen 
			rename( WP_CONTENT_DIR.'/uploads/temporary.jpg', $main_filepath );
		}
		
		// Creëer de parameters voor de foto
		$wp_filetype = wp_check_filetype( $filename, null );
		$attachment = array(
			'post_mime_type' => $wp_filetype['type'],
			'post_title' => $filetitle,
			'post_content' => '',
			'post_author' => get_current_user_id(),
			'post_status' => 'inherit',
		);

		// Probeer de foto in de mediabibliotheek te stoppen
		// Laatste argument: stel de uploadlocatie van de nieuwe afbeelding in op het product van het origineel (of 0 = geen)
		$attachment_id = wp_insert_attachment( $attachment, $main_filepath, $product_id );
		
		if ( ! is_wp_error( $attachment_id ) ) {
			// Check of de uploadlocatie ingegeven was!
			if ( ! isset($product_id) ) {
				// Indien het product nog niet bestaat zal de search naar een 0 opleveren
				$product_id = wc_get_product_id_by_sku( $filetitle );
			}

			if ( $product_id > 0 ) {
				// Voeg de nieuwe attachment-ID weer toe aan het oorspronkelijke product
				$product->set_image_id( $attachment_id );
				$product->save();

				// Stel de uploadlocatie van de nieuwe afbeelding in
				wp_update_post(
					array(
						'ID' => $attachment_id, 
						'post_parent' => $product_id,
					)
				);

				// UPDATE EVENTUEEL OOK NOG DE _WOONET_IMAGES_MAPPING VAN HET PRODUCT MET DEZE SKU M.B.V. DE BROADCAST SNIPPET
				// WANT ANDERS WORDT _THUMBNAIL_ID BIJ DE EERSTVOLGENDE ERP-IMPORT WEER OVERSCHREVEN MET DE OUDE FOTO-ID
			}

			// Registreer ook de metadata
			$attachment_data = wp_generate_attachment_metadata( $attachment_id, $main_filepath );
			wp_update_attachment_metadata( $attachment_id,  $attachment_data );
			// Toon een succesboodschap
			if ( $updated ) {
				$deleted = $deleted ? "verwijderd en opnieuw aangemaakt" : "bijgewerkt";
				$msg = "<i>".$filename."</i> ".$deleted." in mediabibliotheek van site-ID ".get_current_blog_id()." om ".date_i18n('H:i:s')." ...<br/>";
			} else {
				$msg = "<i>".$filename."</i> aangemaakt in mediabibliotheek van site-ID ".get_current_blog_id()." om ".date_i18n('H:i:s')." ...<br/>";
			}
			// Sla het uploadtijdstip van de laatste succesvolle registratie op (indien recenter dan huidige optiewaarde)
			if ( $filestamp > get_option( 'laatste_registratie_timestamp' ) ) {
				update_option( 'laatste_registratie_timestamp', $filestamp );
			}
			$registered = true;
		} else {
			// Geef een waarschuwing als de aanmaak mislukte
			$msg = "Opgelet, er liep iets mis met <i>".$filename."</i>!<br/>";
		}

		return $msg;
	}

	function get_scaled_image_path( $attachment_id, $size = 'full' ) {
		$file = get_attached_file( $attachment_id, true );
		if ( $size === 'full' ) return realpath($file);
		
		$info = image_get_intermediate_size( $attachment_id, $size );
		if ( ! is_array($info) or ! isset($info['file']) ) return false;
		
		return realpath( str_replace( wp_basename($file), $info['file'], $file ) );
	}

	function retrieve_password_for_customer( $user ) {
		// Creëer een key en sla ze op in de 'users'-tabel
		$key = get_password_reset_key($user);

		// Verstuur de e-mail met de speciale link
		WC()->mailer();
		do_action( 'woocommerce_reset_password_notification', $user->user_login, $key );

		return true;
	}

	// Toon een boodschap op de detailpagina indien het product niet thuisgeleverd wordt
	// Icoontje wordt toegevoegd op basis van CSS-klasse .product_shipping_class-breekbaar
	add_action( 'woocommerce_single_product_summary', 'show_delivery_warning', 45 );

	function show_delivery_warning() {
		global $product;
		$cat_ids = $product->get_category_ids();
		$parent_id = get_term( $cat_ids[0], 'product_cat' )->parent;
		$output = '';

		if ( get_term( $cat_ids[0], 'product_cat' )->slug === 'spirits' or get_term( $cat_ids[0], 'product_cat' )->slug === 'bier' or get_term( $parent_id, 'product_cat' )->slug === 'wijn' ) {
			$output = 'Ons vakmanschap drink je met verstand! Je dient minstens 18 jaar oud te zijn om dit alcoholische product te bestellen. ';
		}

		if ( ! is_b2b_customer() and ! does_risky_delivery() and $product->get_shipping_class() === 'breekbaar' ) {
			$output .= 'Opgelet: dit product kan enkel afgehaald worden in de winkel! ';
			if ( get_term( $cat_ids[0], 'product_cat' )->slug === 'bier' ) {
				$output .= 'Tip: losse bierflesjes zijn wel beschikbaar voor thuislevering.';
			}
			if ( get_term( $parent_id, 'product_cat' )->slug === 'fruitsap' ) {
				$output .= 'Tip: tetrabrikken en kleine sapflesjes zijn wel beschikbaar voor thuislevering.';
			}
		}

		if ( $output !== '' ) {
			echo '<p style="margin: 1em 0;">'.$output.'</p>';
		}
	}

	// Creëer een custom hiërarchische taxonomie op producten om partner/landinfo in op te slaan
	add_action( 'init', 'register_partner_taxonomy', 0 );
	
	function register_partner_taxonomy() {
		$taxonomy_name = 'product_partner';
		
		$labels = array(
			'name' => 'Partners',
			'singular_name' => 'Partner',
			'all_items' => 'Alle partners',
			'parent_item' => 'Land',
			'parent_item_colon' => 'Land:',
			'new_item_name' => 'Nieuwe partner',
			'add_new_item' => 'Voeg nieuwe partner toe',
		);

		$args = array(
			'labels' => $labels,
			'description' => 'Ken het product toe aan een partner/land',
			'public' => true,
			'publicly_queryable' => true,
			'hierarchical' => true,
			'show_ui' => true,
			'show_in_menu' => true,
			'show_in_nav_menus' => true,
			'show_in_rest' => true,
			'show_tagcloud' => true,
			'show_in_quick_edit' => true,
			'show_admin_column' => true,
			'query_var' => true,
			'capabilities' => array( 'manage_terms' => 'create_sites', 'edit_terms' => 'create_sites', 'delete_terms' => 'create_sites', 'assign_terms' => 'edit_products' ),
			'rewrite' => array( 'slug' => 'partner', 'with_front' => false, 'ep_mask' => 'test' ),
		);

		register_taxonomy( $taxonomy_name, 'product', $args );
		register_taxonomy_for_object_type( $taxonomy_name, 'product' );
	}

	// Maak onze custom taxonomiën beschikbaar in menu editor
	add_filter( 'woocommerce_attribute_show_in_nav_menus', 'register_custom_taxonomies_for_menus', 1, 2 );

	function register_custom_taxonomies_for_menus( $register, $name = '' ) {
		return true;
	}

	// Vermijd dat geselecteerde termen in hiërarchische taxonomieën naar boven springen
	add_filter( 'wp_terms_checklist_args', 'do_not_jump_to_top', 10, 2 );

	function do_not_jump_to_top( $args, $post_id ) {
		if ( is_admin() ) {
			$args['checked_ontop'] = false;
		}
		return $args;
	}

	// Registreer een extra tabje op de productdetailpagina voor de voedingswaardes
	add_filter( 'woocommerce_product_tabs', 'add_extra_product_tabs' );
	
	function add_extra_product_tabs( $tabs ) {
		global $product;
		// Voeg altijd tabje met herkomstinfo toe
		$tabs['partner_info'] = array(
			'title' 	=> 'Partnerinfo',
			'priority' 	=> 12,
			'callback' 	=> function() { output_tab_content('partner'); },
		);

		// Voeg tabje met voedingswaarde toe (indien niet leeg)
		if ( get_tab_content('food') !== false ) {
			$eh = $product->get_attribute( 'pa_eenheid' );
			if ( $eh === 'L' ) {
				$suffix = 'ml';
			} elseif ( $eh === 'KG' ) {
				$suffix = 'g';
			}
			$tabs['food_info'] = array(
				'title' 	=> 'Voedingswaarde per 100 '.$suffix,
				'priority' 	=> 14,
				'callback' 	=> function() { output_tab_content('food'); },
			);
		}

		// Voeg tabje met allergenen toe (indien niet leeg)
		if ( get_tab_content('allergen') !== false ) {
			$tabs['allergen_info'] = array(
				'title' 	=> 'Allergenen',
				'priority' 	=> 16,
				'callback' 	=> function() { output_tab_content('allergen'); },
			);
		}

		// Titel wijzigen van standaardtabs kan maar prioriteit niet! (description = 10, additional_information = 20)
		$tabs['additional_information']['title'] = 'Technische fiche';
		
		return $tabs;
	}

	// Lijst van alle keys en labels die op het voedingstabblad kunnen verschijnen en via WC API extern opgehaald worden
	$food_api_labels = array(
		'_ingredients' => 'Ingrediënten',
		'_energy' => 'Energie',
		'_fat' => 'Vetten',
		'_fasat' => 'waarvan verzadigde vetzuren',
		'_famscis' => 'waarvan enkelvoudig onverzadigde vetzuren',
		'_fapucis' => 'waarvan meervoudig onverzadigde vetzuren',
		'_choavl' => 'Koolhydraten',
		'_sugar' => 'waarvan suikers',
		'_polyl' => 'waarvan polyolen',
		'_starch' => 'waarvan zetmeel',
		'_fibtg' => 'Vezels',
		'_pro' => 'Eiwitten',
		'_salteq' => 'Zout',
	);
	$food_required_keys = array( '_fat', '_fasat', '_choavl', '_sugar', '_pro', '_salteq' );
	$food_secondary_keys = array( '_fasat', '_famscis', '_fapucis', '_sugar', '_polyl', '_starch' );

	// Haal de legende op die bij een gegeven ingrediëntenlijst hoort
	function get_ingredients_legend( $ingredients ) {
		$legend = array();
		if ( ! empty( $ingredients ) ) {
			if ( strpos( $ingredients, '*' ) !== false ) {
				$legend[] = '* '.__( 'ingrediënt uit een eerlijke handelsrelatie', 'oxfam-webshop' );
			}
			if ( strpos( $ingredients, '°' ) !== false ) {
				$legend[] = '° '.__( 'ingrediënt van biologische landbouw', 'oxfam-webshop' );
			}
			if ( strpos( $ingredients, '†' ) !== false ) {
				$legend[] = '† '.__( 'ingrediënt verkregen in de periode van omschakeling naar biologische landbouw', 'oxfam-webshop' );
			}
		}
		return $legend;
	}

	// Retourneer de gegevens voor een custom tab (antwoordt met FALSE indien geen gegevens beschikbaar)
	function get_tab_content( $type ) {
		global $product;
		$has_row = false;
		$alt = 1;
		ob_start();
		echo '<table class="shop_attributes">';

		if ( $type === 'partner' ) {
			$str = 'partners';

			$partners = get_partner_terms_by_product( $product );
			if ( count($partners) > 0 ) {
				$has_row = true;

				if ( count($partners) === 1 ) $str = 'een partner';
				?>
					<tr class="<?php if ( ( $alt = $alt * -1 ) == 1 ) echo 'alt'; ?>">
						<th>Partners</th>
						<td>
						<?php
							$i = 1;
							$msg = "";
							foreach ( $partners as $term_id => $partner_name ) {
								$partner_info = get_info_by_partner( get_term_by( 'id', $term_id, 'product_partner' ) );
								
								if ( isset( $partner_info['archive'] ) and strlen( $partner_info['archive'] ) > 10 ) {
									$text = '<a href="'.$partner_info['archive'].'" title="Bekijk alle producten van deze partner">'.$partner_info['name'].'</a>';
								} else {
									$text = $partner_info['name'];
								}
								
								if ( $i !== 1 ) $msg .= '<br/>';
								$msg .= $text." &mdash; ".$partner_info['country'];
								$i++;
							}
							echo $msg;
						?>
						</td>
					</tr>
				<?php
			}
			
			// Enkel tonen indien percentage bekend 
			if ( intval( $product->get_attribute( 'pa_fairtrade' ) ) > 40 ) {
			?>
				<tr class="<?php if ( ( $alt = $alt * -1 ) == 1 ) echo 'alt'; ?>">
					<th><?php echo 'Fairtradepercentage'; ?></th>
					<td><?php echo 'Dit product is voor '.number_format( $product->get_attribute( 'pa_fairtrade' ), 0 ).' % afkomstig van '.$str.' waarmee Oxfam-Wereldwinkels een eerlijke handelsrelatie onderhoudt. <a href="https://www.oxfamwereldwinkels.be/expertise/labels/" target="_blank">Lees meer over deze certificering op onze website.</a>'; ?></td>
				</tr>
			<?php	
			}
			
		} else {
			
			if ( false === ( $oft_quality_data = get_site_transient( $product->get_sku().'_quality_data' ) ) ) {

				// Haal de kwaliteitsdata op in de OFT-site indien ze nog niet gecached werd in een transient 
				require_once WP_CONTENT_DIR.'/wc-api/autoload.php';
				$logger = wc_get_logger();
				$context = array( 'source' => 'Quality' );
				$oft_db = new Client(
					'https://www.oxfamfairtrade.be', OFT_WC_KEY, OFT_WC_SECRET,
					[
						'wp_api' => true,
						'version' => 'wc/v2',
						'query_string_auth' => true,
					]
				);
				// Trash wordt niet doorzocht via SKU
				$params = array( 'status' => 'any', 'sku' => $product->get_sku(), 'lang' => 'nl' );
				
				try {
					global $food_api_labels;
					$oft_products = $oft_db->get( 'products', $params );
					$last_response = $oft_db->http->getResponse();
					
					if ( $last_response->getCode() === 200 ) {
						if ( count($oft_products) > 1 ) {
							$logger->alert( 'Multiple results found for SKU '.$product->get_sku().' in OFT database', $context );
						} else {
							$oft_product = reset($oft_products);
							
							if ( $oft_product === false ) {	
								// Indien we de oude product-ID uit de OFT-database hebben, kunnen we $oft_product langs deze weg nog rechtstreeks opvullen
								if ( $product->meta_exists('oft_product_id') and intval( $product->get_meta('oft_product_id') ) > 0 ) {
									$oft_product = $oft_db->get( 'products/'.$product->get_meta('oft_product_id') );
									$last_response = $oft_db->http->getResponse();

									if ( $last_response->getCode() === 200 ) {
										$logger->notice( 'SKU '.$product->get_sku().' queried by ID in OFT database', $context );
									} else {
										$oft_product = false;
										$logger->alert( 'SKU '.$product->get_sku().' could not be queried by ID in OFT database', $context );
									}
								} else {
									$logger->notice( 'SKU '.$product->get_sku().' not found in OFT database', $context );
								}
							}

							if ( $oft_product !== false ) {	
								
								// Stop voedingswaarden én ingrediënten in een array met als keys de namen van de eigenschappen
								foreach ( $oft_product->meta_data as $meta_data ) {
									if ( array_key_exists( $meta_data->key, $food_api_labels ) ) {
										$oft_quality_data['food'][$meta_data->key] = $meta_data->value;
									}
								}

								// Stop allergenen in een array met als keys de slugs van de allergenen
								foreach ( $oft_product->product_allergen as $product_allergen ) {
									$oft_quality_data['allergen'][$product_allergen->slug] = $product_allergen->name;
								}

								set_site_transient( $product->get_sku().'_quality_data', $oft_quality_data, DAY_IN_SECONDS );

							}
						}
					} else {
						$logger->alert( 'API response code was '.$last_response->getCode(), $context );
					}
				} catch ( HttpClientException $e ) {
					$logger->alert( $e->getMessage(), $context );
				}
			}

			// Check of de data inmiddels wel beschikbaar is
			if ( $oft_quality_data ) {
				
				if ( $type === 'food' ) {
					
					// Check of er voedingswaarden zijn, tonen van zodra energie ingevuld is
					if ( array_key_exists( 'food', $oft_quality_data ) and floatval($oft_quality_data['food']['_energy']) > 0 ) {
						global $food_api_labels, $food_required_keys, $food_secondary_keys;
						$has_row = true;

						wc_print_r( $oft_quality_data, true );

						foreach ( $food_api_labels as $food_key => $food_label ) {
							// Toon voedingswaarde als het een verplicht veld is en in 2de instantie als er expliciet een (nul)waarde ingesteld is
							if ( in_array( $food_key, $food_required_keys ) or array_key_exists( $food_key, $oft_quality_data['food'] ) ) {
								$food_value = '';
								if ( array_key_exists( $food_key, $oft_quality_data['food'] ) ) {
									// Vul de waarde in uit de database
									$food_value = $oft_quality_data['food'][$food_key];
								}

								if ( floatval($food_value) > 0 ) {
									// Formatter het getal als Belgische tekst
									$food_value = str_replace( '.', ',', $food_value );
								} elseif ( in_array( $food_key, $food_required_keys ) ) {
									// Zet een nul (zonder expliciete precisie)
									$food_value = '0';
								} else {
									// Rij niet tonen, skip naar volgende key
									continue;
								}
								?>
								<tr class="<?php if ( ( $alt = $alt * -1 ) == 1 ) echo 'alt'; ?>">
									<th class="<?php echo in_array( $food_key, $food_secondary_keys ) ? 'secondary' : 'primary'; ?>"><?php echo $food_label; ?></th>
									<td class="<?php echo in_array( $food_key, $food_secondary_keys ) ? 'secondary' : 'primary'; ?>"><?php
										if ( $food_key === '_energy' ) {
											echo $food_value.' kJ';
										} else {
											echo $food_value.' g';
										}
									?></td>
								</tr>
								<?php
							}
						}
					}

				} elseif ( $type === 'allergen' ) {

					if ( array_key_exists( 'food', $oft_quality_data ) and array_key_exists( '_ingredients', $oft_quality_data['food'] ) ) {
						$ingredients = $oft_quality_data['food']['_ingredients'];
						if ( strlen($ingredients) > 3 ) {
							// Ingrediënteninfo beschikbaar, dus tabje tonen
							$has_row = true;
							?>
							<tr class="<?php if ( ( $alt = $alt * -1 ) == 1 ) echo 'alt'; ?>">
								<th><?php echo 'Ingrediënten'; ?></th>
								<td><?php 
									echo $ingredients;
									if ( get_ingredients_legend($ingredients) ) {
										echo '<small class="ingredients">'.implode( '<br/>', get_ingredients_legend($ingredients) ).'</small>';
									}
								?></td>
							</tr>
							<?php
						}
					}

					$contains = array();
					$traces = array();
					$no_allergens = false;
					if ( array_key_exists( 'allergen', $oft_quality_data ) ) {
						// Allergeneninfo beschikbaar, dus tabje tonen
						$has_row = true;
						foreach ( $oft_quality_data['allergen'] as $slug => $name ) {
							$parts = explode( '-', $slug );
							if ( $parts[0] === 'c' ) {
								$contains[] = $name;
							} elseif ( $parts[0] === 'mc' ) {
								$traces[] = $name;
							} elseif ( $parts[0] === 'none' ) {
								$no_allergens = true;
							}
						}
					} else {
						write_log( "ERROR: NO ALLERGEN TERMS SET YET FOR SKU ".$product->get_sku() );
					}
					?>
					<tr class="<?php if ( ( $alt = $alt * -1 ) == 1 ) echo 'alt'; ?>">
						<th><?php echo 'Dit product bevat'; ?></th>
						<td>
						<?php
							if ( count($contains) > 0 ) {
								echo implode( ', ', $contains );
							} else {
								if ( $no_allergens === true or count($traces) > 0 ) {
									echo 'geen meldingsplichtige allergenen';
								} else {
									echo '/';
								}
							}
						?>
						</td>
					</tr>

					<tr class="<?php if ( ( $alt = $alt * -1 ) == 1 ) echo 'alt'; ?>">
						<th><?php echo 'Kan sporen bevatten van'; ?></th>
						<td>
						<?php
							if ( count($traces) > 0 ) {
								echo implode( ', ', $traces );
							} else {
								if ( $no_allergens === true or count($contains) > 0 ) {
									echo 'geen meldingsplichtige allergenen';
								} else {
									echo '/';
								}
							}
						?>
						</td>
					</tr>
					<?php
				}
			}
		}
		
		echo '</table>';
		
		if ( $has_row ) {
			return ob_get_clean();
		} else {
			ob_end_clean();
			return false;
		}
	}

	// Print de inhoud van een tab
	function output_tab_content( $type ) {
		if ( get_tab_content( $type ) !== false ) {
			echo '<div class="nm-additional-information-inner">'.get_tab_content( $type ).'</div>';
		} else {
			echo '<div class="nm-additional-information-inner"><i>Geen info beschikbaar.</i></div>';
		}
	}

	// Retourneert een array met strings van landen waaruit dit product afkomstig is (en anders false)
	function get_countries_by_product( $product ) {
		$terms = get_the_terms( $product->get_id(), 'product_partner' );
		$args = array( 'taxonomy' => 'product_partner', 'parent' => 0, 'hide_empty' => false, 'fields' => 'ids' );
		$continents = get_terms( $args );
		
		if ( count($terms) > 0 ) {
			foreach ( $terms as $term ) {
				if ( ! in_array( $term->parent, $continents, true ) ) {
					// De bovenliggende term is geen continent, dus het is een partner!
					$parent_term = get_term_by( 'id', $term->parent, 'product_partner' );
					// Voeg de naam van de bovenliggende term (= land) toe aan het lijstje
					$countries[] = $parent_term->name;
				} else {
					// In dit geval is het zeker een land (en zeker geen continent zijn want checkboxes uitgeschakeld + enkel gelinkt aan laagste term)
					$countries[] = $term->name;
				}
			}
			// Ontdubbel de landen en sorteer values alfabetisch
			$countries = array_unique( $countries );
			sort( $countries, SORT_STRING );
		} else {
			// Fallback indien nog geen herkomstinfo bekend
			$countries = false;
		}
		
		return $countries;
	}

	// Retourneert een array term_id => name van de partners die bijdragen aan het product
	function get_partner_terms_by_product( $product ) {
		// Vraag alle partnertermen op die gelinkt zijn aan dit product (helaas geen filterargumenten beschikbaar)
		// Producten worden door de import enkel aan de laagste hiërarchische term gelinkt, dus dit zijn per definitie landen of partners!
		$terms = get_the_terms( $product->get_id(), 'product_partner' );
		
		// Vraag de term-ID's van de continenten in deze site op
		$args = array( 'taxonomy' => 'product_partner', 'parent' => 0, 'hide_empty' => false, 'fields' => 'ids' );
		$continents = get_terms( $args );
		$partners = array();
		
		if ( is_array($terms) and count($terms) > 0 ) {
			foreach ( $terms as $term ) {
				if ( ! in_array( $term->parent, $continents, true ) ) {
					// De bovenliggende term is geen continent, dus het is een partner!
					$partners[$term->term_id] = $term->name;
				}
			}
		}

		// Sorteer alfabetisch op value (= partnernaam) maar bewaar de index (= term-ID)
		asort($partners);
		return $partners;
	}

	// Retourneert zo veel mogelijk beschikbare info bij een partner (enkel naam en land steeds ingesteld!)
	function get_info_by_partner( $partner ) {
		$partner_info['name'] = $partner->name;
		$partner_info['country'] = get_term_by( 'id', $partner->parent, 'product_partner' )->name;
		$partner_info['archive'] = get_term_link( $partner->term_id );
		
		if ( strlen( $partner->description ) > 20 ) {
			// Check of er een link naar een partnerpagina aanwezig is
			$parts = explode( '/partners/', $partner->description );
			if ( count( $parts ) >= 2 ) {
				// Knip alles weg na de eindslash van de URL
				$slugs = explode( '/', $parts[1] );
				// Fallback: knip alles weg na de afsluitende dubbele quote van het href-attribuut
				$slugs = explode( '"', $slugs[0] );
				$partner_info = array_merge( $partner_info, get_external_partner( $slugs[0] ) );
			} else {
				// Fallback: zet de naam van de partner om in een slug
				$partner_info = array_merge( $partner_info, get_external_partner( $partner->name ) );
			}
		}

		return $partner_info;
	}

	// Vervroeg actie zodat ze ook in de linkerkolom belandt op tablet (blijkbaar alles t.e.m. prioriteit 15)
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20 );
	add_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 12 );
	
	// Herkomstlanden en promoties net boven de winkelmandknop tonen
	add_action( 'woocommerce_single_product_summary', 'show_product_origin', 14 );

	function show_product_origin() {
		global $product;
		if ( $product->get_meta('_herkomst_nl') !== '' ) {
			echo '<p class="herkomst">';
				echo 'Herkomst: '.$product->get_meta('_herkomst_nl');
			echo '</p>';
		}
		if ( ! is_b2b_customer() ) {
			// Opgelet: nu verbergen we alle promotekstjes voor B2B-klanten, ook indien er een coupon met 'b2b' aangemaakt zou zijn
			if ( $product->is_on_sale() and $product->get_meta('promo_text') !== '' ) {
				echo '<p class="promotie">';
					// Check of de noussines voor de koffieactie wel op voorraad zijn
					$product_id = wc_get_product_id_by_sku('24501');
					$noussines = wc_get_product( $product_id );
					if ( date_i18n('Y-m-d') < '2020-02-16' and $noussines !== false and ! $noussines->is_in_stock() ) {
						echo 'De koffieactie is momenteel helaas niet beschikbaar omdat onze voorraad noussines uitgeput is.';
					} else {
						echo $product->get_meta('promo_text').' Geldig t.e.m. '.$product->get_date_on_sale_to()->date_i18n('l j F Y').'. Niet van toepassing bij verkoop op factuur.';
					}
				echo '</p>';
			}
		}
	}

	// Partnerquote tonen, net onder de winkelmandknop
	add_action( 'woocommerce_single_product_summary', 'show_random_partner_quote', 75 );

	function show_random_partner_quote() {
		global $product;
		$partners = get_partner_terms_by_product( $product );
		if ( count( $partners ) > 0 ) {
			$partners_with_quote = array();
			// Sla enkel de partners op waarvan de info een ondertekende quote bevat 
			foreach ( $partners as $term_id => $partner_name ) {
				$partner_info = get_info_by_partner( get_term_by( 'id', $term_id, 'product_partner' ) );
				if ( isset( $partner_info['quote'] ) and strlen( $partner_info['quote'] ) > 5 ) {
					$partners_with_quote[] = $partner_info;
				}
			}
			// Toon een random quote
			if ( count( $partners_with_quote ) > 0 ) {
				$i = random_int( 0, count($partners_with_quote) - 1 );
				if ( isset( $partner_info['quote_by'] ) and strlen( $partner_info['quote_by'] ) > 5 ) {
					$signature = $partners_with_quote[$i]['quote_by'];
				} else {
					$signature = $partners_with_quote[$i]['name'].', '.$partners_with_quote[$i]['country'];
				}
				echo nm_shortcode_nm_testimonial( array( 'signature' => $signature, 'image_url' => $partners_with_quote[$i]['quote_photo'], 'link' => $partners_with_quote[$i]['url'] ), $partners_with_quote[$i]['quote'] );
			}
		}
	}

	// Formatteer de gewichten in de attributen
	add_filter( 'woocommerce_attribute', 'add_suffixes', 10, 3 );

	function add_suffixes( $wpautop, $attribute, $values ) {
		$weighty_attributes = array( 'pa_choavl', 'pa_famscis', 'pa_fapucis', 'pa_fasat', 'pa_fat', 'pa_fibtg', 'pa_polyl', 'pa_pro', 'pa_salteq', 'pa_starch', 'pa_sugar' );
		$percenty_attributes = array( 'pa_alcohol', 'pa_fairtrade' );

		global $product;
		$eh = $product->get_attribute('pa_eenheid');
		if ( $eh === 'L' ) {
			$suffix = 'liter';
		} elseif ( $eh === 'KG' ) {
			$suffix = 'kilogram';
		}

		if ( in_array( $attribute['name'], $weighty_attributes ) ) {
			$values[0] = str_replace('.', ',', $values[0]).' g';
		} elseif ( in_array( $attribute['name'], $percenty_attributes ) ) {
			$values[0] = number_format( str_replace( ',', '.', $values[0] ), 1, ',', '.' ).' %';
		} elseif ( $attribute['name'] === 'pa_eprijs' ) {
			$values[0] = '&euro; '.number_format( str_replace( ',', '.', $values[0] ), 2, ',', '.' ).' per '.$suffix;
		} elseif ( $attribute['name'] === 'pa_ompak' ) {
			$values[0] = $values[0].' stuks';
		}

		$wpautop = wpautop( wptexturize( implode( ', ', $values ) ) );
		return $wpautop;
	}



	#############
	# MULTISITE #
	#############

	// Verhinder dat de lokale voorraad- en uitlichtingsinstellingen overschreven worden bij elke update
	add_filter( 'woo_mstore/save_meta_to_post/ignore_meta_fields', 'ignore_featured_and_stock', 10, 2 );

	function ignore_featured_and_stock( $ignored_fields, $post_id ) {
		$ignored_fields[] = 'total_sales';
		$ignored_fields[] = '_stock';
		$ignored_fields[] = '_stock_status';
		$ignored_fields[] = '_wc_review_count';
		$ignored_fields[] = '_wc_rating_count';
		$ignored_fields[] = '_wc_average_rating';
		$ignored_fields[] = '_in_bestelweb';

		return $ignored_fields;
	}

	// Zorg dat productupdates ook gesynchroniseerd worden via WP All Import (hoge prioriteit = helemaal op het einde)
	add_action( 'pmxi_saved_post', 'run_product_sync', 50, 1 );
	
	function run_product_sync( $post_id ) {
		// Enkel uitvoeren indien het een product was dat bijgewerkt werd
		if ( get_post_type( $post_id ) === 'product' ) {
			global $WOO_MSTORE;
			$WOO_MSTORE->quick_edit_save( $post_id, get_post( $post_id ), true );
		}
	}

	function translate_to_fr( $code ) {
		$fr = get_site_option( 'countries_fr' );
		return $fr[$code];
	}

	function translate_to_en( $code ) {
		$en = get_site_option( 'countries_en' );
		return $en[$code];
	}

	// Reset alle '_in_bestelweb' velden voor we aan de ERP-import beginnen
	add_action( 'pmxi_before_xml_import', 'before_xml_import', 10, 1 );
	
	function before_xml_import( $import_id ) {
		if ( $import_id == 7 ) {
			// Zet de key '_in_bestelweb' van alle producten op nee
			$args = array(
				'post_type'			=> 'product',
				'post_status'		=> array( 'publish', 'draft', 'trash' ),
				'posts_per_page'	=> -1,
			);

			$to_remove = new WP_Query( $args );

			if ( $to_remove->have_posts() ) {
				while ( $to_remove->have_posts() ) {
					$to_remove->the_post();
					update_post_meta( get_the_ID(), '_in_bestelweb', 'nee' );
				}
				wp_reset_postdata();
			}
		}
	}

	// Zet producten die onaangeroerd bleven door de ERP-import uit voorraad
	add_action( 'pmxi_after_xml_import', 'after_xml_import', 10, 1 );
	
	function after_xml_import( $import_id ) {
		if ( $import_id == 7 ) {
			// Vind alle producten waarvan de key '_in_bestelweb' onaangeroerd is (= zat niet in Odisy-import)
			$args = array(
				'post_type'			=> 'product',
				'post_status'		=> array( 'publish', 'draft', 'trash' ),
				'posts_per_page'	=> -1,
				'meta_key'			=> '_in_bestelweb', 
				'meta_value'		=> 'nee',
				'meta_compare'		=> '=',
			);

			$to_outofstock = new WP_Query( $args );

			if ( $to_outofstock->have_posts() ) {
				while ( $to_outofstock->have_posts() ) {
					$to_outofstock->the_post();
					$productje = wc_get_product( get_the_ID() );
					$productje->set_stock_status('outofstock');
					$productje->save();
				}
				wp_reset_postdata();
			}

			// Hernoem het importbestand zodat we een snapshot krijgen dat niet overschreven wordt
			$old = WP_CONTENT_DIR."/erp-import.csv";
			$new = WP_CONTENT_DIR."/erp-import-".date_i18n('Y-m-d').".csv";
			rename( $old, $new );
		}
	}

	// Functie die product-ID's van de hoofdsite vertaalt en het metaveld opslaat in de huidige subsite (op basis van artikelnummer)
	/**
	* @param int $local_product_id
	* @param string $meta_key
	* @param array $product_meta_item_row
	*/	
	function translate_main_to_local_ids( $local_product_id, $meta_key, $product_meta_item_row ) {
		write_log("MAAK POST ".get_the_ID()." LOKAAL IN BLOG ".get_current_blog_id());
		if ( $product_meta_item_row ) {
			foreach ( $product_meta_item_row as $main_product_id ) {
				switch_to_blog(1);
				$main_product = wc_get_product( $main_product_id );
				restore_current_blog();
				$local_product_ids[] = wc_get_product_id_by_sku( $main_product->get_sku() );
			}
			// Niet serialiseren voor coupons
			$coupon_keys = array( 'product_ids', 'exclude_product_ids', '_wjecf_free_product_ids' );
			if ( in_array( $meta_key, $coupon_keys ) ) {
				$local_product_ids = implode( ',', $local_product_ids );
			}
			update_post_meta( $local_product_id, $meta_key, $local_product_ids );
		} else {
			// Zorg ervoor dat het veld ook bij de child geleegd wordt!
			update_post_meta( $local_product_id, $meta_key, null );
		}
	}



	################
	# COMMUNICATIE #
	################

	// Voeg een custom dashboard widget toe met nieuws over het pilootproject
	add_action( 'wp_dashboard_setup', 'add_pilot_widget' );

	function add_pilot_widget() {
		global $wp_meta_boxes;

		wp_add_dashboard_widget(
			'dashboard_pilot_news_widget',
			'Info voor webshopmedewerkers',
			'dashboard_pilot_news_widget_function'
		);

		$dashboard = $wp_meta_boxes['dashboard']['normal']['core'];

		$my_widget = array( 'dashboard_pilot_news_widget' => $dashboard['dashboard_pilot_news_widget'] );
		unset( $dashboard['dashboard_pilot_news_widget'] );

		$sorted_dashboard = array_merge( $my_widget, $dashboard );
		$wp_meta_boxes['dashboard']['normal']['core'] = $sorted_dashboard;
	}
	
	// Stel de inhoud van de widget op
	function dashboard_pilot_news_widget_function() {
		echo '<div class="rss-widget">';
		echo '<p>De <a href="https://github.com/OxfamFairTrade/ob2c/wiki" target="_blank">online FAQ voor webshopbeheerders</a> staat online. Hierin verzamelen we alle mogelijke vragen die jullie als lokale webshopbeheerders kunnen hebben en beantwoorden we ze punt per punt met tekst en screenshots. Gebruik eventueel de zoekfunctie bovenaan rechts.</p>';
		echo '<p>Daarnaast kun je de nieuwe slides van de voorbije opleidingssessies raadplegen voor een overzicht van alle afspraken en praktische details: <a href="https://shop.oxfamwereldwinkels.be/wp-content/uploads/slides-opleiding-B2C-webshop-concept.pdf" target="_blank">Deel 1: Concept</a> (16/05/2020) en <a href="https://shop.oxfamwereldwinkels.be/wp-content/uploads/slides-opleiding-B2C-webshop-praktisch.pdf" target="_blank">Deel 2: Praktisch</a> (30/05/2020). Op <a href="https://copain.oww.be/webshop" target="_blank">de webshoppagina op Copain</a> vind je een overzicht van de belangrijkste documenten.</p>';
		echo '<p>Stuur een mailtje naar <a href="mailto:e-commerce@oft.be?">e-commerce@oft.be</a> als er toch nog iets onduidelijk is, of als je een suggestie hebt. Voor dringende problemen mag je ook telefonisch contact opnemen met Frederik Neirynck via <a href="tel:+3292188863">09/218.88.63</a>.</p>';
		echo '</div>';
	}

	function get_tracking_number( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$tracking_number = false;
		// Query alle order comments waarin het over Bpost gaat en zet de oudste bovenaan
		$args = array( 'post_id' => $order->get_id(), 'type' => 'order_note', 'orderby' => 'comment_date_gmt', 'order' => 'ASC', 'search' => 'bpost' );
		// Want anders zien we de private opmerkingen niet!
		remove_filter( 'comments_clauses', array( 'WC_Comments', 'exclude_order_comments' ) );
		$comments = get_comments( $args );
		if ( count($comments) > 0 ) {
			foreach ( $comments as $bpost_note ) {
				// Check of we een 24-cijferig tracking number kunnen terugvinden
				if ( preg_match( '/[0-9]{24}/', $bpost_note->comment_content, $numbers ) === 1 ) {
					// Waarde in meest recente comment zal geretourneerd worden!
					$tracking_number = $numbers[0];
				}
			}
		}
		// Reactiveer filter
		add_filter( 'comments_clauses', array( 'WC_Comments', 'exclude_order_comments' ) );
		return $tracking_number;
	}

	function get_tracking_link( $tracking_number, $order ) {
		return 'https://track.bpost.cloud/btr/web/#/search?itemCode='.$tracking_number.'&postalCode='.$order->get_shipping_postcode().'&lang=nl';
	}

	function get_logistic_params( $order, $echo = false ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$params = array();
		$params['volume'] = 0.0;
		$params['maximum'] = 0.0;
		$params['weight'] = 0.0;
		
		foreach ( $order->get_items() as $line_item ) {
			if ( false !== ( $product = $line_item->get_product() ) ) {
				$volume = 1.0;
				
				if ( ( $length = floatval( $product->get_length() ) ) > 0 ) {
					$volume *= $length;
					if ( $length > $params['maximum'] ) {
						$params['maximum'] = $length;
					}
				}
				if ( ( $width = floatval( $product->get_width() ) ) > 0 ) {
					$volume *= $width;
					if ( $width > $params['maximum'] ) {
						$params['maximum'] = $width;
					}
				}
				if ( ( $height = floatval( $product->get_height() ) ) > 0 ) {
					$volume *= $height;
					if ( $height > $params['maximum'] ) {
						$params['maximum'] = $height;
					}
				}

				if ( $echo ) {
					echo $product->get_name().': '.number_format( $volume / 1000000, 2, ',', '.' ).' liter (x'.$line_item->get_quantity().')<br/>';
				}
				$params['volume'] += $line_item->get_quantity() * $volume;
				$params['weight'] += $line_item->get_quantity() * floatval( $product->get_weight() );
			} 
		}

		// Volume omrekenen van kubieke millimeters naar liter
		$params['volume'] /= 1000000;
		// Maximale afmeting omrekenen naar cm
		$params['maximum'] = ceil( $params['maximum'] / 10 );
		// Gewicht sowieso reeds in kilogram (maar check instellingen?)

		return $params;
	}
	
	// Voeg berichten toe bovenaan adminpagina's
	add_action( 'admin_head', 'show_only_oxfam_notices', 10000 );

	function show_only_oxfam_notices() {
		// Aanpassingen voor iPhone 10+
		echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';

		// Gelijkaardige 'Show plugins/themes notices to admin only'-optie van User Role Editor niet inschakelen!
		if ( ! current_user_can('create_sites') ) {
			remove_all_actions('admin_notices');
		}
		add_action( 'admin_notices', 'oxfam_admin_notices' );
	}

	function oxfam_admin_notices() {
		global $pagenow, $post_type;
		$screen = get_current_screen();
		if ( $pagenow === 'index.php' and $screen->base === 'dashboard' ) {
			if ( in_array( get_current_blog_id(), get_site_option('oxfam_blocked_sites') ) ) {
				echo '<div class="notice notice-error">';
					echo '<p>Deze site is momentaal afgeschermd voor het grote publiek (inloggen verplicht) en verschijnt niet op de kaart!</p>';
				echo '</div>';
			}
			if ( get_option('mollie-payments-for-woocommerce_test_mode_enabled') === 'yes' ) {
				echo '<div class="notice notice-success">';
					echo '<p>De betalingen op deze site staan momenteel in testmodus! Voel je vrij om naar hartelust te experimenteren met bestellingen.</p>';
				echo '</div>';
			}
			if ( get_current_site()->domain === 'shop.oxfamwereldwinkels.be' ) {
				echo '<div class="notice notice-success">';
					echo '<p>Automatische berichten vanuit de helpdeskmailbox gingen sinds enkele weken in de meeste gevallen rechtstreeks naar de spamfolder. Dit is nu verholpen.</p>';
				echo '</div>';
				echo '<div class="notice notice-info">';
					echo '<p>De talrijke promoties voor de maand juni werden ingesteld (zie <a href="https://copain.oww.be/k/n111/news/view/20167/1429/promo-s-1-1-online-winkel-juni-update-bier.html" target="_blank">Copain</a>). Opgelet: de 3+1 actie op de JUSTE bieren geldt zowel op clips (= rechtstreekse korting van 25%), op losse flesjes (= elk 4de flesje van dezelfde soort krijgt 100% korting) als op de nieuwe biercadeausets met één soort bier (= per set wordt een gratis flesje toegevoegd). Het leeggoed op alle gratis flesjes dient wel betaald te worden en wordt dus automatisch aangerekend door de webshop. De kortingsregels in de webshop stemmen overeen met de instructies voor ShopPlus.</p>';
				echo '</div>';
				echo '<div class="notice notice-success">';
					echo '<p>Er werden 5 nieuwe producten toegevoegd aan de database:</p><ul style="margin-left: 2em;">';
						$skus = array( '19236', '19237', '19238', '19239', '25013' );
						foreach ( $skus as $sku ) {
							$product_id = wc_get_product_id_by_sku($sku);
							if ( $product_id ) {
								$product = wc_get_product($product_id);
								echo '<li><a href="'.$product->get_permalink().'" target="_blank">'.$product->get_title().'</a> ('.$product->get_attribute('pa_shopplus').')</li>';
							}
						}
					echo '</ul><p>';
					if ( current_user_can('manage_network_users') ) {
						echo 'Je herkent deze producten aan de blauwe achtergrond onder \'<a href="admin.php?page=oxfam-products-list">Voorraadbeheer</a>\'. ';
					}
					echo 'Pas wanneer een beheerder ze in voorraad plaatst, worden deze producten ook zichtbaar en bestelbaar voor klanten. De paasfiguren werden verborgen.</p>';
				echo '</div>';
				if ( does_home_delivery() ) {
					// Boodschappen voor winkels die thuislevering doen
					// echo '<div class="notice notice-success">';
					// 	echo '<p>Om de sluiting van het wereldwinkelnetwerk te verzachten werden de verzendkosten in alle webshops verlaagd naar 4,95 i.p.v. 6,95 euro per bestelling én is gratis levering tijdelijk beschikaar vanaf 50 i.p.v. 100 euro.</p>';
					// echo '</div>';
				}
				// echo '<div class="notice notice-warning">';
				// 	echo '<p>3 aflopende producten werden uit de database verwijderd omdat ze eind juni vervielen. Het gaat om 24644 Hart melkchocolade, 24645 Minifiguurtjes melkchocolade 5 x 15 g en 24646 Beertje melkchocolade.</p>';
				// echo '</div>';
				if ( does_sendcloud_delivery() ) {
					// Boodschappen voor winkels die verzenden met SendCloud
				}
			}
		}
	}

	// Schakel onnuttige widgets uit voor iedereen
	add_action( 'admin_init', 'remove_dashboard_meta' );

	function remove_dashboard_meta() {
		remove_meta_box( 'dashboard_right_now', 'dashboard', 'normal' );
		remove_meta_box( 'dashboard_activity', 'dashboard', 'normal' );
		remove_meta_box( 'woocommerce_dashboard_recent_reviews', 'dashboard', 'normal' );
		remove_meta_box( 'dashboard_quick_press', 'dashboard', 'side' );
		remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );
		remove_meta_box( 'wpb_visual_composer', 'vc_grid_item', 'side' );
		remove_meta_box( 'wpb_visual_composer', 'vc_grid_item-network', 'side' );
		
		if ( ! current_user_can('create_sites') ) {
			remove_meta_box( 'dashboard_primary', 'dashboard-network', 'normal' );
			remove_meta_box( 'network_dashboard_right_now', 'dashboard-network', 'normal' );
			// Want lukt niet via URE Pro
			remove_meta_box( 'postcustom', 'shop_order', 'normal' );
		}

		remove_action( 'welcome_panel', 'wp_welcome_panel' );
	}

	// Beheerd via WooCommerce Order Status Manager of is dit voor het dashboard?
	// add_filter( 'woocommerce_reports_get_order_report_data_args', 'wc_reports_get_order_custom_report_data_args', 100, 1 );

	function wc_reports_get_order_custom_report_data_args( $args ) {
		$args['order_status'] = array( 'on-hold', 'processing', 'claimed', 'completed' );
		return $args;
	};

	function get_latest_newsletters_in_folder( $list_id = '5cce3040aa', $folder_id = 'bbc1d65c43' ) {
		$server = substr( MAILCHIMP_APIKEY, strpos( MAILCHIMP_APIKEY, '-' ) + 1 );

		$args = array(
			'headers' => array(
				'Authorization' => 'Basic '.base64_encode( 'user:'.MAILCHIMP_APIKEY ),
			),
		);

		$response = wp_remote_get( 'https://'.$server.'.api.mailchimp.com/3.0/campaigns?since_send_time='.date_i18n( 'Y-m-d', strtotime('-6 months') ).'&status=sent&list_id='.$list_id.'&folder_id='.$folder_id.'&sort_field=send_time&sort_dir=ASC', $args );

		$mailings = "";
		if ( $response['response']['code'] == 200 ) {
			$body = json_decode($response['body']);
			$mailings .= "<p>Dit zijn de nieuwsbrieven van de afgelopen zes maanden:</p>";
			$mailings .= "<ul style='margin-left: 20px; margin-bottom: 1em;'>";

			foreach ( array_reverse($body->campaigns) as $campaign ) {
				$mailings .= '<li><a href="'.$campaign->long_archive_url.'" target="_blank">'.$campaign->settings->subject_line.'</a> ('.date_i18n( 'j F Y', strtotime($campaign->send_time) ).')</li>';
			}

			$mailings .= "</ul>";
		}		

		return $mailings;
	}

	function get_mailchimp_status_in_list( $list_id = '5cce3040aa' ) {
		$current_user = wp_get_current_user();
		$email = $current_user->user_email;
		$response = get_mailchimp_response_by_email( $email );
		 
		$msg = "";
		if ( $response['response']['code'] == 200 ) {
			$body = json_decode( $response['body'] );

			if ( $body->status === "subscribed" ) {
				$msg .= "al geabonneerd op het Digizine. Aan het begin van elke maand ontvang je dus een (h)eerlijke mail boordevol fairtradenieuws.";
			} else {
				$msg .= "helaas niet langer geabonneerd op het Digizine. Vul <a href='https://oxfamwereldwinkels.us3.list-manage.com/subscribe?u=d66c099224e521aa1d87da403&id=".$list_id."&FNAME=".$current_user->user_firstname."&LNAME=".$current_user->user_lastname."&EMAIL=".$email."&SOURCE=webshop' target='_blank'>het formulier</a> in om op je stappen terug te keren!";
			}
		} else {
			$msg .= "nog nooit geabonneerd geweest op het Digzine. Vul <a href='https://oxfamwereldwinkels.us3.list-manage.com/subscribe?u=d66c099224e521aa1d87da403&id=".$list_id."&FNAME=".$current_user->user_firstname."&LNAME=".$current_user->user_lastname."&EMAIL=".$email."&SOURCE=webshop' target='_blank'>het formulier</a> in om daar verandering in te brengen!";
		}

		return "<p>Je bent met het e-mailadres <a href='mailto:".$email."' target='_blank'>".$email."</a> ".$msg."</p>";
	}

	function get_mailchimp_response_by_email( $email, $list_id = '5cce3040aa' ) {
		$server = substr( MAILCHIMP_APIKEY, strpos( MAILCHIMP_APIKEY, '-' ) + 1 );
		$member_id = md5( format_mail( $email ) );

		$args = array(
			'headers' => array(
				'Authorization' => 'Basic '.base64_encode( 'user:'.MAILCHIMP_APIKEY ),
			),
		);

		$response = wp_remote_get( 'https://'.$server.'.api.mailchimp.com/3.0/lists/'.$list_id.'/members/'.$member_id, $args );
		return $response;
	}



	##############
	# SHORTCODES #
	##############

	// Personaliseer de begroeting op de startpagina
	add_shortcode( 'topbar', 'print_greeting' );
	add_shortcode( 'copyright', 'print_copyright' );
	add_shortcode( 'straat', 'print_place' );
	add_shortcode( 'postcode', 'print_zipcode' );
	add_shortcode( 'gemeente', 'print_city' );
	add_shortcode( 'telefoon', 'print_telephone' );
	add_shortcode( 'e-mail', 'print_mail' );
	add_shortcode( 'openingsuren', 'print_office_hours' );
	add_shortcode( 'toon_titel', 'print_portal_title' );
	add_shortcode( 'toon_wc_notices', 'print_woocommerce_messages' );
	add_shortcode( 'toon_inleiding', 'print_welcome' );
	add_shortcode( 'toon_shops', 'print_store_selector' );
	// add_shortcode( 'toon_kaart', 'print_store_locator_map' );
	add_shortcode( 'toon_thuislevering', 'print_delivery_snippet' );
	add_shortcode( 'toon_postcodelijst', 'print_delivery_zips' );
	add_shortcode( 'toon_winkel_kaart', 'print_store_map' );
	add_shortcode( 'scrolltext', 'print_scroll_text' );
	add_shortcode( 'widget_usp', 'print_widget_usp' );
	add_shortcode( 'widget_delivery', 'print_widget_delivery' );
	add_shortcode( 'widget_contact', 'print_widget_contact' );
	add_shortcode( 'company_name', 'get_company_name' );
	add_shortcode( 'contact_address', 'get_company_contact' );
	add_shortcode( 'map_address', 'get_company_address' );
	add_shortcode( 'email_footer', 'get_company_and_year' );
	add_shortcode( 'email_header', 'get_local_logo_url' );
	add_shortcode( 'toon_eventueel_promos', 'show_conditional_promo_row' );

	function show_conditional_promo_row() {
		$args = array(
			// Parameter nog niet beschikbaar in WooCommerce 3.0!
			// 'stock_status' => 'instock',
			'include' => wc_get_product_ids_on_sale(),
		);
		$sale_products = wc_get_products( $args );
		if ( count( $sale_products ) > 2 ) {
			return do_shortcode('[vc_column][vc_column_text el_class="ob2c-category-title"]<h2 style="text-align: center;">Promoties</h2>[/vc_column_text][nm_product_slider shortcode="sale_products" per_page="-1" columns="4" orderby="rand" order="DESC" columns_mobile="1" arrows="1"][/vc_column]');
		} else {
			return '';
		}
	}

	function print_widget_usp() {
		return do_shortcode('[nm_feature icon="pe-7s-timer" layout="centered" title="'.__( 'Titel van unique selling point in footer', 'oxfam-webshop' ).'"]'.__( 'Inhoud van unique selling point in footer.', 'oxfam-webshop' ).'[/nm_feature]');
	}

	function print_widget_delivery() {
		if ( is_b2b_customer() ) {
			$text = __( 'Inhoud van praktisch blokje in footer (indien B2B-klant).', 'oxfam-webshop' );
		} elseif ( does_home_delivery() ) {
			$text = __( 'Inhoud van praktisch blokje in footer (indien ook thuislevering).', 'oxfam-webshop' );
		} else {
			$text = __( 'Inhoud van praktisch blokje in footer (inden enkel afhaling).', 'oxfam-webshop' );
		}
		return do_shortcode('[nm_feature icon="pe-7s-global" layout="centered" title="'.__( 'Titel van praktisch blokje in footer', 'oxfam-webshop' ).'"]'.$text.'[/nm_feature]');
	}

	function print_widget_contact() {
		return do_shortcode('[nm_feature icon="pe-7s-comment" layout="centered" title="'.__( 'Titel van contactblokje in footer', 'oxfam-webshop' ).'"]'.sprintf( __( 'Inhoud van het contactblokje in de footer. Bevat <a href="mailto:%1$s">een e-mailadres</a> en een aanklikbaar telefoonnummer (%2$s).', 'oxfam-webshop' ), get_company_email(), '<a href="tel:+32'.substr( preg_replace( '/[^0-9]/', '', get_oxfam_shop_data('telephone') ), 1 ).'">'.get_oxfam_shop_data('telephone').'</a>' ).'[/nm_feature]');
	}

	function print_greeting() {
		if ( date_i18n('G') < 6 ) {
			$greeting = "Goeienacht";
		} elseif ( date_i18n('G') < 12 ) {
			$greeting = "Goeiemorgen";
		} elseif ( date_i18n('G') < 20 ) {
			$greeting = "Goeiemiddag";
		} else {
			$greeting = "Goeieavond";
		}
		return sprintf( __( 'Verwelkoming (%1$s) van de bezoeker (%2$s) op de webshop (%3$s).', 'oxfam-webshop' ), $greeting, get_customer(), get_company_name() );
	}

	function get_customer() {
		global $current_user;
		return ( is_user_logged_in() and strlen($current_user->user_firstname) > 1 ) ? $current_user->user_firstname : "bezoeker";
	}

	function print_copyright() {
		$text = get_company_name().' &copy; 2017-'.date_i18n('Y');
		if ( ! is_main_site() ) {
			// Contactpagina niet linken op portaalpagina
			$text = '<a href="'.get_site_url( get_current_blog_id(), '/contact/' ).'">'.$text.'</a>';
		}
		return $text;
	}

	function print_office_hours( $atts = [] ) {
		// Overschrijf defaults met expliciete data van de gebruiker MAG VOORZICHTIG UITGEFASEERD WORDEN
		$atts = shortcode_atts( array( 'id' => get_option('oxfam_shop_post_id'), 'start' => 'today' ), $atts );
		
		$output = '';
		$days = get_office_hours( NULL, $atts['id'] );
		// Kijk niet naar sluitingsdagen bij winkels waar we expliciete afhaaluren ingesteld hebben
		$exceptions = array( 'dilbeek', 'hoogstraten', 'leuven', 'roeselare', 'brugge', 'knokke', 'gistel', 'evergem' );
		if ( in_array( $atts['id'], $exceptions ) ) {
			$holidays = array('2020-05-21');
		} else {
			// Uitzondering voor Borgerhout en Merksem
			if ( $atts['id'] == 3316 or $atts['id'] == 3646 ) {
				$holidays = get_site_option('oxfam_holidays');
			} else {
				// TO DO: Vervang dit door de expliciete 'closing_days' van de post-ID, want anders sluiten alle winkels van zodra de hoofdwinkel gesloten is, wat niet noodzakelijk klopt!
				$holidays = get_option( 'oxfam_holidays', get_site_option('oxfam_holidays') );
			}
		}

		if ( $atts['start'] === 'today' ) {
			// Begin met de weekdag van vandaag
			$start = intval( date('N') );
		} else {
			// Begin gewoon op maandag
			$start = 1;
		}

		for ( $cnt = 0; $cnt < 7; $cnt++ ) {
			// Fix voor zondagen
			$index = ( ( $start + $cnt - 1 ) % 7 ) + 1;
			
			// Check of er voor deze dag wel openingsuren bestaan
			if ( $days[$index] ) {
				$date = "";
				if ( $atts['start'] === 'today' ) {
					$date = " ".date( 'j/n', strtotime( "this ".date( 'l', strtotime("Sunday +{$index} days") ) ) );
				}
				// Toon sluitingsdagen indien we de specifieke openingsuren voor de komende 7 dagen tonen
				if ( $atts['start'] === 'today' and in_array( date_i18n( 'Y-m-d', strtotime("+{$cnt} days") ), $holidays ) ) {
					$output .= "<br/>".ucwords( date_i18n( 'l', strtotime("Sunday +{$index} days") ) ).$date.": uitzonderlijk gesloten";
				} else {
					foreach ( $days[$index] as $part => $part_hours ) {
						if ( ! isset( $$index ) ) {
							$output .= "<br/>".ucwords( date_i18n( 'l', strtotime("Sunday +{$index} days") ) ).$date.": " . $part_hours['start'] . " - " . $part_hours['end'];
							$$index = true;
						} else {
							$output .= " en " . $part_hours['start'] . " - " . $part_hours['end'];
						}
					}
				}
			}
		}
		
		// Boodschap over afhaling op afspraak enkel toevoegen indien hele week gesloten
		if ( strpos( $output, ' - ' ) === false ) {
			$locations = get_option('woocommerce_pickup_locations');
			if ( count( $locations ) > 1 ) {
				$text = 'Om de verspreiding van het coronavirus tegen te gaan, zijn al onze winkels momenteel gesloten. Afhalen kan enkel nog <u>op afspraak</u>. Na het plaatsen van je bestelling contacteren we je om een tijdstip af te spreken.';
			} else {
				$text = 'Om de verspreiding van het coronavirus tegen te gaan, is onze winkel momenteel gesloten. Afhalen kan enkel nog <u>op afspraak</u>. Na het plaatsen van je bestelling contacteren we je om een tijdstip af te spreken.';
			}
			$output = '<p class="corona-notice">'.$text.'</p>';
		} else {
			// if ( $atts['id'] === 'brugge' ) {
			// 	// Extra tekst in de mail
			// 	if ( ! is_checkout() ) {
			// 		$text .= '<br/>Opgelet: de poort is gesloten, bel aan bij de deur links. We nemen steeds de nodige hygiënische maatregelen. Alvast bedankt voor je begrip!';
			// 	}
			// }

			// Knip de eerste <br/> er weer af
			$output = substr( $output, 5 );
		}
		
		return $output;
	}

	function print_oxfam_shop_data( $key, $atts ) {
		// Overschrijf defaults door opgegeven attributen
		$atts = shortcode_atts( array( 'id' => get_option('oxfam_shop_post_id') ), $atts );
		return get_oxfam_shop_data( $key, 0, false, $atts['id'] );
	}

	function print_mail() {
		return "<a href='mailto:".get_company_email()."'>".get_company_email()."</a>";
	}

	function print_place( $atts = [] ) {
		return print_oxfam_shop_data( 'place', $atts );
	}

	function print_zipcode( $atts = [] ) {
		return print_oxfam_shop_data( 'zipcode', $atts );
	}

	function print_city( $atts = [] ) {
		return print_oxfam_shop_data( 'city', $atts );
	}

	function print_telephone( $atts = [] ) {
		return print_oxfam_shop_data( 'telephone', $atts );
	}

	function print_welcome() {
		// Negeer afgeschermde en gearchiveerde sites
		$sites = get_sites( array( 'site__not_in' => get_site_option('oxfam_blocked_sites'), 'public' => 1, 'count' => true ) );
		// Trek hoofdsite af van totaal
		$msg = '<img src="'.get_stylesheet_directory_uri().'/markers/placemarker-afhaling.png" class="placemarker">';
		$msg .= '<h3 class="afhaling">'.sprintf( __( 'Begroetingstekst met het aantal webshops (%d) en promotie voor de afhaalkaart.', 'oxfam-webshop' ), $sites-1 ).'</h3>';
		return $msg;
	}

	function print_portal_title() {
		return __( 'Titel in de header van de portaalpagina', 'oxfam-webshop' );
	}

	function print_store_selector() {
		$global_zips = get_shops();
		$all_zips = get_site_option( 'oxfam_flemish_zip_codes' );
		$msg = '<img src="'.get_stylesheet_directory_uri().'/markers/placemarker-levering.png" class="placemarker">';
		$msg .= '<h3 class="thuislevering">'.__( 'Blokje uitleg bij store selector op basis van postcode.', 'oxfam-webshop' ).'</h3><br/>';
		$msg .= '<div class="input-group">';
		$msg .= '<input type="text" class="minimal" placeholder="zoek op postcode" id="oxfam-zip-user" autocomplete="off"> ';
		$msg .= '<button class="minimal" type="submit" id="do_oxfam_redirect" disabled><i class="pe-7s-search"></i></button>';
		$msg .= '</div>';
		foreach ( $all_zips as $zip => $city ) {
			if ( isset( $global_zips[$zip] ) ) {
				$url = $global_zips[$zip];
			} else {
				$url = '';
			}
			$msg .= '<input type="hidden" class="oxfam-zip-value" id="'.$zip.'" value="'.$url.'">';
		}
		return $msg;
	}

	function print_store_locator_map() {
		// Eventuele styling: maptype='light_monochrome'
		return do_shortcode("[flexiblemap src='".content_url('/maps/global.kml')."' width='100%' height='600px' zoom='9' hidemaptype='true' hidescale='false' kmlcache='4 hours' locale='nl-BE' id='map-oxfam']");
	}

	function print_woocommerce_messages() {
		if ( function_exists('wc_print_notices') and wc_notice_count() > 0 ) {
			return wc_print_notices();
		} else {
			return '';
		}
	}

	function print_delivery_snippet() {
		$msg = "";
		if ( does_home_delivery() ) {
			$msg = "Heb je gekozen voor levering? Dan staan we maximaal 3 werkdagen later met je pakje op je stoep (*).";
		}
		return $msg;
	}

	function print_delivery_zips() {
		$msg = "";
		if ( does_home_delivery() ) {
			$cities = get_site_option( 'oxfam_flemish_zip_codes' );
			$zips = get_oxfam_covered_zips();
			$list = array();
			foreach ( $zips as $zip ) {
				// Enkel Vlaamse steden expliciet vermelden
				if ( array_key_exists( $zip, $cities ) ) {
					$zip_city = explode( '/', $cities[$zip] );
					$list[] = $zip." ".trim($zip_city[0]);
				}
			}
			$msg = "<small>(*) Oxfam-Wereldwinkels kiest bewust voor lokale verwerking. Deze webshop levert aan huis in ".implode( ', ', $list ).".<br/><br/>Staat je postcode niet in deze lijst? <a href='/'>Keer dan terug naar de portaalpagina</a> en vul daar je postcode in.</small>";
		}
		return $msg;
	}

	function print_store_map() {
		// Zoom kaart wat minder ver in indien grote regio
		if ( get_current_blog_id() === 25 ) {
			// Uitzondering voor Regio Brugge
			$zoom = 11;
		} elseif ( is_regional_webshop() ) {
			$zoom = 13;
		} else {
			$zoom = 15;
		}
		return do_shortcode("[flexiblemap src='".content_url( '/maps/site-'.get_current_blog_id().'.kml?v='.rand() )."' width='100%' height='600px' zoom='".$zoom."' hidemaptype='true' hidescale='false' kmlcache='8 hours' locale='nl-BE' id='map-oxfam']");
	}

	function print_scroll_text() {
		return __( 'Tekst die verschijnt bovenaan de hoofdpagina met producten.', 'oxfam-webshop' );
	}



	###########
	# HELPERS #
	###########

	function get_flemish_zips_and_cities() {
		$zips = get_site_option( 'oxfam_flemish_zip_codes' );
		foreach ( $zips as $zip => $cities ) {
			$parts = explode( '/', $cities );
			foreach ( $parts as $city ) {
				$content[] = array( 'label' => $zip.' '.trim($city), 'value' => $zip );	
			}
		}
		return $content;
	}

	function does_risky_delivery() {
		// In de toekomst variabel maken
		return false;
	}

	function does_home_delivery() {
		// Kijk in de testsites naar de geactiveerde verzendmethodes i.p.v. de toegekende poastcodes
		// Zal misschien ook nodig zijn voor Gent-Sint-Pieters?
		if ( get_option('mollie-payments-for-woocommerce_test_mode_enabled') === 'yes' ) {
			// Klopt altijd, behalve voor Geraardsbergen
			return true;
		} else {
			return get_option( 'oxfam_zip_codes' );
		}
	}

	function does_sendcloud_delivery() {
		if ( does_home_delivery() ) {
			$sendcloud_zone_id = 3;
			$zone = WC_Shipping_Zones::get_zone_by( 'zone_id', $sendcloud_zone_id );
			if ( $zone ) {
				// Enkel actieve methodes meetellen
				$methods = $zone->get_shipping_methods( true );
				if ( count( $methods ) > 0 ) {
					return true;
				} else {
					return false;
				}
			} else {
				return false;
			}
		} else {
			return false;
		}
	}

	function is_regional_webshop() {
		// Antwerpen, Leuven, Mechelen en Wetteren
		$regions = array( 24, 28, 40 );
		// Opgelet: vergeet de custom orderstatus 'claimed' niet te publiceren naar deze subsites!
		return in_array( get_current_blog_id(), $regions );
	}

	function get_external_wpsl_store( $shop_post_id, $domain = 'www.oxfamwereldwinkels.be' ) {
		$store_data = false;
		$shop_post_id = intval( $shop_post_id );

		if ( false === ( $store_data = get_site_transient( $shop_post_id.'_store_data' ) ) ) {
			// Op dit moment is de API nog volledig publiek, dus dit is toekomstmuziek
			$args = array(
				'headers' => array(
					'Authorization' => 'Basic '.base64_encode( OWW_USER.':'.OWW_PASSWORD ),
				),
			);
			$response = wp_remote_get( 'https://'.$domain.'/wp-json/wp/v2/wpsl_stores/'.$shop_post_id );
			
			$logger = wc_get_logger();
			$context = array( 'source' => 'WordPress API' );
			
			if ( $response['response']['code'] == 200 ) {
				$logger->debug( 'Shop data saved in transient for ID '.$shop_post_id, $context );
				// Bewaar als een array i.p.v. een object
				$store_data = json_decode( $response['body'], true );
				set_site_transient( $shop_post_id.'_store_data', $store_data, DAY_IN_SECONDS );
			} else {
				$logger->notice( 'Could not retrieve shop data for ID '.$shop_post_id, $context );
			}
		}

		return $store_data;
	}

	function get_external_partner( $partner_name, $domain = 'www.oxfamwereldwinkels.be' ) {
		$partner_info = array();
		$partner_slug = sanitize_title( $partner_name );

		if ( false === ( $partner_info = get_site_transient( $partner_slug.'_partner_data' ) ) ) {
			// Op dit moment is de API nog volledig publiek, dus dit is toekomstmuziek
			$args = array(
				'headers' => array(
					'Authorization' => 'Basic '.base64_encode( OWW_USER.':'.OWW_PASSWORD ),
				),
			);

			// Dit levert een array met (in principe) één element
			// Zoekt default enkel naar objecten met de status 'publish'
			$response = wp_remote_get( 'https://'.$domain.'/wp-json/wp/v2/partners/?slug='.$partner_slug );
			
			$logger = wc_get_logger();
			$context = array( 'source' => 'WordPress API' );
			
			if ( $response['response']['code'] == 200 ) {
				// Zet het JSON-object om in een array
				$matching_partners = json_decode( $response['body'], true );
				
				if ( count( $matching_partners ) === 1 ) {
					$partner_data = $matching_partners[0];
					$partner_info['url'] = $partner_data['link'];
					$partner_info['quote'] = $partner_data['quote']['content'];
					$partner_info['quote_by'] = $partner_data['quote']['speaker'];
					$partner_info['quote_photo'] = $partner_data['quote']['image'];
					set_site_transient( $partner_slug.'_partner_data', $partner_info, DAY_IN_SECONDS );
					$logger->debug( 'Partner data saved in transient for '.$partner_slug, $context );
				} elseif ( count( $matching_partners ) > 1 ) {
					$logger->notice( 'Multiple partners found for '.$partner_slug, $context );
				} else {
					$logger->notice( 'No partner found for '.$partner_slug, $context );
				}
			} else {
				$logger->notice( 'Could not retrieve partner for '.$partner_slug, $context );
			}
		}

		return $partner_info;
	}

	// Parameter $raw bepaalt of we de correcties voor de webshops willen uitschakelen (mag verdwijnen van zodra logomateriaal uit OWW-site komt)
	function get_oxfam_shop_data( $key, $node = 0, $raw = false, $shop_post_id = 0 ) {
		if ( $shop_post_id === 0 ) $shop_post_id = get_option('oxfam_shop_post_id');

		if ( ! is_main_site() ) {

			$oww_store_data = get_external_wpsl_store( $shop_post_id );
			if ( $oww_store_data !== false ) {
				// Bestaat in principe altijd
				$location_data = $oww_store_data['location'];
				
				if ( intval( $shop_post_id ) === 3598 ) {
					// Uitzonderingen voor Regio Leuven vzw
					switch ($key) {
						case 'tax':
							return call_user_func( 'format_'.$key, 'BE 0479.961.641' );
						case 'account':
							return call_user_func( 'format_'.$key, 'BE86 0014 0233 4050' );
						case 'headquarter':
							return call_user_func( 'format_'.$key, 'Parijsstraat 56, 3000 Leuven' );
						case 'telephone':
							return call_user_func( 'format_'.$key, '0468113033', '.' );
					}
				} elseif ( intval( $shop_post_id ) === 3226 ) {
					// Uitzonderingen voor Regio Antwerpen vzw
					switch ($key) {
						case 'account':
							return call_user_func( 'format_'.$key, 'BE56 0018 1366 6388' );
					}
				} elseif ( intval( $shop_post_id ) === 3362 ) {
					// Uitzonderingen voor Deinze
					switch ($key) {
						case 'telephone':
							return call_user_func( 'format_'.$key, '0493082695', '.' );
					}
				}
				
				if ( array_key_exists( $key, $location_data ) and $location_data[$key] !== '' ) {
					
					switch ($key) {
						case 'telephone':
							// Geef alternatieve delimiter mee
							return call_user_func( 'format_'.$key, $location_data[$key], '.' );
						case 'headquarter':
							// Plak de adresgegevens van de vzw aan elkaar
							return call_user_func( 'format_place', $location_data[$key]['place'] ).', '.call_user_func( 'format_zipcode', $location_data[$key]['zipcode'] ).' '.call_user_func( 'format_city', $location_data[$key]['city'] );
						case 'll':
							// Er bestaat geen formatteerfunctie voor coördinaten
							return $location_data[$key];
					}

					return call_user_func( 'format_'.$key, $location_data[$key] );

				} else {
					return "";
				}
			}

		} else {

			switch ($key) {
				case 'place':
					return call_user_func( 'format_place', 'Ververijstraat 17' );
				case 'zipcode':
					return call_user_func( 'format_zipcode', '9000' );
				case 'city':
					return call_user_func( 'format_city', 'Gent' );
				case 'telephone':
					return call_user_func( 'format_telephone', '092188899', '.' );
				case 'tax':
					return call_user_func( 'format_tax', 'BE 0415.365.777' );
				default:
					return "(gegevens cvba)";
			}

		}
	}

	function get_company_name() {
		return get_bloginfo('name');
	}

	function get_main_shop_node() {
		$list = get_option('oxfam_shop_nodes');
		return $list[0];
	}

	function get_company_email() {
		return get_option('admin_email');
	}

	function get_company_contact() {
		return get_company_address()."<br/><a href='mailto:".get_company_email()."'>".get_company_email()."</a><br/>".get_oxfam_shop_data('telephone')."<br/>".get_oxfam_shop_data('tax');
	}

	function get_company_address( $shop_post_id = 0 ) {
		if ( $shop_post_id === 0 ) $shop_post_id = get_option('oxfam_shop_post_id');
		return get_oxfam_shop_data( 'place', 0, false, $shop_post_id )."<br/>".get_oxfam_shop_data( 'zipcode', 0, false, $shop_post_id )." ".get_oxfam_shop_data( 'city', 0, false, $shop_post_id );
	}

	function get_full_company() {
		return get_company_name()."<br/>".get_company_address()."<br/>".get_company_contact();
	}

	function get_shops() {
		$global_zips = array();
		// Negeer afgeschermde en gearchiveerde sites
		$sites = get_sites( array( 'site__not_in' => get_site_option('oxfam_blocked_sites'), 'public' => 1, ) );
		foreach ( $sites as $site ) {
			switch_to_blog( $site->blog_id );
			$local_zips = get_option( 'oxfam_zip_codes' );
			if ( $local_zips !== false ) {
				foreach ( $local_zips as $zip ) {
					if ( isset($global_zips[$zip]) ) {
						write_log("CONSISTENTIEFOUT BLOG-ID ".$site->blog_id.": Postcode ".$zip." is reeds gelinkt aan ".$global_zips[$zip].'!');
					}
					$global_zips[$zip] = 'https://' . $site->domain . $site->path;
				}
			}
			restore_current_blog();
		}
		ksort($global_zips);
		return $global_zips;
	}

	add_filter( 'flexmap_custom_map_types', function( $map_types, $attrs ) {
		if ( empty($attrs['maptype']) ) {
			return $map_types;
		}

		if ( $attrs['maptype'] === 'light_monochrome' and empty($map_types['light_monochrome']) ) {
			$custom_type = '{ "styles" : [{"stylers":[{"hue":"#ffffff"},{"invert_lightness":false},{"saturation":-100}]}], "options" : { "name" : "Light Monochrome" } }';
			$map_types['light_monochrome'] = json_decode($custom_type);
		}
		return $map_types;
	}, 10, 2 );

	function get_company_and_year() {
		return get_company_name().' &copy; 2017-'.date_i18n('Y');
	}

	function get_local_logo_url() {
		if ( file_exists( get_stylesheet_directory().'/logo/'.get_option('oxfam_shop_node').'.png' ) ) {
			// TO DO: Logo's hernummeren naar post-ID's
			return get_stylesheet_directory_uri().'/logo/'.get_option('oxfam_shop_node').'.png';
		} else {
			// Fallback
			return get_stylesheet_directory_uri().'/images/oww-logo.svg';
		}
	}

	function get_oxfam_covered_zips() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM ".$wpdb->prefix."woocommerce_shipping_zone_locations WHERE location_type = 'postcode'" );
		$zips = false;
		if ( count($rows) > 0 ) {
			foreach ( $rows as $row ) {
				$zips[] = $row->location_code;
			}
			$zips = array_unique( $zips );
			// Verwijder de default '9999'-waarde uit ongebruikte verzendmethodes
			if ( ( $key = array_search( '9999', $zips ) ) !== false ) {
				unset($zips[$key]);
			}
			sort( $zips, SORT_NUMERIC );
		}
		return $zips;
	}


	
	##########
	# SEARCH #
	##########

	// Verander capability van 'manage_options' naar 'create_sites' zodat enkel superbeheerders de instellingen kunnen wijzigen
	add_filter( 'relevanssi_options_capability', function( $capability ) { return 'create_sites'; } );
	
	// Verander capability van 'edit_pages' naar 'manage_woocommerce' zodat ook lokale beheerders de logs kunnen bekijken
	add_filter( 'relevanssi_user_searches_capability', function( $capability ) { return 'manage_woocommerce'; } );
		
	// Probeert reguliere meervouden en verkleinwoorden automatisch weg te laten uit zoektermen (én index)
	add_filter( 'relevanssi_stemmer', 'relevanssi_dutch_stemmer' );

	function relevanssi_dutch_stemmer( $term ) {
		// De 'synoniemen' die een woord simpelweg verlengen voeren we pas door nu de content opgesplitst is in woorden
		$synonyms = array( 'blauw' => 'blauwe', 'groen' => 'groene', 'wit' => 'witte', 'zwart' => 'zwarte', 'paars' => 'paarse', 'bruin' => 'bruine' );
		foreach ( $synonyms as $search => $replace ) {
			if ( strcmp( $term, $search ) === 0 ) $term = $replace;
		}
		
		$len = strlen($term);
		
		if ( $len > 4 ) {
			$last_3 = substr($term, -3, 3);
			$last_4 = substr($term, -4, 4);
			$vowels = array( "a", "e", "i", "o", "u" );

			// Knip alle meervouden op 's' weg
			if ( substr($term, -2, 2) === "'s" ) {
				$term = substr($term, 0, -2);
			} elseif ( in_array( $last_4, array( "eaus", "eaux" ) ) ) {
				$term = substr($term, 0, -1);
			} elseif ( substr($term, -1, 1) === "s" and ! in_array( substr($term, -2, 1), array( "a", "i", "o", "u" ), true ) and ! ( in_array( substr($term, -2, 1), $vowels, true ) and in_array( substr($term, -3, 1), $vowels, true ) ) ) {
				// Behalve na een klinker (m.u.v. 'e') of een tweeklank!
				$term = substr($term, 0, -1);
			}

			// Knip de speciale meervouden op 'en' met een wisselende eindletter weg
			if ( $last_3 === "'en" ) {
				$term = substr($term, 0, -3);
			} elseif ( $last_3 === "eën" ) {
				$term = substr($term, 0, -3)."e";
			} elseif ( $last_3 === "iën" ) {
				$term = substr($term, 0, -3)."ie";
			} elseif ( $last_4 === "ozen" ) {
				// Andere onregelmatige meervouden vangen we op via de synoniemen!
				$term = substr($term, 0, -3)."os";
			}

			// Knip de gewone meervouden op 'en' weg
			if ( substr($term, -2, 2) === "en" and ! in_array( substr($term, -3, 1), $vowels, true ) ) {
				$term = substr($term, 0, -2);
			}

			// Knip de verkleinende suffixen weg
			if ( substr($term, -4, 4) === "ltje" ) {
				$term = substr($term, 0, -3);
			} elseif ( substr($term, -4, 4) === "mpje" ) {
				$term = substr($term, 0, -3);
			} elseif ( substr($term, -4, 4) === "etje" ) {
				$term = substr($term, 0, -4);
			} elseif ( substr($term, -2, 2) === "je" ) {
				// Moeilijk te achterhalen wanneer de laatste 't' ook weg moet!
				$term = substr($term, 0, -2);
			}

			// Knip de overblijvende verdubbelde eindletters weg
			if ( in_array( substr($term, -2, 2), array( "bb", "dd", "ff", "gg", "kk", "ll", "mm", "nn", "pp", "rr", "ss", "tt" ) ) ) {
				$term = substr($term, 0, -1);
			}
		}

		return $term;
	}

	// Plaats een zoeksuggestie net onder de titel van zoekpagina's als er minder dan 5 resultaten zijn
	// Probleem: de 'woocommerce_archive_description'-actie wordt niet uitgevoerd door Savoy bovenaan zoekresultaten!
	// add_action( 'woocommerce_archive_description', 'ob2c_add_didyoumean' );

	function ob2c_add_didyoumean() {
		if ( is_search() ) {
			relevanssi_didyoumean( get_search_query(), "<p>Bedoelde je misschien <i>", "</i> ?</p>", 5 );
		}
	}

	// Zorg ervoor dat de zoeksuggestie opnieuw linkt naar de productenzoeker
	add_filter( 'relevanssi_didyoumean_url', 'ob2c_modify_didyoumean_url', 10, 1 );
	
	function ob2c_modify_didyoumean_url( $url ) {
		return add_query_arg( 'post_type', 'product', $url );
	}

	// Verhinder dat termen die slechts 1x in de index voorkomen de automatische suggesties verstoren
	// add_filter( 'relevanssi_get_words_having', function() { return 2; } );

	// Toon de bestsellers op zoekpagina's zonder resultaten MOET MEER NAAR BOVEN + VERSCHIJNT OOK ALS ER WEL RESULTATEN ZIJN
	// add_action( 'woocommerce_after_main_content', 'add_bestsellers' );

	function add_bestsellers() {
		global $wp_query;
		if ( is_search() and $wp_query->found_posts == 0 ) {
			echo do_shortcode('[vc_row css=".vc_custom_1487859300634{padding-top: 25px !important;padding-bottom: 25px !important;}"][vc_column][vc_text_separator title="<h2>Werp een blik op onze bestsellers ...</h2>" css=".vc_custom_1487854440279{padding-bottom: 25px !important;}"][best_selling_products per_page="10" columns="5" orderby="rand"][/vc_column][/vc_row]');
		}
	}

	// Zorg ervoor dat verborgen producten niet geïndexeerd worden (en dus niet opduiken in de zoekresultaten) SOWIESO AL ONZICHTBAAR, ZIE OPTIE
	add_filter( 'relevanssi_woocommerce_indexing', 'ob2c_exclude_hidden_products', 10, 1 );
	
	function ob2c_exclude_hidden_products( $blocks ) {
		$blocks['outofstock'] = false;
		// $blocks['exclude-from-catalog'] = false;
		$blocks['exclude-from-search'] = true;
		return $blocks;
	}

	// Voeg de bovenliggende categorie en de herkomstlanden toe aan de te indexeren content van een product (inclusief synoniemen)
	add_filter( 'relevanssi_content_to_index', 'ob2c_index_parent_category_and_origin', 10, 2 );

	function ob2c_index_parent_category_and_origin( $content, $post ) {
		global $relevanssi_variables;
		$categories = get_the_terms( $post->ID, 'product_cat' );
		if ( is_array( $categories ) ) {
			foreach ( $categories as $category ) {
				// Check de bovenliggende cateogrie
				if ( ! empty( $category->parent ) ) {
					$parent = get_term( $category->parent, 'product_cat' );
					if ( array_key_exists( 'synonyms', $relevanssi_variables ) ) {
						// Laat de synoniemenlijst eerst nog even inwerken, ook op de herkomst
						$search = array_keys($relevanssi_variables['synonyms']);
						$replace = array_values($relevanssi_variables['synonyms']);
						$content .= str_ireplace( $search, $replace, get_post_meta( $post->ID, '_herkomst_nl', true ).' '.$parent->name ).' ';
					} else {
						// Voeg direct toe, samen met herkomst
						$content .= get_post_meta( $post->ID, '_herkomst_nl', true ).' '.$parent->name.' ';
					}
				}
			}
		}
		return $content;
	}
	
	// Verleng de logs tot 90 dagen
	add_filter( 'relevanssi_30days', function() { return 90; } );



	#############
	# DEBUGGING #
	#############

	// Verhinder het lekken van gegevens uit de API aan niet-ingelogde gebruikers
	add_filter( 'rest_authentication_errors', 'only_allow_administrator_rest_access' );

	function only_allow_administrator_rest_access( $access ) {
		if ( ! is_user_logged_in() or ! current_user_can('manage_options') ) {
			return new WP_Error( 'rest_cannot_access', 'Access prohibited!', array( 'status' => rest_authorization_required_code() ) );
		}
		return $access;
	}

	// Print variabelen op een overzichtelijke manier naar debug.log
	if ( ! function_exists( 'write_log' ) ) {
		function write_log( $log )  {
			if ( defined('WP_DEBUG_LOG') and WP_DEBUG_LOG ) {
				if ( is_array( $log ) or is_object( $log ) ) {
					$log = serialize( $log );
				}
				error_log( "[".date_i18n('d/m/Y H:i:s')."] ".$log."\n", 3, WP_CONTENT_DIR.'/activity.log' );
			}
		}
	}

	// Verwissel twee associatieve keys in een array
	function array_swap_assoc( $key1, $key2, $array ) {
		$new_array = array();
		foreach ( $array as $key => $value ) {
			if ( $key == $key1 ) {
				$new_array[$key2] = $array[$key2];
			} elseif ( $key == $key2 ) {
				$new_array[$key1] = $array[$key1];
			} else {
				$new_array[$key] = $value;
			}
		}
		return $new_array;
	}

	// Creëer een random sequentie (niet gebruiken voor echte beveiliging)
	function generate_pseudo_random_string( $length = 10 ) {
		$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$characters_length = strlen($characters);
		$random_string = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$random_string .= $characters[rand( 0, $characters_length - 1 )];
		}
		return $random_string;
	}

	// Overzichtelijkere debugfunctie definiëren
	function var_dump_pre( $variable ) {
		echo '<pre>';
		var_dump($variable);
		echo '</pre>';
		return null;
	}
	
?>