<?php
/** Scheduling configuration module. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class HMN_CRM_Scheduling implements HMN_CRM_Module_Interface {
	const OPTION = 'hmn_crm_scheduling_settings';
	const SYNC_STATUS_OPTION = 'hmn_crm_scheduling_sync_status';
	const ENGINE_MAP_OPTION = 'hmn_crm_scheduling_engine_map';
	const SYNC_HOOK = 'hmn_crm_sync_scheduling';
	const SYNC_LOCK = 'hmn_crm_scheduling_sync_lock';

	public function __construct() { $this->boot(); }
	public function boot() {
		add_action( 'admin_post_hmn_crm_save_scheduling', array( $this, 'save' ) );
		add_action( 'admin_post_hmn_crm_sync_scheduling', array( $this, 'retry_sync' ) );
		add_action( self::SYNC_HOOK, array( __CLASS__, 'run_scheduled_sync' ) );
	}

	public static function defaults() {
		$plan = array();
		foreach ( array( 'شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه' ) as $index => $label ) {
			$plan[ $index ] = array( 'label' => $label, 'enabled' => $index < 6 ? 1 : 0, 'start' => '09:00', 'end' => '17:00', 'break_start' => '', 'break_end' => '' );
		}
		return array( 'plan' => $plan, 'slot_step' => 15, 'buffer_minutes' => 0, 'min_notice_hours' => 2, 'max_future_days' => 30, 'daily_limit' => 0, 'concurrent_bookings' => 1, 'allow_holidays' => 0, 'cancel_hours' => 24, 'exceptions' => array() );
	}
	public static function settings() { return wp_parse_args( get_option( self::OPTION, array() ), self::defaults() ); }

	/** Return the last server-to-server synchronization result. */
	public static function sync_status() {
		$status = get_option( self::SYNC_STATUS_OPTION, array() );
		return wp_parse_args( is_array( $status ) ? $status : array(), array( 'state' => 'never', 'updated_at' => 0, 'error' => '', 'attempts' => 0 ) );
	}

	/** Queue a background synchronization without exposing the engine to the browser. */
	public static function queue_sync( $reset_attempts = false ) {
		$status = self::sync_status();
		$status['state'] = 'pending';
		$status['updated_at'] = time();
		$status['error'] = '';
		if ( $reset_attempts ) {
			$status['attempts'] = 0;
			while ( $timestamp = wp_next_scheduled( self::SYNC_HOOK ) ) { wp_unschedule_event( $timestamp, self::SYNC_HOOK ); }
		}
		update_option( self::SYNC_STATUS_OPTION, $status, false );
		if ( ! wp_next_scheduled( self::SYNC_HOOK ) ) { wp_schedule_single_event( time() + 5, self::SYNC_HOOK ); }
	}

	/** Execute one protected background sync attempt, retrying transient failures. */
	public static function run_scheduled_sync() {
		if ( get_transient( self::SYNC_LOCK ) ) { return; }
		set_transient( self::SYNC_LOCK, 1, 2 * MINUTE_IN_SECONDS );
		$status = self::sync_status();
		$sync = self::sync_to_easyappointments( self::settings() );
		if ( is_wp_error( $sync ) ) {
			$attempts = absint( $status['attempts'] ) + 1;
			update_option( self::SYNC_STATUS_OPTION, array( 'state' => 'failed', 'updated_at' => time(), 'error' => sanitize_text_field( $sync->get_error_message() ), 'attempts' => $attempts ), false );
			if ( $attempts < 5 && ! wp_next_scheduled( self::SYNC_HOOK ) ) { wp_schedule_single_event( time() + min( 6 * HOUR_IN_SECONDS, 5 * MINUTE_IN_SECONDS * (int) pow( 2, $attempts - 1 ) ), self::SYNC_HOOK ); }
			delete_transient( self::SYNC_LOCK );
			return;
		}
		update_option( self::ENGINE_MAP_OPTION, $sync, false );
		update_option( self::SYNC_STATUS_OPTION, array( 'state' => 'synced', 'updated_at' => time(), 'error' => '', 'attempts' => 0 ), false );
		delete_transient( self::SYNC_LOCK );
	}

	/**
	 * Apply HMN CRM's booking rules to the slots returned by Easy!Appointments.
	 * The engine remains the source of truth for conflicts; this is the local
	 * policy layer used by both public and operator booking flows.
	 */
	public static function filter_slots( $slots, $date, $service_id = 0, $provider_id = 0 ) {
		if ( ! is_array( $slots ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) { return array(); }
		$s = self::settings(); $tz = new DateTimeZone( 'Asia/Tehran' );
		$day = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $tz );
		if ( ! $day ) { return array(); }
		$now = new DateTimeImmutable( 'now', $tz );
		$today = $now->setTime( 0, 0 );
		if ( $day < $today || $day > $today->modify( '+' . absint( $s['max_future_days'] ?? 30 ) . ' days' ) ) { return array(); }

		$defaults = self::defaults(); $index = ( (int) $day->format( 'w' ) + 1 ) % 7; // Saturday is index 0.
		$plan = wp_parse_args( is_array( $s['plan'][ $index ] ?? null ) ? $s['plan'][ $index ] : array(), $defaults['plan'][ $index ] );
		if ( empty( $plan['enabled'] ) ) { return array(); }
		if ( self::daily_limit_reached( $date, absint( $s['daily_limit'] ?? 0 ) ) ) { return array(); }
		$start = $plan['start']; $end = $plan['end']; $blocked = array();

		foreach ( (array) ( $s['exceptions'] ?? array() ) as $exception ) {
			if ( ! self::exception_matches( $exception, $date, $service_id, $provider_id ) ) { continue; }
			$type = $exception['type'] ?? '';
			if ( ( 'holiday' === $type || 'leave' === $type ) && empty( $s['allow_holidays'] ) ) { return array(); }
			if ( 'special_hours' === $type ) {
				if ( ! empty( $exception['start_time'] ) ) { $start = $exception['start_time']; }
				if ( ! empty( $exception['end_time'] ) ) { $end = $exception['end_time']; }
			}
			if ( 'blocked' === $type ) { $blocked[] = array( $exception['start_time'] ?? '', $exception['end_time'] ?? '' ); }
		}

		$start_minutes = self::minutes( $start ); $end_minutes = self::minutes( $end );
		if ( null === $start_minutes || null === $end_minutes || $start_minutes >= $end_minutes ) { return array(); }
		$break_start = self::minutes( $plan['break_start'] ?? '' ); $break_end = self::minutes( $plan['break_end'] ?? '' );
		$interval = max( 1, absint( $s['slot_step'] ?? 15 ) + absint( $s['buffer_minutes'] ?? 0 ) );
		$minimum = $now->modify( '+' . absint( $s['min_notice_hours'] ?? 0 ) . ' hours' );
		$result = array();
		foreach ( $slots as $slot ) {
			$slot = sanitize_text_field( (string) $slot ); $minutes = self::minutes( $slot );
			if ( null === $minutes || $minutes < $start_minutes || $minutes >= $end_minutes || 0 !== ( $minutes - $start_minutes ) % $interval ) { continue; }
			if ( null !== $break_start && null !== $break_end && $minutes >= $break_start && $minutes < $break_end ) { continue; }
			$starts_at = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $date . ' ' . $slot, $tz );
			if ( ! $starts_at || $starts_at < $minimum ) { continue; }
			$denied = false;
			foreach ( $blocked as $range ) { $from = self::minutes( $range[0] ); $until = self::minutes( $range[1] ); if ( null === $from || null === $until || ( $minutes >= $from && $minutes < $until ) ) { $denied = true; break; } }
			if ( ! $denied ) { $result[] = $slot; }
		}
		return array_values( array_unique( $result ) );
	}

	/** Enforce the local daily appointment cap before displaying or accepting slots. */
	private static function daily_limit_reached( $date, $limit ) {
		if ( $limit < 1 || ! class_exists( 'HMN_CRM_EasyAppointments' ) ) { return false; }
		$appointments = HMN_CRM_EasyAppointments::request( 'GET', 'appointments', null, array( 'from' => $date, 'till' => $date, 'length' => 500 ) );
		if ( is_wp_error( $appointments ) || ! is_array( $appointments ) ) { return true; }
		$count = 0;
		foreach ( $appointments as $appointment ) {
			$status = strtolower( sanitize_key( (string) ( $appointment['status'] ?? '' ) ) );
			if ( false !== strpos( $status, 'cancel' ) || false !== strpos( $status, 'delete' ) ) { continue; }
			$start = (string) ( $appointment['start'] ?? ( $appointment['start_datetime'] ?? '' ) );
			if ( $date === substr( $start, 0, 10 ) && ++$count >= $limit ) { return true; }
		}
		return false;
	}

	/** Whether an existing appointment can be cancelled under the local policy. */
	public static function can_cancel_appointment( $appointment ) {
		$hours = absint( self::settings()['cancel_hours'] ?? 0 );
		if ( ! $hours ) { return true; }
		$start = (string) ( is_array( $appointment ) ? ( $appointment['start'] ?? ( $appointment['start_datetime'] ?? '' ) ) : '' );
		$starts_at = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $start, new DateTimeZone( 'Asia/Tehran' ) );
		if ( ! $starts_at ) { return false; }
		return new DateTimeImmutable( 'now', new DateTimeZone( 'Asia/Tehran' ) ) < $starts_at->modify( '-' . $hours . ' hours' );
	}

	private static function minutes( $time ) {
		if ( ! preg_match( '/^(\d{2}):(\d{2})$/', (string) $time, $m ) || (int) $m[1] > 23 || (int) $m[2] > 59 ) { return null; }
		return ( (int) $m[1] * 60 ) + (int) $m[2];
	}

	private static function exception_matches( $row, $date, $service_id, $provider_id ) {
		$target = (string) ( $row['target'] ?? '' );
		if ( $target && $target !== 'provider:' . absint( $provider_id ) && $target !== 'service:' . absint( $service_id ) ) { return false; }
		$from = self::jalali_to_gregorian( $row['start_date'] ?? '' ); $until = self::jalali_to_gregorian( $row['end_date'] ?? '' );
		if ( ! $from ) { return false; } if ( ! $until ) { $until = $from; }
		return $date >= $from && $date <= $until;
	}

	private static function jalali_to_gregorian( $value ) {
		$value = strtr( trim( (string) $value ), array( '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9' ) );
		if ( ! preg_match( '#^(\d{4})/(\d{1,2})/(\d{1,2})$#', $value, $m ) ) { return ''; }
		$jy = (int) $m[1] - 979; $jm = (int) $m[2]; $jd = (int) $m[3]; if ( $jm < 1 || $jm > 12 || $jd < 1 || $jd > 31 ) { return ''; }
		$days = 365 * $jy + intdiv( $jy, 33 ) * 8 + intdiv( ( $jy % 33 ) + 3, 4 ) + 78 + $jd + ( $jm < 7 ? ( $jm - 1 ) * 31 : ( $jm - 7 ) * 30 + 186 );
		$gy = 1600 + 400 * intdiv( $days, 146097 ); $days %= 146097;
		if ( $days > 36524 ) { $gy += 100 * intdiv( --$days, 36524 ); $days %= 36524; if ( $days >= 365 ) { $days++; } }
		$gy += 4 * intdiv( $days, 1461 ); $days %= 1461;
		if ( $days > 365 ) { $gy += intdiv( $days - 1, 365 ); $days = ( $days - 1 ) % 365; }
		$gd = $days + 1; $leap = ( $gy % 4 === 0 && $gy % 100 !== 0 ) || $gy % 400 === 0; $months = array( 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 ); $gm = 1;
		foreach ( $months as $length ) { if ( $gd <= $length ) { break; } $gd -= $length; $gm++; }
		return sprintf( '%04d-%02d-%02d', $gy, $gm, $gd );
	}

	/** Write supported schedule values into Easy!Appointments through its REST API. */
	private static function sync_to_easyappointments( $settings ) {
		if ( ! class_exists( 'HMN_CRM_EasyAppointments' ) || ! HMN_CRM_EasyAppointments::configured() ) { return new WP_Error( 'hmn_engine_missing', 'Easy!Appointments connection is not configured.' ); }
		$providers = HMN_CRM_EasyAppointments::request( 'GET', 'providers', null, array( 'length' => 200 ) );
		$services = HMN_CRM_EasyAppointments::request( 'GET', 'services', null, array( 'length' => 200 ) );
		if ( is_wp_error( $providers ) ) { return $providers; } if ( is_wp_error( $services ) ) { return $services; }
		$providers = is_array( $providers ) ? $providers : array(); $services = is_array( $services ) ? $services : array();
		$working_plan = self::engine_working_plan( $settings['plan'] ?? array() );
		foreach ( $providers as $provider ) {
			$id = absint( $provider['id'] ?? 0 ); if ( ! $id ) { continue; }
			$result = HMN_CRM_EasyAppointments::request( 'PUT', 'providers/' . $id, array( 'settings' => array( 'workingPlan' => $working_plan ) ) );
			if ( is_wp_error( $result ) ) { return new WP_Error( 'hmn_engine_provider', 'Could not save provider working plan: ' . $result->get_error_message() ); }
		}
		$engine_interval = max( 1, absint( $settings['slot_step'] ?? 15 ) + absint( $settings['buffer_minutes'] ?? 0 ) );
		foreach ( $services as $service ) {
			$id = absint( $service['id'] ?? 0 ); if ( ! $id ) { continue; }
			$result = HMN_CRM_EasyAppointments::request( 'PUT', 'services/' . $id, array( 'slotInterval' => $engine_interval, 'attendantsNumber' => max( 1, absint( $settings['concurrent_bookings'] ?? 1 ) ) ) );
			if ( is_wp_error( $result ) ) { return new WP_Error( 'hmn_engine_service', 'Could not save service slot interval: ' . $result->get_error_message() ); }
		}
		foreach ( array( 'book_advance_timeout' => absint( $settings['min_notice_hours'] ?? 0 ) * 60, 'future_booking_limit' => absint( $settings['max_future_days'] ?? 30 ) ) as $name => $value ) {
			$result = HMN_CRM_EasyAppointments::request( 'PUT', 'settings/' . $name, array( 'value' => (string) $value ) );
			if ( is_wp_error( $result ) ) { return new WP_Error( 'hmn_engine_setting', 'Could not save engine booking rule: ' . $result->get_error_message() ); }
		}
		$previous = get_option( self::ENGINE_MAP_OPTION, array() );
		foreach ( (array) ( $previous['working_plan_exceptions'] ?? array() ) as $id ) { $result = HMN_CRM_EasyAppointments::request( 'DELETE', 'working_plan_exceptions/' . absint( $id ) ); if ( is_wp_error( $result ) && 404 !== (int) ( $result->get_error_data()['status'] ?? 0 ) ) { return $result; } }
		foreach ( (array) ( $previous['unavailabilities'] ?? array() ) as $id ) { $result = HMN_CRM_EasyAppointments::request( 'DELETE', 'unavailabilities/' . absint( $id ) ); if ( is_wp_error( $result ) && 404 !== (int) ( $result->get_error_data()['status'] ?? 0 ) ) { return $result; } }
		$map = array( 'working_plan_exceptions' => array(), 'unavailabilities' => array() );
		foreach ( (array) ( $settings['exceptions'] ?? array() ) as $exception ) {
			$from = self::jalali_to_gregorian( $exception['start_date'] ?? '' ); $until = self::jalali_to_gregorian( $exception['end_date'] ?? '' ); if ( ! $from ) { continue; } if ( ! $until ) { $until = $from; }
			foreach ( self::exception_provider_ids( $exception['target'] ?? '', $providers ) as $provider_id ) {
				if ( 'blocked' === ( $exception['type'] ?? '' ) ) {
					$start = $from . ' ' . ( $exception['start_time'] ?: '00:00' ) . ':00'; $end = $until . ' ' . ( $exception['end_time'] ?: '23:59' ) . ':59';
					$result = HMN_CRM_EasyAppointments::request( 'POST', 'unavailabilities', array( 'start' => $start, 'end' => $end, 'providerId' => $provider_id, 'notes' => 'HMN CRM scheduling sync' ) );
					if ( is_wp_error( $result ) ) { return new WP_Error( 'hmn_engine_block', 'Could not save blocked period: ' . $result->get_error_message() ); } $map['unavailabilities'][] = absint( $result['id'] ?? 0 ); continue;
				}
				$payload = array( 'startDate' => $from, 'endDate' => $until, 'providerId' => $provider_id, 'startTime' => null, 'endTime' => null, 'breaks' => array() );
				if ( 'special_hours' === ( $exception['type'] ?? '' ) ) { $payload['startTime'] = $exception['start_time'] ?: null; $payload['endTime'] = $exception['end_time'] ?: null; }
				$result = HMN_CRM_EasyAppointments::request( 'POST', 'working_plan_exceptions', $payload );
				if ( is_wp_error( $result ) ) { return new WP_Error( 'hmn_engine_exception', 'Could not save working plan exception: ' . $result->get_error_message() ); } $map['working_plan_exceptions'][] = absint( $result['id'] ?? 0 );
			}
		}
		$map['working_plan_exceptions'] = array_values( array_filter( $map['working_plan_exceptions'] ) ); $map['unavailabilities'] = array_values( array_filter( $map['unavailabilities'] ) ); return $map;
	}

	private static function engine_working_plan( $plan ) {
		$names = array( 'saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday' ); $defaults = self::defaults()['plan']; $result = array();
		foreach ( $names as $index => $name ) { $row = wp_parse_args( is_array( $plan[ $index ] ?? null ) ? $plan[ $index ] : array(), $defaults[ $index ] ); if ( empty( $row['enabled'] ) ) { $result[ $name ] = null; continue; } $breaks = array(); if ( null !== self::minutes( $row['break_start'] ?? '' ) && null !== self::minutes( $row['break_end'] ?? '' ) ) { $breaks[] = array( 'start' => $row['break_start'], 'end' => $row['break_end'] ); } $result[ $name ] = array( 'start' => $row['start'], 'end' => $row['end'], 'breaks' => $breaks ); }
		return $result;
	}

	private static function exception_provider_ids( $target, $providers ) {
		$target = (string) $target; $ids = array(); foreach ( (array) $providers as $provider ) { $id = absint( $provider['id'] ?? 0 ); if ( ! $id ) { continue; } $service_id = 0 === strpos( $target, 'service:' ) ? absint( substr( $target, 8 ) ) : 0; if ( ! $target || $target === 'provider:' . $id || ( $service_id && in_array( $service_id, array_map( 'absint', (array) ( $provider['services'] ?? array() ) ), true ) ) ) { $ids[] = $id; } } return array_values( array_unique( $ids ) );
	}

	public function save() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'hmn_crm_save_scheduling' ) ) { wp_die( 'دسترسی نامعتبر است.' ); }
		$defaults = self::defaults(); $posted = wp_unslash( $_POST ); $plan = array();
		foreach ( $defaults['plan'] as $i => $day ) { $row = is_array( $posted['plan'][ $i ] ?? null ) ? $posted['plan'][ $i ] : array(); $plan[ $i ] = array( 'label' => $day['label'], 'enabled' => empty( $row['enabled'] ) ? 0 : 1, 'start' => self::time( $row['start'] ?? $day['start'] ), 'end' => self::time( $row['end'] ?? $day['end'] ), 'break_start' => self::time( $row['break_start'] ?? '' ), 'break_end' => self::time( $row['break_end'] ?? '' ) ); }
		$exceptions = array();
		foreach ( (array) ( $posted['exceptions'] ?? array() ) as $row ) { if ( ! is_array( $row ) || empty( $row['start_date'] ) ) { continue; } $exceptions[] = array( 'type' => in_array( $row['type'] ?? '', array( 'holiday', 'leave', 'blocked', 'special_hours' ), true ) ? $row['type'] : 'holiday', 'start_date' => sanitize_text_field( $row['start_date'] ), 'end_date' => sanitize_text_field( $row['end_date'] ?? '' ), 'start_time' => self::time( $row['start_time'] ?? '' ), 'end_time' => self::time( $row['end_time'] ?? '' ), 'target' => sanitize_text_field( $row['target'] ?? '' ), 'note' => sanitize_text_field( $row['note'] ?? '' ) ); }
		$settings = array( 'plan' => $plan, 'slot_step' => max( 1, absint( $posted['slot_step'] ?? 15 ) ), 'buffer_minutes' => absint( $posted['buffer_minutes'] ?? 0 ), 'min_notice_hours' => absint( $posted['min_notice_hours'] ?? 0 ), 'max_future_days' => max( 1, absint( $posted['max_future_days'] ?? 30 ) ), 'daily_limit' => absint( $posted['daily_limit'] ?? 0 ), 'concurrent_bookings' => max( 1, absint( $posted['concurrent_bookings'] ?? 1 ) ), 'allow_holidays' => empty( $posted['allow_holidays'] ) ? 0 : 1, 'cancel_hours' => absint( $posted['cancel_hours'] ?? 0 ), 'exceptions' => $exceptions );
		update_option( self::OPTION, $settings, false );
		self::queue_sync( true );
		wp_safe_redirect( add_query_arg( array( 'section' => 'scheduling', 'updated' => 1, 'sync' => 'queued' ), home_url( '/hcrm/' ) ) ); exit;
	}

	/** Queue a fresh sync attempt from the protected CRM portal. */
	public function retry_sync() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'hmn_crm_sync_scheduling' ) ) { wp_die( 'دسترسی نامعتبر است.' ); }
		self::queue_sync( true );
		wp_safe_redirect( add_query_arg( array( 'section' => 'scheduling', 'sync' => 'queued' ), home_url( '/hcrm/' ) ) ); exit;
	}
	private static function time( $value ) { $value = sanitize_text_field( (string) $value ); return preg_match( '/^\d{2}:\d{2}$/', $value ) ? $value : ''; }

	public static function render_portal( $base, $user ) {
		$s = self::settings();
		$providers = HMN_CRM_EasyAppointments::request( 'GET', 'providers' );
		$services = HMN_CRM_EasyAppointments::request( 'GET', 'services' );
		$providers = is_array( $providers ) ? $providers : array();
		$services = is_array( $services ) ? $services : array();
		$sync_status = self::sync_status();
		add_action( 'wp_footer', static function() { ?>
			<style>
			.hmn-date-control{cursor:pointer!important;background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='18' height='18' fill='none' stroke='%23667085' stroke-width='1.7'%3E%3Crect x='2.5' y='4' width='13' height='11.5' rx='2'/%3E%3Cpath d='M5.5 2.5v3M12.5 2.5v3M2.5 8h13'/%3E%3C/svg%3E") no-repeat 12px center!important;padding-left:38px!important}.hmn-jalali-picker{position:fixed;z-index:99999;width:294px;background:#fff;border:1px solid #d0d5dd;border-radius:14px;box-shadow:0 18px 40px rgba(16,24,40,.18);padding:14px;direction:rtl}.hmn-jalali-picker[hidden]{display:none}.hmn-jp-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:13px;font-weight:800;color:#172033}.hmn-jp-head button{width:34px;height:34px;border:1px solid #e4e7ec;background:#fff;border-radius:8px;color:#5b4cf0;font-size:20px;cursor:pointer}.hmn-jp-week,.hmn-jp-days{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;text-align:center}.hmn-jp-week{font-size:11px;color:#667085;margin-bottom:5px}.hmn-jp-days button{height:33px;border:0;border-radius:7px;background:transparent;color:#344054;font:inherit;cursor:pointer}.hmn-jp-days button:hover{background:#f1efff;color:#5b4cf0}.hmn-jp-days button.is-selected{background:#5b4cf0;color:#fff}.hmn-inline-toggle{display:flex!important;align-items:center!important;justify-content:flex-start!important;gap:10px!important;min-height:42px;margin-top:23px;white-space:nowrap}.hmn-inline-toggle input{width:19px!important;height:19px!important;margin:0!important;accent-color:var(--brand)}
			</style>
			<div class="hmn-jalali-picker" id="hmn-jalali-picker" hidden><div class="hmn-jp-head"><button type="button" data-jp="next" aria-label="ماه بعد">‹</button><strong></strong><button type="button" data-jp="prev" aria-label="ماه قبل">›</button></div><div class="hmn-jp-week"><span>ش</span><span>ی</span><span>د</span><span>س</span><span>چ</span><span>پ</span><span>ج</span></div><div class="hmn-jp-days"></div></div>
			<script>(function(){var p=document.getElementById('hmn-jalali-picker'),title=p.querySelector('strong'),days=p.querySelector('.hmn-jp-days'),active=null,state={},months=['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];function en(v){return String(v||'').replace(/[۰-۹]/g,function(x){return '0123456789'['۰۱۲۳۴۵۶۷۸۹'.indexOf(x)]}).replace(/[٠-٩]/g,function(x){return '0123456789'['٠١٢٣٤٥٦٧٨٩'.indexOf(x)]})}function fa(v){return String(v).replace(/\d/g,function(x){return '۰۱۲۳۴۵۶۷۸۹'[x]})}function j2g(y,m,d){var jy=y-979,jm=m,jd=d,ds=365*jy+Math.floor(jy/33)*8+Math.floor((jy%33+3)/4)+78+jd+(jm<7?(jm-1)*31:(jm-7)*30+186),gy=1600+400*Math.floor(ds/146097);ds%=146097;if(ds>36524){gy+=100*Math.floor(--ds/36524);ds%=36524;if(ds>=365)ds++}gy+=4*Math.floor(ds/1461);ds%=1461;if(ds>365){gy+=Math.floor((ds-1)/365);ds=(ds-1)%365}var gd=ds+1,leap=(gy%4===0&&gy%100!==0)||gy%400===0,ml=[31,leap?29:28,31,30,31,30,31,31,30,31,30,31],gm=0;while(gd>ml[gm]){gd-=ml[gm++]};return new Date(gy,gm,gd)}function g2j(date){var gy=date.getFullYear(),gm=date.getMonth()+1,gd=date.getDate(),gdm=[0,31,59,90,120,151,181,212,243,273,304,334],jy=gy>1600?979:0;gy-=gy>1600?1600:621;var gy2=gm>2?gy+1:gy,ds=365*gy+Math.floor((gy2+3)/4)-Math.floor((gy2+99)/100)+Math.floor((gy2+399)/400)-80+gd+gdm[gm-1];jy+=33*Math.floor(ds/12053);ds%=12053;jy+=4*Math.floor(ds/1461);ds%=1461;if(ds>365){jy+=Math.floor((ds-1)/365);ds=(ds-1)%365}return{y:jy,m:ds<186?1+Math.floor(ds/31):7+Math.floor((ds-186)/30),d:1+(ds<186?ds%31:(ds-186)%30)}}function dim(m){return m<7?31:m<12?30:29}function setup(i){i.readOnly=true;i.autocomplete='off';i.classList.add('hmn-date-control');i.placeholder=i.name.indexOf('start_date')>-1?'تاریخ شروع':'تاریخ پایان'}function render(){title.textContent=months[state.m-1]+' '+fa(state.y);days.innerHTML='';var offset=(j2g(state.y,state.m,1).getDay()+1)%7;for(var i=0;i<offset;i++)days.appendChild(document.createElement('span'));for(var d=1;d<=dim(state.m);d++){var b=document.createElement('button');b.type='button';b.textContent=fa(d);b.dataset.day=d;if(active&&en(active.value)===state.y+'/'+String(state.m).padStart(2,'0')+'/'+String(d).padStart(2,'0'))b.className='is-selected';days.appendChild(b)}}function open(i){setup(i);active=i;var m=en(i.value).match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/),now=g2j(new Date());state={y:m?+m[1]:now.y,m:m?+m[2]:now.m};render();var r=i.getBoundingClientRect();p.style.top=Math.min(window.innerHeight-330,r.bottom+8)+'px';p.style.right=Math.max(10,window.innerWidth-r.right)+'px';p.hidden=false}document.querySelectorAll('input[name*="[start_date]"],input[name*="[end_date]"]').forEach(setup);var holiday=document.querySelector('input[name="allow_holidays"]');if(holiday)holiday.closest('label').classList.add('hmn-inline-toggle');document.addEventListener('click',function(e){var i=e.target.closest('input[name*="[start_date]"],input[name*="[end_date]"]');if(i){open(i);return}var b=e.target.closest('[data-jp]');if(b){state.m+=b.dataset.jp==='next'?1:-1;if(state.m>12){state.m=1;state.y++}if(state.m<1){state.m=12;state.y--}render();return}b=e.target.closest('.hmn-jp-days button');if(b&&active){active.value=fa(state.y)+'/'+fa(String(state.m).padStart(2,'0'))+'/'+fa(String(b.dataset.day).padStart(2,'0'));active.dispatchEvent(new Event('change',{bubbles:true}));p.hidden=true;return}if(!p.contains(e.target))p.hidden=true});document.addEventListener('keydown',function(e){if(e.key==='Escape')p.hidden=true})})();</script>
		<?php } );
		add_action( 'wp_footer', static function() { ?>
			<script>(function(){var list=document.getElementById('hmn-exceptions');function fields(root){root.querySelectorAll('input[name*="[start_date]"],input[name*="[end_date]"]').forEach(function(i){i.readOnly=true;i.autocomplete='off';i.classList.add('hmn-date-control');i.placeholder=i.name.indexOf('start_date')>-1?'تاریخ شروع':'تاریخ پایان'})}if(list){fields(list);var hint=list.previousElementSibling;if(hint)hint.textContent='تاریخ شروع و پایان را از تقویم انتخاب کنید.';new MutationObserver(function(rows){rows.forEach(function(row){row.addedNodes.forEach(function(node){if(node.nodeType===1)fields(node)})})}).observe(list,{childList:true})}var holiday=document.querySelector('input[name="allow_holidays"]');if(holiday)holiday.closest('label').classList.add('hmn-inline-toggle')})();</script>
		<?php } );
		$sync_message = 'هنوز همگام‌سازی انجام نشده است.';
		$sync_color = '#667085';
		if ( 'synced' === $sync_status['state'] ) { $sync_message = 'آخرین همگام‌سازی با موتور موفق بود.'; $sync_color = '#027a48'; }
		if ( 'pending' === $sync_status['state'] ) { $sync_message = 'تنظیمات در CRM اعمال شده‌اند و همگام‌سازی با موتور در صف است.'; $sync_color = '#175cd3'; }
		if ( 'failed' === $sync_status['state'] ) { $sync_message = 'همگام‌سازی موتور ناموفق بود: ' . $sync_status['error']; $sync_color = '#b42318'; }
		$sync_html = '<section id="hmn-sync-status" class="hmn-settings-card" style="margin:22px 0;color:' . esc_attr( $sync_color ) . '"><h2>وضعیت همگام‌سازی موتور</h2><p>' . esc_html( $sync_message ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="hmn_crm_sync_scheduling"><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'hmn_crm_sync_scheduling' ) ) . '"><button class="hmn-add-exception" type="submit">همگام‌سازی مجدد با موتور</button></form></section>';
		add_action( 'wp_footer', static function() use ( $sync_html ) { echo $sync_html . '<script>(function(){var card=document.getElementById("hmn-sync-status"),main=document.querySelector(".hmn-main"),form=document.querySelector(".hmn-settings-page");if(card&&main){main.insertBefore(card,form||main.firstChild);}})();</script>'; } );
		?>
<!doctype html><html <?php language_attributes(); ?> dir="rtl"><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title>تنظیمات نوبت‌دهی | HMN CRM</title><?php wp_head(); ?><style><?php HMN_CRM_Dashboard::portal_styles(); ?>.hmn-settings-page{display:grid;gap:22px}.hmn-settings-card{background:var(--surface);border:1px solid var(--line);border-radius:16px;padding:22px}.hmn-settings-card h2{font-size:18px;margin:0 0 7px}.hmn-settings-card>p{margin:0 0 20px;color:var(--muted)}.hmn-day-row{display:grid;grid-template-columns:100px 70px 1fr 1fr 1fr 1fr;gap:10px;align-items:center;padding:10px 0;border-bottom:1px solid var(--line)}.hmn-day-row input,.hmn-settings-grid input,.hmn-settings-grid select,.hmn-exception-row input,.hmn-exception-row select{height:42px;border:1px solid var(--line);border-radius:8px;background:var(--surface);color:var(--ink);padding:0 10px;font:inherit;width:100%}.hmn-settings-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.hmn-settings-grid label{display:grid;gap:7px;font-weight:700}.hmn-settings-grid small{color:var(--muted);font-weight:400}.hmn-exception-list{display:grid;gap:10px}.hmn-exception-row{display:grid;grid-template-columns:120px 1fr 1fr 1fr 1fr 1.4fr 42px;gap:8px;padding:10px;background:var(--canvas);border:1px solid var(--line);border-radius:10px}.hmn-save{border:0;border-radius:9px;background:var(--brand);color:#fff;padding:13px 22px;font:inherit;font-weight:700;cursor:pointer}.hmn-add-exception{border:1px solid var(--brand);border-radius:9px;background:var(--soft);color:var(--brand);padding:10px 15px;font:inherit;cursor:pointer;margin-top:12px}.hmn-remove-exception{border:0;border-radius:8px;background:#fff1f3;color:#b42318;cursor:pointer;font-size:20px}.hmn-notice{padding:12px 15px;border-radius:9px;background:#ecfdf3;color:#027a48}.hmn-target-hint{font-size:12px;color:var(--muted);margin:0 0 8px}@media(max-width:900px){.hmn-day-row{grid-template-columns:90px 60px 1fr 1fr}.hmn-day-row input:nth-of-type(3),.hmn-day-row input:nth-of-type(4){grid-column:3/5}.hmn-settings-grid{grid-template-columns:1fr 1fr}.hmn-exception-row{grid-template-columns:1fr 1fr 1fr}.hmn-exception-row button{min-height:42px}}@media(max-width:640px){.hmn-main{padding:16px}.hmn-settings-card{padding:16px}.hmn-day-row{grid-template-columns:1fr 1fr}.hmn-day-row strong{grid-column:1/2}.hmn-day-row label{justify-self:end}.hmn-day-row input{min-width:0}.hmn-settings-grid{grid-template-columns:1fr}.hmn-exception-row{grid-template-columns:1fr 1fr}.hmn-exception-row input,.hmn-exception-row select{min-width:0}}</style></head><body class="hmn-portal-body"><div class="hmn-portal"><aside class="hmn-sidebar"><div class="hmn-brand"><span class="hmn-brand-mark">H</span><span>HMN CRM</span></div><nav class="hmn-nav"><a href="<?php echo esc_url( $base ); ?>">⌂ داشبورد نوبت‌ها</a><a href="<?php echo esc_url( add_query_arg( 'section', 'customers', $base ) ); ?>">♙ مشتریان</a><a class="is-active" href="<?php echo esc_url( add_query_arg( 'section', 'scheduling', $base ) ); ?>">⚙ تنظیمات نوبت‌دهی</a><a href="<?php echo esc_url( admin_url( 'admin.php?page=hmn-crm-sms' ) ); ?>">✉ تنظیمات پیامک</a></nav><div class="hmn-user"><span class="hmn-avatar"><?php echo esc_html( mb_substr( $user->display_name ?: $user->user_login, 0, 1 ) ); ?></span><div><strong><?php echo esc_html( $user->display_name ); ?></strong><a href="<?php echo esc_url( wp_logout_url( $base ) ); ?>">خروج از حساب</a></div></div></aside><main class="hmn-main"><header class="hmn-topbar"><button class="hmn-menu" type="button" aria-label="باز کردن منو">☰</button><div><p class="hmn-eyebrow">عملیات نوبت‌دهی</p><h1>تنظیمات تقویم و رزرو</h1></div><a class="hmn-today" href="<?php echo esc_url( $base ); ?>">بازگشت به نوبت‌ها</a></header><?php if ( isset( $_GET['updated'] ) ) : ?><p class="hmn-notice">تنظیمات ذخیره شد.</p><?php endif; ?><form class="hmn-settings-page" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="hmn_crm_save_scheduling"><?php wp_nonce_field( 'hmn_crm_save_scheduling' ); ?><section class="hmn-settings-card"><h2>تقویم و برنامه کاری</h2><p>روزهای کاری، ساعت فعالیت و زمان استراحت را تعیین کنید.</p><?php foreach ( $s['plan'] as $i => $day ) : ?><div class="hmn-day-row"><strong><?php echo esc_html( $day['label'] ); ?></strong><label><input type="checkbox" name="plan[<?php echo esc_attr( $i ); ?>][enabled]" value="1" <?php checked( $day['enabled'] ); ?>> فعال</label><input type="time" name="plan[<?php echo esc_attr( $i ); ?>][start]" value="<?php echo esc_attr( $day['start'] ); ?>" aria-label="شروع"><input type="time" name="plan[<?php echo esc_attr( $i ); ?>][end]" value="<?php echo esc_attr( $day['end'] ); ?>" aria-label="پایان"><input type="time" name="plan[<?php echo esc_attr( $i ); ?>][break_start]" value="<?php echo esc_attr( $day['break_start'] ); ?>" aria-label="شروع استراحت"><input type="time" name="plan[<?php echo esc_attr( $i ); ?>][break_end]" value="<?php echo esc_attr( $day['break_end'] ); ?>" aria-label="پایان استراحت"></div><?php endforeach; ?></section><section class="hmn-settings-card"><h2>قوانین رزرو</h2><p>قوانین عمومی فرم رزرو آنلاین و ثبت اپراتور.</p><div class="hmn-settings-grid"><label>گام زمانی نوبت<small>دقیقه</small><input type="number" min="1" name="slot_step" value="<?php echo esc_attr( $s['slot_step'] ); ?>"></label><label>فاصله بین دو نوبت<small>دقیقه</small><input type="number" min="0" name="buffer_minutes" value="<?php echo esc_attr( $s['buffer_minutes'] ); ?>"></label><label>حداقل فاصله تا رزرو<small>ساعت</small><input type="number" min="0" name="min_notice_hours" value="<?php echo esc_attr( $s['min_notice_hours'] ); ?>"></label><label>حداکثر بازه رزرو آینده<small>روز</small><input type="number" min="1" name="max_future_days" value="<?php echo esc_attr( $s['max_future_days'] ); ?>"></label><label>سقف نوبت روزانه<small>۰ یعنی بدون سقف</small><input type="number" min="0" name="daily_limit" value="<?php echo esc_attr( $s['daily_limit'] ); ?>"></label><label>رزرو هم‌زمان<small>تعداد بیمار در یک ساعت</small><input type="number" min="1" name="concurrent_bookings" value="<?php echo esc_attr( $s['concurrent_bookings'] ); ?>"></label><label>مهلت لغو یا جابه‌جایی<small>ساعت قبل نوبت</small><input type="number" min="0" name="cancel_hours" value="<?php echo esc_attr( $s['cancel_hours'] ); ?>"></label><label>رزرو در تعطیلات<input type="checkbox" name="allow_holidays" value="1" <?php checked( $s['allow_holidays'] ); ?>> اجازه داده شود</label></div></section><section class="hmn-settings-card"><h2>تعطیلات و استثناها</h2><p class="hmn-target-hint">تاریخ‌ها را به شمسی وارد کنید؛ مانند ۱۴۰۵/۰۷/۰۱. در مرحله اتصال موتور، این تاریخ‌ها به میلادی تبدیل و همگام می‌شوند.</p><div class="hmn-exception-list" id="hmn-exceptions"><?php foreach ( $s['exceptions'] as $n => $row ) { self::exception_row( $n, $row, $providers, $services ); } ?></div><button class="hmn-add-exception" type="button" id="hmn-add-exception">+ افزودن تعطیلی یا استثنا</button></section><button class="hmn-save" type="submit">ذخیره تنظیمات</button></form></main></div><template id="hmn-exception-template"><?php self::exception_row( '__INDEX__', array(), $providers, $services ); ?></template><script>(function(){var portal=document.querySelector('.hmn-portal'),menu=document.querySelector('.hmn-menu'),side=document.querySelector('.hmn-sidebar');menu.onclick=function(e){e.stopPropagation();portal.classList.toggle('menu-open')};document.addEventListener('click',function(e){if(portal.classList.contains('menu-open')&&!side.contains(e.target)&&!menu.contains(e.target))portal.classList.remove('menu-open')});var list=document.querySelector('#hmn-exceptions'),template=document.querySelector('#hmn-exception-template'),add=document.querySelector('#hmn-add-exception'),index=list.children.length;add.onclick=function(){list.insertAdjacentHTML('beforeend',template.innerHTML.replaceAll('__INDEX__',index++))};list.addEventListener('click',function(e){if(e.target.classList.contains('hmn-remove-exception'))e.target.closest('.hmn-exception-row').remove()})})();</script><?php wp_footer(); ?></body></html>
		<?php
	}
	private static function exception_row( $index, $row, $providers, $services ) { $row = wp_parse_args( $row, array( 'type' => 'holiday', 'start_date' => '', 'end_date' => '', 'start_time' => '', 'end_time' => '', 'target' => '', 'note' => '' ) ); ?><div class="hmn-exception-row"><select name="exceptions[<?php echo esc_attr( $index ); ?>][type]"><option value="holiday" <?php selected( $row['type'], 'holiday' ); ?>>تعطیلی</option><option value="leave" <?php selected( $row['type'], 'leave' ); ?>>مرخصی پزشک</option><option value="blocked" <?php selected( $row['type'], 'blocked' ); ?>>بازه مسدود</option><option value="special_hours" <?php selected( $row['type'], 'special_hours' ); ?>>ساعت ویژه</option></select><input name="exceptions[<?php echo esc_attr( $index ); ?>][start_date]" placeholder="تاریخ شروع شمسی" value="<?php echo esc_attr( $row['start_date'] ); ?>"><input name="exceptions[<?php echo esc_attr( $index ); ?>][end_date]" placeholder="تاریخ پایان شمسی" value="<?php echo esc_attr( $row['end_date'] ); ?>"><input type="time" name="exceptions[<?php echo esc_attr( $index ); ?>][start_time]" value="<?php echo esc_attr( $row['start_time'] ); ?>"><input type="time" name="exceptions[<?php echo esc_attr( $index ); ?>][end_time]" value="<?php echo esc_attr( $row['end_time'] ); ?>"><select name="exceptions[<?php echo esc_attr( $index ); ?>][target]"><option value="">کل مرکز</option><?php foreach ( $providers as $p ) : ?><option value="provider:<?php echo esc_attr( absint( $p['id'] ?? 0 ) ); ?>" <?php selected( $row['target'], 'provider:' . absint( $p['id'] ?? 0 ) ); ?>>پزشک: <?php echo esc_html( trim( ( $p['firstName'] ?? '' ) . ' ' . ( $p['lastName'] ?? '' ) ) ); ?></option><?php endforeach; ?><?php foreach ( $services as $service ) : ?><option value="service:<?php echo esc_attr( absint( $service['id'] ?? 0 ) ); ?>" <?php selected( $row['target'], 'service:' . absint( $service['id'] ?? 0 ) ); ?>>سرویس: <?php echo esc_html( $service['name'] ?? '' ); ?></option><?php endforeach; ?></select><input name="exceptions[<?php echo esc_attr( $index ); ?>][note]" placeholder="توضیح" value="<?php echo esc_attr( $row['note'] ); ?>"><button type="button" class="hmn-remove-exception" aria-label="حذف">×</button></div><?php }
}

new HMN_CRM_Scheduling();
