<?php
/**
 * Melipayamak Console SMS services.
 *
 * @package HMN_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Send SMS through the current Melipayamak Console REST APIs only. */
class HMN_CRM_SMS {

	/** Approved-pattern / service-line endpoint. */
	const SHARED_ENDPOINT = 'https://console.melipayamak.com/api/send/shared/';

	/** Free-text endpoint for approved sender lines. */
	const ADVANCED_ENDPOINT = 'https://console.melipayamak.com/api/send/advanced/';

	/**
	 * Send an approved Melipayamak pattern.
	 *
	 * Use this method for service-line OTP and booking notifications. Pattern
	 * variables must be supplied in the same order as the approved pattern.
	 *
	 * @param string $to Recipient mobile number.
	 * @param int    $body_id Melipayamak pattern ID.
	 * @param array  $args Ordered pattern values.
	 * @return array|WP_Error
	 */
	public function send_pattern( $to, $body_id, array $args ) {
		$settings = $this->get_settings();
		$api_key  = $this->get_api_key( $settings );
		$payload  = array(
			'bodyId' => absint( $body_id ),
			'to'     => $this->clean_phone( $to ),
			'args'   => $this->clean_args( $args ),
		);

		if ( empty( $api_key ) || ! preg_match( '/^09\d{9}$/', $payload['to'] ) || empty( $payload['bodyId'] ) ) {
			return new WP_Error( 'hmn_crm_sms_pattern_invalid_input', __( 'کلید API، شماره گیرنده و کد الگو الزامی است.', 'hmn-crm' ) );
		}

		return $this->perform_request( self::SHARED_ENDPOINT . rawurlencode( $api_key ), $payload, 'shared' );
	}

	/**
	 * Generate and send a five-digit OTP using the configured service pattern.
	 *
	 * The OTP pattern must contain exactly one variable for the generated code.
	 *
	 * @param string $mobile Recipient mobile number.
	 * @return array|WP_Error Includes `code` only after a successful send.
	 */
	public function send_otp( $mobile ) {
		$settings = $this->get_settings();
		$body_id  = isset( $settings['melipayamak_otp_body_id'] ) && is_scalar( $settings['melipayamak_otp_body_id'] ) ? absint( $settings['melipayamak_otp_body_id'] ) : 0;
		$code     = (string) wp_rand( 10000, 99999 );
		$result   = $this->send_pattern( $mobile, $body_id, array( $code ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['code'] = $code;
		return $result;
	}

	/**
	 * Send free text through the Console Advanced API.
	 *
	 * This is retained for manual or future non-template messages; booking and
	 * OTP notifications use send_pattern() because service patterns are more
	 * reliable for Iranian mobile recipients.
	 *
	 * @param string $to Recipient mobile number.
	 * @param string $text Message body.
	 * @param string $from Optional approved sender line.
	 * @return array|string|WP_Error
	 */
	public function send_advanced( $to, $text, $from = '' ) {
		$settings = $this->get_settings();
		$api_key  = $this->get_api_key( $settings );
		$to       = $this->clean_phone( $to );
		$from     = '' !== trim( (string) $from ) ? $this->clean_sender( $from ) : $this->clean_sender( $this->scalar_setting( $settings, 'melipayamak_sender' ) );
		$text     = is_scalar( $text ) ? sanitize_textarea_field( wp_unslash( (string) $text ) ) : '';

		if ( empty( $api_key ) || ! preg_match( '/^09\d{9}$/', $to ) || empty( $from ) || empty( $text ) ) {
			return new WP_Error( 'hmn_crm_sms_advanced_invalid_input', __( 'کلید API، شماره فرستنده، شماره گیرنده و متن پیامک الزامی است.', 'hmn-crm' ) );
		}

		return $this->perform_request( self::ADVANCED_ENDPOINT . rawurlencode( $api_key ), array(
			'from' => $from,
			'to'   => array( $to ),
			'text' => $text,
			'udh'  => '',
		), 'advanced' );
	}

	/** Execute a Console API request and normalize its result. */
	private function perform_request( $url, $payload, $method ) {
		$response = wp_remote_post( $url, array(
			'timeout' => 20,
			'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
			'body'    => wp_json_encode( $payload ),
		) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'hmn_crm_sms_connection_error', sprintf( __( 'اتصال به سرور ملی‌پیامک برقرار نشد: %s', 'hmn-crm' ), sanitize_text_field( $response->get_error_message() ) ), array( 'payload' => $payload, 'method' => $method ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$json   = json_decode( $raw, true );
		$success = $status >= 200 && $status < 300 && ( 'advanced' === $method || ( is_array( $json ) && ! empty( $json['recId'] ) ) );
		if ( $success ) {
			return is_array( $json ) ? $json : array( 'raw_response' => $raw );
		}

		$message = is_array( $json ) && ! empty( $json['status'] ) ? sanitize_text_field( $json['status'] ) : ( is_array( $json ) && ! empty( $json['message'] ) ? sanitize_text_field( $json['message'] ) : __( 'ارسال پیامک ناموفق بود.', 'hmn-crm' ) );
		if ( 401 === $status || 403 === $status ) {
			$message = __( 'کلید API نامعتبر است یا دسترسی ارسال پیامک ندارد.', 'hmn-crm' );
		} elseif ( 400 === $status ) {
			$message = __( 'درخواست نامعتبر است؛ کد الگو و ترتیب متغیرهای آن را بررسی کنید.', 'hmn-crm' );
		}

		return new WP_Error( 'hmn_crm_sms_api_error', sprintf( '%s (HTTP %d، پاسخ: %s)', $message, $status, $this->safe_raw( $raw ) ), array( 'payload' => $payload, 'status_code' => $status, 'raw_response' => $raw, 'method' => $method ) );
	}

	private function get_settings() { $settings = get_option( 'hmn_crm_sms_settings', array() ); return is_array( $settings ) ? $settings : array(); }
	private function get_api_key( $settings ) { return $this->scalar_setting( $settings, 'melipayamak_api_key' ); }
	private function scalar_setting( $settings, $key, $default = '' ) { return isset( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) ? sanitize_text_field( $settings[ $key ] ) : $default; }
	private function clean_phone( $phone ) { return is_scalar( $phone ) ? preg_replace( '/\D+/', '', sanitize_text_field( wp_unslash( (string) $phone ) ) ) : ''; }
	private function clean_sender( $sender ) { return is_scalar( $sender ) ? preg_replace( '/[^0-9+]/', '', sanitize_text_field( wp_unslash( (string) $sender ) ) ) : ''; }
	private function clean_args( $args ) { $values = array(); foreach ( $args as $value ) { if ( is_scalar( $value ) ) { $values[] = sanitize_text_field( wp_unslash( (string) $value ) ); } } return $values; }
	private function safe_raw( $raw ) { return sanitize_text_field( preg_replace( '/[\r\n\t]+/', ' ', (string) $raw ) ); }
}
