<?php
/**
 * Rung 2: Google Search Console insights via the anonymous grant.
 *
 * The flow, end to end:
 *   1. The audit page mints a single-use nonce (15-min transient) and sends
 *      the admin to app.seomatic.ai/connect/wp-insights with this site's URL.
 *   2. SEOmatic runs the Google consent and then delivers the grant token
 *      HERE, server-to-server, at POST /?rest_route=/seomatic/v1/grant,
 *      authenticated by that nonce. The token never rides a browser URL on
 *      this site — not in history, not in access logs, not in referers.
 *   3. The insights renderer calls SEOmatic's /api/v1/insights with the
 *      stored token and caches the findings for 12 hours.
 *
 * The nonce is the whole authentication of step 2: single-use (deleted on
 * first accepted delivery), short-lived, and compared with hash_equals. An
 * attacker who cannot read this site's DB cannot forge a delivery, and one
 * who can read the DB already has the options table the token lands in.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEOmatic_Insights {

	const NONCE_TRANSIENT  = 'seomatic_gsc_connect_nonce';
	const TOKEN_OPTION     = 'seomatic_gsc_grant_token';
	const DOMAIN_OPTION    = 'seomatic_gsc_grant_domain';
	const CACHE_TRANSIENT  = 'seomatic_gsc_insights';
	const CACHE_TTL        = 12 * HOUR_IN_SECONDS;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			'seomatic/v1',
			'/grant',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'receive_grant' ),
				// Deliberately open: the caller is SEOmatic's server, which
				// has no WordPress user. The single-use nonce below is the
				// authentication.
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'seomatic/v1',
			'/ask',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'proxy_ask' ),
				// Admin-only, cookie+nonce authenticated (X-WP-Nonce). The
				// proxy exists so the SEOmatic API key NEVER reaches browser
				// JS — it stays server-side and rides only this hop.
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/** Rung 3: forward one question to SEOmatic's /api/v1/ask. */
	public static function proxy_ask( WP_REST_Request $request ) {
		$api_key = get_option( 'seomatic_connect_api_key', '' );
		if ( '' === $api_key ) {
			return new WP_Error( 'no_key', 'No SEOmatic account connected.', array( 'status' => 400 ) );
		}
		$question = trim( (string) $request->get_param( 'question' ) );
		if ( '' === $question || mb_strlen( $question ) > 2000 ) {
			return new WP_Error( 'bad_question', 'Question required (max 2000 chars).', array( 'status' => 400 ) );
		}
		$response = wp_remote_post(
			SEOMATIC_CONNECT_APP_URL . '/api/v1/ask',
			array(
				'timeout' => 60,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode(
					array(
						'question' => $question,
						// Funnel attribution: names the surface, grants nothing.
						'client'   => 'wp-plugin',
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'upstream', 'SEOmatic could not be reached.', array( 'status' => 502 ) );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'upstream', 'Unexpected answer from SEOmatic.', array( 'status' => 502 ) );
		}
		// Pass the app's own status through — 402 is the quota wall and
		// carries the upgrade path in `error`; the UI relays it verbatim
		// (never refuse on the user's behalf).
		return new WP_REST_Response( $body, $code );
	}

	/** Mint (or reuse) the connect nonce and build the consent URL. */
	public static function connect_url() {
		$nonce = get_transient( self::NONCE_TRANSIENT );
		if ( ! is_string( $nonce ) || '' === $nonce ) {
			$nonce = wp_generate_password( 32, false, false );
			set_transient( self::NONCE_TRANSIENT, $nonce, 15 * MINUTE_IN_SECONDS );
		}
		return SEOMATIC_CONNECT_APP_URL . '/connect/wp-insights?site='
			. rawurlencode( home_url() ) . '&wpnonce=' . rawurlencode( $nonce );
	}

	public static function receive_grant( WP_REST_Request $request ) {
		$expected = get_transient( self::NONCE_TRANSIENT );
		$nonce    = (string) $request->get_param( 'nonce' );
		if ( ! is_string( $expected ) || '' === $expected
			|| ! hash_equals( $expected, $nonce ) ) {
			return new WP_Error( 'bad_nonce', 'No pending connection.', array( 'status' => 403 ) );
		}

		$token  = (string) $request->get_param( 'grantToken' );
		$domain = strtolower( (string) $request->get_param( 'domain' ) );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{20,64}$/', $token )
			|| ! preg_match( '/^[a-z0-9.-]{4,253}$/', $domain ) ) {
			return new WP_Error( 'bad_payload', 'Invalid grant.', array( 'status' => 400 ) );
		}

		// Single-use: the nonce dies on the first accepted delivery, so a
		// replay of the same POST is a 403, not a silent overwrite.
		delete_transient( self::NONCE_TRANSIENT );
		update_option( self::TOKEN_OPTION, $token, false );
		update_option( self::DOMAIN_OPTION, $domain, false );
		delete_transient( self::CACHE_TRANSIENT );

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/** The signup URL, carrying the grant token so the new account lands
	 * with Search Console already connected (SEOmatic claims it at signup). */
	public static function signup_url() {
		$token = get_option( self::TOKEN_OPTION, '' );
		$base  = SEOMATIC_CONNECT_APP_URL . '/connect/wp-insights/signup';
		return '' === $token
			? SEOMATIC_CONNECT_APP_URL . '/signup?src=wp-plugin'
			: $base . '?gsc_grant=' . rawurlencode( $token );
	}

	/**
	 * Zero-paste key delivery: once the visitor's signup has claimed the
	 * grant, the grant token can be traded ONCE for a free-scoped API key
	 * (the app's /api/v1/wp-key, single-use latch server-side). Tried
	 * lazily on audit-page loads — cheap, and a 10-minute backoff keeps an
	 * unclaimed grant from probing on every refresh. Returns true when a
	 * key was just delivered (the page shows a one-time notice).
	 */
	public static function maybe_exchange_key() {
		if ( '' !== get_option( 'seomatic_connect_api_key', '' ) ) {
			return false; // already keyed (pasted or previously exchanged).
		}
		$token = get_option( self::TOKEN_OPTION, '' );
		if ( '' === $token || get_transient( 'seomatic_key_exchange_backoff' ) ) {
			return false;
		}
		set_transient( 'seomatic_key_exchange_backoff', 1, 10 * MINUTE_IN_SECONDS );
		$response = wp_remote_post(
			SEOMATIC_CONNECT_APP_URL . '/api/v1/wp-key',
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'grantToken' => $token,
						'domain'     => get_option( self::DOMAIN_OPTION, '' ),
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['found'] ) || empty( $body['apiKey'] )
			|| ! preg_match( '/^smk_[A-Za-z0-9_]{8,128}$/', (string) $body['apiKey'] ) ) {
			return false;
		}
		update_option( 'seomatic_connect_api_key', (string) $body['apiKey'] );
		delete_transient( 'seomatic_connect_status' );
		return true;
	}

	public static function connected() {
		return '' !== get_option( self::TOKEN_OPTION, '' );
	}

	public static function disconnect() {
		delete_option( self::TOKEN_OPTION );
		delete_option( self::DOMAIN_OPTION );
		delete_transient( self::CACHE_TRANSIENT );
	}

	/**
	 * Fetch the four findings, cached 12h. Returns:
	 *   array  — the insights payload
	 *   'expired' — the grant is dead (30-day TTL) or revoked: reconnect
	 *   'error'   — transient upstream failure: keep calm, retry later
	 *   null      — not connected
	 */
	public static function insights( $force = false ) {
		if ( ! self::connected() ) {
			return null;
		}
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$response = wp_remote_post(
			SEOMATIC_CONNECT_APP_URL . '/api/v1/insights',
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'grantToken' => get_option( self::TOKEN_OPTION, '' ),
						'domain'     => get_option( self::DOMAIN_OPTION, '' ),
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return 'error';
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $body ) ) {
			return 'error';
		}
		if ( empty( $body['found'] ) ) {
			// Dead grant — 30-day TTL, revocation, or property mismatch.
			// Honest state beats a silent empty dashboard.
			return 'expired';
		}
		$body['fetched_at'] = time();
		set_transient( self::CACHE_TRANSIENT, $body, self::CACHE_TTL );
		return $body;
	}
}
