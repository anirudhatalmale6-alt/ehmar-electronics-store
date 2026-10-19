<?php
/**
 * Ehmar Electronics child theme.
 */

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'ehmar', get_stylesheet_directory_uri() . '/assets/ehmar.css', array(), filemtime( get_stylesheet_directory() . '/assets/ehmar.css' ) );
} );

add_action( 'after_setup_theme', function () {
	add_theme_support( 'custom-logo' );
	add_editor_style( 'assets/ehmar.css' );
} );

// Four products per row on desktop listings.
add_filter( 'loop_shop_per_page', fn() => 24 );

// The block theme's "Uncategorized" product category is noise in a small catalogue.
add_filter( 'woocommerce_product_categories_widget_args', function ( $args ) {
	$args['exclude'] = get_option( 'default_product_cat' );
	return $args;
} );

// WooCommerce 11.2 loads the mini-cart script module on cart/checkout pages but skips hydrating its
// state there, so the cart store fetches "undefinedwc/store/v1/cart" (404). Provide the REST root.
add_action( 'wp_enqueue_scripts', function () {
	if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() ) && function_exists( 'wp_interactivity_state' ) ) {
		wp_interactivity_state( 'woocommerce', array( 'restUrl' => get_rest_url() ) );
	}
} );

/* ---------------------------------------------------------------- Cars & Engines request form */

const EHMAR_REQUEST_TO = 'info@ehmar.com';

add_shortcode( 'ehmar_car_request', function () {
	$status = sanitize_key( $_GET['request'] ?? '' );
	ob_start();
	if ( 'sent' === $status ) {
		echo '<div class="ehmar-notice ehmar-notice-ok">Thank you! Your request has been sent. We will contact you shortly with options and a quote.</div>';
	} elseif ( 'error' === $status ) {
		echo '<div class="ehmar-notice ehmar-notice-err">Please fill in your name, a valid email and what you are looking for, then try again.</div>';
	}
	?>
	<form id="request" class="ehmar-request" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="ehmar_car_request">
		<?php wp_nonce_field( 'ehmar_car_request', 'ehmar_nonce' ); ?>
		<p class="ehmar-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
		<div class="ehmar-request-grid">
			<label>Your name *<input type="text" name="name" required maxlength="100"></label>
			<label>Email *<input type="email" name="email" required maxlength="150"></label>
			<label>Phone / WhatsApp<input type="tel" name="phone" maxlength="40"></label>
			<label>Country *<input type="text" name="country" required maxlength="60" placeholder="e.g. Nigeria, Ghana"></label>
			<label>I am looking for *
				<select name="type" required><option value="Car">A car</option><option value="Engine">An engine</option><option value="Car and engine">Both</option></select>
			</label>
			<label>Budget (USD)<input type="text" name="budget" maxlength="40" placeholder="e.g. 8,000"></label>
			<label>Make<input type="text" name="make" maxlength="60" placeholder="e.g. Toyota"></label>
			<label>Model<input type="text" name="model" maxlength="60" placeholder="e.g. Camry"></label>
			<label>Year(s)<input type="text" name="year" maxlength="40" placeholder="e.g. 2015 – 2018"></label>
		</div>
		<label class="ehmar-request-full">Anything else? (engine size, colour, mileage, delivery port…)<textarea name="message" rows="4" maxlength="2000"></textarea></label>
		<p><button type="submit" class="wp-element-button">Send my request</button></p>
	</form>
	<?php
	return ob_get_clean();
} );

$ehmar_handle_request = function () {
	$back = wp_get_referer() ?: home_url( '/cars-engines/' );
	$back = remove_query_arg( 'request', $back );

	// Bots fill the hidden field; humans never see it. Pretend success so they learn nothing.
	if ( ! empty( $_POST['website'] ) ) {
		wp_safe_redirect( add_query_arg( 'request', 'sent', $back ) . '#request' );
		exit;
	}
	$fields = array();
	foreach ( array( 'name', 'email', 'phone', 'country', 'type', 'budget', 'make', 'model', 'year' ) as $f ) {
		$fields[ $f ] = sanitize_text_field( wp_unslash( $_POST[ $f ] ?? '' ) );
	}
	$fields['message'] = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );

	$valid = wp_verify_nonce( $_POST['ehmar_nonce'] ?? '', 'ehmar_car_request' )
		&& $fields['name'] && is_email( $fields['email'] ) && $fields['country'];
	if ( ! $valid ) {
		wp_safe_redirect( add_query_arg( 'request', 'error', $back ) . '#request' );
		exit;
	}

	$labels = array( 'name' => 'Name', 'email' => 'Email', 'phone' => 'Phone / WhatsApp', 'country' => 'Country', 'type' => 'Looking for', 'budget' => 'Budget (USD)', 'make' => 'Make', 'model' => 'Model', 'year' => 'Year(s)', 'message' => 'Details' );
	$body   = "New Cars & Engines request from ehmar.com\n\n";
	foreach ( $labels as $k => $label ) {
		$body .= str_pad( $label . ':', 18 ) . ( '' !== $fields[ $k ] ? $fields[ $k ] : '-' ) . "\n";
	}
	$subject = sprintf( 'Car/engine request: %s %s %s (%s)', $fields['type'], $fields['make'], $fields['model'], $fields['country'] );
	wp_mail( EHMAR_REQUEST_TO, $subject, $body, array( 'Reply-To: ' . $fields['name'] . ' <' . $fields['email'] . '>' ) );

	wp_safe_redirect( add_query_arg( 'request', 'sent', $back ) . '#request' );
	exit;
};
add_action( 'admin_post_nopriv_ehmar_car_request', $ehmar_handle_request );
add_action( 'admin_post_ehmar_car_request', $ehmar_handle_request );

// Products imported from Square have no short description; WordPress would otherwise auto-generate one
// from the full spec sheet and show a wall of text under the price. Show nothing instead (specs stay in
// the Description tab).
add_filter( 'get_the_excerpt', function ( $excerpt, $post ) {
	return ( $post && 'product' === $post->post_type && '' === trim( (string) $post->post_excerpt ) ) ? '' : $excerpt;
}, 20, 2 );

// Interim shipping has a $0 placeholder cost that is confirmed with the customer after ordering;
// the block cart/checkout would label it "Free", which promises something we have not agreed.
add_action( 'wp_enqueue_scripts', function () {
	if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() ) ) {
		wp_add_inline_script( 'wp-i18n', 'wp.i18n.setLocaleData( { "Free": [ "To be confirmed" ] }, "woocommerce" );' );
	}
}, 5 );
