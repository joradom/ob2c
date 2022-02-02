<?php
/**
 * Single product short description
 *
 * @see     https://docs.woocommerce.com/document/template-structure/
 * @author  Automattic
 * @package WooCommerce/Templates
 * @version 3.3.0
 NM: Modified - Added "entry-content" class */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

global $post;
$short_description = apply_filters( 'woocommerce_short_description', $post->post_excerpt );

?>
<div class="woocommerce-product-details__short-description entry-content">
	<?php
		if ( is_national_product( $post->ID ) ) {		
			if ( stripos( get_permalink(), '/wijn/' ) > 0 ) {
				// Wijn: toon eerst korte 'lekker bij'-omschrijving, en vervolgens lange sommeliersbeschrijving
				echo $short_description;
				// Géén get_the_content() gebruiken, want dan wordt 'the_content'-filter niet doorlopen en ontbreken de paragrafen
				the_content();
			} else {
				if ( ! $short_description ) {
					// Toon enkel lange beschrijving
					the_content();
				} else {
					// Als het product géén uitgelichte partner heeft, zou hier enkel de korte beschrijving moeten verschijnen ...
					the_content();
					echo $short_description;
					
					// Logica om $featured_partner te bepalen verhuizen van product-origin.php naar content-single-product.php, zodat die info hier ook al beschikbaar is?
					// global $featured_partner;
					// if ( $featured_partner ) {
					// 	echo $long_description;
					// } else {
					// 	echo $short_description;
					// }
				}
			}
		} else {
			echo $short_description;
		}
	?>
</div>
