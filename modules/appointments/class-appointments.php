<?php
/**
 * Appointment lifecycle module.
 *
 * Protected appointment actions are registered and implemented here. The
 * Easy!Appointments module is used solely as the REST transport adapter.
 *
 * @package HMN_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HMN_CRM_Appointments implements HMN_CRM_Module_Interface {
	public function __construct() {
		$this->boot();
	}

	public function boot() {
		add_action( 'wp_ajax_hmn_ea_operator_book', array( $this, 'operator_book' ) );
		add_action( 'wp_ajax_hmn_ea_customer_history', array( $this, 'customer_history' ) );
		add_action( 'wp_ajax_hmn_ea_cancel_appointment', array( $this, 'cancel' ) );
		add_action( 'wp_ajax_hmn_ea_delivery_diag', array( $this, 'delivery_diag' ) );
	}

	public function operator_book() {
		$this->verify();
		$first    = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
		$last     = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
		$phone    = preg_replace( '/\D+/', '', sanitize_text_field( wp_unslash( $_POST['operator_phone'] ?? '' ) ) );
		$service  = absint( $_POST['service_id'] ?? 0 );
		$provider = absint( $_POST['provider_id'] ?? 0 );
		$date     = sanitize_text_field( wp_unslash( $_POST['appointment_date'] ?? '' ) );
		$time     = sanitize_text_field( wp_unslash( $_POST['appointment_time'] ?? '' ) );
		if ( ! $first || ! $last || ! preg_match( '/^09\d{9}$/', $phone ) || ! $service || ! $provider || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			wp_send_json_error( array( 'message' => 'همه اطلاعات نوبت را کامل و صحیح وارد کنید.' ), 400 );
		}
		$slots = HMN_CRM_EasyAppointments::request( 'GET', 'availabilities', null, array( 'serviceId' => $service, 'providerId' => $provider, 'date' => $date ) );
		if ( is_wp_error( $slots ) ) { $this->error( $slots ); }
		if ( class_exists( 'HMN_CRM_Scheduling' ) ) { $slots = HMN_CRM_Scheduling::filter_slots( $slots, $date, $service, $provider ); }
		if ( ! in_array( $time, $slots, true ) ) { wp_send_json_error( array( 'message' => 'این ساعت دیگر آزاد نیست.' ), 409 ); }
		$customer = class_exists( 'HMN_CRM_Customers' ) ? HMN_CRM_Customers::find_by_phone( $phone ) : null;
		if ( is_wp_error( $customer ) ) { $this->error( $customer ); }
		$customer_id = absint( is_array( $customer ) ? ( $customer['id'] ?? 0 ) : 0 );
		if ( ! $customer_id ) {
			$customer = HMN_CRM_EasyAppointments::request( 'POST', 'customers', array( 'firstName' => $first, 'lastName' => $last, 'phone' => $phone, 'first_name' => $first, 'last_name' => $last, 'phone_number' => $phone, 'email' => $phone . '@phone.invalid', 'timezone' => 'Asia/Tehran', 'language' => 'english' ) );
			if ( is_wp_error( $customer ) ) { $this->error( $customer ); }
			$customer_id = absint( $customer['id'] ?? 0 );
		}
		$service_data = HMN_CRM_EasyAppointments::request( 'GET', 'services/' . $service );
		$duration = is_array( $service_data ) ? absint( $service_data['duration'] ?? 15 ) : 15;
		$start = $date . ' ' . $time . ':00';
		$start_object = DateTime::createFromFormat( 'Y-m-d H:i:s', $start, new DateTimeZone( 'Asia/Tehran' ) );
		$end = $start_object ? $start_object->modify( '+' . $duration . ' minutes' )->format( 'Y-m-d H:i:s' ) : $start;
		$appointment = HMN_CRM_EasyAppointments::request( 'POST', 'appointments', array( 'start' => $start, 'end' => $end, 'customerId' => $customer_id, 'providerId' => $provider, 'serviceId' => $service, 'start_datetime' => $start, 'end_datetime' => $end, 'id_users_customer' => $customer_id, 'id_users_provider' => $provider, 'id_services' => $service, 'status' => 'Booked' ) );
		if ( is_wp_error( $appointment ) ) { $this->error( $appointment ); }
		$appointment_id = absint( $appointment['id'] ?? 0 );
		$sms_settings = get_option( 'hmn_crm_sms_settings', array() );
		$body_id      = is_array( $sms_settings ) ? absint( $sms_settings['melipayamak_booking_body_id'] ?? 0 ) : 0;
		if ( $body_id && $phone ) {
			try {
				$sms_result = ( new HMN_CRM_SMS() )->send_pattern( $phone, $body_id, array( trim( $first . ' ' . $last ), self::jalali_date( $date ), $time ), array( 'type' => 'booking', 'appointment_id' => $appointment_id ) );
				if ( is_array( $sms_result ) && class_exists( 'HMN_CRM_SMS' ) ) {
					HMN_CRM_SMS::record_recid( $appointment_id, $sms_result );
				}
			} catch ( Exception $e ) {
				error_log( 'HMN CRM booking SMS failed for appointment ' . $appointment_id . ': ' . $e->getMessage() );
			}
		}
		wp_send_json_success( array( 'id' => $appointment_id ) );
	}

	public function delivery_diag() {
		$this->verify();
		$action = isset( $_POST['diag'] ) ? sanitize_key( wp_unslash( $_POST['diag'] ) ) : '';
		if ( 'store_recid' === $action ) {
			$aid    = absint( $_POST['appointment_id'] ?? 0 );
			$rec_id = absint( $_POST['rec_id'] ?? 0 );
			if ( ! $aid || ! $rec_id ) { wp_send_json_error( array( 'message' => 'appointment_id و rec_id معتبر الزامی‌اند.' ) ); }
			HMN_CRM_SMS::record_recid( $aid, array( 'recId' => $rec_id ) );
			$stored = HMN_CRM_SMS::recids_for_appointments( array( $aid ) );
			wp_send_json_success( array( 'stored' => $stored, 'map' => HMN_CRM_SMS::recids_for_appointments( array( $aid ) ) ) );
		}
		if ( 'delivery_status' === $action ) {
			$rec_id = absint( $_POST['rec_id'] ?? 0 );
			if ( ! $rec_id ) { wp_send_json_error( array( 'message' => 'rec_id معتبر الزامی است.' ) ); }
			$result = HMN_CRM_SMS::delivery_statuses( array( $rec_id ) );
			wp_send_json_success( array( 'rec_id' => $rec_id, 'delivery_status' => isset( $result[ $rec_id ] ) ? $result[ $rec_id ] : 'pending', 'raw_map' => $result ) );
		}
		if ( 'full_check' === $action ) {
			$aid    = absint( $_POST['appointment_id'] ?? 0 );
			$rec_id = absint( $_POST['rec_id'] ?? 0 );
			if ( ! $aid || ! $rec_id ) { wp_send_json_error( array( 'message' => 'appointment_id و rec_id معتبر الزامی‌اند.' ) ); }
			HMN_CRM_SMS::record_recid( $aid, array( 'recId' => $rec_id ) );
			$recids  = HMN_CRM_SMS::recids_for_appointments( array( $aid ) );
			$rec     = isset( $recids[ (string) $aid ] ) ? $recids[ (string) $aid ] : 0;
			$status  = $rec ? ( HMN_CRM_SMS::delivery_statuses( array( $rec ) )[ $rec ] ?? 'pending' ) : 'no_recid';
			wp_send_json_success( array( 'appointment_id' => $aid, 'recid_for' => $rec, 'delivery_status_for' => $status ) );
		}
		wp_send_json_error( array( 'message' => 'action diag نامعتبر است.' ) );
	}

	public function customer_history() {
		$this->verify();
		$phone = preg_replace( '/\D+/', '', sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ) );
		if ( ! preg_match( '/^09\d{9}$/', $phone ) ) { wp_send_json_error( array( 'message' => 'شماره تلفن معتبر نیست.' ), 400 ); }
		$rows = HMN_CRM_EasyAppointments::request( 'GET', 'appointments', null, array( 'from' => '2000-01-01', 'till' => '2100-01-01', 'with' => 'customer,service', 'aggregates' => 1, 'length' => 500 ) );
		if ( is_wp_error( $rows ) ) { $this->error( $rows ); }
		$history = array();
		foreach ( (array) $rows as $row ) {
			$customer = is_array( $row['customer'] ?? null ) ? $row['customer'] : array();
			$number = preg_replace( '/\D+/', '', (string) ( $customer['phone'] ?? ( $customer['phone_number'] ?? '' ) ) );
			if ( $phone !== $number ) { continue; }
			$start = (string) ( $row['start'] ?? ( $row['start_datetime'] ?? '' ) );
			$service = is_array( $row['service'] ?? null ) ? $row['service'] : array();
			$history[] = array( 'id' => absint( $row['id'] ?? 0 ), 'date' => self::jalali_date( substr( $start, 0, 10 ) ), 'time' => substr( $start, 11, 5 ), 'service' => sanitize_text_field( $service['name'] ?? '' ), 'status' => sanitize_text_field( $row['status'] ?? '' ) );
		}
		usort( $history, function( $a, $b ) { return $b['id'] <=> $a['id']; } );
		wp_send_json_success( array( 'history' => $history ) );
	}

	public function cancel() {
		$this->verify();
		$id = absint( $_POST['appointment_id'] ?? 0 );
		$silent = ! empty( $_POST['silent'] );
		if ( ! $id ) { wp_send_json_error( array( 'message' => 'شناسه نوبت معتبر نیست.' ), 400 ); }
		$appointment = HMN_CRM_EasyAppointments::request( 'GET', 'appointments/' . $id );
		if ( is_wp_error( $appointment ) ) { $this->error( $appointment ); }
		if ( class_exists( 'HMN_CRM_Scheduling' ) && ! HMN_CRM_Scheduling::can_cancel_appointment( $appointment ) ) {
			wp_send_json_error( array( 'message' => 'مهلت لغو یا جابه‌جایی این نوبت گذشته است.' ), 409 );
		}
		if ( ! $silent && ! empty( $appointment['customerId'] ) ) {
			$customer = HMN_CRM_EasyAppointments::request( 'GET', 'customers/' . absint( $appointment['customerId'] ) );
			if ( ! is_wp_error( $customer ) ) {
				$phone = preg_replace( '/\D+/', '', (string) ( $customer['phone'] ?? ( $customer['phone_number'] ?? '' ) ) );
				$name = trim( (string) ( $customer['firstName'] ?? ( $customer['first_name'] ?? '' ) ) . ' ' . (string) ( $customer['lastName'] ?? ( $customer['last_name'] ?? '' ) ) );
				$settings = get_option( 'hmn_crm_sms_settings', array() );
				$body_id = is_array( $settings ) ? absint( $settings['melipayamak_booking_cancel_body_id'] ?? 0 ) : 0;
				if ( $body_id && $phone ) {
					try {
						$sms_result = ( new HMN_CRM_SMS() )->send_pattern( $phone, $body_id, array( $name, self::jalali_date( substr( (string) ( $appointment['start'] ?? '' ), 0, 10 ) ), substr( (string) ( $appointment['start'] ?? '' ), 11, 5 ) ), array( 'type' => 'cancel', 'appointment_id' => $id ) );
						if ( is_array( $sms_result ) && class_exists( 'HMN_CRM_SMS' ) ) {
							HMN_CRM_SMS::record_recid( $id, $sms_result );
						}
					} catch ( Exception $e ) {
						error_log( 'HMN CRM cancel SMS failed for appointment ' . $id . ': ' . $e->getMessage() );
					}
				}
			}
		}
		$deleted = HMN_CRM_EasyAppointments::request( 'DELETE', 'appointments/' . $id );
		if ( is_wp_error( $deleted ) ) { $this->error( $deleted ); }
		wp_send_json_success();
	}

	private function verify() { if ( ! HMN_CRM_Core::can( HMN_CRM_Core::CAP_MANAGE_APPOINTMENTS ) || ! check_ajax_referer( 'hmn_ea_operator_booking', 'nonce', false ) ) { wp_send_json_error( array( 'message' => 'دسترسی نامعتبر است.' ), 403 ); } }
	private function error( $error ) { wp_send_json_error( array( 'message' => $error->get_error_message() ), (int) ( $error->get_error_data()['status'] ?? 502 ) ); }
	private function send_booking_sms( $phone, $name, $date, $time ) {
		$settings = get_option( 'hmn_crm_sms_settings', array() );
		$body_id  = is_array( $settings ) ? absint( $settings['melipayamak_booking_body_id'] ?? 0 ) : 0;
		if ( $body_id ) {
			try {
				$sms_result = ( new HMN_CRM_SMS() )->send_pattern( $phone, $body_id, array( $name, self::jalali_date( $date ), $time ), array( 'type' => 'booking' ) );
				if ( is_array( $sms_result ) && class_exists( 'HMN_CRM_SMS' ) ) {
					HMN_CRM_SMS::record_recid( 0, $sms_result );
				}
			} catch ( Exception $e ) {
				error_log( 'HMN CRM booking SMS (no-id) failed: ' . $e->getMessage() );
			}
		}
	}
	private static function jalali_date( $date ) { $time = strtotime( $date ); $gy = (int) wp_date( 'Y', $time ); $gm = (int) wp_date( 'n', $time ); $gd = (int) wp_date( 'j', $time ); $gdm = array( 0,31,59,90,120,151,181,212,243,273,304,334 ); $jy = $gy > 1600 ? 979 : 0; $gy -= $gy > 1600 ? 1600 : 621; $gy2 = $gm > 2 ? $gy + 1 : $gy; $days = 365 * $gy + (int) floor( ( $gy2 + 3 ) / 4 ) - (int) floor( ( $gy2 + 99 ) / 100 ) + (int) floor( ( $gy2 + 399 ) / 400 ) - 80 + $gd + $gdm[ $gm - 1 ]; $jy += 33 * (int) floor( $days / 12053 ); $days %= 12053; $jy += 4 * (int) floor( $days / 1461 ); $days %= 1461; if ( $days > 365 ) { $jy += (int) floor( ( $days - 1 ) / 365 ); $days = ( $days - 1 ) % 365; } $jm = $days < 186 ? 1 + (int) floor( $days / 31 ) : 7 + (int) floor( ( $days - 186 ) / 30 ); $jd = 1 + ( $days < 186 ? $days % 31 : ( $days - 186 ) % 30 ); return sprintf( '%04d/%02d/%02d', $jy, $jm, $jd ); }
}

new HMN_CRM_Appointments();
