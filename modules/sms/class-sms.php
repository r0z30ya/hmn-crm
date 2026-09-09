<?php
/**
 * Melipayamak REST SMS services.
 *
 * @package HMN_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Send pattern SMS using either supported Melipayamak REST contract. */
class HMN_CRM_SMS {

	/** Shared API endpoint (method 2). */
	const API_ENDPOINT = 'https://console.melipayamak.com/api/send/shared/';

	/** BaseServiceNumber endpoint (method 1). */
	const METHOD_ONE_ENDPOINT = 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber';

	/**
	 * Backward-compatible pattern sender; this is method 2.
	 *
	 * @param string $to Recipient mobile number.
	 * @param int    $body_id Pattern ID.
	 * @param array  $args Ordered pattern values.
	 * @return array|WP_Error
	 */
	public function send_pattern( $to, $body_id, array $args ) {
		return $this->send_method_two( $to, $body_id, $args );
	}

	/** Send with BaseServiceNumber (method 1). */
	public function send_method_one( $to, $body_id, array $args ) {
		$settings = $this->get_settings();
		$payload  = array(
			'username' => $this->scalar_setting( $settings, 'melipayamak_username' ),
			'password' => $this->scalar_setting( $settings, 'melipayamak_password', $this->get_api_key( $settings ) ),
			'to'       => $this->clean_phone( $to ),
			'text'     => $this->clean_args( $args, ';' ),
			'bodyId'   => absint( $body_id ),
		);
		return $this->perform_request( self::METHOD_ONE_ENDPOINT, $payload, 'method_one' );
	}

	/** Send with console shared endpoint (method 2). */
	public function send_method_two( $to, $body_id, array $args ) {
		$settings = $this->get_settings();
		$api_key  = $this->get_api_key( $settings );
		$payload  = array( 'bodyId' => absint( $body_id ), 'to' => $this->clean_phone( $to ), 'args' => $this->clean_args( $args ) );
		if ( empty( $payload['to'] ) || empty( $payload['bodyId'] ) || empty( $payload['args'] ) || empty( $api_key ) ) {
			return new WP_Error( 'hmn_crm_sms_invalid_input', __( 'شماره، Body ID، کلید API و حداقل یک متغیر الزامی است.', 'hmn-crm' ), array( 'payload' => $payload, 'status_code' => 0, 'raw_response' => '' ) );
		}
		return $this->perform_request( self::API_ENDPOINT . rawurlencode( $api_key ), $payload, 'method_two' );
	}

