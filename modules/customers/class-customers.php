<?php
/**
 * Customer CRM module.
 *
 * Owns local CRM data and customer-facing operations. Easy!Appointments remains
 * the appointment-engine source for identity basics; CRM-only fields remain
 * portable in the WordPress database.
 *
 * @package HMN_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HMN_CRM_Customers implements HMN_CRM_Module_Interface {
	const TABLE_VERSION = '1.1';

	public function __construct() {
		$this->boot();
	}

	public function boot() {
		add_action( 'hmn_crm_migrate', array( $this, 'install' ) );
		add_action( 'wp_ajax_hmn_customer_meta_save', array( $this, 'save_meta_ajax' ) );
		add_action( 'wp_ajax_hmn_ea_operator_customer', array( $this, 'operator_lookup_ajax' ) );
	}

	/** Create only the CRM extension table; do not duplicate Easy customer records. */
	public function install() {
		if ( self::TABLE_VERSION === get_option( 'hmn_crm_customer_table_version' ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			customer_id bigint(20) unsigned NOT NULL,
			file_number varchar(100) NOT NULL DEFAULT '',
			national_id varchar(20) NOT NULL DEFAULT '',
			status varchar(30) NOT NULL DEFAULT 'active',
			notes longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (customer_id),
			KEY file_number (file_number),
			KEY national_id (national_id),
			KEY status (status)
		) {$charset};";
		dbDelta( $sql );
		update_option( 'hmn_crm_customer_table_version', self::TABLE_VERSION, false );
	}

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'hmn_crm_customer_meta';
	}

	/** Return unified CRM customer rows for a search query. */
	public static function search( $query = '' ) {
		$engine_rows = HMN_CRM_EasyAppointments::request( 'GET', 'customers', null, array( 'length' => 500 ) );
		if ( is_wp_error( $engine_rows ) ) {
			return $engine_rows;
		}
		global $wpdb;
		$meta_rows = $wpdb->get_results( 'SELECT customer_id, file_number, national_id, status, notes FROM ' . self::table_name(), ARRAY_A );
		$meta      = array();
		foreach ( (array) $meta_rows as $row ) {
			$meta[ absint( $row['customer_id'] ) ] = $row;
		}

		$customers = array();
		foreach ( (array) $engine_rows as $row ) {
			$id   = absint( $row['id'] ?? 0 );
			$item = array(
				'id'       => $id,
				'first'    => sanitize_text_field( $row['firstName'] ?? ( $row['first_name'] ?? '' ) ),
				'last'     => sanitize_text_field( $row['lastName'] ?? ( $row['last_name'] ?? '' ) ),
				'phone'    => sanitize_text_field( $row['phone'] ?? ( $row['phone_number'] ?? '' ) ),
				'file'     => sanitize_text_field( $meta[ $id ]['file_number'] ?? '' ),
				'national' => sanitize_text_field( $meta[ $id ]['national_id'] ?? '' ),
				'status'   => sanitize_key( $meta[ $id ]['status'] ?? 'active' ),
			);
			if ( '' === $query || false !== mb_stripos( implode( ' ', $item ), $query ) ) {
				$customers[] = $item;
			}
		}
		usort( $customers, function( $a, $b ) {
			return strnatcasecmp( $a['last'] . $a['first'], $b['last'] . $b['first'] );
		} );
		return $customers;
	}

	/** Find the exact engine customer by mobile number. */
	public static function find_by_phone( $phone ) {
		$phone = preg_replace( '/\D+/', '', (string) $phone );
		$rows  = HMN_CRM_EasyAppointments::request( 'GET', 'customers', null, array( 'q' => $phone, 'length' => 100 ) );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		foreach ( (array) $rows as $row ) {
			$value = $row['phone'] ?? ( $row['phone_number'] ?? '' );
			if ( $phone === preg_replace( '/\D+/', '', (string) $value ) ) {
				return $row;
			}
		}
		return null;
	}

	public function operator_lookup_ajax() {
		self::verify_operator_request();
		$phone = preg_replace( '/\D+/', '', sanitize_text_field( wp_unslash( $_POST['operator_phone'] ?? '' ) ) );
		if ( ! preg_match( '/^09\d{9}$/', $phone ) ) {
			wp_send_json_error( array( 'message' => 'شماره تلفن معتبر نیست.' ), 400 );
		}
		$customer = self::find_by_phone( $phone );
		if ( is_wp_error( $customer ) ) {
			self::send_error( $customer );
		}
		if ( ! $customer ) {
			wp_send_json_success( array( 'found' => false ) );
		}
		wp_send_json_success( array(
			'found'      => true,
			'first_name' => sanitize_text_field( $customer['firstName'] ?? ( $customer['first_name'] ?? '' ) ),
			'last_name'  => sanitize_text_field( $customer['lastName'] ?? ( $customer['last_name'] ?? '' ) ),
		) );
	}

	public function save_meta_ajax() {
		self::verify_operator_request();
		$id       = absint( $_POST['customer_id'] ?? 0 );
		$file     = sanitize_text_field( wp_unslash( $_POST['file_number'] ?? '' ) );
		$national = preg_replace( '/\D+/', '', sanitize_text_field( wp_unslash( $_POST['national_id'] ?? '' ) ) );
		if ( ! $id || ( $national && ! preg_match( '/^\d{10}$/', $national ) ) ) {
			wp_send_json_error( array( 'message' => 'شماره پرونده یا شماره ملی معتبر نیست.' ), 400 );
		}
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->replace( self::table_name(), array(
			'customer_id' => $id,
			'file_number' => $file,
			'national_id' => $national,
			'status'      => 'active',
			'notes'       => '',
			'created_at'  => $now,
			'updated_at'  => $now,
		), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		wp_send_json_success();
	}

	/** Customer module owns its portal UI rather than the appointment dashboard. */
	public static function render_portal( $base, $user ) {
		$query     = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$customers = self::search( $query );
		$error     = is_wp_error( $customers ) ? $customers->get_error_message() : '';
		$customers = is_array( $customers ) ? $customers : array();
		?>
<!doctype html><html <?php language_attributes(); ?> dir="rtl"><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title>مشتریان | HMN CRM</title><?php wp_head(); ?><style><?php HMN_CRM_Dashboard::portal_styles(); ?>
.hmn-customers{background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:0 6px 22px rgba(22,32,51,.035)}.hmn-customer-toolbar{padding:22px;border-bottom:1px solid var(--line)}.hmn-search{display:flex;gap:10px}.hmn-search input{width:min(100%,460px);height:46px;border:1px solid #d0d5dd;border-radius:9px;padding:0 14px;font:inherit}.hmn-search button,.hmn-edit-customer{border:0;border-radius:9px;background:var(--brand);color:#fff;padding:0 17px;font:inherit;cursor:pointer}.hmn-customer-count,.hmn-customer-error{margin:14px 0 0;color:var(--muted)}.hmn-customer-error{color:#b42318}.hmn-customer-table th:nth-child(n+3),.hmn-customer-table td:nth-child(n+3){text-align:center}.hmn-customer-modal{position:fixed;inset:0;z-index:1002;background:rgba(16,24,40,.45);display:grid;place-items:center;padding:16px}.hmn-customer-modal[hidden]{display:none}.hmn-customer-dialog{position:relative;width:min(100%,440px);background:#fff;border-radius:16px;padding:24px}.hmn-customer-dialog label{display:grid;gap:7px;margin-top:15px;font-weight:600}.hmn-customer-dialog input{height:46px;border:1px solid #d0d5dd;border-radius:9px;padding:0 12px;font:inherit}.hmn-customer-dialog form{display:grid}.hmn-customer-dialog button[type=submit]{margin-top:20px;height:46px}.hmn-customer-close{position:absolute;left:12px;top:12px;border:0;border-radius:50%;width:32px;height:32px;cursor:pointer}@media(max-width:640px){.hmn-search{flex-direction:column}.hmn-search input,.hmn-search button{width:100%}}</style></head><body class="hmn-portal-body"><div class="hmn-portal"><aside class="hmn-sidebar"><div class="hmn-brand"><span class="hmn-brand-mark">H</span><span>HMN CRM</span></div><nav class="hmn-nav"><a href="<?php echo esc_url( $base ); ?>"><span>⌂</span> داشبورد نوبت‌ها</a><a class="is-active" href="<?php echo esc_url( add_query_arg( 'section', 'customers', $base ) ); ?>"><span>♙</span> مشتریان</a><a href="<?php echo esc_url( admin_url( 'admin.php?page=hmn-crm-sms' ) ); ?>"><span>✉</span> تنظیمات پیامک</a><a href="<?php echo esc_url( admin_url() ); ?>"><span>⚙</span> پیشخوان وردپرس</a></nav><div class="hmn-user"><span class="hmn-avatar"><?php echo esc_html( mb_substr( $user->display_name ? $user->display_name : $user->user_login, 0, 1 ) ); ?></span><div><strong><?php echo esc_html( $user->display_name ); ?></strong><a href="<?php echo esc_url( wp_logout_url( $base ) ); ?>">خروج از حساب</a></div></div></aside><main class="hmn-main"><header class="hmn-topbar"><div><p class="hmn-eyebrow">مدیریت مرکز درمانی</p><h1>مشتریان</h1></div></header><section class="hmn-customers"><div class="hmn-customer-toolbar"><form class="hmn-search" method="get" action="<?php echo esc_url( $base ); ?>"><input type="hidden" name="section" value="customers"><input name="q" value="<?php echo esc_attr( $query ); ?>" placeholder="جست‌وجو در نام، نام خانوادگی، تلفن، شماره پرونده یا شماره ملی"><button type="submit">جست‌وجو</button></form><?php if ( $error ) : ?><p class="hmn-customer-error"><?php echo esc_html( $error ); ?></p><?php else : ?><p class="hmn-customer-count"><?php echo esc_html( count( $customers ) ); ?> مشتری</p><?php endif; ?></div><div class="hmn-table-wrap"><table class="hmn-customer-table"><thead><tr><th>نام</th><th>نام خانوادگی</th><th>تلفن</th><th>شماره پرونده</th><th>شماره ملی</th><th>عملیات</th></tr></thead><tbody><?php foreach ( $customers as $customer ) : ?><tr><td><?php echo esc_html( $customer['first'] ); ?></td><td><?php echo esc_html( $customer['last'] ); ?></td><td dir="ltr"><?php echo esc_html( $customer['phone'] ); ?></td><td><?php echo esc_html( $customer['file'] ?: '—' ); ?></td><td><?php echo esc_html( $customer['national'] ?: '—' ); ?></td><td><button type="button" class="hmn-action hmn-edit-customer" data-id="<?php echo esc_attr( $customer['id'] ); ?>" data-name="<?php echo esc_attr( trim( $customer['first'] . ' ' . $customer['last'] ) ); ?>" data-file="<?php echo esc_attr( $customer['file'] ); ?>" data-national="<?php echo esc_attr( $customer['national'] ); ?>">تکمیل پرونده</button></td></tr><?php endforeach; ?></tbody></table></div></section></main></div><div class="hmn-customer-modal" hidden><form class="hmn-customer-dialog"><button type="button" class="hmn-customer-close" aria-label="بستن">×</button><h3>تکمیل پرونده مشتری</h3><p class="hmn-customer-name"></p><input type="hidden" name="customer_id"><label>شماره پرونده<input name="file_number"></label><label>شماره ملی<input name="national_id" inputmode="numeric" maxlength="10"></label><button type="submit" class="hmn-edit-customer">ذخیره</button><p class="hmn-customer-message"></p></form></div><script>(function(){var m=document.querySelector('.hmn-customer-modal'),f=m.querySelector('form'),msg=f.querySelector('.hmn-customer-message');document.addEventListener('click',function(e){var b=e.target.closest('.hmn-edit-customer');if(!b||!b.dataset.id)return;f.elements.customer_id.value=b.dataset.id;f.elements.file_number.value=b.dataset.file||'';f.elements.national_id.value=b.dataset.national||'';f.querySelector('.hmn-customer-name').textContent=b.dataset.name;m.hidden=false});m.querySelector('.hmn-customer-close').onclick=function(){m.hidden=true};m.onclick=function(e){if(e.target===m)m.hidden=true};f.onsubmit=function(e){e.preventDefault();msg.textContent='در حال ذخیره…';fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:new URLSearchParams({action:'hmn_customer_meta_save',nonce:<?php echo wp_json_encode( wp_create_nonce( 'hmn_ea_operator_booking' ) ); ?>,customer_id:f.elements.customer_id.value,file_number:f.elements.file_number.value,national_id:f.elements.national_id.value})}).then(r=>r.json()).then(r=>{if(!r.success)throw Error(r.data&&r.data.message||'خطا');location.reload()}).catch(x=>msg.textContent=x.message)}})();</script><?php wp_footer(); ?></body></html>
		<script>(function(){var portal=document.querySelector('.hmn-portal'),sidebar=document.querySelector('.hmn-sidebar'),header=document.querySelector('.hmn-topbar');if(!portal||!sidebar||!header)return;var menu=document.createElement('button');menu.type='button';menu.className='hmn-menu';menu.setAttribute('aria-label','باز کردن منو');menu.textContent='☰';header.insertBefore(menu,header.firstChild);menu.addEventListener('click',function(e){e.stopPropagation();portal.classList.toggle('menu-open')});document.addEventListener('click',function(e){if(portal.classList.contains('menu-open')&&!sidebar.contains(e.target)&&!menu.contains(e.target))portal.classList.remove('menu-open')});})();</script>
		<?php
	}

	private static function verify_operator_request() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'hmn_ea_operator_booking', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی نامعتبر است.' ), 403 );
		}
	}

	private static function send_error( $error ) {
		wp_send_json_error( array( 'message' => $error->get_error_message() ), (int) ( $error->get_error_data()['status'] ?? 502 ) );
	}
}

new HMN_CRM_Customers();
