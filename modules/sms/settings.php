<?php
/** SMS settings, test forms and delivery logs. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Manage SMS settings in a tabbed RTL admin page. */
final class HMN_CRM_SMS_Settings {
	const OPTION_NAME = 'hmn_crm_sms_settings';
	const LOG_OPTION = 'hmn_crm_sms_logs';
	const PAGE_SLUG = 'hmn-crm-sms';
	private static $instance;

	/** Register hooks. */
	public function __construct() {
		self::$instance = $this;
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_hmn_crm_sms_test', array( $this, 'handle_test' ) );
		add_action( 'admin_post_hmn_crm_sms_clear_logs', array( $this, 'clear_logs' ) );
	}

	/** Render callback used by the core menu. */
	public static function render_page() { if ( self::$instance ) { self::$instance->render_settings_page(); } }

	/** Register settings option. */
	public function register_settings() { register_setting( 'hmn_crm_sms_settings_group', self::OPTION_NAME, array( 'type' => 'array', 'sanitize_callback' => array( $this, 'sanitize_settings' ), 'default' => array() ) ); }

	/** Merge and sanitize only values submitted by the current tab. */
	public function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array();
		$current = get_option( self::OPTION_NAME, array() );
		$current = is_array( $current ) ? $current : array();
		$output = $current;
		if ( array_key_exists( 'melipayamak_api_key', $input ) && is_scalar( $input['melipayamak_api_key'] ) ) { $output['melipayamak_api_key'] = sanitize_text_field( $input['melipayamak_api_key'] ); }
		foreach ( array( 'melipayamak_username', 'melipayamak_password' ) as $key ) { if ( array_key_exists( $key, $input ) && is_scalar( $input[ $key ] ) ) { $output[ $key ] = sanitize_text_field( $input[ $key ] ); } }
		foreach ( array( 'melipayamak_otp_body_id', 'melipayamak_booking_body_id', 'melipayamak_booking_edit_body_id', 'melipayamak_booking_cancel_body_id', 'melipayamak_booking_reminder_body_id' ) as $key ) {
			if ( array_key_exists( $key, $input ) && is_scalar( $input[ $key ] ) && '' !== trim( (string) $input[ $key ] ) ) { $output[ $key ] = absint( $input[ $key ] ); }
		}
		return array_merge( $current, $output );
	}

	/** Render all four tabs. */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$settings = get_option( self::OPTION_NAME, array() );
		$tab = isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connection';
		$tabs = array( 'connection' => 'تنظیمات وب‌سرویس', 'templates' => 'الگوهای پیامک', 'test' => 'تست ارسال پیامک', 'logs' => 'لاگ پیامک' );
		if ( ! isset( $tabs[ $tab ] ) ) { $tab = 'connection'; }
		?><div class="wrap" dir="rtl"><h1><?php echo esc_html__( 'HMN CRM — تنظیمات پیامک', 'hmn-crm' ); ?></h1><nav class="nav-tab-wrapper"><?php foreach ( $tabs as $slug => $label ) : ?><a class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $slug ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?></nav><?php
		if ( 'test' === $tab ) { $this->render_test_tab(); } elseif ( 'logs' === $tab ) { $this->render_logs_tab(); } else { $this->render_settings_form( $tab, $settings ); }
		?></div><?php
	}

	/** Render connection or templates form. */
	private function render_settings_form( $tab, $settings ) {
		?><form method="post" action="options.php"><?php settings_fields( 'hmn_crm_sms_settings_group' ); ?><table class="form-table"><?php if ( 'connection' === $tab ) : ?><tr><th><label for="melipayamak_api_key">کلید API ملی‌پیامک</label></th><td><input type="password" class="regular-text" id="melipayamak_api_key" name="hmn_crm_sms_settings[melipayamak_api_key]" value="<?php echo esc_attr( $this->setting( $settings, 'melipayamak_api_key' ) ); ?>" autocomplete="new-password" /><p class="description">کلید اختصاصی API را وارد کنید.</p></td></tr><tr><th><label for="melipayamak_username">نام کاربری روش ۱</label></th><td><input class="regular-text" id="melipayamak_username" name="hmn_crm_sms_settings[melipayamak_username]" value="<?php echo esc_attr( $this->setting( $settings, 'melipayamak_username' ) ); ?>" /></td></tr><tr><th><label for="melipayamak_password">رمز عبور روش ۱</label></th><td><input type="password" class="regular-text" id="melipayamak_password" name="hmn_crm_sms_settings[melipayamak_password]" value="<?php echo esc_attr( $this->setting( $settings, 'melipayamak_password' ) ); ?>" autocomplete="new-password" /></td></tr><?php else : $fields = array( 'melipayamak_otp_body_id' => 'کد تأیید OTP', 'melipayamak_booking_body_id' => 'ثبت نوبت جدید', 'melipayamak_booking_edit_body_id' => 'ویرایش نوبت', 'melipayamak_booking_cancel_body_id' => 'لغو نوبت', 'melipayamak_booking_reminder_body_id' => 'یادآوری نوبت' ); foreach ( $fields as $key => $label ) : ?><tr><th><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th><td><input type="number" min="0" class="small-text" id="<?php echo esc_attr( $key ); ?>" name="hmn_crm_sms_settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $this->setting( $settings, $key ) ); ?>" /></td></tr><?php endforeach; endif; ?></table><?php submit_button( 'ذخیره تنظیمات' ); ?></form><?php
	}

	/** Render two independent test methods. */
	private function render_test_tab() {
		$token = isset( $_GET['hmn_sms_result'] ) && is_scalar( $_GET['hmn_sms_result'] ) ? sanitize_key( wp_unslash( $_GET['hmn_sms_result'] ) ) : '';
		$result = $token ? get_transient( 'hmn_crm_sms_result_' . get_current_user_id() . '_' . $token ) : false;
		if ( $token ) { delete_transient( 'hmn_crm_sms_result_' . get_current_user_id() . '_' . $token ); }
		if ( is_array( $result ) ) { echo '<div class="notice notice-' . esc_attr( $result['type'] ) . ' is-dismissible"><p>' . esc_html( $result['message'] ) . '</p></div>'; }
		?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'hmn_crm_sms_test' ); ?><input type="hidden" name="action" value="hmn_crm_sms_test" /><table class="form-table"><tr><th><label for="hmn-test-phone">شماره موبایل</label></th><td><input required class="regular-text" id="hmn-test-phone" name="phone" placeholder="09123456789" /></td></tr><tr><th><label for="hmn-test-body">Body ID</label></th><td><input required type="number" min="1" class="small-text" id="hmn-test-body" name="body_id" /></td></tr><tr><th><label for="hmn-test-args">متغیرها</label></th><td><input required class="regular-text" id="hmn-test-args" name="args" placeholder="مثال: علی,۱۴۰۳/۰۱/۱۰" /></td></tr></table><p><button class="button button-primary" name="method" value="method_one">تست روش ۱ (BaseServiceNumber)</button> <button class="button" name="method" value="method_two">تست روش ۲ (Shared API)</button></p></form><?php
	}

	/** Render retained SMS logs. */
	private function render_logs_tab() {
		$logs = get_option( self::LOG_OPTION, array() ); $logs = is_array( $logs ) ? $logs : array();
		?><p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="hmn_crm_sms_clear_logs" /><?php wp_nonce_field( 'hmn_crm_sms_clear_logs' ); ?><button class="button">پاک کردن لاگ‌ها</button></form></p><table class="widefat striped"><thead><tr><th>تاریخ و زمان</th><th>گیرنده</th><th>روش</th><th>Payload</th><th>پاسخ API</th><th>خطا</th></tr></thead><tbody><?php if ( empty( $logs ) ) : ?><tr><td colspan="6">لاگی ثبت نشده است.</td></tr><?php else : foreach ( $logs as $log ) : ?><tr><td><?php echo esc_html( $log['date'] ); ?></td><td><?php echo esc_html( $log['to'] ); ?></td><td><?php echo esc_html( $log['method'] ); ?></td><td><pre><?php echo esc_html( $log['payload'] ); ?></pre></td><td><pre><?php echo esc_html( $log['response'] ); ?></pre></td><td><?php echo esc_html( $log['error'] ); ?></td></tr><?php endforeach; endif; ?></tbody></table><?php
	}

	/** Process selected test method and persist result/log. */
	public function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'دسترسی غیرمجاز است.', 403 ); }
		check_admin_referer( 'hmn_crm_sms_test' );
		$phone = isset( $_POST['phone'] ) && is_scalar( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$body_id = isset( $_POST['body_id'] ) && is_scalar( $_POST['body_id'] ) ? absint( $_POST['body_id'] ) : 0;
		$raw = isset( $_POST['args'] ) && is_scalar( $_POST['args'] ) ? sanitize_text_field( wp_unslash( $_POST['args'] ) ) : '';
		$args = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ), 'strlen' ) );
		$method_value = isset( $_POST['method'] ) && is_scalar( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : '';
		$method = 'method_one' === $method_value ? 'method_one' : 'method_two';
		$sms = new HMN_CRM_SMS(); $result = 'method_one' === $method ? $sms->send_method_one( $phone, $body_id, $args ) : $sms->send_method_two( $phone, $body_id, $args );
		$error_data = is_wp_error( $result ) ? $result->get_error_data() : array();
		$error_data = is_array( $error_data ) ? $error_data : array();
		$payload = isset( $error_data['payload'] ) && is_array( $error_data['payload'] ) ? $error_data['payload'] : array( 'bodyId' => $body_id, 'to' => $phone, 'args' => $args );
		if ( 'method_one' === $method ) { $payload['password'] = '[MASKED]'; }
		$error = is_wp_error( $result ) ? $result->get_error_message() : '';
		$data = $error_data;
		$response_raw = is_array( $data ) && isset( $data['raw_response'] ) ? (string) $data['raw_response'] : wp_json_encode( $result, JSON_UNESCAPED_UNICODE );
		$this->add_log( $phone, 'method_one' === $method ? 'روش ۱' : 'روش ۲', wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ), $response_raw, $error );
		$message = $error ? $error : ( 'method_one' === $method ? 'ارسال موفق روش ۱ — Value: ' . ( isset( $result['Value'] ) ? sanitize_text_field( $result['Value'] ) : '' ) : 'ارسال موفق روش ۲ — recId: ' . ( isset( $result['recId'] ) ? sanitize_text_field( (string) $result['recId'] ) : '' ) );
		$token = wp_generate_password( 24, false, false ); set_transient( 'hmn_crm_sms_result_' . get_current_user_id() . '_' . sanitize_key( $token ), array( 'type' => $error ? 'error' : 'success', 'message' => $message ), MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'test', 'hmn_sms_result' => sanitize_key( $token ) ), admin_url( 'admin.php' ) ) ); exit;
	}

	/** Clear all logs. */
	public function clear_logs() { if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'دسترسی غیرمجاز است.', 403 ); } check_admin_referer( 'hmn_crm_sms_clear_logs' ); delete_option( self::LOG_OPTION ); wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'logs' ), admin_url( 'admin.php' ) ) ); exit; }

	/** Append one log and retain only 50 newest records. */
	private function add_log( $to, $method, $payload, $response, $error ) { $logs = get_option( self::LOG_OPTION, array() ); $logs = is_array( $logs ) ? $logs : array(); array_unshift( $logs, array( 'date' => current_time( 'mysql' ), 'to' => sanitize_text_field( $to ), 'method' => sanitize_text_field( $method ), 'payload' => wp_check_invalid_utf8( (string) $payload ), 'response' => wp_check_invalid_utf8( (string) $response ), 'error' => sanitize_text_field( $error ) ) ); update_option( self::LOG_OPTION, array_slice( $logs, 0, 50 ), false ); }
	private function setting( $settings, $key ) { return isset( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) ? $settings[ $key ] : ''; }
}
new HMN_CRM_SMS_Settings();
