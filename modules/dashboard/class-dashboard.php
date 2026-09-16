<?php
/**
 * Custom staff appointment dashboard.
 *
 * @package HMN_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HMN_CRM_Dashboard {

	/** Render the entire /hcrm portal. */
	public static function render_portal() {
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'daily';
		$view = in_array( $view, array( 'daily', 'weekly', 'monthly' ), true ) ? $view : 'daily';
		$date = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : wp_date( 'Y-m-d' );
		$date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : wp_date( 'Y-m-d' );
		$appointments = self::get_appointments( $date, $view );
		$calendar = self::calendar_data( $date, $appointments );
		$user = wp_get_current_user();
		$base = home_url( '/hcrm/' );
		?>
<!doctype html>
<html <?php language_attributes(); ?> dir="rtl">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html__( 'پنل مدیریت نوبت‌ها', 'hmn-crm' ); ?></title>
	<?php wp_head(); ?>
	<style><?php self::styles(); ?></style>
</head>
<body class="hmn-portal-body">
<div class="hmn-portal" data-view="<?php echo esc_attr( $view ); ?>">
	<aside class="hmn-sidebar" id="hmn-sidebar">
		<div class="hmn-brand"><span class="hmn-brand-mark">H</span><span>HMN CRM</span></div>
		<nav class="hmn-nav">
			<a class="is-active" href="<?php echo esc_url( $base ); ?>"><span>⌂</span> داشبورد نوبت‌ها</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=hmn-crm-sms' ) ); ?>"><span>✉</span> تنظیمات پیامک</a>
			<a href="<?php echo esc_url( admin_url() ); ?>"><span>⚙</span> پیشخوان وردپرس</a>
		</nav>
		<div class="hmn-user"><span class="hmn-avatar"><?php echo esc_html( mb_substr( $user->display_name ? $user->display_name : $user->user_login, 0, 1 ) ); ?></span><div><strong><?php echo esc_html( $user->display_name ); ?></strong><a href="<?php echo esc_url( wp_logout_url( $base ) ); ?>">خروج از حساب</a></div></div>
	</aside>
	<main class="hmn-main">
		<header class="hmn-topbar"><button class="hmn-menu" type="button" aria-controls="hmn-sidebar" aria-label="باز کردن منو">☰</button><div><p class="hmn-eyebrow">مدیریت مرکز درمانی</p><h1>نوبت‌ها</h1></div><a class="hmn-today" href="<?php echo esc_url( add_query_arg( array( 'date' => wp_date( 'Y-m-d' ), 'view' => 'daily' ), $base ) ); ?>">امروز</a></header>
		<section class="hmn-summary">
			<div><span>نوبت‌های نمایش داده‌شده</span><strong><?php echo esc_html( count( $appointments ) ); ?></strong></div>
			<div><span>تاریخ انتخاب‌شده</span><strong><?php echo esc_html( self::jalali_date( $date ) ); ?></strong></div>
			<div><span>نمای فعلی</span><strong><?php echo esc_html( array( 'daily' => 'روزانه', 'weekly' => 'هفتگی', 'monthly' => 'ماهانه' )[ $view ] ); ?></strong></div>
		</section>
		<section class="hmn-workspace">
			<div class="hmn-schedule-card">
				<div class="hmn-card-head"><div><p class="hmn-eyebrow">برنامه کاری</p><h2><?php echo esc_html( self::jalali_date( $date, 'l، j F Y' ) ); ?></h2></div><div class="hmn-tabs"><?php foreach ( array( 'daily' => 'روزانه', 'weekly' => 'هفتگی', 'monthly' => 'ماهانه' ) as $slug => $label ) : ?><a class="<?php echo $slug === $view ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'view' => $slug, 'date' => $date ), $base ) ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?></div></div>
				<?php self::render_schedule( $view, $date, $appointments, $base ); ?>
			</div>
			<aside class="hmn-calendar-card"><div class="hmn-card-head"><div><p class="hmn-eyebrow">تقویم نوبت‌دهی</p><h2><?php echo esc_html( self::jalali_date( $date, 'F Y' ) ); ?></h2></div></div><?php self::render_calendar( $calendar, $date, $base, $view ); ?></aside>
		</section>
	</main>
</div>
<script>document.querySelector('.hmn-menu').addEventListener('click',function(){document.querySelector('.hmn-portal').classList.toggle('menu-open');});</script>
<?php wp_footer(); ?>
</body></html>
		<?php
	}

	/** Get appointments from Easy!Appointments, with a JetAppointments fallback. */
	private static function get_appointments( $date, $view ) {
		$from = $date; $to = $date;
		if ( 'weekly' === $view ) { $from = wp_date( 'Y-m-d', strtotime( 'saturday this week', strtotime( $date ) ) ); $to = wp_date( 'Y-m-d', strtotime( $from . ' +6 days' ) ); }
		if ( 'monthly' === $view ) { $from = wp_date( 'Y-m-01', strtotime( $date ) ); $to = wp_date( 'Y-m-t', strtotime( $date ) ); }
		if ( class_exists( 'HMN_CRM_EasyAppointments' ) && HMN_CRM_EasyAppointments::configured() ) {
			$remote = HMN_CRM_EasyAppointments::appointments( $from, $to );
			if ( is_array( $remote ) ) { return $remote; }
		}
		global $wpdb;
		$table = $wpdb->prefix . 'jet_appointments';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$columns = $wpdb->get_col( "DESCRIBE {$table}", 0 ); // Table name is WordPress-prefix derived.
		$date_column = in_array( 'appointment_date', $columns, true ) ? 'appointment_date' : ( in_array( 'date', $columns, true ) ? 'date' : '' );
		if ( ! $date_column ) { return array(); }
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$date_column} BETWEEN %s AND %s ORDER BY {$date_column} ASC", $from, $to ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	private static function render_schedule( $view, $date, $appointments, $base ) {
		if ( 'daily' === $view ) { self::render_table( $appointments ); return; }
		$days = 'weekly' === $view ? 7 : (int) wp_date( 't', strtotime( $date ) );
		$start = 'weekly' === $view ? wp_date( 'Y-m-d', strtotime( 'saturday this week', strtotime( $date ) ) ) : wp_date( 'Y-m-01', strtotime( $date ) );
		$by_date = array(); foreach ( $appointments as $appointment ) { $day = self::appointment_value( $appointment, array( 'appointment_date', 'date' ) ); if ( $day ) { $by_date[ substr( $day, 0, 10 ) ][] = $appointment; } }
		echo '<div class="hmn-board ' . esc_attr( $view ) . '">';
		for ( $i = 0; $i < $days; $i++ ) { $day = wp_date( 'Y-m-d', strtotime( $start . " +{$i} days" ) ); $items = isset( $by_date[ $day ] ) ? $by_date[ $day ] : array(); echo '<a class="hmn-day-box" href="' . esc_url( add_query_arg( array( 'view' => 'daily', 'date' => $day ), $base ) ) . '"><strong>' . esc_html( self::jalali_date( $day, 'l j F' ) ) . '</strong><span>' . esc_html( count( $items ) ) . ' نوبت</span>'; foreach ( array_slice( $items, 0, 3 ) as $item ) { echo '<small>' . esc_html( self::appointment_value( $item, array( 'appointment_time', 'time', 'slot' ), '—' ) ) . ' · ' . esc_html( self::patient_name( $item ) ) . '</small>'; } echo '</a>'; }
		echo '</div>';
	}

	private static function render_table( $appointments ) {
		if ( empty( $appointments ) ) { echo '<div class="hmn-empty"><span>⌁</span><h3>برای این روز نوبتی ثبت نشده است</h3><p>با انتخاب یک روز دیگر از تقویم، برنامه همان روز نمایش داده می‌شود.</p></div>'; return; }
		echo '<div class="hmn-table-wrap"><table><thead><tr><th>ساعت</th><th>نام و نام خانوادگی</th><th>شماره</th><th>خدمت</th><th>شماره پرونده</th><th>عملیات</th></tr></thead><tbody>';
		foreach ( $appointments as $item ) { echo '<tr><td>' . esc_html( self::appointment_value( $item, array( 'appointment_time', 'time', 'slot' ), '—' ) ) . '</td><td><strong>' . esc_html( self::patient_name( $item ) ) . '</strong></td><td dir="ltr">' . esc_html( self::appointment_value( $item, array( 'user_phone', 'phone', 'user_email' ), '—' ) ) . '</td><td>' . esc_html( self::appointment_value( $item, array( 'service_title', 'service', 'service_id' ), '—' ) ) . '</td><td>' . esc_html( self::appointment_value( $item, array( 'case_number', 'file_number', 'id' ), '—' ) ) . '</td><td><button type="button" class="hmn-action">مشاهده</button></td></tr>'; }
		echo '</tbody></table></div>';
	}

	private static function calendar_data( $date, $appointments ) { return array( 'date' => $date, 'appointments' => $appointments ); }
	private static function render_calendar( $data, $date, $base, $view ) {
		$month_start = wp_date( 'Y-m-01', strtotime( $date ) ); $days = (int) wp_date( 't', strtotime( $date ) ); $offset = (int) wp_date( 'w', strtotime( $month_start ) ); $counts = array(); foreach ( $data['appointments'] as $item ) { $item_date = substr( self::appointment_value( $item, array( 'appointment_date', 'date' ) ), 0, 10 ); if ( $item_date ) { $counts[ $item_date ] = isset( $counts[ $item_date ] ) ? $counts[ $item_date ] + 1 : 1; } }
		echo '<div class="hmn-weekdays"><span>ش</span><span>ی</span><span>د</span><span>س</span><span>چ</span><span>پ</span><span>ج</span></div><div class="hmn-calendar-grid">'; for ( $i = 0; $i < $offset; $i++ ) { echo '<span class="is-blank"></span>'; } for ( $day = 1; $day <= $days; $day++ ) { $gregorian = wp_date( 'Y-m-d', strtotime( $month_start . ' +' . ( $day - 1 ) . ' days' ) ); $jalali_day = self::jalali_date( $gregorian, 'j' ); $active = $date === $gregorian ? 'is-selected' : ''; $has = isset( $counts[ $gregorian ] ) ? '<i>' . esc_html( $counts[ $gregorian ] ) . '</i>' : ''; echo '<a class="' . esc_attr( $active ) . '" href="' . esc_url( add_query_arg( array( 'view' => $view, 'date' => $gregorian ), $base ) ) . '">' . esc_html( $jalali_day ) . $has . '</a>'; } echo '</div>';
	}

	private static function appointment_value( $item, $keys, $fallback = '' ) { foreach ( $keys as $key ) { if ( isset( $item[ $key ] ) && is_scalar( $item[ $key ] ) && '' !== (string) $item[ $key ] ) return (string) $item[ $key ]; } return $fallback; }
	private static function patient_name( $item ) { $name = self::appointment_value( $item, array( 'field_name', 'name', 'user_name', 'first_name' ) ); $last = self::appointment_value( $item, array( 'field_lname', 'last_name', 'family' ) ); return trim( $name . ' ' . $last ) ?: 'مراجع بدون نام'; }

	/** Gregorian to Jalali, adapted for display-only local dates. */
	private static function jalali_date( $date, $format = 'Y/m/d' ) { $time = strtotime( $date ); $gy = (int) wp_date( 'Y', $time ); $gm = (int) wp_date( 'n', $time ); $gd = (int) wp_date( 'j', $time ); $gdm = array( 0,31,59,90,120,151,181,212,243,273,304,334 ); $jy = $gy > 1600 ? 979 : 0; $gy -= $gy > 1600 ? 1600 : 621; $gy2 = $gm > 2 ? $gy + 1 : $gy; $days = 365 * $gy + (int) floor( ( $gy2 + 3 ) / 4 ) - (int) floor( ( $gy2 + 99 ) / 100 ) + (int) floor( ( $gy2 + 399 ) / 400 ) - 80 + $gd + $gdm[ $gm - 1 ]; $jy += 33 * (int) floor( $days / 12053 ); $days %= 12053; $jy += 4 * (int) floor( $days / 1461 ); $days %= 1461; if ( $days > 365 ) { $jy += (int) floor( ( $days - 1 ) / 365 ); $days = ( $days - 1 ) % 365; } $jm = $days < 186 ? 1 + (int) floor( $days / 31 ) : 7 + (int) floor( ( $days - 186 ) / 30 ); $jd = 1 + ( $days < 186 ? $days % 31 : ( $days - 186 ) % 30 ); $months = array( 1=>'فروردین',2=>'اردیبهشت',3=>'خرداد',4=>'تیر',5=>'مرداد',6=>'شهریور',7=>'مهر',8=>'آبان',9=>'آذر',10=>'دی',11=>'بهمن',12=>'اسفند' ); $weekdays = array( 'Saturday'=>'شنبه','Sunday'=>'یکشنبه','Monday'=>'دوشنبه','Tuesday'=>'سه‌شنبه','Wednesday'=>'چهارشنبه','Thursday'=>'پنجشنبه','Friday'=>'جمعه' ); $replacements = array( 'Y'=>$jy, 'm'=>str_pad( $jm, 2, '0', STR_PAD_LEFT ), 'd'=>str_pad( $jd, 2, '0', STR_PAD_LEFT ), 'j'=>$jd, 'F'=>$months[ $jm ], 'l'=>$weekdays[ wp_date( 'l', $time ) ] ); return strtr( $format, $replacements ); }

	private static function styles() { ?>
:root{--ink:#172033;--muted:#768198;--surface:#fff;--canvas:#f5f7fb;--brand:#5b4cf0;--brand-dark:#392ab7;--line:#e7eaf1;--soft:#f1efff}*{box-sizing:border-box}body.hmn-portal-body{margin:0;background:var(--canvas);color:var(--ink);font-family:Tahoma,"Segoe UI",sans-serif;font-size:14px}.hmn-portal{display:flex;min-height:100vh}.hmn-sidebar{width:252px;background:#171c30;color:#cdd4e3;padding:28px 18px;display:flex;flex-direction:column;position:fixed;inset:0 0 0 auto;z-index:5}.hmn-brand{font-weight:800;color:#fff;font-size:19px;display:flex;align-items:center;gap:10px;padding:0 10px 32px}.hmn-brand-mark{background:linear-gradient(135deg,#8175ff,#4c3bdd);border-radius:11px;width:34px;height:34px;display:grid;place-items:center}.hmn-nav{display:grid;gap:6px}.hmn-nav a{padding:13px 12px;border-radius:10px;color:inherit;text-decoration:none;display:flex;gap:11px;align-items:center}.hmn-nav a:hover,.hmn-nav .is-active{background:#2a3150;color:#fff}.hmn-user{margin-top:auto;background:#222944;padding:12px;border-radius:13px;display:flex;gap:9px;align-items:center}.hmn-avatar{background:#f2c777;color:#493510;border-radius:50%;width:35px;height:35px;display:grid;place-items:center;font-weight:800}.hmn-user strong{color:#fff;display:block;font-size:12px}.hmn-user a{font-size:11px;color:#aeb9d0;text-decoration:none}.hmn-main{width:calc(100% - 252px);margin-right:252px;padding:36px;max-width:1680px}.hmn-topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:28px}.hmn-topbar h1{margin:3px 0 0;font-size:30px}.hmn-eyebrow{margin:0;color:var(--muted);font-size:12px}.hmn-today,.hmn-action{background:var(--brand);border:0;color:#fff;padding:10px 17px;border-radius:9px;text-decoration:none;font:inherit;cursor:pointer}.hmn-menu{display:none}.hmn-summary{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px}.hmn-summary>div,.hmn-schedule-card,.hmn-calendar-card{background:var(--surface);border:1px solid var(--line);border-radius:16px;box-shadow:0 6px 22px rgba(22,32,51,.035)}.hmn-summary>div{padding:18px}.hmn-summary span{display:block;color:var(--muted);font-size:12px;margin-bottom:8px}.hmn-summary strong{font-size:19px}.hmn-workspace{display:grid;grid-template-columns:minmax(0,1fr) 315px;gap:22px}.hmn-card-head{display:flex;justify-content:space-between;align-items:center;padding:24px 24px 18px}.hmn-card-head h2{font-size:18px;margin:4px 0 0}.hmn-tabs{background:#f2f4f8;padding:4px;border-radius:9px;display:flex}.hmn-tabs a{padding:7px 12px;text-decoration:none;color:var(--muted);border-radius:6px;font-size:12px}.hmn-tabs .is-active{background:#fff;color:var(--brand);box-shadow:0 1px 4px #dfe2eb}.hmn-table-wrap{overflow:auto;border-top:1px solid var(--line)}table{width:100%;border-collapse:collapse;min-width:720px}th,td{text-align:right;padding:16px 24px;border-bottom:1px solid var(--line);white-space:nowrap}th{color:var(--muted);font-size:12px;font-weight:500;background:#fbfcfe}td strong{font-weight:700}.hmn-action{background:var(--soft);color:var(--brand);padding:7px 12px;font-size:12px}.hmn-empty{padding:70px 20px;text-align:center;color:var(--muted)}.hmn-empty span{font-size:38px;color:var(--brand)}.hmn-empty h3{color:var(--ink);margin:10px}.hmn-board{padding:0 18px 18px;display:grid;gap:10px}.hmn-board.weekly{grid-template-columns:repeat(7,minmax(130px,1fr));overflow:auto}.hmn-board.monthly{grid-template-columns:repeat(4,1fr)}.hmn-day-box{border:1px solid var(--line);border-radius:11px;padding:12px;text-decoration:none;color:var(--ink);min-height:115px;display:flex;flex-direction:column;gap:7px}.hmn-day-box:hover{border-color:#bdb7ff;background:#faf9ff}.hmn-day-box span{font-size:11px;color:var(--brand)}.hmn-day-box small{color:var(--muted);font-size:10px}.hmn-calendar-card{height:max-content}.hmn-weekdays,.hmn-calendar-grid{display:grid;grid-template-columns:repeat(7,1fr);text-align:center}.hmn-weekdays{padding:0 15px 7px;color:var(--muted);font-size:11px}.hmn-calendar-grid{padding:0 15px 18px;gap:4px}.hmn-calendar-grid a{position:relative;text-decoration:none;color:var(--ink);aspect-ratio:1;display:grid;place-items:center;border-radius:9px}.hmn-calendar-grid a:hover{background:#f0efff}.hmn-calendar-grid a.is-selected{background:var(--brand);color:#fff}.hmn-calendar-grid i{position:absolute;bottom:3px;right:50%;transform:translateX(50%);font-style:normal;background:#e15b64;color:#fff;border-radius:9px;font-size:9px;line-height:15px;min-width:15px}.hmn-calendar-grid .is-selected i{background:#fff;color:var(--brand)}@media(max-width:980px){.hmn-sidebar{transform:translateX(100%);transition:.2s}.menu-open .hmn-sidebar{transform:translateX(0)}.hmn-main{width:100%;margin:0;padding:24px}.hmn-menu{display:inline-grid;place-items:center;border:0;background:#fff;border-radius:9px;font-size:20px;width:40px;height:40px;margin-left:12px}.hmn-topbar{justify-content:flex-start}.hmn-today{margin-right:auto}.hmn-workspace{grid-template-columns:1fr}.hmn-calendar-card{order:-1}.hmn-calendar-grid a{max-height:47px}}@media(max-width:640px){.hmn-main{padding:16px}.hmn-topbar h1{font-size:23px}.hmn-summary{grid-template-columns:1fr}.hmn-card-head{align-items:flex-start;gap:15px;flex-direction:column;padding:18px}.hmn-tabs{width:100%;justify-content:space-between}.hmn-tabs a{flex:1;text-align:center}.hmn-board.monthly{grid-template-columns:repeat(2,1fr)}.hmn-board.weekly{grid-template-columns:repeat(7,150px)}.hmn-summary>div{padding:14px}}
	<?php }
}
