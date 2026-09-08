<?php
/**
 * Booking and OTP integration for JetEngine forms.
 *
 * @package HMN_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles OTP delivery/validation and booking notifications.
 */
final class HMN_CRM_Booking {

	/** OTP lifetime in seconds. */
	const OTP_TTL = 300;

	/** @var string[] Hashes of notifications sent during the current request. */
	private $sent_notifications = array();

	/** Register AJAX and JetEngine lifecycle hooks. */
	public function __construct() {
		add_action( 'wp_ajax_hmn_send_otp', array( $this, 'send_otp_ajax' ) );
		add_action( 'wp_ajax_nopriv_hmn_send_otp', array( $this, 'send_otp_ajax' ) );

		// JetEngine versions expose the custom validator with this filter.
		add_filter( 'jet-engine/forms/booking/custom-validation', array( $this, 'validate_otp' ), 10, 4 );
		// These post-submit hooks cover JetEngine/JetAppointments integrations.
		add_action( 'jet-engine/forms/booking/post-submit', array( $this, 'send_booking_sms' ), 10, 2 );
		add_action( 'jet-engine/forms/handler/after-send', array( $this, 'send_booking_sms' ), 10, 2 );
		// Can also be selected as a JetEngine “Call Hook” post-submit action.
		add_action( 'hmn_crm_booking_post_submit', array( $this, 'send_booking_sms' ), 10, 2 );
	}

	/** Process an AJAX request and send a five-digit OTP. */
	public function send_otp_ajax() {
		$phone = isset( $_POST['user_phone'] ) && is_scalar( $_POST['user_phone'] )
			? sanitize_text_field( wp_unslash( $_POST['user_phone'] ) ) : '';

		if ( isset( $_POST['nonce'] ) && ( ! is_scalar( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'hmn_send_otp' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'درخواست نامعتبر است.', 'hmn-crm' ) ), 403 );
		}

		if ( ! preg_match( '/^09\d{9}$/', $phone ) ) {
			wp_send_json_error( array( 'message' => __( 'شماره تلفن معتبر نیست.', 'hmn-crm' ) ), 400 );
		}
		if ( get_transient( 'hmn_crm_otp_rate_' . md5( $phone ) ) ) {
			wp_send_json_error( array( 'message' => __( 'لطفاً یک دقیقه دیگر دوباره تلاش کنید.', 'hmn-crm' ) ), 429 );
		}

		$code = (string) wp_rand( 10000, 99999 );
		set_transient( $this->get_transient_key( $phone ), $code, self::OTP_TTL );
		set_transient( 'hmn_crm_otp_rate_' . md5( $phone ), 1, MINUTE_IN_SECONDS );

		$settings = get_option( 'hmn_crm_sms_settings', array() );
		$body_value = isset( $settings['melipayamak_otp_body_id'] ) ? $settings['melipayamak_otp_body_id'] : ( isset( $settings['otp_body_id'] ) ? $settings['otp_body_id'] : 0 );
		$body_id  = is_scalar( $body_value ) ? absint( $body_value ) : 0;
		$sms      = new HMN_CRM_SMS();
		$result   = $sms->send_pattern( $phone, $body_id, array( $code ) );

		if ( is_wp_error( $result ) ) {
			delete_transient( $this->get_transient_key( $phone ) );
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		wp_send_json_success( array( 'message' => __( 'کد تایید ارسال شد.', 'hmn-crm' ) ) );
	}

	/** Validate OTP values supplied by any JetEngine form containing the required fields. */
	public function validate_otp( $valid, $field = array(), $value = '', $form = array() ) {
		$data = $this->extract_form_data( array( $valid, $field, $form, $_POST ) );
		if ( ! isset( $data['user_phone'], $data['otp_code'] ) ) {
			return $valid;
		}

		$phone = sanitize_text_field( (string) $data['user_phone'] );
		$otp   = sanitize_text_field( (string) $data['otp_code'] );
		$stored = get_transient( $this->get_transient_key( $phone ) );
		if ( ! $stored || ! hash_equals( (string) $stored, $otp ) ) {
			$message = __( 'کد تایید اشتباه یا منقضی شده است', 'hmn-crm' );
			foreach ( array( $field, $form ) as $handler ) {
				if ( is_object( $handler ) && method_exists( $handler, 'add_error' ) ) {
					$handler->add_error( 'otp_code', $message );
					return $valid;
				}
			}
			return new WP_Error( 'hmn_crm_invalid_otp', __( 'کد تایید اشتباه یا منقضی شده است', 'hmn-crm' ) );
		}

		delete_transient( $this->get_transient_key( $phone ) );
		return $valid;
	}

	/** Send the booking confirmation SMS after a successful submission. */
	public function send_booking_sms( $form_data = array(), $booking = array() ) {
		$data = $this->extract_form_data( array( $form_data, $booking, $_POST ) );
		if ( empty( $data['user_phone'] ) ) {
			return;
		}
		$notification_key = md5( wp_json_encode( $data ) );
		if ( in_array( $notification_key, $this->sent_notifications, true ) ) {
			return;
		}

		$settings = get_option( 'hmn_crm_sms_settings', array() );
		$body_value = isset( $settings['melipayamak_booking_body_id'] ) ? $settings['melipayamak_booking_body_id'] : ( isset( $settings['booking_body_id'] ) ? $settings['booking_body_id'] : 0 );
		$body_id  = is_scalar( $body_value ) ? absint( $body_value ) : 0;
		$args = array();
		foreach ( array( 'name', 'user_name', 'date', 'appointment_date', 'time', 'appointment_time' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
				$args[] = sanitize_text_field( wp_unslash( (string) $data[ $key ] ) );
			}
		}

		$result = ( new HMN_CRM_SMS() )->send_pattern( sanitize_text_field( (string) $data['user_phone'] ), $body_id, $args );
		if ( ! is_wp_error( $result ) ) {
			$this->sent_notifications[] = $notification_key;
		}
	}

	/** Build a stable transient key from a phone number. */
	private function get_transient_key( $phone ) {
		return 'hmn_crm_otp_' . md5( sanitize_text_field( (string) $phone ) );
	}

	/** Extract fields from the different payload shapes used by JetEngine versions. */
	private function extract_form_data( $sources ) {
		$data = array();
		foreach ( $sources as $source ) {
			if ( is_array( $source ) ) {
				$data = array_merge( $data, $source );
				foreach ( array( 'fields', 'data', 'form_data' ) as $key ) {
					if ( isset( $source[ $key ] ) && is_array( $source[ $key ] ) ) {
						$data = array_merge( $data, $source[ $key ] );
					}
				}
			}
		}
		return $data;
	}
}

new HMN_CRM_Booking();
