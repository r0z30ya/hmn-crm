<?php
/** Easy!Appointments API integration and public booking form. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class HMN_CRM_EasyAppointments {
	const OPTION_NAME = 'hmn_crm_easyappointments_settings';

	public function __construct() {
		add_action( 'wp_ajax_hmn_ea_bootstrap', array( $this, 'bootstrap_ajax' ) );
		add_action( 'wp_ajax_nopriv_hmn_ea_bootstrap', array( $this, 'bootstrap_ajax' ) );
		add_action( 'wp_ajax_hmn_ea_slots', array( $this, 'slots_ajax' ) );
		add_action( 'wp_ajax_nopriv_hmn_ea_slots', array( $this, 'slots_ajax' ) );
		add_action( 'wp_ajax_hmn_ea_book', array( $this, 'book_ajax' ) );
		add_action( 'wp_ajax_nopriv_hmn_ea_book', array( $this, 'book_ajax' ) );
		add_shortcode( 'hmn_booking_form', array( $this, 'render_shortcode' ) );
	}

	public static function settings() { $s = get_option( self::OPTION_NAME, array() ); return is_array( $s ) ? $s : array(); }
	public static function configured() { $s = self::settings(); return ! empty( $s['base_url'] ) && ! empty( $s['api_key'] ); }

	/** Authenticated Easy!Appointments API request. */
	public static function request( $method, $path, $body = null, $query = array() ) {
		$s = self::settings(); $base = isset( $s['base_url'] ) ? untrailingslashit( esc_url_raw( $s['base_url'] ) ) : ''; $key = isset( $s['api_key'] ) && is_scalar( $s['api_key'] ) ? trim( (string) $s['api_key'] ) : '';
		if ( ! $base || ! $key ) { return new WP_Error( 'hmn_ea_not_configured', 'اتصال Easy!Appointments هنوز پیکربندی نشده است.' ); }
		$url = $base . '/index.php/api/v1/' . ltrim( $path, '/' ); if ( $query ) { $url = add_query_arg( $query, $url ); }
		$args = array( 'method' => strtoupper( $method ), 'timeout' => 20, 'headers' => array( 'Accept' => 'application/json', 'Authorization' => 'Bearer ' . $key ) );
		if ( null !== $body ) { $args['headers']['Content-Type'] = 'application/json; charset=utf-8'; $args['body'] = wp_json_encode( $body ); }
		$r = wp_remote_request( $url, $args ); if ( is_wp_error( $r ) ) { return new WP_Error( 'hmn_ea_unreachable', 'ارتباط با موتور نوبت‌دهی برقرار نشد: ' . $r->get_error_message() ); }
		$status = (int) wp_remote_retrieve_response_code( $r ); $raw = (string) wp_remote_retrieve_body( $r ); $data = '' === $raw ? array() : json_decode( $raw, true );
		if ( $status < 200 || $status >= 300 || ( '' !== $raw && ! is_array( $data ) ) ) { $message = is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : 'پاسخ ناموفق از موتور نوبت‌دهی دریافت شد.'; return new WP_Error( 'hmn_ea_api_error', sanitize_text_field( $message ), array( 'status' => $status ) ); }
		return $data;
	}

	public function render_shortcode() {
		if ( ! self::configured() ) { return current_user_can( 'manage_options' ) ? '<p>ابتدا اتصال Easy!Appointments را از HMN CRM ← موتور نوبت‌دهی پیکربندی کنید.</p>' : ''; }
		$s = self::settings(); $show_provider = empty( $s['hide_provider'] ); ob_start(); ?>
<form class="hmn-ea-form" dir="rtl" novalidate>
<div class="hmn-ea-grid"><label>نام<input required name="first_name"></label><label>نام خانوادگی<input required name="last_name"></label></div>
<label>شماره تلفن<input required name="user_phone" type="tel" inputmode="numeric" placeholder="09123456789"></label><div class="hmn-ea-otp"><button type="button" class="hmn-ea-send-otp">ارسال کد تأیید</button><input required name="otp_code" inputmode="numeric" maxlength="5" placeholder="کد تأیید پیامکی"></div>
<label>نوع سرویس<select required name="service_id"><option value="">در حال دریافت خدمات…</option></select></label>
<?php if ( $show_provider ) : ?><label>پزشک<select required name="provider_id"><option value="">ابتدا نوع سرویس را انتخاب کنید</option></select></label><?php else : ?><input name="provider_id" type="hidden" value="<?php echo esc_attr( absint( $s['default_provider_id'] ?? 0 ) ); ?>"><?php endif; ?>
<div class="hmn-ea-grid"><label>تاریخ نوبت<input required name="appointment_date" type="date" min="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>"></label><label>ساعت نوبت<select required name="appointment_time" disabled><option value="">ابتدا تاریخ را انتخاب کنید</option></select></label></div><button class="hmn-ea-submit" type="submit">ثبت نوبت</button><p class="hmn-ea-message" aria-live="polite"></p></form>
<style>.hmn-ea-form{max-width:620px;margin:20px auto;padding:24px;background:#fff;border:1px solid #e4e7ec;border-radius:16px;font-family:Tahoma,sans-serif}.hmn-ea-form label{display:grid;gap:7px;margin:0 0 14px;font-weight:700}.hmn-ea-form input,.hmn-ea-form select{width:100%;padding:11px;border:1px solid #cfd5df;border-radius:8px;font:inherit}.hmn-ea-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.hmn-ea-otp{display:flex;gap:10px;align-items:center;margin-bottom:14px}.hmn-ea-otp input{flex:1}.hmn-ea-send-otp,.hmn-ea-submit{border:0;border-radius:8px;background:#5b4cf0;color:#fff;padding:11px 16px;font:inherit;cursor:pointer}.hmn-ea-submit{width:100%;font-weight:700}.hmn-ea-message{min-height:1.5em;margin:12px 0 0}.hmn-ea-message.is-error{color:#b42318}.hmn-ea-message.is-success{color:#067647}@media(max-width:480px){.hmn-ea-grid{grid-template-columns:1fr}.hmn-ea-otp{align-items:stretch;flex-direction:column}}</style>
<script>(function(){var f=document.currentScript.previousElementSibling.previousElementSibling,ajax=<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,nonce=<?php echo wp_json_encode( wp_create_nonce( 'hmn_ea_booking' ) ); ?>;function req(a,d){d.action=a;d.nonce=nonce;return fetch(ajax,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:new URLSearchParams(d)}).then(r=>r.json()).then(r=>{if(!r.success)throw Error(r.data&&r.data.message||'خطا');return r.data})}function msg(t,c){var e=f.querySelector('.hmn-ea-message');e.textContent=t;e.className='hmn-ea-message '+(c||'')}function fill(e,x,p){e.innerHTML='<option value="">'+p+'</option>';x.forEach(i=>{var o=document.createElement('option');o.value=i.id;o.textContent=i.firstName?i.firstName+' '+i.lastName:i.name;e.appendChild(o)})}var service=f.elements.service_id,provider=f.elements.provider_id,date=f.elements.appointment_date,time=f.elements.appointment_time;req('hmn_ea_bootstrap',{}).then(d=>{fill(service,d.services,'انتخاب نوع سرویس');f._providers=d.providers||[]}).catch(e=>msg(e.message,'is-error'));service.addEventListener('change',()=>{var x=(f._providers||[]).filter(p=>!p.services||p.services.indexOf(+service.value)>=0);if(provider.tagName==='SELECT')fill(provider,x,'انتخاب پزشک');time.disabled=true});function slots(){if(!service.value||!provider.value||!date.value)return;time.disabled=true;req('hmn_ea_slots',{service_id:service.value,provider_id:provider.value,date:date.value}).then(d=>{time.innerHTML='<option value="">انتخاب ساعت</option>';d.slots.forEach(s=>{var o=document.createElement('option');o.value=s;o.textContent=s;time.appendChild(o)});time.disabled=false}).catch(e=>msg(e.message,'is-error'))}if(provider.tagName==='SELECT')provider.addEventListener('change',slots);date.addEventListener('change',slots);f.querySelector('.hmn-ea-send-otp').addEventListener('click',function(){var b=this,p=f.elements.user_phone.value;if(!/^09\d{9}$/.test(p)){msg('شماره تلفن معتبر نیست.','is-error');return}b.disabled=true;req('hmn_send_otp',{user_phone:p,nonce:<?php echo wp_json_encode( wp_create_nonce( 'hmn_send_otp' ) ); ?>}).then(()=>msg('کد تأیید ارسال شد.','is-success')).catch(e=>msg(e.message,'is-error')).finally(()=>setTimeout(()=>b.disabled=false,120000))});f.addEventListener('submit',e=>{e.preventDefault();var d={};new FormData(f).forEach((v,k)=>d[k]=v);var b=f.querySelector('.hmn-ea-submit');b.disabled=true;msg('در حال ثبت نوبت…');req('hmn_ea_book',d).then(r=>{msg('نوبت شما با موفقیت ثبت شد. کد پیگیری: '+r.id,'is-success');f.reset();time.disabled=true}).catch(e=>msg(e.message,'is-error')).finally(()=>b.disabled=false)})})();</script>
<?php return ob_get_clean(); }

	public function bootstrap_ajax() { $this->verify(); $services = self::request( 'GET', 'services' ); $providers = self::request( 'GET', 'providers' ); if ( is_wp_error( $services ) ) { $this->error( $services ); } if ( is_wp_error( $providers ) ) { $this->error( $providers ); } wp_send_json_success( array( 'services' => $services, 'providers' => $providers ) ); }
	public function slots_ajax() { $this->verify(); $service = absint( $_POST['service_id'] ?? 0 ); $provider = absint( $_POST['provider_id'] ?? 0 ); $date = sanitize_text_field( wp_unslash( $_POST['date'] ?? '' ) ); if ( ! $service || ! $provider || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) { wp_send_json_error( array( 'message' => 'اطلاعات انتخاب نوبت کامل نیست.' ), 400 ); } $slots = self::request( 'GET', 'availabilities', null, array( 'serviceId' => $service, 'providerId' => $provider, 'date' => $date ) ); if ( is_wp_error( $slots ) ) { $this->error( $slots ); } wp_send_json_success( array( 'slots' => $slots ) ); }
	public function book_ajax() {
		$this->verify(); $first = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) ); $last = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) ); $phone = preg_replace( '/\D+/', '', sanitize_text_field( wp_unslash( $_POST['user_phone'] ?? '' ) ) ); $otp = sanitize_text_field( wp_unslash( $_POST['otp_code'] ?? '' ) ); $service = absint( $_POST['service_id'] ?? 0 ); $provider = absint( $_POST['provider_id'] ?? 0 ); $date = sanitize_text_field( wp_unslash( $_POST['appointment_date'] ?? '' ) ); $time = sanitize_text_field( wp_unslash( $_POST['appointment_time'] ?? '' ) );
		if ( ! $first || ! $last || ! preg_match( '/^09\d{9}$/', $phone ) || ! $service || ! $provider || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) { wp_send_json_error( array( 'message' => 'همه فیلدهای نوبت را کامل و صحیح وارد کنید.' ), 400 ); }
		$stored = get_transient( 'hmn_crm_otp_' . md5( $phone ) ); if ( ! $stored || ! hash_equals( (string) $stored, $otp ) ) { wp_send_json_error( array( 'message' => 'کد تأیید اشتباه است یا منقضی شده است.' ), 403 ); }
		$slots = self::request( 'GET', 'availabilities', null, array( 'serviceId' => $service, 'providerId' => $provider, 'date' => $date ) ); if ( is_wp_error( $slots ) ) { $this->error( $slots ); } if ( ! in_array( $time, $slots, true ) ) { wp_send_json_error( array( 'message' => 'این زمان دیگر در دسترس نیست؛ لطفاً زمان دیگری انتخاب کنید.' ), 409 ); }
		$customers = self::request( 'GET', 'customers', null, array( 'q' => $phone, 'length' => 100 ) ); if ( is_wp_error( $customers ) ) { $this->error( $customers ); } $customer_id = 0; foreach ( $customers as $customer ) { if ( isset( $customer['phone'] ) && preg_replace( '/\D+/', '', (string) $customer['phone'] ) === $phone ) { $customer_id = absint( $customer['id'] ); break; } }
		if ( ! $customer_id ) { $customer = self::request( 'POST', 'customers', array( 'firstName' => $first, 'lastName' => $last, 'phone' => $phone, 'timezone' => 'Asia/Tehran', 'language' => 'english' ) ); if ( is_wp_error( $customer ) ) { $this->error( $customer ); } $customer_id = absint( $customer['id'] ?? 0 ); }
		$appointment = self::request( 'POST', 'appointments', array( 'start' => $date . ' ' . $time . ':00', 'customerId' => $customer_id, 'providerId' => $provider, 'serviceId' => $service, 'status' => 'Booked' ) ); if ( is_wp_error( $appointment ) ) { $this->error( $appointment ); } delete_transient( 'hmn_crm_otp_' . md5( $phone ) );
		$sms = get_option( 'hmn_crm_sms_settings', array() ); $body_id = is_array( $sms ) ? absint( $sms['melipayamak_booking_body_id'] ?? 0 ) : 0; if ( $body_id ) { ( new HMN_CRM_SMS() )->send_pattern( $phone, $body_id, array( trim( $first . ' ' . $last ), $date, $time ) ); }
		wp_send_json_success( array( 'id' => absint( $appointment['id'] ?? 0 ) ) );
	}
	public static function appointments( $from, $till ) {
		$rows = self::request( 'GET', 'appointments', null, array( 'from' => $from, 'till' => $till, 'with' => 'customer,service,provider', 'length' => 200 ) );
		if ( is_wp_error( $rows ) || ! is_array( $rows ) ) { return $rows; }
		$normalized = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$customer = isset( $row['customer'] ) && is_array( $row['customer'] ) ? $row['customer'] : array();
			$service = isset( $row['service'] ) && is_array( $row['service'] ) ? $row['service'] : array();
			$normalized[] = array( 'id' => absint( $row['id'] ?? 0 ), 'appointment_date' => substr( (string) ( $row['start'] ?? '' ), 0, 10 ), 'appointment_time' => substr( (string) ( $row['start'] ?? '' ), 11, 5 ), 'field_name' => sanitize_text_field( $customer['firstName'] ?? '' ), 'field_lname' => sanitize_text_field( $customer['lastName'] ?? '' ), 'user_phone' => sanitize_text_field( $customer['phone'] ?? '' ), 'service_title' => sanitize_text_field( $service['name'] ?? '' ) );
		}
		return $normalized;
	}
	private function verify() { if ( ! check_ajax_referer( 'hmn_ea_booking', 'nonce', false ) ) { wp_send_json_error( array( 'message' => 'درخواست نامعتبر است.' ), 403 ); } }
	private function error( $e ) { wp_send_json_error( array( 'message' => $e->get_error_message() ), (int) ( $e->get_error_data()['status'] ?? 502 ) ); }
}
new HMN_CRM_EasyAppointments();
