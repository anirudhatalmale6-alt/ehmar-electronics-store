<?php
/**
 * WooCommerce payment gateway: Afriex hosted checkout.
 *
 * Flow: create a checkout session -> redirect the customer to Afriex -> confirm the payment from a signed
 * TRANSACTION webhook, and also by looking the transaction up when the customer comes back. The redirect
 * alone is never trusted (Afriex: "Don't treat the redirect as proof of payment").
 */

defined( 'ABSPATH' ) || exit;

class Ehmar_Afriex_Gateway extends WC_Payment_Gateway {

	const ID = 'ehmar_afriex';

	/** Countries whose customers can pay in their own currency on Afriex checkout. */
	const LOCAL_CURRENCY = array(
		'NG' => 'NGN', 'GH' => 'GHS', 'KE' => 'KES', 'UG' => 'UGX', 'TZ' => 'TZS', 'ET' => 'ETB', 'SL' => 'SLE',
		'CM' => 'XAF', 'CF' => 'XAF', 'TD' => 'XAF', 'CG' => 'XAF', 'GQ' => 'XAF', 'GA' => 'XAF',
		'BJ' => 'XOF', 'BF' => 'XOF', 'CI' => 'XOF', 'GW' => 'XOF', 'ML' => 'XOF', 'NE' => 'XOF', 'SN' => 'XOF', 'TG' => 'XOF',
	);

	/** Afriex checkout currencies (docs: checkout-sessions/create). */
	const CHECKOUT_CURRENCIES = array( 'NGN', 'GHS', 'KES', 'UGX', 'TZS', 'ETB', 'XAF', 'XOF', 'SLE', 'USD' );

	const PAID     = array( 'SUCCESS' );
	const FAILED   = array( 'FAILED', 'REJECTED', 'CANCELLED' );

	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = 'Afriex';
		$this->method_description = 'Customers pay on the secure Afriex checkout page by card, bank transfer or mobile money — in their local currency across Africa, or in US dollars.';
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_ehmar_afriex_webhook', array( $this, 'handle_webhook' ) );
		add_action( 'woocommerce_api_ehmar_afriex_return', array( $this, 'handle_return' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'          => array( 'title' => 'Enable', 'type' => 'checkbox', 'label' => 'Accept payments with Afriex', 'default' => 'no' ),
			'title'            => array( 'title' => 'Title', 'type' => 'text', 'default' => 'Pay with Afriex — card, bank transfer or mobile money' ),
			'description'      => array( 'title' => 'Description', 'type' => 'textarea', 'default' => 'You will be taken to the secure Afriex payment page. Customers in Africa can pay in their local currency.' ),
			'testmode'         => array( 'title' => 'Test mode', 'type' => 'checkbox', 'label' => 'Use the Afriex sandbox (no real money moves)', 'default' => 'yes' ),
			'test_api_key'     => array( 'title' => 'Test API key', 'type' => 'password', 'description' => 'Afriex dashboard (Test mode) → Developer → API Keys.' ),
			'test_webhook_key' => array( 'title' => 'Test webhook public key', 'type' => 'textarea', 'description' => 'Afriex dashboard (Test mode) → Developer → Webhook.' ),
			'live_api_key'     => array( 'title' => 'Live API key', 'type' => 'password', 'description' => 'Afriex dashboard (Live mode) → Developer → API Keys.' ),
			'live_webhook_key' => array( 'title' => 'Live webhook public key', 'type' => 'textarea', 'description' => 'Afriex dashboard (Live mode) → Developer → Webhook.' ),
			'local_currency'   => array( 'title' => 'Local currency', 'type' => 'checkbox', 'label' => 'Let customers in supported African countries pay in their own currency (converted at the Afriex rate)', 'default' => 'yes' ),
			'webhook_info'     => array(
				'title'       => 'Webhook URL',
				'type'        => 'title',
				'description' => 'Save this address in the Afriex dashboard → Developer → Webhook (in both Test and Live mode):<br><code>' . esc_html( home_url( '/?wc-api=ehmar_afriex_webhook' ) ) . '</code>',
			),
		);
	}

