<?php
/**
 * Minimal Afriex Business API client (https://docs.afriex.com).
 */

defined( 'ABSPATH' ) || exit;

class Ehmar_Afriex_API {

	const SANDBOX = 'https://sandbox.api.afriex.com';
	const LIVE    = 'https://api.afriex.com';

	private $api_key;
	private $base;
	private $logger;

	public function __construct( $api_key, $sandbox, $logger = null ) {
		$this->api_key = trim( (string) $api_key );
		$this->base    = $sandbox ? self::SANDBOX : self::LIVE;
		$this->logger  = $logger;
	}

	/**
	 * @return array{ok:bool, status:int, body:mixed, request_id:string, error:string}
	 */
	public function request( $method, $path, $body = null, array $query = array() ) {
		$url = $this->base . $path . ( $query ? '?' . http_build_query( $query ) : '' );
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'x-api-key'    => $this->api_key,
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		// Reads are retried on 503/429/network errors with backoff (honouring Retry-After).
		// A create (POST) is never retried blindly: a timeout could hide a session that was made.
		$attempts = 'GET' === $method ? 3 : 1;
		for ( $try = 1; ; $try++ ) {
			$res    = wp_remote_request( $url, $args );
			$code   = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
			$retry  = is_wp_error( $res ) || 503 === $code || 429 === $code || 502 === $code || 504 === $code;
			if ( ! $retry || $try >= $attempts ) {
				break;
			}
			$after = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_header( $res, 'retry-after' );
			$wait  = min( 5, max( 1, $after ?: $try ) );
			$this->log( sprintf( '%s %s -> %s, retry %d in %ds', $method, $path, $code ?: 'network error', $try, $wait ) );
			sleep( $wait );
		}
		if ( is_wp_error( $res ) ) {
			$this->log( "$method $path failed: " . $res->get_error_message() );
			return array( 'ok' => false, 'status' => 0, 'body' => null, 'request_id' => '', 'error' => $res->get_error_message() );
		}
		$status     = (int) wp_remote_retrieve_response_code( $res );
		$request_id = (string) wp_remote_retrieve_header( $res, 'x-request-id' );
		$decoded    = json_decode( wp_remote_retrieve_body( $res ), true );
		$ok         = $status >= 200 && $status < 300;
		if ( ! $ok ) {
			$this->log( sprintf( '%s %s -> HTTP %d (request %s): %s', $method, $path, $status, $request_id, wp_remote_retrieve_body( $res ) ) );
		}
		$error = $ok ? '' : ( is_array( $decoded ) ? (string) ( $decoded['message'] ?? $decoded['error'] ?? "HTTP $status" ) : "HTTP $status" );
		return array( 'ok' => $ok, 'status' => $status, 'body' => $decoded, 'request_id' => $request_id, 'error' => $error );
	}

	public function create_checkout_session( array $payload ) {
		return $this->request( 'POST', '/api/v1/checkout-session', $payload );
	}

	/** Most recent transaction carrying our merchant reference, or null. */
	public function find_transaction( $reference ) {
		$res = $this->request( 'GET', '/api/v1/transaction', null, array( 'reference' => $reference, 'page' => 0, 'limit' => 10 ) );
		if ( ! $res['ok'] ) {
			return null;
		}
		$list = $res['body']['data'] ?? array();
		if ( isset( $list['data'] ) && is_array( $list['data'] ) ) { // Tolerate a nested page envelope.
			$list = $list['data'];
		}
		$matches = array_values( array_filter( (array) $list, function ( $t ) use ( $reference ) {
			return is_array( $t ) && ( ( $t['merchantReference'] ?? '' ) === $reference || ( $t['meta']['reference'] ?? '' ) === $reference );
		} ) );
		usort( $matches, fn( $a, $b ) => strcmp( (string) ( $b['updatedAt'] ?? '' ), (string) ( $a['updatedAt'] ?? '' ) ) );
		return $matches[0] ?? null;
	}

	/** Units of $to per 1 $from, or null. */
	public function rate( $from, $to ) {
		if ( $from === $to ) {
			return 1.0;
		}
		$res = $this->request( 'GET', '/api/v1/org/rates', null, array( 'fromSymbols' => $from, 'toSymbols' => $to ) );
		$val = $res['ok'] ? ( $res['body']['data']['rates'][ $from ][ $to ] ?? null ) : null;
		return ( is_numeric( $val ) && (float) $val > 0 ) ? (float) $val : null;
	}

	private function log( $msg ) {
		if ( $this->logger ) {
			$this->logger->info( $msg, array( 'source' => 'ehmar-afriex' ) );
		}
	}
}
