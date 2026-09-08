<?php
/**
 * Pattern SMS service for Melipayamak REST API.
 *
 * @package HMN_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Send pattern-based SMS messages.
 */
class HMN_CRM_SMS {

	/**
	 * Melipayamak shared-pattern endpoint.
	 *
	 * @var string
	 */
	const API_ENDPOINT = 'https://api.melipayamak.com/api/send/shared/';

	/**
	 * Send a pattern SMS to a recipient.
	 *
	 * @param string       $to      Recipient mobile number.
	 * @param int|string   $body_id Melipayamak pattern/body ID.
	 * @param array        $args    Pattern values in API order, e.g. array( '54321' ).
	 * @return array|WP_Error Decoded API response or a WordPress error.
	 */
	public function send_pattern( $to, $body_id, array $args ) {
		$to      = is_scalar( $to ) ? sanitize_text_field( wp_unslash( (string) $to ) ) : '';
		$body_id = is_scalar( $body_id ) ? absint( $body_id ) : 0;

		if ( empty( $to ) || empty( $body_id ) ) {
			return new WP_Error( 'hmn_crm_sms_invalid_input', __( 'شماره گیرنده و شناسه الگو الزامی است.', 'hmn-crm' ) );
		}

		$settings = get_option( 'hmn_crm_sms_settings', array() );
		$api_key_value = isset( $settings['melipayamak_api_key'] ) ? $settings['melipayamak_api_key'] : ( isset( $settings['api_key'] ) ? $settings['api_key'] : '' );
		$api_key  = is_scalar( $api_key_value ) ? sanitize_text_field( $api_key_value ) : '';
		if ( empty( $api_key ) ) {
			return new WP_Error( 'hmn_crm_sms_missing_api_key', __( 'کلید API پیامک تنظیم نشده است.', 'hmn-crm' ) );
		}

		$items = array();
		foreach ( (array) $args as $value ) {
			if ( is_scalar( $value ) ) {
				$items[] = sanitize_text_field( wp_unslash( (string) $value ) );
			}
		}

		$payload = array(
			'bodyId' => $body_id,
			'to'     => $to,
			'args'   => $items,
		);

		$response = wp_remote_post(
			self::API_ENDPOINT . rawurlencode( $api_key ),
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'hmn_crm_sms_http_error', $this->mask_sensitive_data( $response->get_error_message() ), array( 'source' => 'transport' ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $response_body, true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$api_message = $this->extract_api_error_message( $decoded, $response_body );
			$message = sprintf( __( 'خطای سرویس پیامک (HTTP %1$d): %2$s', 'hmn-crm' ), $status_code, $api_message );
			return new WP_Error( 'hmn_crm_sms_api_error', $this->mask_sensitive_data( $message ), array( 'status' => $status_code ) );
		}

		return is_array( $decoded ) ? $decoded : array( 'status' => $status_code, 'body' => $response_body );
	}

	/** Extract a useful, non-sensitive message from a JSON or text API response. */
	private function extract_api_error_message( $decoded, $raw_body ) {
		if ( is_array( $decoded ) ) {
			foreach ( array( 'message', 'error', 'detail', 'description', 'Message', 'Error' ) as $key ) {
				if ( isset( $decoded[ $key ] ) && is_scalar( $decoded[ $key ] ) && '' !== trim( (string) $decoded[ $key ] ) ) {
					return sanitize_text_field( (string) $decoded[ $key ] );
				}
			}
			$flattened = wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE );
			if ( $flattened ) { return sanitize_text_field( $flattened ); }
		}
		$raw_body = is_scalar( $raw_body ) ? trim( (string) $raw_body ) : '';
		return '' !== $raw_body ? sanitize_text_field( $raw_body ) : __( 'پاسخ نامشخصی از سرویس دریافت شد.', 'hmn-crm' );
	}

	/** Remove the configured API key from any message before logging/displaying. */
	private function mask_sensitive_data( $message ) {
		$settings = get_option( 'hmn_crm_sms_settings', array() );
		$key = isset( $settings['melipayamak_api_key'] ) ? $settings['melipayamak_api_key'] : ( isset( $settings['api_key'] ) ? $settings['api_key'] : '' );
		$message = is_scalar( $message ) ? (string) $message : '';
		if ( is_scalar( $key ) && '' !== (string) $key ) { $message = str_replace( (string) $key, '[MASKED_API_KEY]', $message ); }
		return sanitize_text_field( $message );
	}
}
