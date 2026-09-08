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
	 * @param array        $args    Pattern values; each item has key and value.
	 * @return array|WP_Error Decoded API response or a WordPress error.
	 */
	public function send_pattern( $to, $body_id, $args = array() ) {
		$to      = is_scalar( $to ) ? sanitize_text_field( wp_unslash( (string) $to ) ) : '';
		$body_id = is_scalar( $body_id ) ? absint( $body_id ) : 0;

		if ( empty( $to ) || empty( $body_id ) ) {
			return new WP_Error( 'hmn_crm_sms_invalid_input', __( 'شماره گیرنده و شناسه الگو الزامی است.', 'hmn-crm' ) );
		}

		$settings = get_option( 'hmn_crm_sms_settings', array() );
		$api_key  = isset( $settings['api_key'] ) && is_scalar( $settings['api_key'] ) ? sanitize_text_field( $settings['api_key'] ) : '';
		if ( empty( $api_key ) ) {
			return new WP_Error( 'hmn_crm_sms_missing_api_key', __( 'کلید API پیامک تنظیم نشده است.', 'hmn-crm' ) );
		}

		$items = array();
		foreach ( (array) $args as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['key'], $item['value'] ) ) {
				continue;
			}

			if ( ! is_scalar( $item['key'] ) || ! is_scalar( $item['value'] ) ) {
				continue;
			}

			$items[] = array(
				'key'   => sanitize_text_field( wp_unslash( (string) $item['key'] ) ),
				'value' => sanitize_text_field( wp_unslash( (string) $item['value'] ) ),
			);
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
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $response_body, true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			return new WP_Error( 'hmn_crm_sms_api_error', __( 'خطا در سرویس پیامک.', 'hmn-crm' ), array( 'status' => $status_code, 'body' => $decoded ? $decoded : $response_body ) );
		}

		return is_array( $decoded ) ? $decoded : array( 'status' => $status_code, 'body' => $response_body );
	}
}