	private function sandbox() {
		return 'yes' === $this->get_option( 'testmode' );
	}

	private function api() {
		return new Ehmar_Afriex_API( $this->get_option( $this->sandbox() ? 'test_api_key' : 'live_api_key' ), $this->sandbox(), wc_get_logger() );
	}

	public function is_available() {
		if ( ! parent::is_available() || '' === trim( (string) $this->get_option( $this->sandbox() ? 'test_api_key' : 'live_api_key' ) ) ) {
			return false;
		}
		// Sandbox payments settle without real money: never offer them to real customers on the live store.
		return ! $this->sandbox() || current_user_can( 'manage_woocommerce' );
	}

	/* ------------------------------------------------------------ Checkout */

	/** Currency the customer pays in, and the multiplier from the store currency. */
	public function charge_currency( WC_Order $order, Ehmar_Afriex_API $api ) {
		$store   = $order->get_currency();
		$country = strtoupper( (string) ( $order->get_billing_country() ?: $order->get_shipping_country() ) );
		$local   = self::LOCAL_CURRENCY[ $country ] ?? null;
		if ( $local && $local !== $store && 'yes' === $this->get_option( 'local_currency' ) ) {
			$rate = $api->rate( $store, $local );
			if ( $rate ) {
				return array( $local, $rate );
			}
		}
		if ( ! in_array( $store, self::CHECKOUT_CURRENCIES, true ) ) {
			return array( null, null );
		}
		return array( $store, 1.0 );
	}

	/** E.164 phone from what the customer typed and their billing country. */
	public static function e164( $phone, $country ) {
		$phone = trim( (string) $phone );
		$plus  = 0 === strpos( $phone, '+' ) || 0 === strpos( $phone, '00' );
		$digits = preg_replace( '/\D+/', '', $phone );
		if ( 0 === strpos( $phone, '00' ) ) {
			$digits = substr( $digits, 2 );
		}
		if ( $plus ) {
			return '+' . $digits;
		}
		$code = ltrim( (string) WC()->countries->get_country_calling_code( $country ), '+' );
		$code = preg_replace( '/\D+/', '', is_array( $code ) ? reset( $code ) : $code );
		if ( $code && 0 === strpos( $digits, $code ) && strlen( $digits ) > strlen( $code ) + 6 ) {
			return '+' . $digits; // Already includes the country code, just without "+".
		}
		return '+' . $code . ltrim( $digits, '0' );
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		$api   = $this->api();

		list( $currency, $rate ) = $this->charge_currency( $order, $api );
		if ( ! $currency ) {
			wc_add_notice( 'Afriex cannot take payments in this currency. Please choose another payment method.', 'error' );
			return array( 'result' => 'failure' );
		}

		$major  = (float) $order->get_total() * $rate;
		$amount = (int) round( $major * 100 ); // Afriex: minor units, minimum 100 = one major unit, for every currency.
		if ( $amount < 100 ) {
			wc_add_notice( 'The order total is too small for an Afriex payment.', 'error' );
			return array( 'result' => 'failure' );
		}

		// A new reference for every attempt: Afriex rejects a reused reference with 409.
		$reference = sprintf( 'ehmar-%d-%s', $order->get_id(), wp_generate_password( 6, false, false ) );
		if ( $this->sandbox() && 'yes' === get_option( 'ehmar_afriex_fast_sandbox' ) ) {
			$reference .= '-SIMULATE_INSTANT';
		}

		$return = add_query_arg(
			array( 'wc-api' => 'ehmar_afriex_return', 'order_id' => $order->get_id(), 'key' => $order->get_order_key() ),
			home_url( '/' )
		);

		$name = trim( preg_replace( '/[<>\p{Cc}]/u', '', $order->get_formatted_billing_full_name() ) );
		$res  = $api->create_checkout_session( array(
			'amount'            => $amount,
			'currency'          => $currency,
			'merchantReference' => $reference,
			'redirectUrl'       => set_url_scheme( $return, 'https' ),
			'customer'          => array(
				'name'        => mb_substr( $name ?: 'Customer', 0, 100 ),
				'email'       => $order->get_billing_email(),
				'phone'       => self::e164( $order->get_billing_phone(), $order->get_billing_country() ),
				'countryCode' => $order->get_billing_country() ?: 'US',
			),
			'channels'          => array( 'VIRTUAL_BANK_ACCOUNT', 'MOBILE_MONEY', 'CARD' ),
			'metadata'          => array( 'orderId' => (string) $order->get_id(), 'source' => 'ehmar.com' ),
		) );

		$url = $res['body']['data']['checkoutUrl'] ?? $res['body']['checkoutUrl'] ?? null;
		if ( ! $res['ok'] || ! $url ) {
			$order->add_order_note( sprintf( 'Afriex checkout could not be created: %s (request %s).', $res['error'] ?: 'no checkout URL', $res['request_id'] ) );
			wc_add_notice( 'We could not start the Afriex payment. Please try again in a moment, or choose another payment method.', 'error' );
			return array( 'result' => 'failure' );
		}

		// Keep every attempt: a session the customer abandoned can still be paid later and must find this order.
		$refs               = (array) $order->get_meta( '_afriex_refs' );
		$refs[ $reference ] = array( 'currency' => $currency, 'amount' => $amount );
		$order->update_meta_data( '_afriex_refs', $refs );
		$order->update_meta_data( '_afriex_reference', $reference );
		$order->update_meta_data( '_afriex_currency', $currency );
		$order->update_meta_data( '_afriex_amount_minor', $amount );
		$order->update_meta_data( '_afriex_rate', $rate );
		$order->update_meta_data( '_afriex_checkout_id', (string) ( $res['body']['data']['checkoutId'] ?? $res['body']['checkoutId'] ?? '' ) );
		$order->update_status( 'pending', sprintf(
			'Afriex checkout created: %s %s%s (ref %s).',
			number_format( $amount / 100, 2 ),
			$currency,
			1.0 !== (float) $rate ? sprintf( ' at 1 %s = %s %s', $order->get_currency(), $rate, $currency ) : '',
			$reference
		) );
		$order->save();

		return array( 'result' => 'success', 'redirect' => $url );
	}

