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