	/** Execute request and normalize response/error details. */
	private function perform_request( $url, $payload, $method ) {
		if ( empty( $payload['to'] ) || empty( $payload['bodyId'] ) ) {
			return new WP_Error( 'hmn_crm_sms_invalid_input', __( 'شماره گیرنده و شناسه الگو الزامی است.', 'hmn-crm' ), array( 'payload' => $payload, 'status_code' => 0, 'raw_response' => '' ) );
		}
		$response = wp_remote_post( $url, array( 'timeout' => 20, 'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ), 'body' => wp_json_encode( $payload ) ) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'hmn_crm_sms_connection_error', sprintf( __( 'اتصال به سرور برقرار نشد: %s', 'hmn-crm' ), sanitize_text_field( $response->get_error_message() ) ), array( 'payload' => $payload, 'status_code' => 0, 'raw_response' => '', 'method' => $method ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$json   = json_decode( $raw, true );
		if ( 'method_one' === $method ) {
			$code = $this->method_one_code( $json, $raw );
			$value = is_array( $json ) && isset( $json['Value'] ) ? (string) $json['Value'] : '';
			if ( 1 === (int) ( is_array( $json ) && isset( $json['RetStatus'] ) ? $json['RetStatus'] : 0 ) && strlen( $value ) > 15 ) {
				return is_array( $json ) ? $json : array( 'Value' => $value );
			}
			$message = $this->method_one_message( $code );
		} else {
			if ( 401 === $status || 403 === $status ) { $message = __( 'apiKey نامعتبر یا منقضی شده است', 'hmn-crm' ); }
			elseif ( 400 === $status ) { $message = __( 'درخواست نامعتبر — bodyId یا متغیرهای الگو را بررسی کنید', 'hmn-crm' ); }
			elseif ( 429 === $status ) { $message = __( 'تعداد درخواست‌ها بیش از حد مجاز — کمی بعد تلاش کنید', 'hmn-crm' ); }
			elseif ( ! is_array( $json ) ) { $message = __( 'پاسخ نامعتبر از سرور', 'hmn-crm' ); }
			elseif ( ! isset( $json['recId'] ) ) { $message = __( 'ارسال ناموفق — recId دریافت نشد', 'hmn-crm' ); }
			else { $message = __( 'ارسال ناموفق', 'hmn-crm' ); }
		}
		$message = sprintf( '%s (HTTP %d، پاسخ خام: %s)', $message, $status, $this->safe_raw( $raw ) );
		return new WP_Error( 'hmn_crm_sms_api_error', $message, array( 'payload' => $payload, 'status_code' => $status, 'raw_response' => $raw, 'method' => $method, 'code' => $code ) );
	}

	/** Map method-one raw status codes to Persian messages. */
	private function method_one_message( $code ) {
		$messages = array( 110 => 'احراز هویت ناموفق: به‌جای رمز عبور باید ApiKey وارد شود (تنظیمات توسعه‌دهندگان پنل)', -110 => 'احراز هویت ناموفق: به‌جای رمز عبور باید ApiKey وارد شود (تنظیمات توسعه‌دهندگان پنل)', 109 => 'IP سرور در پنل مجاز نشده است — در تنظیمات وب‌سرویس پنل، IP مجاز تعریف کنید', -109 => 'IP سرور در پنل مجاز نشده است — در تنظیمات وب‌سرویس پنل، IP مجاز تعریف کنید', 108 => 'IP سرور به دلیل تلاش‌های ناموفق مسدود شده — از پنل رفع مسدودی کنید', -108 => 'IP سرور به دلیل تلاش‌های ناموفق مسدود شده — از پنل رفع مسدودی کنید', -1 => 'دسترسی وب‌سرویس در پنل غیرفعال است', 0 => 'نام کاربری یا رمز عبور اشتباه است', 2 => 'موجودی حساب کافی نیست', 6 => 'سیستم در حال بروزرسانی است، بعداً تلاش کنید', 7 => 'متن پیامک حاوی کلمه فیلترشده است — با واحد پنل تماس بگیرید', 10 => 'کاربر فعال نیست', 11 => 'پیامک ارسال نشد', 12 => 'مدارک کاربر تکمیل نیست', 18 => 'شماره موبایل گیرنده نامعتبر است', 19 => 'سقف ارسال روزانه وب‌سرویس پر شده است', -2 => 'در هر درخواست فقط یک شماره مجاز است', -3 => 'خط ارسال‌کننده تعریف نشده است', -4 => 'Body ID نامعتبر است یا الگو هنوز تأیید نشده', -5 => 'متن ارسالی با متغیرهای الگو مطابقت ندارد (تعداد/ترتیب متغیرها را چک کنید)', -6 => 'خطای داخلی سرور — با پشتیبانی تماس بگیرید', -7 => 'شماره فرستنده یافت نشد', -10 => 'ارسال لینک در متغیرها مجاز نیست' );
		return isset( $messages[ $code ] ) ? $messages[ $code ] : sprintf( 'خطای ناشناخته با کد %s', $code );
	}

	/** Get method-one code from Value/RetStatus. */
	private function method_one_code( $json, $raw ) {
		if ( is_array( $json ) && isset( $json['Value'] ) && is_scalar( $json['Value'] ) && preg_match( '/^-?\d+$/', trim( (string) $json['Value'] ) ) ) {
			return (int) $json['Value'];
		}
		return is_array( $json ) && isset( $json['RetStatus'] ) ? (int) $json['RetStatus'] : (int) trim( (string) $raw );
	}
	private function get_settings() { $settings = get_option( 'hmn_crm_sms_settings', array() ); return is_array( $settings ) ? $settings : array(); }
	private function get_api_key( $settings ) { return $this->scalar_setting( $settings, 'melipayamak_api_key', $this->scalar_setting( $settings, 'api_key' ) ); }
	private function scalar_setting( $settings, $key, $default = '' ) { return isset( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) ? sanitize_text_field( $settings[ $key ] ) : $default; }
	private function clean_phone( $phone ) { return is_scalar( $phone ) ? sanitize_text_field( wp_unslash( (string) $phone ) ) : ''; }
	private function clean_args( $args, $separator = null ) { $values = array(); foreach ( $args as $value ) { if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) { $values[] = sanitize_text_field( wp_unslash( (string) $value ) ); } } return null === $separator ? $values : implode( $separator, $values ); }
	private function safe_raw( $raw ) { return sanitize_text_field( preg_replace( '/[\r\n\t]+/', ' ', (string) $raw ) ); }
}
