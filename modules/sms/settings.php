<?php
/** SMS settings UI. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Manage SMS settings and test delivery. */
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

	/** Return the loaded settings instance. */
	public static function get_instance() { return self::$instance; }

	/** Render callback used by the core menu. */
	public static function render_page() {
		if ( self::$instance ) { self::$instance->render_settings_page(); }
	}

	/** Register the Settings API option. */
	public function register_settings() {
		register_setting( 'hmn_crm_sms_settings_group', self::OPTION_NAME, array( 'type' => 'array', 'sanitize_callback' => array( $this, 'sanitize_settings' ), 'default' => array() ) );
	}

	/** Sanitize all persisted values. */
	public function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array();
		$keys = array( 'melipayamak_api_key' );
		$output = array();
		foreach ( $keys as $key ) { $output[ $key ] = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? sanitize_text_field( $input[ $key ] ) : ''; }
		foreach ( array( 'melipayamak_otp_body_id', 'melipayamak_booking_body_id', 'melipayamak_booking_edit_body_id', 'melipayamak_booking_cancel_body_id', 'melipayamak_booking_reminder_body_id' ) as $key ) {
			$output[ $key ] = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? absint( $input[ $key ] ) : 0;
		}
		return $output;
	}

	/** Render the RTL tabbed settings screen. */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$settings = get_option( self::OPTION_NAME, array() );
		$tab = isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connection';
		$tabs = array( 'connection' => 'تنظیمات وب‌سرویس', 'templates' => 'الگوهای پیامک', 'test' => 'تست ارسال پیامک' );
		if ( ! isset( $tabs[ $tab ] ) ) { $tab = 'connection'; }
		?>
		<div class="wrap" dir="rtl">
			<h1><?php echo esc_html__( 'HMN CRM — تنظیمات پیامک', 'hmn-crm' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?><a class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $slug ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?>
			</nav>
			<?php if ( 'test' === $tab ) : $this->render_test_tab(); else : ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'hmn_crm_sms_settings_group' ); ?>
				<?php if ( 'connection' === $tab ) : ?>
					<table class="form-table"><tr><th><label for="melipayamak_api_key">کلید API ملی‌پیامک</label></th><td><input type="password" class="regular-text" id="melipayamak_api_key" name="hmn_crm_sms_settings[melipayamak_api_key]" value="<?php echo esc_attr( isset( $settings['melipayamak_api_key'] ) ? $settings['melipayamak_api_key'] : '' ); ?>" autocomplete="new-password" /><p class="description">کلید اختصاصی REST API را از پنل ملی‌پیامک وارد کنید.</p></td></tr></table>
				<?php else : $fields = array( 'melipayamak_otp_body_id' => 'کد تأیید OTP', 'melipayamak_booking_body_id' => 'ثبت نوبت جدید', 'melipayamak_booking_edit_body_id' => 'ویرایش نوبت', 'melipayamak_booking_cancel_body_id' => 'لغو نوبت', 'melipayamak_booking_reminder_body_id' => 'یادآوری نوبت (رزرو شده برای فاز بعد)' ); ?>
					<table class="form-table"><?php foreach ( $fields as $key => $label ) : ?><tr><th><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th><td><input type="number" min="0" class="small-text" id="<?php echo esc_attr( $key ); ?>" name="hmn_crm_sms_settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( isset( $settings[ $key ] ) ? $settings[ $key ] : '' ); ?>" /><p class="description">شناسه Body ID الگوی مربوط به <?php echo esc_html( $label ); ?>.</p></td></tr><?php endforeach; ?></table>
				<?php endif; submit_button( 'ذخیره تنظیمات' ); ?>
			</form><?php endif; ?>
		</div><?php
	}

	/** Render the test-message tab. */
	private function render_test_tab() {
		$status = isset( $_GET['hmn_sms_test'] ) ? sanitize_key( wp_unslash( $_GET['hmn_sms_test'] ) ) : '';
		if ( 'success' === $status ) { echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'پیامک آزمایشی با موفقیت ارسال شد.', 'hmn-crm' ) . '</p></div>'; }
		if ( 'error' === $status ) { echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'ارسال ناموفق بود؛ گزارش خطا را بررسی کنید.', 'hmn-crm' ) . '</p></div>'; }
		?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="hmn_crm_sms_test" /><?php wp_nonce_field( 'hmn_crm_sms_test' ); ?><table class="form-table"><tr><th><label for="hmn-test-phone">شماره موبایل</label></th><td><input required type="text" class="regular-text" id="hmn-test-phone" name="phone" placeholder="09123456789" /></td></tr><tr><th><label for="hmn-test-body">Body ID الگو</label></th><td><input required type="number" min="1" class="small-text" id="hmn-test-body" name="body_id" /></td></tr><tr><th><label for="hmn-test-args">مقادیر متغیرها</label></th><td><input type="text" class="regular-text" id="hmn-test-args" name="args" placeholder="مثال: علی،۱۴۰۳/۰۱/۰۱" /><p class="description">مقادیر را با ویرگول انگلیسی جدا کنید.</p></td></tr></table><?php submit_button( 'ارسال پیامک آزمایشی' ); ?></form><?php
	}

	/** Process and log a test-message request. */
	public function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'دسترسی غیرمجاز است.', 'hmn-crm' ), 403 ); }
		check_admin_referer( 'hmn_crm_sms_test' );
		$phone = isset( $_POST['phone'] ) && is_scalar( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$body_id = isset( $_POST['body_id'] ) && is_scalar( $_POST['body_id'] ) ? absint( $_POST['body_id'] ) : 0;
		$raw_args = isset( $_POST['args'] ) && is_scalar( $_POST['args'] ) ? sanitize_text_field( wp_unslash( $_POST['args'] ) ) : '';
		$args = '' === $raw_args ? array() : array_map( 'trim', explode( ',', $raw_args ) );
		$result = ( new HMN_CRM_SMS() )->send_pattern( $phone, $body_id, $args );
		$target = add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'test', 'hmn_sms_test' => is_wp_error( $result ) ? 'error' : 'success' ), admin_url( 'admin.php' ) );
		if ( is_wp_error( $result ) ) { error_log( 'HMN CRM SMS test: ' . $result->get_error_message() ); }
		wp_safe_redirect( $target ); exit;
	}
}
new HMN_CRM_SMS_Settings();
