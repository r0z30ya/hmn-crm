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
		add_action( 'init', array( $this, 'register_routes' ) );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'render_portal' ) );
	}

	/** Register the HMN CRM top-level menu and module submenus. */
	public function register_admin_menu() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		add_menu_page( 'HMN CRM', 'HMN CRM', 'manage_options', 'hmn-crm', array( $this, 'redirect_to_portal' ), 'dashicons-calendar-alt', 30 );
		if ( class_exists( 'HMN_CRM_EasyAppointments_Settings' ) ) {
			add_submenu_page( 'hmn-crm', 'موتور نوبت‌دهی', 'موتور نوبت‌دهی', 'manage_options', 'hmn-crm-easyappointments', array( 'HMN_CRM_EasyAppointments_Settings', 'render_page' ) );
		}
		if ( class_exists( 'HMN_CRM_SMS_Settings' ) ) {
			add_submenu_page( 'hmn-crm', 'تنظیمات پیامک', 'تنظیمات پیامک', 'manage_options', 'hmn-crm-sms', array( 'HMN_CRM_SMS_Settings', 'render_page' ) );
		}
	}

	/** Register the staff portal URL. */
	public function register_routes() {
		add_rewrite_rule( '^hcrm/?$', 'index.php?hmn_crm_portal=1', 'top' );
		if ( '2.2.0' !== get_option( 'hmn_crm_rewrite_version' ) ) {
			flush_rewrite_rules( false );
			update_option( 'hmn_crm_rewrite_version', '2.2.0', false );
		}
	}

	/** Allow the internal portal query variable. */
	public function register_query_var( $vars ) {
		$vars[] = 'hmn_crm_portal';
		return $vars;
	}

	/** Render the portal outside the theme when /hcrm is requested. */
	public function render_portal() {
		$request_path = trim( (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), '/' );
		if ( ! get_query_var( 'hmn_crm_portal' ) && 'hcrm' !== $request_path ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( home_url( '/hcrm/' ) ) );
			exit;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما به پنل مدیریت نوبت‌ها دسترسی ندارید.', 'hmn-crm' ), 403 );
		}
		if ( class_exists( 'HMN_CRM_Dashboard' ) ) {
			HMN_CRM_Dashboard::render_portal();
			exit;
		}
		wp_die( esc_html__( 'ماژول پنل نوبت‌ها بارگذاری نشد.', 'hmn-crm' ), 500 );
	}

	/** Open the custom staff portal from the WordPress menu. */
	public function redirect_to_portal() {
		wp_safe_redirect( home_url( '/hcrm/' ) );
		exit;
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
