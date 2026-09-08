<?php
/**
 * SMS module settings screen.
 *
 * @package HMN_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage SMS module settings in the WordPress dashboard.
 */
final class HMN_CRM_SMS_Settings {

	/**
	 * Option name used to persist all SMS settings.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'hmn_crm_sms_settings';

	/**
	 * Register WordPress hooks.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
	}

	/**
	 * Register the module option with the WordPress Settings API.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'hmn_crm_sms_settings_group',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Add the SMS settings page to the WordPress options menu.
	 *
	 * @return void
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'تنظیمات پیامک HMN CRM', 'hmn-crm' ),
			__( 'پیامک HMN CRM', 'hmn-crm' ),
			'manage_options',
			'hmn-crm-sms',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Sanitize SMS settings before saving.
	 *
	 * @param array $input Raw submitted values.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array();

		return array(
			'api_key'         => isset( $input['api_key'] ) && is_scalar( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : '',
			'otp_body_id'     => isset( $input['otp_body_id'] ) && is_scalar( $input['otp_body_id'] ) ? absint( $input['otp_body_id'] ) : 0,
			'booking_body_id' => isset( $input['booking_body_id'] ) && is_scalar( $input['booking_body_id'] ) ? absint( $input['booking_body_id'] ) : 0,
		);
	}

	/**
	 * Render the SMS settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = get_option( self::OPTION_NAME, array() );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'تنظیمات پیامک HMN CRM', 'hmn-crm' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'hmn_crm_sms_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="hmn-crm-api-key"><?php echo esc_html__( 'API Key', 'hmn-crm' ); ?></label></th>
						<td><input type="password" class="regular-text" id="hmn-crm-api-key" name="hmn_crm_sms_settings[api_key]" value="<?php echo esc_attr( isset( $settings['api_key'] ) ? $settings['api_key'] : '' ); ?>" autocomplete="new-password" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="hmn-crm-otp-body-id"><?php echo esc_html__( 'Body ID برای OTP', 'hmn-crm' ); ?></label></th>
						<td><input type="number" min="0" class="small-text" id="hmn-crm-otp-body-id" name="hmn_crm_sms_settings[otp_body_id]" value="<?php echo esc_attr( isset( $settings['otp_body_id'] ) ? $settings['otp_body_id'] : '' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="hmn-crm-booking-body-id"><?php echo esc_html__( 'Body ID برای اطلاع‌رسانی نوبت', 'hmn-crm' ); ?></label></th>
						<td><input type="number" min="0" class="small-text" id="hmn-crm-booking-body-id" name="hmn_crm_sms_settings[booking_body_id]" value="<?php echo esc_attr( isset( $settings['booking_body_id'] ) ? $settings['booking_body_id'] : '' ); ?>" /></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

new HMN_CRM_SMS_Settings();
