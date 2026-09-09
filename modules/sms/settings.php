<?php
/** SMS settings UI. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Manage console SMS settings and OTP test. */
final class HMN_CRM_SMS_Settings {
	const OPTION_NAME = 'hmn_crm_sms_settings';
	const PAGE_SLUG = 'hmn-crm-sms';
	private static $instance;

	/** Register hooks. */
	public function __construct() {
		self::$instance = $this;
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_hmn_crm_sms_test', array( $this, 'handle_test' ) );
	}

	/** Render callback. */
	public static function render_page() { if ( self::$instance ) { self::$instance->render(); } }

	/** Register Settings API option. */
	public function register_settings() { register_setting( 'hmn_crm_sms_settings_group', self::OPTION_NAME, array( 'type' => 'array', 'sanitize_callback' => array( $this, 'sanitize_settings' ), 'default' => array() ) ); }

	/** Merge current values and sanitize submitted fields. */
	public function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array(); $current = get_option( self::OPTION_NAME, array() ); $current = is_array( $current ) ? $current : array(); $output = $current;
		foreach ( array( 'melipayamak_api_key', 'melipayamak_username', 'melipayamak_password', 'melipayamak_smart_username', 'melipayamak_smart_from', 'custom_css' ) as $key ) { if ( array_key_exists( $key, $input ) && is_scalar( $input[ $key ] ) ) { $output[ $key ] = 'custom_css' === $key ? wp_strip_all_tags( (string) $input[ $key ] ) : sanitize_text_field( $input[ $key ] ); } }
		foreach ( array( 'melipayamak_otp_body_id', 'melipayamak_booking_body_id', 'melipayamak_booking_edit_body_id', 'melipayamak_booking_cancel_body_id', 'melipayamak_booking_reminder_body_id' ) as $key ) { if ( array_key_exists( $key, $input ) && is_scalar( $input[ $key ] ) && '' !== trim( (string) $input[ $key ] ) ) { $output[ $key ] = absint( $input[ $key ] ); } }
		return array_merge( $current, $output );
	}

	/** Render settings page. */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$settings = get_option( self::OPTION_NAME, array() ); $tab = isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connection'; $tabs = array( 'connection' => 'تنظیمات وب‌سرویس', 'templates' => 'الگوهای پیامک', 'test' => 'تست ارسال پیامک' ); if ( ! isset( $tabs[ $tab ] ) ) { $tab = 'connection'; }
		?><div class="wrap" dir="rtl"><h1>HMN CRM — تنظیمات پیامک</h1><nav class="nav-tab-wrapper"><?php foreach ( $tabs as $slug => $label ) : ?><a class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $slug ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?></nav><?php if ( 'test' === $tab ) { $this->render_test( $settings ); } else { $this->render_form( $tab, $settings ); } ?></div><?php
	}

	/** Render connection/templates forms. */
	private function render_form( $tab, $s ) {
		?><form method="post" action="options.php"><?php settings_fields( 'hmn_crm_sms_settings_group' ); ?><table class="form-table"><?php if ( 'connection' === $tab ) : ?><tr><th><label for="melipayamak_api_key">کلید API کنسول</label></th><td><input type="password" class="regular-text" id="melipayamak_api_key" name="hmn_crm_sms_settings[melipayamak_api_key]" value="<?php echo esc_attr( $this->v( $s, 'melipayamak_api_key' ) ); ?>" autocomplete="new-password" /></td></tr><tr><th><label for="melipayamak_smart_username">نام کاربری اسمارت</label></th><td><input class="regular-text" id="melipayamak_smart_username" name="hmn_crm_sms_settings[melipayamak_smart_username]" value="<?php echo esc_attr( $this->v( $s, 'melipayamak_smart_username' ) ); ?>" /></td></tr><tr><th><label for="melipayamak_smart_from">شماره فرستنده اسمارت</label></th><td><input class="regular-text" id="melipayamak_smart_from" name="hmn_crm_sms_settings[melipayamak_smart_from]" value="<?php echo esc_attr( $this->v( $s, 'melipayamak_smart_from' ) ); ?>" /></td></tr><tr><th><label for="hmn-crm-custom-css">Custom CSS</label></th><td><textarea class="large-text code" rows="8" id="hmn-crm-custom-css" name="hmn_crm_sms_settings[custom_css]"><?php echo esc_textarea( $this->v( $s, 'custom_css' ) ); ?></textarea></td></tr><?php else : $fields = array( 'melipayamak_otp_body_id' => 'کد تأیید OTP', 'melipayamak_booking_body_id' => 'ثبت نوبت جدید', 'melipayamak_booking_edit_body_id' => 'ویرایش نوبت', 'melipayamak_booking_cancel_body_id' => 'لغو نوبت', 'melipayamak_booking_reminder_body_id' => 'یادآوری نوبت' ); foreach ( $fields as $key => $label ) : ?><tr><th><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th><td><input type="number" min="0" class="small-text" id="<?php echo esc_attr( $key ); ?>" name="hmn_crm_sms_settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $this->v( $s, $key ) ); ?>" /></td></tr><?php endforeach; endif; ?></table><?php submit_button( 'ذخیره تنظیمات' ); ?></form><?php
	}

	/** Render OTP test form and result. */
	private function render_test( $s ) {
		$status = isset( $_GET['hmn_sms_test'] ) ? sanitize_key( wp_unslash( $_GET['hmn_sms_test'] ) ) : ''; if ( 'success' === $status ) { echo '<div class="notice notice-success"><p>OTP با موفقیت از کنسول ارسال شد.</p></div>'; } elseif ( 'error' === $status ) { echo '<div class="notice notice-error"><p>' . esc_html( isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : 'ارسال OTP ناموفق بود.' ) . '</p></div>'; }
		?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'hmn_crm_sms_test' ); ?><input type="hidden" name="action" value="hmn_crm_sms_test" /><input type="hidden" name="method" value="otp" /><table class="form-table"><tr><th><label for="hmn-test-phone">شماره موبایل</label></th><td><input required class="regular-text" id="hmn-test-phone" name="phone" placeholder="09123456789" /></td></tr></table><?php submit_button( 'تست ارسال OTP' ); ?></form><?php
	}

	/** Handle OTP test. */
	public function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'دسترسی غیرمجاز است.', 403 ); } check_admin_referer( 'hmn_crm_sms_test' );
		$phone = isset( $_POST['phone'] ) && is_scalar( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : ''; $result = ( new HMN_CRM_SMS() )->send_otp( $phone ); $args = array( 'page' => self::PAGE_SLUG, 'tab' => 'test' ); if ( is_wp_error( $result ) ) { $args['hmn_sms_test'] = 'error'; $args['message'] = sanitize_text_field( $result->get_error_message() ); error_log( 'HMN CRM OTP test failed: ' . $args['message'] ); } else { $args['hmn_sms_test'] = 'success'; } wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) ); exit;
	}

	/** Return a setting value. */
	private function v( $s, $key ) { return isset( $s[ $key ] ) && is_scalar( $s[ $key ] ) ? $s[ $key ] : ''; }
}
new HMN_CRM_SMS_Settings();
