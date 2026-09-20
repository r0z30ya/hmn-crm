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
	const ROLE_MANAGER = 'hmn_crm_manager';
	const CAP_ACCESS = 'hmn_crm_access';
	const CAP_MANAGE_APPOINTMENTS = 'hmn_crm_manage_appointments';
	const CAP_MANAGE_CUSTOMERS = 'hmn_crm_manage_customers';
	const CAP_MANAGE_SCHEDULING = 'hmn_crm_manage_scheduling';
	const CAP_MANAGE_SETTINGS = 'hmn_crm_manage_settings';

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
		add_action( 'wp_footer', array( $this, 'render_portal_navigation_enhancements' ), 20 );
	}

	/** Create the base CRM role and its capability vocabulary. */
	public function ensure_roles() {
		if ( class_exists( 'HMN_CRM_Access' ) ) {
			HMN_CRM_Access::ensure_roles();
			return;
		}
		$capabilities = array(
			'read' => true,
			self::CAP_ACCESS => true,
			self::CAP_MANAGE_APPOINTMENTS => true,
			self::CAP_MANAGE_CUSTOMERS => true,
		);
		$role = get_role( self::ROLE_OPERATOR );
		if ( ! $role ) {
			add_role( self::ROLE_OPERATOR, 'اپراتور CRM', $capabilities );
		} else {
			foreach ( $capabilities as $capability => $granted ) { if ( $granted ) { $role->add_cap( $capability ); } }
		}
		$manager_capabilities = array_merge( $capabilities, array( self::CAP_MANAGE_SCHEDULING => true, self::CAP_MANAGE_SETTINGS => true ) );
		$manager = get_role( self::ROLE_MANAGER );
		if ( ! $manager ) { add_role( self::ROLE_MANAGER, 'مدیر CRM', $manager_capabilities ); }
		else { foreach ( $manager_capabilities as $capability => $granted ) { if ( $granted ) { $manager->add_cap( $capability ); } } }
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
		/* The custom route is rendered here, so WordPress must not retain its 404 status. */
		global $wp_query;
		if ( $wp_query instanceof WP_Query ) {
			$wp_query->is_404 = false;
			$wp_query->is_home = false;
			$wp_query->is_page = true;
		}
		status_header( 200 );
		nocache_headers();
		if ( ! is_user_logged_in() ) {
			if ( class_exists( 'HMN_CRM_Auth' ) ) { HMN_CRM_Auth::render_login(); }
			else { $this->render_crm_login(); }
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
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( 0 === strpos( $action, 'hmn_crm_' ) ) { return; }
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
		?><style>.hmn-portal .hmn-nav a[href*="/wp-admin/"]{display:none!important}</style><?php
	}

	public function redirect_operator_after_wp_login( $redirect_to, $requested_redirect_to, $user ) {
		if ( $user instanceof WP_User && ! user_can( $user, 'manage_options' ) && user_can( $user, self::CAP_ACCESS ) ) { return home_url( '/hcrm/' ); }
		return $redirect_to;
	}

	/** Keep the scheduling link nested beneath the dashboard in every CRM portal view. */
	public function render_portal_navigation_enhancements() {
		$appointments_url = home_url( '/hcrm/' );
		$patients_url = add_query_arg( 'section', 'customers', $appointments_url );
		$scheduling_url = add_query_arg( 'section', 'scheduling', $appointments_url );
		$panel_url = add_query_arg( 'section', 'settings', $appointments_url );
		$sms_url = $panel_url . '#hmn-sms-settings';
		$show_scheduling = self::can( self::CAP_MANAGE_SCHEDULING );
		$show_panel_settings = self::can( self::CAP_MANAGE_SETTINGS );
		$hide_wp_admin = ! current_user_can( 'manage_options' );
		?>
		<style>
			.hmn-nav{gap:5px!important}.hmn-nav-parent{width:100%;border:0;background:transparent;color:inherit;border-radius:10px;padding:13px 12px;font:inherit;text-align:right;cursor:pointer;display:flex;align-items:center;justify-content:space-between}.hmn-nav-parent:hover,.hmn-nav-parent.is-open{background:#2a3150;color:#fff}.hmn-nav-parent .hmn-nav-arrow{transition:transform .18s}.hmn-nav-parent.is-open .hmn-nav-arrow{transform:rotate(180deg)}.hmn-nav-group{display:none;margin:0 12px 4px;border-right:1px solid #46506f;padding-right:8px}.hmn-nav-group.is-open{display:grid;gap:3px}.hmn-nav-group a{padding:9px 10px!important;font-size:12px!important;color:#bfc8df!important}.hmn-nav-group a.is-active,.hmn-nav-group a:hover{color:#fff!important;background:#2a3150}.hmn-brand{font-size:18px!important}
		</style>
		<script>(function(){
			var urls={appointments:<?php echo wp_json_encode( $appointments_url ); ?>,patients:<?php echo wp_json_encode( $patients_url ); ?>,scheduling:<?php echo wp_json_encode( $scheduling_url ); ?>,panel:<?php echo wp_json_encode( $panel_url ); ?>,sms:<?php echo wp_json_encode( $sms_url ); ?>},canSchedule=<?php echo wp_json_encode( $show_scheduling ); ?>,canPanel=<?php echo wp_json_encode( $show_panel_settings ); ?>,hideWpAdmin=<?php echo wp_json_encode( $hide_wp_admin ); ?>,current=location.href;
			document.querySelectorAll('.hmn-brand').forEach(function(brand){brand.innerHTML='<span class="hmn-brand-mark">H</span><span>پنل هومانا</span>'});
			document.querySelectorAll('.hmn-portal .hmn-nav').forEach(function(nav){
				var items='<a data-nav="appointments" href="'+urls.appointments+'"><span>⌂</span> نوبت‌ها</a><a data-nav="patients" href="'+urls.patients+'"><span>♙</span> بیماران</a>';
				if(canSchedule||canPanel){items+='<button type="button" class="hmn-nav-parent" aria-expanded="false"><span><span>⚙</span> تنظیمات</span><span class="hmn-nav-arrow">⌄</span></button><div class="hmn-nav-group">';
					if(canSchedule)items+='<a data-nav="scheduling" href="'+urls.scheduling+'">تنظیمات نوبت‌دهی</a>';
					if(canPanel){items+='<a data-nav="panel" href="'+urls.panel+'">تنظیمات پنل</a><a data-nav="sms" href="'+urls.sms+'">تنظیمات پیامک</a>';}
				items+='</div>';}
				nav.innerHTML=items;
				var active=current.indexOf('section=customers')>-1?'patients':current.indexOf('section=scheduling')>-1?'scheduling':location.hash==='#hmn-sms-settings'?'sms':current.indexOf('section=settings')>-1?'panel':'appointments';
				var link=nav.querySelector('[data-nav="'+active+'"]');if(link)link.classList.add('is-active');
				var parent=nav.querySelector('.hmn-nav-parent'),group=nav.querySelector('.hmn-nav-group');if(parent&&group){var open=active==='scheduling'||active==='panel';parent.classList.toggle('is-open',open);group.classList.toggle('is-open',open);parent.setAttribute('aria-expanded',open?'true':'false');parent.onclick=function(){var next=!group.classList.contains('is-open');group.classList.toggle('is-open',next);parent.classList.toggle('is-open',next);parent.setAttribute('aria-expanded',next?'true':'false')}}
			});
		})();</script>
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
