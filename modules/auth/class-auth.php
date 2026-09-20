<?php
/** Custom CRM sign-in and WordPress-admin isolation. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class HMN_CRM_Auth implements HMN_CRM_Module_Interface {
	public function __construct() { $this->boot(); }
	public function boot() {
		add_action( 'admin_post_nopriv_hmn_crm_login', array( __CLASS__, 'handle_login' ) );
		add_action( 'admin_post_hmn_crm_login', array( __CLASS__, 'handle_login' ) );
		add_action( 'admin_init', array( __CLASS__, 'keep_crm_users_out_of_wp_admin' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'hide_admin_bar' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'redirect_after_wp_login' ), 10, 3 );
	}

	public static function handle_login() {
		if ( ! check_admin_referer( 'hmn_crm_login', 'hmn_crm_login_nonce' ) ) { self::failed(); }
		$user = wp_signon( array( 'user_login' => sanitize_text_field( wp_unslash( $_POST['log'] ?? '' ) ), 'user_password' => (string) ( $_POST['pwd'] ?? '' ), 'remember' => ! empty( $_POST['rememberme'] ) ), is_ssl() );
		if ( is_wp_error( $user ) || ! HMN_CRM_Core::can( HMN_CRM_Core::CAP_ACCESS ) ) { if ( ! is_wp_error( $user ) ) { wp_logout(); } self::failed(); }
		wp_safe_redirect( home_url( '/hcrm/' ) ); exit;
	}

	private static function failed() { wp_safe_redirect( add_query_arg( 'hmn_crm_login', 'failed', home_url( '/hcrm/' ) ) ); exit; }

	public static function keep_crm_users_out_of_wp_admin() {
		if ( ! is_user_logged_in() || current_user_can( 'manage_options' ) || wp_doing_ajax() ) { return; }
		$action = sanitize_key( wp_unslash( $_REQUEST['action'] ?? '' ) );
		if ( 0 === strpos( $action, 'hmn_crm_' ) ) { return; }
		if ( HMN_CRM_Core::can( HMN_CRM_Core::CAP_ACCESS ) ) { wp_safe_redirect( home_url( '/hcrm/' ) ); exit; }
	}

	public static function hide_admin_bar( $show ) { return HMN_CRM_Core::can( HMN_CRM_Core::CAP_ACCESS ) && ! current_user_can( 'manage_options' ) ? false : $show; }
	public static function redirect_after_wp_login( $redirect, $requested, $user ) { return $user instanceof WP_User && ! user_can( $user, 'manage_options' ) && user_can( $user, HMN_CRM_Core::CAP_ACCESS ) ? home_url( '/hcrm/' ) : $redirect; }

	public static function render_login() {
		$failed = 'failed' === sanitize_key( wp_unslash( $_GET['hmn_crm_login'] ?? '' ) );
		?><!doctype html><html <?php language_attributes(); ?> dir="rtl"><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ورود به HMN CRM</title><?php wp_head(); ?><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f5f7fb;color:#172033;font-family:Tahoma,"Segoe UI",sans-serif}.hmn-login{width:min(100% - 32px,400px);padding:32px;background:#fff;border:1px solid #e7eaf1;border-radius:18px;box-shadow:0 16px 42px rgba(22,32,51,.12)}.hmn-login-brand{font-weight:800;font-size:20px;margin-bottom:28px}.hmn-login label{display:grid;gap:7px;margin:14px 0;font-size:13px;font-weight:700}.hmn-login input[type=text],.hmn-login input[type=password]{height:46px;border:1px solid #d0d5dd;border-radius:9px;padding:0 12px;font:inherit;direction:ltr;text-align:left}.hmn-login .remember{display:flex;align-items:center;gap:7px;font-weight:400}.hmn-login button{width:100%;height:48px;border:0;border-radius:9px;background:#5b4cf0;color:#fff;font:inherit;font-weight:700;cursor:pointer;margin-top:8px}.hmn-login-error{padding:10px 12px;background:#fff1f3;color:#b42318;border-radius:8px;font-size:13px}</style></head><body><main class="hmn-login"><div class="hmn-login-brand">HMN CRM</div><h1>ورود اپراتور</h1><p>نام کاربری و رمز عبور CRM را وارد کنید.</p><?php if ( $failed ) : ?><div class="hmn-login-error">نام کاربری، رمز عبور یا سطح دسترسی معتبر نیست.</div><?php endif; ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><label>نام کاربری<input type="text" name="log" required autocomplete="username"></label><label>رمز عبور<input type="password" name="pwd" required autocomplete="current-password"></label><label class="remember"><input type="checkbox" name="rememberme" value="forever"> مرا به خاطر بسپار</label><input type="hidden" name="action" value="hmn_crm_login"><?php wp_nonce_field( 'hmn_crm_login', 'hmn_crm_login_nonce' ); ?><button type="submit">ورود به CRM</button></form></main><?php wp_footer(); ?></body></html><?php
	}
}
new HMN_CRM_Auth();
