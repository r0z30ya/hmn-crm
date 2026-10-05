<?php
/**
 * Accounting module.
 *
 * Daily income/expense ledger for the clinic with an operator-friendly
 * Jalali calendar, per-day totals and optional patient linkage.
 *
 * @package HMN_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HMN_CRM_Accounting implements HMN_CRM_Module_Interface {
	const TABLE_VERSION    = '1.0';
	const OPTION_VERSION   = 'hmn_crm_accounting_table_version';
	const OPTION_CATEGORIES = 'hmn_crm_accounting_categories';

	/** Default income/expense categories seeded on first run. */
	private static function default_categories() {
		return array(
			'income'  => array( 'درمان', 'جراحی', 'فروش مواد', 'سایر درآمد' ),
			'expense' => array( 'اجاره', 'حقوق', 'مواد مصرفی', 'تجهیزات', 'تبلیغات', 'سایر هزینه' ),
		);
	}

	public function __construct() {
		$this->boot();
	}

	public function boot() {
		add_action( 'hmn_crm_migrate', array( $this, 'install' ) );
		add_action( 'wp_ajax_hmn_crm_accounting_save', array( $this, 'save_ajax' ) );
		add_action( 'wp_ajax_hmn_crm_accounting_delete', array( $this, 'delete_ajax' ) );
		add_action( 'wp_ajax_hmn_crm_accounting_categories', array( $this, 'categories_ajax' ) );
	}

	/** Create the transactions table (versioned like the customer meta table). */
	public function install() {
		if ( self::TABLE_VERSION === get_option( self::OPTION_VERSION ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			trx_date date NOT NULL,
			type varchar(10) NOT NULL DEFAULT 'income',
			category varchar(100) NOT NULL DEFAULT '',
			amount bigint(20) unsigned NOT NULL DEFAULT 0,
			description text NULL,
			customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
			payment_method varchar(30) NOT NULL DEFAULT 'cash',
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY trx_date (trx_date),
			KEY type (type),
			KEY customer_id (customer_id)
		) {$charset};";
		dbDelta( $sql );
		update_option( self::OPTION_VERSION, self::TABLE_VERSION, false );

		if ( ! get_option( self::OPTION_CATEGORIES ) ) {
			update_option( self::OPTION_CATEGORIES, self::default_categories(), false );
		}
	}

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'hmn_crm_transactions';
	}

	/** Category list for one side, or both. */
	public static function categories( $type = null ) {
		$cats = get_option( self::OPTION_CATEGORIES, self::default_categories() );
		$cats = is_array( $cats ) ? $cats : self::default_categories();
		if ( in_array( $type, array( 'income', 'expense' ), true ) ) {
			$cats[ $type ] = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) $cats[ $type ] ) ) ) );
			return $cats[ $type ];
		}
		return $cats;
	}

	/** All patients for the transaction form (id + name + file number). */
	public static function patients() {
		if ( ! class_exists( 'HMN_CRM_Customers' ) ) { return array(); }
		$rows = HMN_CRM_Customers::search( '' );
		if ( is_wp_error( $rows ) || ! is_array( $rows ) ) { return array(); }
		$patients = array();
		foreach ( $rows as $row ) {
			$patients[] = array(
				'id'   => absint( $row['id'] ?? 0 ),
				'name' => trim( sanitize_text_field( $row['first'] ?? '' ) . ' ' . sanitize_text_field( $row['last'] ?? '' ) ),
				'file' => sanitize_text_field( $row['file'] ?? '' ),
			);
		}
		usort( $patients, function ( $a, $b ) { return strnatcasecmp( $a['name'], $b['name'] ); } );
		return $patients;
	}

	/** Transactions for one Gregorian day, plus income/expense/net totals. */
	public static function day_report( $date ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, trx_date, type, category, amount, description, customer_id, payment_method FROM ' . self::table_name() . ' WHERE trx_date = %s ORDER BY id ASC',
			$date
		), ARRAY_A );
		$totals = array( 'income' => 0, 'expense' => 0, 'net' => 0 );
		$items  = array();
		foreach ( (array) $rows as $row ) {
			$type  = 'expense' === $row['type'] ? 'expense' : 'income';
			$items[] = array(
				'id'       => absint( $row['id'] ),
				'type'     => $type,
				'category' => sanitize_text_field( $row['category'] ),
				'amount'   => absint( $row['amount'] ),
				'description' => sanitize_text_field( (string) $row['description'] ),
				'customer_id' => absint( $row['customer_id'] ),
				'payment_method' => sanitize_key( $row['payment_method'] ),
			);
			$totals[ $type ] += absint( $row['amount'] );
		}
		$totals['net'] = $totals['income'] - $totals['expense'];
		return array( 'items' => $items, 'totals' => $totals );
	}

	/** Month-to-date totals for the summary strip. */
	public static function month_report( $from, $to ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT COALESCE(SUM(CASE WHEN type = %s THEN amount ELSE 0 END), 0) AS income, COALESCE(SUM(CASE WHEN type = %s THEN amount ELSE 0 END), 0) AS expense FROM ' . self::table_name() . ' WHERE trx_date BETWEEN %s AND %s',
			'income', 'expense', $from, $to
		), ARRAY_A );
		$income  = absint( $row['income'] ?? 0 );
		$expense = absint( $row['expense'] ?? 0 );
		return array( 'income' => $income, 'expense' => $expense, 'net' => $income - $expense );
	}

	/** Insert or update one transaction. */
	public static function save_transaction( $data ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$row = array(
			'trx_date'       => $data['date'],
			'type'           => 'expense' === $data['type'] ? 'expense' : 'income',
			'category'       => sanitize_text_field( $data['category'] ?? '' ),
			'amount'         => max( 0, absint( $data['amount'] ?? 0 ) ),
			'description'    => sanitize_textarea_field( $data['description'] ?? '' ),
			'customer_id'    => absint( $data['customer_id'] ?? 0 ),
			'payment_method' => in_array( $data['payment_method'] ?? '', array( 'cash', 'card', 'transfer', 'cheque' ), true ) ? $data['payment_method'] : 'cash',
			'created_by'     => get_current_user_id(),
			'updated_at'     => $now,
		);
		if ( ! empty( $data['id'] ) ) {
			return false !== $wpdb->update( self::table_name(), $row, array( 'id' => absint( $data['id'] ) ), array( '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%s' ), array( '%d' ) );
		}
		$row['created_at'] = $now;
		return false !== $wpdb->insert( self::table_name(), $row, array( '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%s' ) );
	}

	/** Remove one transaction owned by nobody in particular (shared ledger). */
	public static function delete_transaction( $id ) {
		global $wpdb;
		return false !== $wpdb->delete( self::table_name(), array( 'id' => absint( $id ) ), array( '%d' ) );
	}

	public function save_ajax() {
		if ( ! HMN_CRM_Core::can( HMN_CRM_Core::CAP_MANAGE_ACCOUNTING ) || ! check_ajax_referer( 'hmn_crm_accounting', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی نامعتبر است.' ), 403 );
		}
		$date = sanitize_text_field( wp_unslash( $_POST['date'] ?? '' ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			wp_send_json_error( array( 'message' => 'تاریخ معتبر نیست.' ), 400 );
		}
		$amount = preg_replace( '/\D/', '', (string) ( $_POST['amount'] ?? '' ) );
		if ( ! $amount || ! absint( $amount ) ) {
			wp_send_json_error( array( 'message' => 'مبلغ معتبر نیست.' ), 400 );
		}
		$ok = self::save_transaction( array(
			'id'             => absint( $_POST['id'] ?? 0 ),
			'date'           => $date,
			'type'           => sanitize_key( wp_unslash( $_POST['type'] ?? 'income' ) ),
			'category'       => wp_unslash( $_POST['category'] ?? '' ),
			'amount'         => absint( $amount ),
			'description'    => wp_unslash( $_POST['description'] ?? '' ),
			'customer_id'    => absint( $_POST['customer_id'] ?? 0 ),
			'payment_method' => sanitize_key( wp_unslash( $_POST['payment_method'] ?? 'cash' ) ),
		) );
		if ( ! $ok ) {
			wp_send_json_error( array( 'message' => 'ثبت تراکنش ناموفق بود.' ), 500 );
		}
		wp_send_json_success();
	}

	public function delete_ajax() {
		if ( ! HMN_CRM_Core::can( HMN_CRM_Core::CAP_MANAGE_ACCOUNTING ) || ! check_ajax_referer( 'hmn_crm_accounting', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی نامعتبر است.' ), 403 );
		}
		$id = absint( $_POST['id'] ?? 0 );
		if ( ! $id || ! self::delete_transaction( $id ) ) {
			wp_send_json_error( array( 'message' => 'حذف تراکنش ناموفق بود.' ), 400 );
		}
		wp_send_json_success();
	}

	/** Add a custom category to the income or expense list. */
	public function categories_ajax() {
		if ( ! HMN_CRM_Core::can( HMN_CRM_Core::CAP_MANAGE_ACCOUNTING ) || ! check_ajax_referer( 'hmn_crm_accounting', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی نامعتبر است.' ), 403 );
		}
		$type  = sanitize_key( wp_unslash( $_POST['cat_type'] ?? '' ) );
		$value = sanitize_text_field( wp_unslash( $_POST['cat_value'] ?? '' ) );
		if ( ! in_array( $type, array( 'income', 'expense' ), true ) || '' === $value ) {
			wp_send_json_error( array( 'message' => 'دسته معتبر نیست.' ), 400 );
		}
		$cats           = self::categories();
		$cats[ $type ]  = array_values( array_unique( array_merge( $cats[ $type ], array( $value ) ) ) );
		update_option( self::OPTION_CATEGORIES, $cats, false );
		wp_send_json_success( array( 'categories' => $cats ) );
	}

	/** Render the accounting portal page ( Jalali calendar + day ledger ). */
	public static function render_portal( $base, $user ) {
		if ( ! HMN_CRM_Core::can( HMN_CRM_Core::CAP_MANAGE_ACCOUNTING ) ) {
			wp_die( 'شما به بخش حسابداری دسترسی ندارید.', 403 );
		}
		$date = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : wp_date( 'Y-m-d' );
		$date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : wp_date( 'Y-m-d' );
		$day   = self::day_report( $date );
		$month = self::month_report( self::jalali_month_start( $date ), self::jalali_month_end( $date ) );
		$patients = self::patients();
		$by_id = array();
		foreach ( $patients as $p ) { $by_id[ $p['id'] ] = $p; }
		$day_name = HMN_CRM_Dashboard::hmn_public_jalali( $date, 'l، j F Y' );
		?>
<!doctype html><html <?php language_attributes(); ?> dir="rtl"><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title>حسابداری | HMN CRM</title><style><?php HMN_CRM_Dashboard::portal_styles(); ?></style><style>
.hmn-accounting{display:grid;gap:20px}.hmn-ac-card{background:var(--surface);border:1px solid var(--line);border-radius:16px;padding:22px;color:var(--ink)}.hmn-ac-summary{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.hmn-ac-summary>div{background:var(--surface);border:1px solid var(--line);border-radius:16px;padding:18px;box-shadow:0 6px 22px rgba(22,32,51,.035)}.hmn-ac-summary span{display:block;color:var(--muted);font-size:12px;margin-bottom:8px}.hmn-ac-summary strong{font-size:19px}.hmn-ac-summary .is-income strong{color:#027a48}.hmn-ac-summary .is-expense strong{color:#b42318}.hmn-ac-workspace{display:grid;grid-template-columns:315px minmax(0,1fr);gap:22px}.hmn-ac-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:6px}.hmn-ac-new{border:0;border-radius:9px;background:var(--brand);color:#fff;padding:11px 18px;font:inherit;font-weight:700;cursor:pointer}.hmn-ac-table{width:100%;border-collapse:collapse;min-width:0}.hmn-ac-table th,.hmn-ac-table td{padding:12px;text-align:right;border-bottom:1px solid var(--line);white-space:normal;vertical-align:middle}.hmn-ac-table th{color:var(--muted);font-size:12px;font-weight:700;background:#fbfcfe}.hmn-ac-table .is-income{color:#027a48;font-weight:700}.hmn-ac-table .is-expense{color:#b42318;font-weight:700}.hmn-ac-row-actions{display:flex;gap:6px;justify-content:flex-end}.hmn-ac-row-actions button{border:0;border-radius:8px;padding:7px 13px;font:inherit;font-size:12px;cursor:pointer;background:var(--soft);color:var(--brand)}.hmn-ac-row-actions .is-danger{background:#fff1f3;color:#b42318}.hmn-ac-modal{position:fixed;inset:0;z-index:1003;background:rgba(16,24,40,.45);display:grid;place-items:center;padding:16px}.hmn-ac-modal[hidden]{display:none}.hmn-ac-dialog{width:min(100%,470px);background:var(--surface);color:var(--ink);border-radius:16px;padding:24px}.hmn-ac-dialog h3{margin:0 0 14px}.hmn-ac-dialog label{display:grid;gap:7px;margin-top:13px;font-weight:700;font-size:13px}.hmn-ac-dialog input,.hmn-ac-dialog select,.hmn-ac-dialog textarea{min-height:42px;border:1px solid var(--line);border-radius:8px;padding:9px 11px;font:inherit;background:var(--surface);color:var(--ink)}.hmn-ac-dialog textarea{min-height:70px}.hmn-ac-dialog .grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px}.hmn-ac-dialog button[type=submit]{margin-top:18px;width:100%;height:46px;border:0;border-radius:9px;background:var(--brand);color:#fff;font:inherit;font-weight:700;cursor:pointer}.hmn-ac-msg{margin-top:10px;min-height:18px;font-size:12px;color:#b42318}.hmn-ac-close{float:left;border:0;background:#f2f4f7;border-radius:50%;width:32px;height:32px;font-size:20px;cursor:pointer}.hmn-ac-cat-add{display:flex;gap:6px;margin-top:8px}.hmn-ac-cat-add input{flex:1}.hmn-ac-cat-add button{border:0;border-radius:8px;background:var(--soft);color:var(--brand);padding:0 14px;cursor:pointer}.hmn-ac-type-switch{display:flex;background:#f2f4f8;border-radius:9px;padding:4px;gap:4px}.hmn-ac-type-switch button{flex:1;border:0;background:transparent;color:var(--muted);border-radius:6px;padding:8px;font:inherit;font-weight:700;cursor:pointer}.hmn-ac-type-switch button.is-active{background:#fff;color:var(--brand);box-shadow:0 1px 4px #dfe2eb}.hmn-dark .hmn-ac-dialog{background:var(--surface)}.hmn-dark .hmn-ac-type-switch{background:#283147}.hmn-dark .hmn-ac-type-switch button.is-active{background:#222b40;color:var(--ink)}.hmn-dark .hmn-ac-table th{background:#222b40;color:var(--ink)}.hmn-dark .hmn-ac-row-actions button{background:#2a3150;color:var(--ink)}.hmn-dark .hmn-ac-row-actions .is-danger{background:#3a1d24;color:#ff9ba3}.hmn-dark .hmn-ac-close{background:#2a3150;color:var(--ink)}@media(max-width:980px){.hmn-ac-workspace{grid-template-columns:1fr}.hmn-ac-summary{grid-template-columns:1fr}.hmn-ac-dialog .grid2{grid-template-columns:1fr}}.hmn-ac-cal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}.hmn-ac-cal-head button{border:1px solid var(--line);background:var(--surface);color:var(--brand);border-radius:8px;width:38px;height:34px;font-size:22px;cursor:pointer}.hmn-ac-cal-head strong{font-size:15px}
</style></head><body class="hmn-portal-body"><div class="hmn-portal"><aside class="hmn-sidebar"><div class="hmn-brand"><span class="hmn-brand-mark">H</span><span>HMN CRM</span></div><nav class="hmn-nav"><a href="<?php echo esc_url( $base ); ?>">⌂ داشبورد نوبت‌ها</a><a href="<?php echo esc_url( add_query_arg( 'section', 'customers', $base ) ); ?>">♙ بیماران</a><a class="is-active" href="<?php echo esc_url( add_query_arg( 'section', 'accounting', $base ) ); ?>">۵ حسابداری</a><a href="<?php echo esc_url( add_query_arg( 'section', 'scheduling', $base ) ); ?>">⚙ تنظیمات نوبت‌دهی</a><a href="<?php echo esc_url( add_query_arg( 'section', 'settings', $base ) ); ?>">⚙ تنظیمات پنل</a></nav><?php HMN_CRM_Dashboard::portal_sms_status(); ?><div class="hmn-user"><span class="hmn-avatar"><?php echo esc_html( mb_substr( $user->display_name ? $user->display_name : $user->user_login, 0, 1 ) ); ?></span><div><strong><?php echo esc_html( $user->display_name ); ?></strong><a href="<?php echo esc_url( wp_logout_url( $base ) ); ?>">خروج از حساب</a></div></div></aside><main class="hmn-main"><header class="hmn-topbar"><button class="hmn-menu" type="button" aria-label="باز کردن منو">☰</button><div><p class="hmn-eyebrow">مدیریت مالی مرکز</p><h1>حسابداری</h1></div><a class="hmn-today" href="<?php echo esc_url( add_query_arg( array( 'section' => 'accounting', 'date' => wp_date( 'Y-m-d' ) ), $base ) ); ?>">امروز</a></header>
<section class="hmn-ac-summary">
	<div class="is-income"><span>درآمد این ماه</span><strong><?php echo esc_html( number_format( $month['income'] ) ); ?> تومان</strong></div>
	<div class="is-expense"><span>هزینه این ماه</span><strong><?php echo esc_html( number_format( $month['expense'] ) ); ?> تومان</strong></div>
	<div><span>خالص این ماه</span><strong><?php echo esc_html( number_format( $month['net'] ) ); ?> تومان</strong></div>
</section>
<section class="hmn-ac-workspace">
	<aside class="hmn-ac-card"><div class="hmn-card-head" style="padding:0 0 12px"><div><p class="hmn-eyebrow">تقویم حسابداری</p><h2 style="font-size:16px;margin:2px 0 0" id="hmn-ac-month-name"><?php echo esc_html( HMN_CRM_Dashboard::hmn_public_jalali( $date, 'F Y' ) ); ?></h2></div></div><div class="hmn-ac-cal-head"><button type="button" data-ac-cal="next">‹</button><strong id="hmn-ac-month-label"><?php echo esc_html( HMN_CRM_Dashboard::hmn_public_jalali( $date, 'F Y' ) ); ?></strong><button type="button" data-ac-cal="prev">›</button></div><div class="hmn-weekdays"><span>ش</span><span>ی</span><span>د</span><span>س</span><span>چ</span><span>پ</span><span>ج</span></div><div class="hmn-calendar-grid" id="hmn-ac-grid"></div></aside>
	<div class="hmn-ac-card">
		<div class="hmn-ac-toolbar"><div><p class="hmn-eyebrow">دفتر روزانه</p><h2 style="font-size:17px;margin:2px 0 0" id="hmn-ac-day-title"><?php echo esc_html( $day_name ); ?></h2></div><button type="button" class="hmn-ac-new" id="hmn-ac-new">+ ثبت تراکنش</button></div>
		<div class="hmn-ac-summary" style="grid-template-columns:repeat(3,1fr);margin:12px 0 16px">
			<div class="is-income"><span>درآمد روز</span><strong id="hmn-ac-day-income"><?php echo esc_html( number_format( $day['totals']['income'] ) ); ?></strong></div>
			<div class="is-expense"><span>هزینه روز</span><strong id="hmn-ac-day-expense"><?php echo esc_html( number_format( $day['totals']['expense'] ) ); ?></strong></div>
			<div><span>خالص روز</span><strong id="hmn-ac-day-net"><?php echo esc_html( number_format( $day['totals']['net'] ) ); ?></strong></div>
		</div>
		<div class="hmn-table-wrap"><table class="hmn-ac-table"><thead><tr><th>شرح</th><th>دسته</th><th>نوع</th><th>مبلغ (تومان)</th><th>پرداخت</th><th>بیمار</th><th>عملیات</th></tr></thead><tbody id="hmn-ac-rows"><?php if ( empty( $day['items'] ) ) : ?><tr><td colspan="7" style="text-align:center;color:var(--muted)">برای این روز تراکنشی ثبت نشده است.</td></tr><?php else: foreach ( $day['items'] as $trx ) : $trx_patient = $trx['customer_id'] && isset( $by_id[ $trx['customer_id'] ] ) ? $by_id[ $trx['customer_id'] ]['name'] : ''; ?><tr data-trx="<?php echo esc_attr( $trx['id'] ); ?>"><td><?php echo esc_html( $trx['description'] ?: '—' ); ?></td><td><?php echo esc_html( $trx['category'] ?: '—' ); ?></td><td class="<?php echo esc_attr( 'is-' . $trx['type'] ); ?>"><?php echo esc_html( 'income' === $trx['type'] ? 'درآمد' : 'هزینه' ); ?></td><td dir="ltr" class="<?php echo esc_attr( 'is-' . $trx['type'] ); ?>"><?php echo esc_html( number_format( $trx['amount'] ) ); ?></td><td><?php echo esc_html( array( 'cash' => 'نقدی', 'card' => 'کارت', 'transfer' => 'انتقال', 'cheque' => 'چک' )[ $trx['payment_method'] ] ?? $trx['payment_method'] ); ?></td><td><?php echo esc_html( $trx_patient ?: '—' ); ?></td><td><div class="hmn-ac-row-actions"><button type="button" data-edit="<?php echo esc_attr( $trx['id'] ); ?>" data-type="<?php echo esc_attr( $trx['type'] ); ?>" data-category="<?php echo esc_attr( $trx['category'] ); ?>" data-amount="<?php echo esc_attr( $trx['amount'] ); ?>" data-description="<?php echo esc_attr( $trx['description'] ); ?>" data-customer="<?php echo esc_attr( $trx['customer_id'] ); ?>" data-payment="<?php echo esc_attr( $trx['payment_method'] ); ?>">ویرایش</button><button type="button" class="is-danger" data-del="<?php echo esc_attr( $trx['id'] ); ?>">حذف</button></div></td></tr><?php endforeach; endif; ?></tbody></table></div>
	</div>
</section>
</main></div>
<div class="hmn-ac-modal" hidden id="hmn-ac-modal"><form class="hmn-ac-dialog" id="hmn-ac-form"><button type="button" class="hmn-ac-close" aria-label="بستن">×</button><h3 id="hmn-ac-form-title">ثبت تراکنش</h3>
<input type="hidden" name="id" value="">
<div class="hmn-ac-type-switch" id="hmn-ac-type"><button type="button" data-type="income" class="is-active">درآمد</button><button type="button" data-type="expense">هزینه</button></div>
<label>مبلغ (تومان)<input name="amount" inputmode="numeric" required placeholder="مثال: 500,000"></label>
<div class="grid2"><label>دسته<select name="category" id="hmn-ac-category"></select></label><label>روش پرداخت<select name="payment_method"><option value="cash">نقدی</option><option value="card">کارت</option><option value="transfer">انتقال</option><option value="cheque">چک</option></select></label></div>
<label>بیمار (اختیاری)<select name="customer_id" id="hmn-ac-patient"><option value="">— بدون اتصال —</option><?php foreach ( $patients as $p ) : ?><option value="<?php echo esc_attr( $p['id'] ); ?>"><?php echo esc_html( $p['name'] . ( $p['file'] ? ' · پرونده ' . $p['file'] : '' ) ); ?></option><?php endforeach; ?></select></label>
<label>شرح<textarea name="description" placeholder="توضیح تراکنش…"></textarea></label>
<div class="hmn-ac-cat-add"><input id="hmn-ac-new-cat" placeholder="افزودن دسته جدید…"><button type="button" id="hmn-ac-add-cat">افزودن</button></div>
<button type="submit">ذخیره تراکنش</button><p class="hmn-ac-msg" id="hmn-ac-message"></p>
</form></div>
<script>(function(){
var ajax=<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,nonce=<?php echo wp_json_encode( wp_create_nonce( 'hmn_crm_accounting' ) ); ?>,base=<?php echo wp_json_encode( add_query_arg( 'section', 'accounting', $base ) ); ?>,current=<?php echo wp_json_encode( $date ); ?>,today=<?php echo wp_json_encode( wp_date( 'Y-m-d' ) ); ?>,cats=<?php echo wp_json_encode( self::categories() ); ?>;
function g2j(d){var gy=d.getFullYear(),gm=d.getMonth()+1,gd=d.getDate(),gdm=[0,31,59,90,120,151,181,212,243,273,304,334],jy=gy>1600?979:0;gy-=gy>1600?1600:621;var gy2=gm>2?gy+1:gy,days=365*gy+Math.floor((gy2+3)/4)-Math.floor((gy2+99)/100)+Math.floor((gy2+399)/400)-80+gd+gdm[gm-1];jy+=33*Math.floor(days/12053);days%=12053;jy+=4*Math.floor(days/1461);days%=1461;if(days>365){jy+=Math.floor((days-1)/365);days=(days-1)%365}var jm=days<186?1+Math.floor(days/31):7+Math.floor((days-186)/30),jd=1+(days<186?days%31:(days-186)%30);return{y:jy,m:jm,d:jd}}
function j2g(y,m,d){var gy=y+621;if(m>10||(m===10&&d>10))gy++;var x=new Date(gy,2,21);x.setDate(x.getDate()+(m<=6?(m-1)*31:186+(m-7)*30)+d-1);var z=new Date(x.getTime()-x.getTimezoneOffset()*60000);return z.toISOString().slice(0,10)}
var months=['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
var cal=g2j(new Date(current+'T12:00:00'));cal={y:cal.y,m:cal.m};
function renderCal(){var grid=document.getElementById('hmn-ac-grid'),first=j2g(cal.y,cal.m,1),offset=(new Date(first+'T12:00:00').getDay()+1)%7,days=cal.m<=6?31:cal.m<=11?30:29;document.getElementById('hmn-ac-month-label').textContent=months[cal.m-1]+' '+cal.y;grid.innerHTML='';for(var i=0;i<offset;i++)grid.appendChild(document.createElement('span'));for(var d=1;d<=days;d++){var iso=j2g(cal.y,cal.m,d),a=document.createElement('a');a.textContent=d;a.href=base+'&date='+iso;if(iso===current)a.className='is-selected';grid.appendChild(a)}}
document.querySelectorAll('[data-ac-cal]').forEach(function(b){b.onclick=function(){cal.m+=b.dataset.acCal==='next'?1:-1;if(cal.m>12){cal.m=1;cal.y++}if(cal.m<1){cal.m=12;cal.y--}renderCal()}});
function fmt(n){return Number(n||0).toLocaleString('fa-IR')}
function fillCats(type){var sel=document.getElementById('hmn-ac-category');sel.innerHTML='';(cats[type]||[]).forEach(function(c){var o=document.createElement('option');o.textContent=c;sel.appendChild(o)})}
var form=document.getElementById('hmn-ac-form'),modal=document.getElementById('hmn-ac-modal'),msg=document.getElementById('hmn-ac-message'),type='income';
function req(a,d){d.action=a;d.nonce=nonce;return fetch(ajax,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:new URLSearchParams(d)}).then(r=>r.json()).then(r=>{if(!r.success)throw Error(r.data&&r.data.message||'خطا');return r.data})}
function loadDay(){var u=new URL(base);u.searchParams.set('date',current);fetch(u,{headers:{'X-Requested-With':'fetch'}}).then(r=>r.text()).then(function(html){var doc=new DOMParser().parseFromString(html,'text/html');var rows=doc.getElementById('hmn-ac-rows');document.getElementById('hmn-ac-rows').innerHTML=rows?rows.innerHTML:'';['hmn-ac-day-income','hmn-ac-day-expense','hmn-ac-day-net'].forEach(function(id){var el=doc.getElementById(id);if(el)document.getElementById(id).textContent=el.textContent});document.getElementById('hmn-ac-day-title').textContent=doc.getElementById('hmn-ac-day-title').textContent;bind()})}
function bind(){document.querySelectorAll('#hmn-ac-rows [data-edit]').forEach(function(b){b.onclick=function(){var d=b.dataset;openForm({id:d.edit,type:d.type,category:d.category,amount:d.amount,description:d.description,customer_id:d.customer,payment_method:d.payment})}});document.querySelectorAll('#hmn-ac-rows [data-del]').forEach(function(b){b.onclick=function(){if(!confirm('این تراکنش حذف شود؟'))return;req('hmn_crm_accounting_delete',{id:b.dataset.del}).then(loadDay).catch(x=>alert(x.message))}})}
function esc(s){var d=document.createElement('div');d.textContent=s||'';return d.innerHTML}
function payLabel(v){return {cash:'نقدی',card:'کارت',transfer:'انتقال',cheque:'چک'}[v]||v}
function openForm(data){form.reset();form.elements.id.value=data&&data.id?data.id:'';type=data&&data.type||'income';document.querySelectorAll('#hmn-ac-type button').forEach(function(x){x.classList.toggle('is-active',x.dataset.type===type)});fillCats(type);if(data){document.getElementById('hmn-ac-form-title').textContent='ویرایش تراکنش';form.elements.category.value=data.category||'';form.elements.amount.value=String(data.amount||'');form.elements.description.value=data.description||'';form.elements.customer_id.value=data.customer_id||'';form.elements.payment_method.value=data.payment_method||'cash'}else{document.getElementById('hmn-ac-form-title').textContent='ثبت تراکنش'}msg.textContent='';modal.hidden=false}
document.getElementById('hmn-ac-new').onclick=function(){openForm(null)};
document.querySelectorAll('#hmn-ac-type button').forEach(function(b){b.onclick=function(){type=b.dataset.type;document.querySelectorAll('#hmn-ac-type button').forEach(function(x){x.classList.toggle('is-active',x===b)});fillCats(type)}});
document.getElementById('hmn-ac-add-cat').onclick=function(){var input=document.getElementById('hmn-ac-new-cat'),v=input.value.trim();if(!v)return;req('hmn_crm_accounting_categories',{cat_type:type,cat_value:v}).then(function(d){cats=d.categories;fillCats(type);var sel=document.getElementById('hmn-ac-category');sel.value=v;input.value=''}).catch(x=>msg.textContent=x.message)};
form.onsubmit=function(e){e.preventDefault();var amount=(form.elements.amount.value||'').replace(/[۰-۹]/g,function(c){return '۰۱۲۳۴۵۶۷۸۹'.indexOf(c)}).replace(/[^\d]/g,'');msg.textContent='در حال ذخیره…';req('hmn_crm_accounting_save',{id:form.elements.id.value,date:current,type:type,category:form.elements.category.value,amount:amount,description:form.elements.description.value,customer_id:form.elements.customer_id.value,payment_method:form.elements.payment_method.value}).then(function(){modal.hidden=true;loadDay()}).catch(x=>msg.textContent=x.message)};
var close=document.querySelector('.hmn-ac-close');close.onclick=function(){modal.hidden=true};modal.onclick=function(e){if(e.target===modal)modal.hidden=true};
function renderRows(items){document.getElementById('hmn-ac-rows').innerHTML='';}
window.hmnAcRefresh=function(){loadDay()};
loadDay();renderCal();
})();
</script><?php wp_footer(); ?><?php HMN_CRM_Dashboard::portal_theme_script(); ?></body></html>
	<?php
	}

	/** Jalali month start (Gregorian Y-m-d) for the selected date. */
	private static function jalali_month_start( $date ) {
		$parts = explode( '/', HMN_CRM_Dashboard::hmn_public_jalali( $date ) );
		return self::jalali_to_gregorian( (int) $parts[0], (int) $parts[1], 1 );
	}

	/** Jalali month end (Gregorian Y-m-d) for the selected date. */
	private static function jalali_month_end( $date ) {
		$parts = explode( '/', HMN_CRM_Dashboard::hmn_public_jalali( $date ) );
		$year  = (int) $parts[0];
		$month = (int) $parts[1] + 1;
		if ( $month > 12 ) { $month = 1; $year++; }
		return wp_date( 'Y-m-d', strtotime( self::jalali_to_gregorian( $year, $month, 1 ) . ' -1 day' ) );
	}

	/** Jalali to Gregorian conversion for range boundaries. */
	private static function jalali_to_gregorian( $year, $month, $day ) {
		$gy = $year + 621;
		if ( $month > 10 || ( 10 === $month && $day > 10 ) ) { $gy++; }
		$start  = new DateTimeImmutable( $gy . '-03-21', wp_timezone() );
		$offset = $month <= 6 ? ( $month - 1 ) * 31 + $day - 1 : 186 + ( $month - 7 ) * 30 + $day - 1;
		return $start->modify( '+' . $offset . ' days' )->format( 'Y-m-d' );
	}
}
new HMN_CRM_Accounting();
