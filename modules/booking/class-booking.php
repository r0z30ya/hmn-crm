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
		add_action( 'wp_ajax_hmn_refresh_otp_nonce', array( $this, 'refresh_otp_nonce' ) );
		add_action( 'wp_ajax_nopriv_hmn_refresh_otp_nonce', array( $this, 'refresh_otp_nonce' ) );

		// JetEngine versions expose the custom validator with this filter.
		add_filter( 'jet-engine/forms/booking/custom-validation', array( $this, 'validate_otp' ), 10, 4 );
		// These post-submit hooks cover JetEngine/JetAppointments integrations.
		add_action( 'jet-engine/forms/booking/post-submit', array( $this, 'send_booking_sms' ), 10, 2 );
		add_action( 'jet-engine/forms/handler/after-send', array( $this, 'send_booking_sms' ), 10, 2 );
		// Can also be selected as a JetEngine “Call Hook” post-submit action.
		add_action( 'hmn_crm_booking_post_submit', array( $this, 'send_booking_sms' ), 10, 2 );
		add_action( 'wp_head', array( $this, 'print_custom_css' ) );
		add_action( 'wp_footer', array( $this, 'print_frontend_script' ), 99 );
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
			wp_send_json_error( array( 'message' => __( 'لطفاً کمی بعد دوباره تلاش کنید.', 'hmn-crm' ) ), 429 );
		}

		set_transient( 'hmn_crm_otp_rate_' . md5( $phone ), 1, 120 );

		$settings = get_option( 'hmn_crm_sms_settings', array() );
		$body_value = isset( $settings['melipayamak_otp_body_id'] ) ? $settings['melipayamak_otp_body_id'] : ( isset( $settings['otp_body_id'] ) ? $settings['otp_body_id'] : 0 );
		$body_id  = is_scalar( $body_value ) ? absint( $body_value ) : 0;
		$sms      = new HMN_CRM_SMS();
		$result   = $sms->send_otp( $phone );
		if ( ! is_wp_error( $result ) && isset( $result['code'] ) && is_scalar( $result['code'] ) ) {
			$code = sanitize_text_field( (string) $result['code'] );
			set_transient( $this->get_transient_key( $phone ), $code, self::OTP_TTL );
		} elseif ( ! is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => __( 'پاسخ نامعتبر از سرویس پیامک دریافت شد.', 'hmn-crm' ) ), 502 );
		}

		if ( is_wp_error( $result ) ) {
			delete_transient( $this->get_transient_key( $phone ) );
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		wp_send_json_success( array( 'message' => __( 'کد تایید ارسال شد.', 'hmn-crm' ) ) );
	}

	/** Return a fresh nonce for an OTP request. */
	public function refresh_otp_nonce() {
		wp_send_json_success( array( 'nonce' => wp_create_nonce( 'hmn_send_otp' ) ) );
	}

	/** Print administrator-provided booking CSS safely in the document head. */
	public function print_custom_css() {
		$settings = get_option( 'hmn_crm_sms_settings', array() );
		$css = isset( $settings['custom_css'] ) && is_scalar( $settings['custom_css'] ) ? (string) $settings['custom_css'] : '';
		if ( '' !== trim( $css ) ) { echo "\n<style id=\"hmn-crm-custom-css\">\n" . wp_strip_all_tags( $css ) . "\n</style>\n"; }
	}

	/** Add the OTP button and a lightweight Jalali date presentation layer. */
	public function print_frontend_script() {
		if ( is_admin() ) { return; }
		$ajax_url = admin_url( 'admin-ajax.php' );
		$nonce = wp_create_nonce( 'hmn_send_otp' );
		?>
		<script>
		(function(){
		var ajaxUrl=<?php echo wp_json_encode( $ajax_url ); ?>, nonce=<?php echo wp_json_encode( $nonce ); ?>, cooldown=120;
			function pad(n){return n<10?'0'+n:n;}
			function insertButton(input){if(input.dataset.hmnOtpReady)return; input.dataset.hmnOtpReady='1'; var otp=input.form?input.form.querySelector('input[name="otp_code"],input[name*="[otp_code]"]'):null; if(!otp){otp=document.createElement('input');otp.type='text';otp.name='otp_code';otp.placeholder='کد تأیید';otp.className='hmn-crm-otp-code';input.parentNode.appendChild(otp);} var b=document.createElement('button'); b.type='button'; b.className='hmn-crm-send-otp button'; b.textContent='ارسال رمز یکبار مصرف'; input.parentNode.appendChild(b); b.addEventListener('click',function(){var phone=input.value.trim(); if(!/^09\d{9}$/.test(phone)){alert('شماره موبایل معتبر نیست.');return;} b.disabled=true; var remaining=cooldown; var timer=setInterval(function(){b.textContent='ارسال مجدد ('+remaining+' ثانیه)'; remaining--; if(remaining<0){clearInterval(timer);b.disabled=false;b.textContent='ارسال رمز یکبار مصرف';}},1000); fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:new URLSearchParams({action:'hmn_refresh_otp_nonce'})}).then(function(r){return r.json();}).then(function(n){if(n&&n.success&&n.data&&n.data.nonce)nonce=n.data.nonce; return fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:new URLSearchParams({action:'hmn_send_otp',user_phone:phone,nonce:nonce})});}).then(function(r){return r.json();}).then(function(j){alert(j&&j.success?'کد تایید ارسال شد.':(j&&j.data&&j.data.message?'خطا: '+j.data.message:'ارسال OTP ناموفق بود.'));}).catch(function(){alert('اتصال به سرور برقرار نشد.');});});}
			document.querySelectorAll('input[name="user_phone"],input[name*="[user_phone]"]').forEach(insertButton);
			var observer=new MutationObserver(function(){document.querySelectorAll('input[name="user_phone"],input[name*="[user_phone]"]').forEach(insertButton);}); observer.observe(document.body,{childList:true,subtree:true});
			/* Native date input remains Gregorian for JetAppointments; a Jalali text mirror is shown to users. */
			window.hmnCrmToJalali=function(g){var d=new Date(g),gy=d.getFullYear(),gm=d.getMonth()+1,gd=d.getDate(),gdm=[0,31,59,90,120,151,181,212,243,273,304,334],jy=gy>1600?979:0; gy-=gy>1600?1600:621; var gy2=gm>2?gy+1:gy,days=365*gy+Math.floor((gy2+3)/4)-Math.floor((gy2+99)/100)+Math.floor((gy2+399)/400)-80+gd+gdm[gm-1]; jy+=33*Math.floor(days/12053); days%=12053; jy+=4*Math.floor(days/1461); days%=1461; if(days>365){jy+=Math.floor((days-1)/365);days=(days-1)%365;} var jm=days<186?1+Math.floor(days/31):7+Math.floor((days-186)/30),jd=1+(days<186?days%31:(days-186)%30); return jy+'/'+pad(jm)+'/'+pad(jd);};
			function installJalali(input){if(input.dataset.hmnJalaliReady)return; input.dataset.hmnJalaliReady='1'; var visible=document.createElement('input'); visible.type='text'; visible.className=input.className+' hmn-crm-jalali-date'; visible.placeholder='۱۴۰۳/۰۱/۱۰'; visible.value=input.value?hmnCrmToJalali(input.value):''; input.type='hidden'; input.parentNode.insertBefore(visible,input); visible.addEventListener('change',function(){var m=visible.value.match(/^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})$/); if(!m){return;} var jy=+m[1],jm=+m[2],jd=+m[3],gy=jy+621; if(jm>10||(jm===10&&jd>11))gy++; var date=new Date(gy,2,21); date.setDate(date.getDate()+(jm<=6?(jm-1)*31:186+(jm-7)*30)+jd-1); input.value=date.getFullYear()+'-'+pad(date.getMonth()+1)+'-'+pad(date.getDate()); input.dispatchEvent(new Event('change',{bubbles:true}));});}
			document.querySelectorAll('input[type="date"],input[name*="appointment_date"]').forEach(function(i){installJalali(i);});
		})();
		</script>
		<?php
	}

	/** Validate OTP values supplied by any JetEngine form containing the required fields. */
	public function validate_otp( $valid, $field = array(), $value = '', $form = array() ) {
		$data = $this->extract_form_data( array( $valid, $field, $form, $_POST ) );
		if ( ! isset( $data['user_phone'], $data['otp_code'] ) ) {
			return $valid;
		}

		$phone = sanitize_text_field( (string) $data['user_phone'] );
		$otp   = sanitize_text_field( (string) $data['otp_code'] );
		$attempt_key = 'hmn_crm_otp_attempts_' . md5( $phone );
		$attempts = (int) get_transient( $attempt_key );
		$stored = get_transient( $this->get_transient_key( $phone ) );
		if ( ! $stored || ! hash_equals( (string) $stored, $otp ) ) {
			$attempts++;
			set_transient( $attempt_key, $attempts, self::OTP_TTL );
			if ( $attempts >= 5 ) { delete_transient( $this->get_transient_key( $phone ) ); }
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