	/* ------------------------------------------------------------ Settlement */

	/** Find our order from an Afriex merchant reference ("ehmar-<id>-<rand>"). */
	public static function order_for_reference( $reference ) {
		if ( ! preg_match( '/^ehmar-(\d+)-/', (string) $reference, $m ) ) {
			return null;
		}
		$order = wc_get_order( (int) $m[1] );
		if ( ! $order ) {
			return null;
		}
		$refs = (array) $order->get_meta( '_afriex_refs' );
		return ( $order->get_meta( '_afriex_reference' ) === $reference || isset( $refs[ $reference ] ) ) ? $order : null;
	}

	/**
	 * Apply an Afriex transaction to its order. Returns a short outcome string.
	 */
	public function apply_transaction( WC_Order $order, array $txn, $source ) {
		$status = strtoupper( (string) ( $txn['status'] ?? '' ) );
		$txn_id = (string) ( $txn['transactionId'] ?? $txn['id'] ?? '' );

		// Ignore deliveries older than what we already applied (webhooks can arrive out of order).
		$updated = (string) ( $txn['updatedAt'] ?? '' );
		$last    = (string) $order->get_meta( '_afriex_last_update' );
		if ( $updated && $last && strcmp( $updated, $last ) < 0 ) {
			return 'stale';
		}

		if ( in_array( $status, self::PAID, true ) ) {
			if ( $order->is_paid() ) {
				// A second session paid for the same order (e.g. the customer paid twice): flag it once for a refund.
				$first = (string) $order->get_transaction_id();
				if ( $txn_id && $first && $txn_id !== $first && ! $order->get_meta( '_afriex_extra_' . $txn_id ) ) {
					$order->update_meta_data( '_afriex_extra_' . $txn_id, 1 );
					$order->save();
					$order->add_order_note( "Afriex reports a SECOND successful payment for this order (transaction $txn_id; the order was already paid by $first). Refund it from the Afriex dashboard." );
					return 'second-payment';
				}
				return 'already-paid';
			}
			if ( ! $this->amount_matches( $order, $txn ) ) {
				$order->update_status( 'on-hold', sprintf( 'Afriex reports SUCCESS but the amount/currency does not match the checkout (%s). Please check the Afriex dashboard before shipping.', wp_json_encode( array_intersect_key( $txn, array_flip( array( 'sourceAmount', 'sourceCurrency', 'destinationAmount', 'destinationCurrency' ) ) ) ) ) );
				return 'mismatch';
			}
			$order->update_meta_data( '_afriex_last_update', $updated );
			$order->payment_complete( $txn_id );
			$order->add_order_note( "Afriex payment confirmed ($source). Transaction $txn_id." );
			return 'paid';
		}

		if ( in_array( $status, self::FAILED, true ) ) {
			$reference = (string) ( $txn['merchantReference'] ?? $txn['meta']['reference'] ?? '' );
			if ( $reference && $reference !== $order->get_meta( '_afriex_reference' ) ) {
				// An older attempt failed while the customer is already on a newer one: do not fail the order.
				$order->add_order_note( "Afriex: an earlier payment attempt ($reference) ended $status ($source)." );
				return 'old-attempt-failed';
			}
			if ( ! $order->is_paid() ) {
				// Branch on failureReason.code / retryable, never on the message text.
				$fr        = (array) ( $txn['meta']['failureReason'] ?? array() );
				$code      = (string) ( $fr['code'] ?? '' );
				$retryable = ! empty( $fr['retryable'] );
				$order->update_meta_data( '_afriex_last_update', $updated );
				$order->update_meta_data( '_afriex_failure_code', $code );
				$order->update_meta_data( '_afriex_failure_retryable', $retryable ? 'yes' : 'no' );
				$order->update_status( 'failed', trim( sprintf(
					'Afriex payment %s (%s). Code %s, %s. %s',
					$status,
					$source,
					$code ?: 'none',
					$retryable ? 'retryable: the customer can try again' : 'not retryable: the customer should use another payment method',
					(string) ( $fr['message'] ?? '' )
				) ) );
			}
			return 'failed';
		}

		// Everything else is in flight (PENDING, PROCESSING, IN_REVIEW, …) or after-the-fact (REFUNDED, DISPUTE_*).
		if ( $status && $order->get_meta( '_afriex_last_status' ) !== $status ) {
			$order->update_meta_data( '_afriex_last_status', $status );
			$order->update_meta_data( '_afriex_last_update', $updated );
			$order->add_order_note( "Afriex status: $status ($source)." );
			$order->save();
		}
		return 'pending';
	}

