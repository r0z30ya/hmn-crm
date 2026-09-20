<?php
/**
 * HMN CRM module loader and application core.
 *
 * @package HMN_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-module.php';

/**
 * Main HMN CRM singleton.
 */
final class HMN_CRM_Core {
	const ROLE_OPERATOR = 'hmn_crm_operator';
	const CAP_ACCESS = 'hmn_crm_access';
	const CAP_MANAGE_APPOINTMENTS = 'hmn_crm_manage_appointments';
	const CAP_MANAGE_CUSTOMERS = 'hmn_crm_manage_customers';
	const CAP_MANAGE_SCHEDULING = 'hmn_crm_manage_scheduling';

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
		add_action( 'init', array( $this, 'ensure_roles' ), 1 );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'init', array( $this, 'register_routes' ) );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'render_portal' ) );
		add_action( 'wp_footer', array( $this, 'render_portal_navigation_enhancements' ), 20 );
		add_action( 'admin_post_nopriv_hmn_crm_login', array( $this, 'handle_crm_login' ) );
		add_action( 'admin_post_hmn_crm_login', array( $this, 'handle_crm_login' ) );
		add_action( 'admin_init', array( $this, 'keep_operators_out_of_wp_admin' ) );
		add_filter( 'show_admin_bar', array( $this, 'hide_operator_admin_bar' ) );
		add_filter( 'login_redirect', array( $this, 'redirect_operator_after_wp_login' ), 10, 3 );
	}

	/** Create the base CRM role and its capability vocabulary. */
	public function ensure_roles() {
		$capabilities = array(
			'read' => true,
			self::CAP_ACCESS => true,
			self::CAP_MANAGE_APPOINTMENTS => true,
			self::CAP_MANAGE_CUSTOMERS => true,
		);
		$role = get_role( self::ROLE_OPERATOR );
		if ( ! $role ) {
			add_role( self::ROLE_OPERATOR, 'اپراتور CRM', $capabilities );
			return;
		}
		foreach ( $capabilities as $capability => $granted ) {
			if ( $granted ) { $role->add_cap( $capability ); }
		}
	}

	/** Administrators retain CRM access; other users need an explicit CRM capability. */
	public static function can( $capability = self::CAP_ACCESS ) {
		return current_user_can( 'manage_options' ) || current_user_can( $capability );
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
		/** Let feature modules perform their own, versioned schema migrations. */
		do_action( 'hmn_crm_migrate' );
		add_rewrite_rule( '^hcrm/?$', 'index.php?hmn_crm_portal=1', 'top' );
		if ( '2.2.0' !== get_option( 'hmn_crm_rewrite_version' ) ) {
			flush_rewrite_rules( false );
			update_option( 'hmn_crm_rewrite_version', '2.2.0', false );
		}
	}

	/** Run module migrations on plugin activation as well as normal requests. */
	public function migrate() {
		$this->ensure_roles();
		do_action( 'hmn_crm_migrate' );
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
			$this->render_crm_login();
			exit;
		}
		if ( ! self::can( self::CAP_ACCESS ) ) {
			wp_die( esc_html__( 'شما به پنل مدیریت نوبت‌ها دسترسی ندارید.', 'hmn-crm' ), 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			add_action( 'wp_head', array( $this, 'hide_operator_portal_links' ) );
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

	/** Process the branded CRM sign-in form with WordPress' native authentication. */
	public function handle_crm_login() {
		if ( ! check_admin_referer( 'hmn_crm_login', 'hmn_crm_login_nonce' ) ) {
			wp_safe_redirect( add_query_arg( 'hmn_crm_login', 'failed', home_url( '/hcrm/' ) ) );
			exit;
		}
		$credentials = array(
			'user_login' => sanitize_text_field( wp_unslash( $_POST['log'] ?? '' ) ),
			'user_password' => (string) ( $_POST['pwd'] ?? '' ),
			'remember' => ! empty( $_POST['rememberme'] ),
		);
		$user = wp_signon( $credentials, is_ssl() );
		if ( is_wp_error( $user ) || ! self::can( self::CAP_ACCESS ) ) {
			if ( ! is_wp_error( $user ) ) { wp_logout(); }
			wp_safe_redirect( add_query_arg( 'hmn_crm_login', 'failed', home_url( '/hcrm/' ) ) );
			exit;
		}
		wp_safe_redirect( home_url( '/hcrm/' ) );
		exit;
	}

	/** Render a standalone sign-in page at /hcrm for logged-out CRM staff. */
	private function render_crm_login() {
		$failed = isset( $_GET['hmn_crm_login'] ) && 'failed' === sanitize_key( wp_unslash( $_GET['hmn_crm_login'] ) );
		?><!doctype html>
<html <?php language_attributes(); ?> dir="rtl">
<head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ورود به HMN CRM</title><?php wp_head(); ?>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f5f7fb;color:#172033;font-family:Tahoma,"Segoe UI",sans-serif}.hmn-login{width:min(100% - 32px,400px);padding:32px;background:#fff;border:1px solid #e7eaf1;border-radius:18px;box-shadow:0 16px 42px rgba(22,32,51,.12)}.hmn-login-brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:20px;margin-bottom:28px}.hmn-login-mark{display:grid;place-items:center;width:38px;height:38px;border-radius:12px;color:#fff;background:linear-gradient(135deg,#8175ff,#4c3bdd)}.hmn-login h1{font-size:21px;margin:0 0 8px}.hmn-login p{color:#667085;line-height:1.8;margin:0 0 22px}.hmn-login label{display:grid;gap:7px;margin:14px 0;font-size:13px;font-weight:700}.hmn-login input[type=text],.hmn-login input[type=password]{height:46px;border:1px solid #d0d5dd;border-radius:9px;padding:0 12px;font:inherit;direction:ltr;text-align:left}.hmn-login .remember{display:flex;align-items:center;gap:7px;font-weight:400}.hmn-login button{width:100%;height:48px;border:0;border-radius:9px;background:#5b4cf0;color:#fff;font:inherit;font-weight:700;cursor:pointer;margin-top:8px}.hmn-login-error{padding:10px 12px;background:#fff1f3;color:#b42318;border-radius:8px;font-size:13px}</style></head>
<body><main class="hmn-login"><div class="hmn-login-brand"><span class="hmn-login-mark">H</span><span>HMN CRM</span></div><h1>ورود اپراتور</h1><p>نام کاربری و رمز عبور CRM خود را وارد کنید.</p><?php if ( $failed ) : ?><div class="hmn-login-error">نام کاربری، رمز عبور یا سطح دسترسی معتبر نیست.</div><?php endif; ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><label>نام کاربری<input type="text" name="log" required autocomplete="username"></label><label>رمز عبور<input type="password" name="pwd" required autocomplete="current-password"></label><label class="remember"><input type="checkbox" name="rememberme" value="forever"> مرا به خاطر بسپار</label><input type="hidden" name="action" value="hmn_crm_login"><?php wp_nonce_field( 'hmn_crm_login', 'hmn_crm_login_nonce' ); ?><button type="submit">ورود به CRM</button></form></main><?php wp_footer(); ?></body></html><?php
	}

	/** CRM-only users never enter the WordPress administration area. */
	public function keep_operators_out_of_wp_admin() {
		if ( ! is_user_logged_in() || current_user_can( 'manage_options' ) || wp_doing_ajax() ) { return; }
		if ( self::can( self::CAP_ACCESS ) ) {
			wp_safe_redirect( home_url( '/hcrm/' ) );
			exit;
		}
	}

	public function hide_operator_admin_bar( $show ) {
		return self::can( self::CAP_ACCESS ) && ! current_user_can( 'manage_options' ) ? false : $show;
	}

	/** Avoid exposing WordPress navigation links in the CRM shell, even briefly. */
	public function hide_operator_portal_links() {
		?><style>.hmn-portal .hmn-nav a[href*="/wp-admin/"],.hmn-portal .hmn-nav a[href*="section=scheduling"]{display:none!important}</style><?php
	}

	public function redirect_operator_after_wp_login( $redirect_to, $requested_redirect_to, $user ) {
		if ( $user instanceof WP_User && ! user_can( $user, 'manage_options' ) && user_can( $user, self::CAP_ACCESS ) ) { return home_url( '/hcrm/' ); }
		return $redirect_to;
	}

	/** Keep the scheduling link nested beneath the dashboard in every CRM portal view. */
	public function render_portal_navigation_enhancements() {
		$settings_url = add_query_arg( 'section', 'scheduling', home_url( '/hcrm/' ) );
		$hide_scheduling = ! self::can( self::CAP_MANAGE_SCHEDULING );
		$hide_wp_admin = ! current_user_can( 'manage_options' );
		?>
		<style>.hmn-portal .hmn-nav-child{margin:-4px 0 2px 18px!important;padding:9px 12px!important;font-size:12px;color:#bfc8df!important}.hmn-portal .hmn-nav-child span{font-size:12px}.hmn-portal .hmn-nav-child:hover,.hmn-portal .hmn-nav-child.is-active{color:#fff!important}</style>
		<script>(function(){var navs=document.querySelectorAll('.hmn-portal .hmn-nav'),url=<?php echo wp_json_encode( $settings_url ); ?>,hideScheduling=<?php echo wp_json_encode( $hide_scheduling ); ?>,hideWpAdmin=<?php echo wp_json_encode( $hide_wp_admin ); ?>;navs.forEach(function(nav){if(hideWpAdmin)nav.querySelectorAll('a[href*="/wp-admin/"]').forEach(function(link){link.remove()});var link=nav.querySelector('a[href*="section=scheduling"]'),parent=nav.querySelector('a');if(hideScheduling){if(link)link.remove();return}if(link){link.classList.add('hmn-nav-child');return}if(!parent)return;link=document.createElement('a');link.href=url;link.className='hmn-nav-child';link.innerHTML='<span>⚙</span> تنظیمات نوبت‌دهی';parent.insertAdjacentElement('afterend',link)})})();</script>
		<?php
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
