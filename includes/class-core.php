<?php
/**
 * HMN CRM module loader and application core.
 *
 * @package HMN_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main HMN CRM singleton.
 */
final class HMN_CRM_Core {

	/**
	 * Singleton instance.
	 *
	 * @var HMN_CRM_Core|null
	 */
	private static $instance = null;

	/**
	 * Loaded module slugs.
	 *
	 * @var string[]
	 */
	private $loaded_modules = array();

	/**
	 * Private constructor to enforce the singleton pattern.
	 */
	private function __construct() {
		$this->load_modules();
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
	}

	/** Register the HMN CRM top-level menu and module submenus. */
	public function register_admin_menu() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		add_menu_page( 'HMN CRM', 'HMN CRM', 'manage_options', 'hmn-crm', array( $this, 'render_dashboard' ), 'dashicons-calendar-alt', 30 );
		if ( class_exists( 'HMN_CRM_SMS_Settings' ) ) {
			add_submenu_page( 'hmn-crm', 'تنظیمات پیامک', 'تنظیمات پیامک', 'manage_options', 'hmn-crm-sms', array( 'HMN_CRM_SMS_Settings', 'render_page' ) );
		}
	}

	/** Render the top-level dashboard placeholder. */
	public function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?><div class="wrap" dir="rtl"><h1><?php echo esc_html__( 'HMN CRM', 'hmn-crm' ); ?></h1><p><?php echo esc_html__( 'به داشبورد مدیریت HMN CRM خوش آمدید.', 'hmn-crm' ); ?></p></div><?php
	}

	/**
	 * Return the one core instance.
	 *
	 * @return HMN_CRM_Core
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Load every module's class and optional settings file.
	 *
	 * A module is a direct child directory of modules/. For a directory named
	 * "sms", class-sms.php and settings.php are loaded when they exist.
	 *
	 * @return void
	 */
	private function load_modules() {
		$modules_directory = dirname( __DIR__ ) . DIRECTORY_SEPARATOR . 'modules';

		if ( ! is_dir( $modules_directory ) ) {
			return;
		}

		$module_directories = glob( $modules_directory . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR );
		if ( false === $module_directories ) {
			return;
		}

		foreach ( $module_directories as $module_directory ) {
			$module_name = basename( $module_directory );
			if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]*$/i', $module_name ) ) {
				continue;
			}

			$class_file    = $module_directory . DIRECTORY_SEPARATOR . 'class-' . sanitize_file_name( $module_name ) . '.php';
			$settings_file = $module_directory . DIRECTORY_SEPARATOR . 'settings.php';

			if ( is_readable( $class_file ) ) {
				require_once $class_file;
			}

			if ( is_readable( $settings_file ) ) {
				require_once $settings_file;
			}

			$this->loaded_modules[] = $module_name;
		}
	}

	/**
	 * Get the slugs of successfully scanned modules.
	 *
	 * @return string[]
	 */
	public function get_loaded_modules() {
		return $this->loaded_modules;
	}

	/**
	 * Prevent cloning.
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Prevent unserializing another instance.
	 *
	 * @return void
	 */
	public function __wakeup() {
		throw new Exception( 'Cannot unserialize HMN_CRM_Core.' );
	}
}