	/** The settled amount must be the one we asked for, in the same currency (1% tolerance for rounding). */
	private function amount_matches( WC_Order $order, array $txn ) {
		$reference     = (string) ( $txn['merchantReference'] ?? $txn['meta']['reference'] ?? '' );
		$refs          = (array) $order->get_meta( '_afriex_refs' );
		$want_currency = (string) ( $refs[ $reference ]['currency'] ?? $order->get_meta( '_afriex_currency' ) );
		$want          = (int) ( $refs[ $reference ]['amount'] ?? $order->get_meta( '_afriex_amount_minor' ) ) / 100;
		foreach ( array( array( 'destinationAmount', 'destinationCurrency' ), array( 'sourceAmount', 'sourceCurrency' ) ) as $pair ) {
			$cur = strtoupper( (string) ( $txn[ $pair[1] ] ?? '' ) );
			$amt = $txn[ $pair[0] ] ?? null;
			if ( $cur === $want_currency && is_numeric( $amt ) && abs( (float) $amt - $want ) <= max( 0.01, $want * 0.01 ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------ Webhook */

	public function handle_webhook() {
		$raw = (string) file_get_contents( 'php://input' );
		$sig = (string) ( $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '' );

		if ( ! $this->verify_signature( $raw, $sig ) ) {
			wc_get_logger()->warning( 'Afriex webhook rejected: bad signature.', array( 'source' => 'ehmar-afriex' ) );
			status_header( 401 );
			exit( 'invalid signature' );
		}

		$event = json_decode( $raw, true );
		$id    = (string) ( $event['id'] ?? '' );
		$type  = (string) ( $event['event'] ?? '' );

		// Retries and replays carry the same id: process each event once.
		if ( $id && get_transient( 'ehmar_afx_evt_' . md5( $id ) ) ) {
			status_header( 200 );
			exit( 'duplicate' );
		}
		// UAT drill, sandbox only: answer one delivery with 500 to watch Afriex redeliver it.
		if ( $this->sandbox() && get_option( 'ehmar_afriex_fail_next_webhook' ) ) {
			delete_option( 'ehmar_afriex_fail_next_webhook' );
			wc_get_logger()->info( "Afriex webhook $type $id: answered 500 (retry drill)", array( 'source' => 'ehmar-afriex' ) );
			status_header( 500 );
			exit( 'retry drill' );
		}

		$outcome = 'ignored';
		if ( 0 === strpos( $type, 'TRANSACTION.' ) ) {
			$txn       = (array) ( $event['data'] ?? array() );
			$reference = $txn['merchantReference'] ?? $txn['meta']['reference'] ?? '';
			$order     = self::order_for_reference( $reference );
			$outcome   = $order ? $this->apply_transaction( $order, $txn, 'webhook' ) : 'no-order';
		}
		// Remember the event only once it has been applied, so a delivery that crashed half-way is retried.
		if ( $id ) {
			set_transient( 'ehmar_afx_evt_' . md5( $id ), 1, 2 * DAY_IN_SECONDS );
		}
		wc_get_logger()->info( "Afriex webhook $type $id: $outcome", array( 'source' => 'ehmar-afriex' ) );
		status_header( 200 );
		exit( 'ok' );
	}

	/** ECDSA P-384 / SHA-256, DER signature base64-encoded, over the raw body (docs: webhooks/introduction). */
	public function verify_signature( $raw, $signature_b64 ) {
		$pem = trim( (string) $this->get_option( $this->sandbox() ? 'test_webhook_key' : 'live_webhook_key' ) );
		if ( '' === $pem || '' === $signature_b64 || '' === $raw ) {
			return false;
		}
		if ( false === strpos( $pem, 'BEGIN' ) ) {
			$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( preg_replace( '/\s+/', '', $pem ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
		}
		$key = openssl_pkey_get_public( $pem );
		$sig = base64_decode( $signature_b64, true );
		return $key && false !== $sig && 1 === openssl_verify( $raw, $sig, $key, OPENSSL_ALGO_SHA256 );
	}

	/* ------------------------------------------------------------ Customer returns from Afriex */

	public function handle_return() {
		$order = wc_get_order( absint( $_GET['order_id'] ?? 0 ) );
		if ( ! $order || ! hash_equals( $order->get_order_key(), (string) ( $_GET['key'] ?? '' ) ) ) {
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}
		if ( ! $order->is_paid() ) {
			$txn = $this->api()->find_transaction( (string) $order->get_meta( '_afriex_reference' ) );
			if ( $txn ) {
				$this->apply_transaction( $order, $txn, 'return' );
			}
			$order = wc_get_order( $order->get_id() );
		}

		if ( $order->has_status( 'failed' ) ) {
			wc_add_notice(
				'no' === $order->get_meta( '_afriex_failure_retryable' )
					? 'Your Afriex payment was declined. Please choose another payment method or contact us.'
					: 'Your Afriex payment did not go through. Please try again or choose another payment method.',
				'error'
			);
			wp_safe_redirect( $order->get_checkout_payment_url() );
			exit;
		}
		if ( ! $order->is_paid() && 'success' === ( $_GET['status'] ?? '' ) && $order->has_status( 'pending' ) ) {
			// Afriex says it succeeded but the deposit has not settled yet: hold the stock, wait for the webhook.
			$order->update_status( 'on-hold', 'Customer returned from Afriex; waiting for the payment to settle.' );
		}
		wp_safe_redirect( $this->get_return_url( $order ) );
		exit;
	}
}
