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
	 * @param array  $context Optional log context: type + appointment_id.
	 * @return array|WP_Error
	 */
	public function send_pattern( $to, $body_id, array $args, array $context = array() ) {
		$settings = $this->get_settings();
		$api_key  = $this->get_api_key( $settings );
		$payload  = array(
			'bodyId' => absint( $body_id ),
			'to'     => $this->clean_phone( $to ),
			'args'   => $this->clean_args( $args ),
		);

		if ( empty( $api_key ) || ! preg_match( '/^09\d{9}$/', $payload['to'] ) || empty( $payload['bodyId'] ) ) {
			$error = new WP_Error( 'hmn_crm_sms_pattern_invalid_input', __( 'کلید API، شماره گیرنده و کد الگو الزامی است.', 'hmn-crm' ) );
			$this->log_attempt( $payload['to'], $context, $body_id, $error );
			return $error;
		}

		$result = $this->perform_request( self::SHARED_ENDPOINT . rawurlencode( $api_key ), $payload, 'shared' );
		$this->log_attempt( $payload['to'], $context, $body_id, $result );
		return $result;
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
		$result   = $this->send_pattern( $mobile, $body_id, array( $code ), array( 'type' => 'otp' ) );

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
			$error = new WP_Error( 'hmn_crm_sms_advanced_invalid_input', __( 'کلید API، شماره فرستنده، شماره گیرنده و متن پیامک الزامی است.', 'hmn-crm' ) );
			$this->log_attempt( $to, array( 'type' => 'advanced' ), 0, $error );
			return $error;
		}

		$result = $this->perform_request( self::ADVANCED_ENDPOINT . rawurlencode( $api_key ), array(
			'from' => $from,
			'to'   => array( $to ),
			'text' => $text,
			'udh'  => '',
		), 'advanced' );
		$this->log_attempt( $to, array( 'type' => 'advanced' ), 0, $result );
		return $result;
	}

	/** Record a send attempt in the delivery log, extracting the recId on success. */
	private function log_attempt( $phone, $context, $body_id, $result ) {
		$type           = isset( $context['type'] ) && is_scalar( $context['type'] ) ? (string) $context['type'] : 'pattern';
		$appointment_id = isset( $context['appointment_id'] ) ? absint( $context['appointment_id'] ) : 0;
		if ( is_wp_error( $result ) ) {
			self::log_send( $phone, $type, $body_id, $appointment_id, 0, 'failed', $result->get_error_message() );
			return;
		}
		self::log_send( $phone, $type, $body_id, $appointment_id, self::extract_recid( $result ), 'sent' );
	}

	/** Credit-balance endpoint of the Melipayamak console. */
	const CREDIT_ENDPOINT = 'https://console.melipayamak.com/api/receive/credit/';

	/** Delivery-report endpoint of the Melipayamak console. */
	const STATUS_ENDPOINT = 'https://console.melipayamak.com/api/receive/status/';

	/** Cached SMS credit status: green = connected with balance, red = error, gray = unconfigured. */
	public static function credit_status( $force_refresh = false ) {
		$cache_key = 'hmn_crm_sms_credit_status';
		$cached    = get_transient( $cache_key );
		if ( ! $force_refresh && false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$settings = get_option( 'hmn_crm_sms_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$api_key  = isset( $settings['melipayamak_api_key'] ) && is_scalar( $settings['melipayamak_api_key'] ) ? trim( (string) $settings['melipayamak_api_key'] ) : '';

		if ( '' === $api_key ) {
			$status = array( 'state' => 'unset', 'credit' => null, 'message' => 'کلید API پیامک تنظیم نشده است.' );
			set_transient( $cache_key, $status, 10 * MINUTE_IN_SECONDS );
			return $status;
		}

		$response = wp_remote_get( self::CREDIT_ENDPOINT . rawurlencode( $api_key ), array(
			'timeout' => 12,
			'headers' => array( 'Content-Type' => 'application/json' ),
		) );

		if ( is_wp_error( $response ) ) {
			$status = array( 'state' => 'error', 'credit' => null, 'message' => 'اتصال به ملی‌پیامک برقرار نشد.' );
			set_transient( $cache_key, $status, 5 * MINUTE_IN_SECONDS );
			return $status;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$json        = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $status_code < 200 || $status_code >= 300 || ! is_array( $json ) ) {
			$status = array( 'state' => 'error', 'credit' => null, 'message' => 'کلید API پیامک نامعتبر است.' );
			set_transient( $cache_key, $status, 5 * MINUTE_IN_SECONDS );
			return $status;
		}

		/* Console credit API answers with { "credit": <number> } (or "value" on some plans). */
		$credit = null;
		foreach ( array( 'credit', 'value', 'amount' ) as $key ) {
			if ( isset( $json[ $key ] ) && is_numeric( $json[ $key ] ) ) { $credit = (int) $json[ $key ]; break; }
		}

		if ( null === $credit ) {
			$status = array( 'state' => 'error', 'credit' => null, 'message' => 'پاسخ نامشخص از ملی‌پیامک.' );
		} elseif ( $credit <= 0 ) {
			$status = array( 'state' => 'empty', 'credit' => $credit, 'message' => 'شارژ پنل پیامک تمام شده است.' );
		} else {
			$status = array( 'state' => 'ok', 'credit' => $credit, 'message' => 'شارژ: ' . number_format( $credit ) );
		}
		set_transient( $cache_key, $status, 10 * MINUTE_IN_SECONDS );
		return $status;
	}

	/** SMS delivery-log table schema version. */
	const TABLE_VERSION = '1.0';

	/** Option key holding the installed delivery-log table version. */
	const OPTION_VERSION = 'hmn_crm_sms_log_table_version';

	/** Create the SMS delivery-log table (versioned migration, run on hmn_crm_migrate). */
	public static function install() {
		if ( self::TABLE_VERSION === get_option( self::OPTION_VERSION ) ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			phone varchar(20) NOT NULL DEFAULT '',
			message_type varchar(20) NOT NULL DEFAULT 'pattern',
			template_id bigint(20) unsigned NOT NULL DEFAULT 0,
			appointment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			rec_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'sent',
			error text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY rec_id (rec_id),
			KEY appointment_id (appointment_id)
		) {$charset};";
		dbDelta( $sql );
		update_option( self::OPTION_VERSION, self::TABLE_VERSION, false );
	}

	/** Delivery-log table name. */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'hmn_crm_sms_log';
	}

	/** Extract the Melipayamak recId from a send result, or 0 when absent. */
	public static function extract_recid( $result ) {
		if ( ! is_array( $result ) ) { return 0; }
		foreach ( array( 'recId', 'recid', 'value' ) as $key ) {
			if ( isset( $result[ $key ] ) && is_numeric( $result[ $key ] ) ) { return absint( $result[ $key ] ); }
		}
		return 0;
	}

	/** Append one send attempt to the delivery log; prune rows older than 120 days at most hourly. */
	public static function log_send( $phone, $type, $body_id, $appointment_id, $rec_id, $status, $error = '' ) {
		global $wpdb;
		$wpdb->insert( self::table_name(), array(
			'phone'          => preg_replace( '/\D+/', '', (string) $phone ),
			'message_type'   => substr( sanitize_key( (string) $type ), 0, 20 ),
			'template_id'    => absint( $body_id ),
			'appointment_id' => absint( $appointment_id ),
			'rec_id'         => absint( $rec_id ),
			'status'         => ( 'failed' === $status ) ? 'failed' : 'sent',
			'error'          => (string) $error,
			'created_at'     => current_time( 'mysql' ),
		), array( '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' ) );
		if ( ! get_transient( 'hmn_crm_sms_log_pruned' ) ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - 120 * DAY_IN_SECONDS );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table_name() . ' WHERE created_at < %s', $cutoff ) );
			set_transient( 'hmn_crm_sms_log_pruned', 1, HOUR_IN_SECONDS );
		}
	}

	/** Most recent delivery-log rows, newest first. */
	public static function recent_logs( $limit = 30 ) {
		global $wpdb;
		$limit = max( 1, min( 200, absint( $limit ) ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT id, phone, message_type, template_id, appointment_id, rec_id, status, error, created_at FROM ' . self::table_name() . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** Persist the recId of a successful pattern send against the appointment it belongs to. */
	public static function record_recid( $appointment_id, $result ) {
		$appointment_id = absint( $appointment_id );
		if ( ! $appointment_id || ! is_array( $result ) ) { return; }
		$rec_id = self::extract_recid( $result );
		if ( ! $rec_id ) { return; }
		$map   = get_option( 'hmn_crm_sms_recids', array() );
		$map   = is_array( $map ) ? $map : array();
		$map[ (string) $appointment_id ] = array( 'r' => $rec_id, 't' => time() );
		/* Keep the store bounded: drop entries older than 90 days and cap at 3000 rows. */
		foreach ( $map as $key => $entry ) { if ( ( $entry['t'] ?? 0 ) < time() - 90 * DAY_IN_SECONDS ) { unset( $map[ $key ] ); } }
		if ( count( $map ) > 3000 ) {
			uasort( $map, function( $a, $b ) { return ( $a['t'] ?? 0 ) <=> ( $b['t'] ?? 0 ); } );
			$map = array_slice( $map, -3000, null, true );
		}
		update_option( 'hmn_crm_sms_recids', $map, false );
	}

	/** RecId map (appointment ID => recId) for the given appointment IDs. */
	public static function recids_for_appointments( array $appointment_ids ) {
		if ( empty( $appointment_ids ) ) { return array(); }
		$map = get_option( 'hmn_crm_sms_recids', array() );
		$map = is_array( $map ) ? $map : array();
		$found = array();
		foreach ( $appointment_ids as $id ) { $key = (string) absint( $id ); if ( isset( $map[ $key ]['r'] ) ) { $found[ $key ] = absint( $map[ $key ]['r'] ); } }
		return $found;
	}

	/** Delivery states for the given recIds: map recId => delivered|pending|failed. Cached 30 minutes. */
	public static function delivery_statuses( array $rec_ids ) {
		$rec_ids = array_values( array_unique( array_filter( array_map( 'absint', $rec_ids ) ) ) );
		if ( empty( $rec_ids ) ) { return array(); }
		$cache = get_transient( 'hmn_crm_sms_delivery_cache' );
		$cache = is_array( $cache ) ? $cache : array();
		$missing = array();
		foreach ( $rec_ids as $rec_id ) { if ( ! isset( $cache[ $rec_id ] ) ) { $missing[] = $rec_id; } }
		if ( ! empty( $missing ) ) {
			foreach ( self::fetch_delivery_statuses( $missing ) as $rec_id => $state ) { $cache[ $rec_id ] = $state; }
			if ( count( $cache ) > 2000 ) { $cache = array_slice( $cache, -2000, null, true ); }
			set_transient( 'hmn_crm_sms_delivery_cache', $cache, 30 * MINUTE_IN_SECONDS );
		}
		$result = array();
		foreach ( $rec_ids as $rec_id ) { $result[ $rec_id ] = isset( $cache[ $rec_id ] ) ? $cache[ $rec_id ] : 'pending'; }
		return $result;
	}

	/** Ask the console status endpoint; its lists align positionally with the requested recIds. */
	private static function fetch_delivery_statuses( array $rec_ids ) {
		$settings = get_option( 'hmn_crm_sms_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$api_key  = isset( $settings['melipayamak_api_key'] ) && is_scalar( $settings['melipayamak_api_key'] ) ? trim( (string) $settings['melipayamak_api_key'] ) : '';
		if ( '' === $api_key ) { return array(); }
		$response = wp_remote_post( self::STATUS_ENDPOINT . rawurlencode( $api_key ), array(
			'timeout' => 12,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'recIds' => array_map( 'absint', $rec_ids ) ) ),
		) );
		if ( is_wp_error( $response ) ) { return array(); }
		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$json        = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status_code < 200 || $status_code >= 300 || ! is_array( $json ) ) { return array(); }

		$codes  = isset( $json['resultsAsCode'] ) && is_array( $json['resultsAsCode'] ) ? array_values( $json['resultsAsCode'] ) : array();
		$texts  = isset( $json['results'] ) && is_array( $json['results'] ) ? array_values( $json['results'] ) : array();
		$states = array();
		foreach ( $rec_ids as $index => $rec_id ) {
			if ( array_key_exists( $index, $codes ) ) { $states[ $rec_id ] = self::normalize_delivery_state( $codes[ $index ] ); }
			elseif ( array_key_exists( $index, $texts ) ) { $states[ $rec_id ] = self::normalize_delivery_state( $texts[ $index ] ); }
			else { $states[ $rec_id ] = 'pending'; }
		}
		/* Some plans answer with an object map of recId => status instead of positional lists. */
		if ( empty( $codes ) && empty( $texts ) ) {
			foreach ( $json as $key => $value ) {
				if ( is_array( $value ) ) {
					$states[ absint( $value['recId'] ?? $key ) ] = self::normalize_delivery_state( $value['status'] ?? $value['state'] ?? $value['value'] ?? '' );
				} elseif ( is_numeric( $key ) ) {
					$states[ absint( $key ) ] = self::normalize_delivery_state( $value );
				}
			}
		}
		return $states;
	}

	/** Map Melipayamak delivery codes to a simple state: 2 = delivered, 3/16 = failed, rest = pending. */
	private static function normalize_delivery_state( $value ) {
		if ( is_string( $value ) && ! is_numeric( $value ) ) {
			$value = trim( $value );
			if ( '' === $value ) { return 'pending'; }
			if ( false !== mb_stripos( $value, 'خطا' ) || false !== stripos( $value, 'fail' ) || false !== stripos( $value, 'error' ) ) { return 'failed'; }
			if ( false !== stripos( $value, 'deliv' ) || false !== mb_stripos( $value, 'تحویل' ) ) { return 'delivered'; }
			return 'pending';
		}
		$code = (int) $value;
		if ( 2 === $code ) { return 'delivered'; }
		if ( 3 === $code || 16 === $code ) { return 'failed'; }
		return 'pending';
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
