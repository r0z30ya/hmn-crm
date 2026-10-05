<?php
/** CRM-only settings for Easy!Appointments and SMS. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class HMN_CRM_Settings implements HMN_CRM_Module_Interface {
	public function __construct() { $this->boot(); }
	public function boot() {
		add_action( 'wp_ajax_hmn_crm_settings_save', array( $this, 'save_ajax' ) );
		add_action( 'wp_ajax_hmn_crm_settings_test_ea', array( $this, 'test_ea_ajax' ) );
		add_action( 'wp_ajax_hmn_crm_entity_update', array( $this, 'entity_update_ajax' ) );
		add_action( 'wp_ajax_hmn_crm_entity_delete', array( $this, 'entity_delete_ajax' ) );
	}

	public static function render_portal( $base, $user ) {
		$services = HMN_CRM_EasyAppointments::request( 'GET', 'services', null, array( 'length' => 500 ) );
		$providers = HMN_CRM_EasyAppointments::request( 'GET', 'providers', null, array( 'length' => 500 ) );
		$services = is_array( $services ) ? $services : array();
		$providers = is_array( $providers ) ? $providers : array();
		$sms = get_option( HMN_CRM_SMS_Settings::OPTION_NAME, array() );
		$ea = HMN_CRM_EasyAppointments::settings();
		add_action( 'wp_footer', static function() use ( $providers ) { ?>
			<style>.hmn-modal{position:fixed;inset:0;z-index:2000;background:rgba(16,24,40,.55);display:grid;place-items:center;padding:16px}.hmn-modal[hidden]{display:none}.hmn-modal-card{width:min(100%,700px);max-height:90vh;overflow:auto;background:#fff;border-radius:16px;padding:24px;position:relative}.hmn-modal-close{position:absolute;left:12px;top:10px;border:0;background:#f2f4f7;border-radius:50%;width:34px;height:34px;font-size:22px;cursor:pointer}.hmn-modal-card form{display:grid;gap:0}.hmn-modal-card .hmn-settings-save{justify-self:start}.hmn-settings-card>.hmn-modal-launch{margin:0 0 16px}</style>
			<script>(function(){
				var providers=<?php echo wp_json_encode( array_map( static function( $provider ) { return array( 'id' => absint( $provider['id'] ?? 0 ), 'name' => trim( ( $provider['firstName'] ?? '' ) . ' ' . ( $provider['lastName'] ?? '' ) ) ); }, $providers ) ); ?>;
				function modalize(kind,title,label){var form=document.querySelector('form[data-hmn-settings="'+kind+'"]');if(!form)return;var card=form.closest('.hmn-settings-card'),button=document.createElement('button'),modal=document.createElement('div'),dialog=document.createElement('div'),close=document.createElement('button');button.type='button';button.className='hmn-settings-save hmn-modal-launch';button.textContent=label;modal.className='hmn-modal';modal.hidden=true;dialog.className='hmn-modal-card';close.type='button';close.className='hmn-modal-close';close.textContent='×';close.setAttribute('aria-label','بستن');var heading=document.createElement('h2');heading.textContent=title;dialog.append(close,heading,form);modal.append(dialog);document.body.append(modal);card.insertBefore(button,card.querySelector('table')||null);button.onclick=function(){modal.hidden=false};close.onclick=function(){modal.hidden=true};modal.onclick=function(e){if(e.target===modal)modal.hidden=true};}
				var providerForm=document.querySelector('form[data-hmn-settings="provider"]');if(providerForm){providerForm.closest('.hmn-settings-card').querySelector('p').textContent='پزشک فقط به‌عنوان ارائه‌دهندهٔ خدمت ثبت می‌شود و هیچ حساب یا دسترسی‌ای در پنل هومانا ندارد.';var labels=providerForm.querySelectorAll('label');if(labels[4]){labels[4].hidden=true;labels[4].querySelector('input').required=false}if(labels[5]){labels[5].hidden=true;labels[5].querySelector('input').required=false}modalize('provider','افزودن پزشک','+ افزودن پزشک');}
				var serviceForm=document.querySelector('form[data-hmn-settings="service"]');if(serviceForm){serviceForm.closest('.hmn-settings-card').querySelector('p').textContent='خدمت جدید به موتور نوبت‌دهی افزوده می‌شود.';serviceForm.elements.slot_interval.min=0;modalize('service','افزودن سرویس','+ افزودن سرویس');}
				var engine=document.querySelector('form[data-hmn-settings="ea"]');if(engine){var card=engine.closest('.hmn-settings-card');card.querySelector('h2').textContent='اتصال موتور نوبت‌دهی';card.querySelector('p').textContent='آدرس موتور و کلید اتصال فقط در پنل ذخیره می‌شوند.';var input=engine.elements.default_provider_id,select=document.createElement('select');select.name='default_provider_id';select.innerHTML='<option value="">انتخاب پزشک پیش‌فرض</option>'+providers.map(function(p){return '<option value="'+p.id+'">'+p.name+'</option>'}).join('');select.value=input.value;input.replaceWith(select);var test=document.createElement('button'),message=engine.querySelector('.hmn-settings-message');test.type='button';test.className='hmn-settings-save';test.textContent='تست اتصال موتور';test.style.marginRight='8px';test.onclick=function(){test.disabled=true;message.className='hmn-settings-message';message.textContent='در حال بررسی اتصال…';fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'hmn_crm_settings_test_ea',nonce:<?php echo wp_json_encode( wp_create_nonce( 'hmn_crm_settings' ) ); ?>})}).then(function(r){return r.json()}).then(function(r){if(!r.success)throw Error(r.data&&r.data.message||'خطای نامشخص');message.textContent=r.data.message}).catch(function(e){message.className='hmn-settings-message error';message.textContent=e.message}).finally(function(){test.disabled=false})};engine.querySelector('.hmn-settings-save').insertAdjacentElement('afterend',test);}
			})();</script>
		<?php }, 5 );
		?>
<!doctype html><html <?php language_attributes(); ?> dir="rtl"><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title>تنظیمات CRM</title><style><?php HMN_CRM_Dashboard::portal_styles(); ?></style><style>.hmn-settings{display:grid;gap:20px}.hmn-settings-card{background:var(--surface);border:1px solid var(--line);border-radius:16px;padding:22px}.hmn-settings-card h2{margin:0 0 7px;font-size:19px}.hmn-settings-card p{color:var(--muted);margin:0 0 18px}.hmn-settings-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.hmn-settings-grid label{display:grid;gap:6px;font-weight:700}.hmn-settings-grid input,.hmn-settings-grid textarea,.hmn-settings-grid select{min-height:42px;border:1px solid var(--line);border-radius:8px;padding:9px 11px;font:inherit;background:var(--surface);color:var(--ink)}.hmn-settings-grid textarea{min-height:90px}.hmn-settings-grid .full{grid-column:1/-1}.hmn-settings-card{min-width:0;overflow:hidden}.hmn-settings-card .hmn-settings-table{margin-top:18px;border-top:1px solid var(--line)}.hmn-settings-save{margin-top:16px;border:0;border-radius:8px;padding:11px 18px;background:var(--brand);color:#fff;font:inherit;font-weight:700;cursor:pointer}.hmn-settings-message{margin-top:12px;min-height:20px;color:#027a48}.hmn-settings-message.error{color:#b42318}.hmn-settings-card .hmn-settings-table{display:block;width:100%;overflow-x:auto;white-space:normal;min-width:0}.hmn-settings-table{width:100%;border-collapse:collapse;min-width:0}.hmn-settings-table th,.hmn-settings-table td{padding:11px 12px;text-align:right;border-bottom:1px solid var(--line);white-space:normal;vertical-align:middle}.hmn-settings-table th{color:var(--muted);font-size:12px;font-weight:700;background:#fbfcfe}.hmn-settings-table td{color:var(--ink)}.hmn-settings-table tbody tr:hover{background:#faf9ff}.hmn-settings-table td[dir=ltr]{text-align:left;direction:ltr}.hmn-settings-table .hmn-row-actions{display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap}.hmn-settings-table .hmn-row-action{border:0;border-radius:8px;padding:7px 14px;font:inherit;font-size:12px;cursor:pointer;background:var(--soft);color:var(--brand);white-space:nowrap}.hmn-settings-table .hmn-row-action:hover{background:#e6e1ff}.hmn-settings-table .hmn-row-action.is-danger{background:#fff1f3;color:#b42318}.hmn-settings-table .hmn-row-action.is-danger:hover{background:#ffe4e6}.hmn-checks{display:flex;flex-wrap:wrap;gap:9px}.hmn-checks label{display:flex;align-items:center;gap:5px;font-weight:400}@media(max-width:650px){.hmn-settings-grid{grid-template-columns:1fr}.hmn-settings-grid .full{grid-column:auto}}</style></head><body class="hmn-portal-body"><div class="hmn-portal"><aside class="hmn-sidebar"><div class="hmn-brand"><span class="hmn-brand-mark">H</span><span>HMN CRM</span></div><nav class="hmn-nav"><a href="<?php echo esc_url( $base ); ?>">⌂ داشبورد نوبت‌ها</a><a href="<?php echo esc_url( add_query_arg( 'section', 'customers', $base ) ); ?>">♙ مشتریان</a><a href="<?php echo esc_url( add_query_arg( 'section', 'accounting', $base ) ); ?>">۵ حسابداری</a><a href="<?php echo esc_url( add_query_arg( 'section', 'scheduling', $base ) ); ?>">⚙ تنظیمات نوبت‌دهی</a><a class="is-active" href="<?php echo esc_url( add_query_arg( 'section', 'settings', $base ) ); ?>">⚙ تنظیمات CRM</a></nav><?php HMN_CRM_Dashboard::portal_sms_status(); ?><div class="hmn-user"><span class="hmn-avatar"><?php echo esc_html( mb_substr( $user->display_name ?: $user->user_login, 0, 1 ) ); ?></span><div><strong><?php echo esc_html( $user->display_name ); ?></strong><a href="<?php echo esc_url( wp_logout_url( $base ) ); ?>">خروج از حساب</a></div></div></aside><main class="hmn-main"><header class="hmn-topbar"><button class="hmn-menu" type="button" aria-label="باز کردن منو">☰</button><div><p class="hmn-eyebrow">تنظیمات سامانه</p><h1>تنظیمات CRM</h1></div><a class="hmn-today" href="<?php echo esc_url( $base ); ?>">بازگشت به نوبت‌ها</a></header><div class="hmn-settings">
<section class="hmn-settings-card"><h2>پزشکان</h2><p>پزشک جدید مستقیماً در Easy!Appointments ساخته می‌شود.</p><form data-hmn-settings="provider"><div class="hmn-settings-grid"><label>نام<input name="first_name" required></label><label>نام خانوادگی<input name="last_name" required></label><label>ایمیل<input name="email" type="email" required></label><label>تلفن<input name="phone"></label><label>نام کاربری EA<input name="username" required></label><label>رمز ورود EA<input name="password" type="password" minlength="8" required></label><label class="full">سرویس‌های قابل ارائه<div class="hmn-checks"><?php foreach ( $services as $service ) : ?><label><input type="checkbox" name="services[]" value="<?php echo esc_attr( absint( $service['id'] ?? 0 ) ); ?>"><?php echo esc_html( $service['name'] ?? '' ); ?></label><?php endforeach; ?></div></label></div><button class="hmn-settings-save">افزودن پزشک</button><div class="hmn-settings-message"></div></form><?php self::providers_table( $providers, $services ); ?></section>
<section class="hmn-settings-card"><h2>سرویس‌ها</h2><p>سرویس جدید و ارتباط آن با پزشکان در Easy!Appointments ذخیره می‌شود.</p><form data-hmn-settings="service"><div class="hmn-settings-grid"><label>نام سرویس<input name="name" required></label><label>مدت (دقیقه)<input name="duration" type="number" min="1" value="15" required></label><label>هزینه<input name="price" type="number" min="0" value="0"></label><label>فاصله اسلات (دقیقه)<input name="slot_interval" type="number" min="1" value="15"></label><label>تعداد رزرو هم‌زمان<input name="attendants_number" type="number" min="1" value="1"></label><label>رنگ<input name="color" type="color" value="#5b4cf0"></label><label class="full">توضیحات<textarea name="description"></textarea></label><label class="full">پزشکان ارائه‌دهنده<div class="hmn-checks"><?php foreach ( $providers as $provider ) : ?><label><input type="checkbox" name="providers[]" value="<?php echo esc_attr( absint( $provider['id'] ?? 0 ) ); ?>"><?php echo esc_html( trim( ( $provider['firstName'] ?? '' ) . ' ' . ( $provider['lastName'] ?? '' ) ) ); ?></label><?php endforeach; ?></div></label></div><button class="hmn-settings-save">افزودن سرویس</button><div class="hmn-settings-message"></div></form><?php self::services_table( $services, $providers ); ?></section>
<section class="hmn-settings-card" id="hmn-sms-settings"><h2>تنظیمات پیامک</h2><p>این صفحه جایگزین تنظیمات پیامک در پیشخوان WordPress است.</p><form data-hmn-settings="sms"><div class="hmn-settings-grid"><label>کلید API ملی‌پیامک<input name="melipayamak_api_key" type="password" value="<?php echo esc_attr( $sms['melipayamak_api_key'] ?? '' ); ?>"></label><label>شماره فرستنده<input name="melipayamak_sender" value="<?php echo esc_attr( $sms['melipayamak_sender'] ?? '' ); ?>"></label><?php foreach ( array( 'melipayamak_otp_body_id' => 'الگوی OTP', 'melipayamak_booking_body_id' => 'الگوی ثبت نوبت', 'melipayamak_booking_edit_body_id' => 'الگوی ویرایش نوبت', 'melipayamak_booking_cancel_body_id' => 'الگوی لغو نوبت', 'melipayamak_booking_reminder_body_id' => 'الگوی یادآوری نوبت' ) as $key => $label ) : ?><label><?php echo esc_html( $label ); ?><input name="<?php echo esc_attr( $key ); ?>" type="number" min="0" value="<?php echo esc_attr( $sms[ $key ] ?? '' ); ?>"></label><?php endforeach; ?></div><button class="hmn-settings-save">ذخیره تنظیمات پیامک</button><div class="hmn-settings-message"></div></form></section>
<section class="hmn-settings-card"><h2>اتصال Easy!Appointments</h2><p>آدرس موتور و کلید API فقط در CRM ذخیره می‌شود.</p><form data-hmn-settings="ea"><div class="hmn-settings-grid"><label>آدرس نصب<input name="base_url" type="url" required value="<?php echo esc_attr( $ea['base_url'] ?? '' ); ?>"></label><label>کلید API<input name="api_key" type="password" required value="<?php echo esc_attr( $ea['api_key'] ?? '' ); ?>"></label><label>پزشک پیش‌فرض<input name="default_provider_id" type="number" min="1" value="<?php echo esc_attr( $ea['default_provider_id'] ?? '' ); ?>"></label><label><span>نمایش پزشک در فرم سایت</span><input name="hide_provider" type="checkbox" value="1" <?php checked( empty( $ea['hide_provider'] ) ); ?>></label></div><button class="hmn-settings-save">ذخیره اتصال</button><div class="hmn-settings-message"></div></form></section>
</div></main></div><script>(function(){var ajax=<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,nonce=<?php echo wp_json_encode( wp_create_nonce( 'hmn_crm_settings' ) ); ?>;document.querySelectorAll('form[data-hmn-settings]').forEach(function(form){form.addEventListener('submit',function(e){e.preventDefault();var message=form.querySelector('.hmn-settings-message'),data=new FormData(form);data.append('action','hmn_crm_settings_save');data.append('nonce',nonce);data.append('kind',form.dataset.hmnSettings);message.className='hmn-settings-message';message.textContent='در حال ذخیره…';fetch(ajax,{method:'POST',body:new URLSearchParams(data)}).then(function(r){return r.json()}).then(function(r){if(!r.success)throw Error(r.data&&r.data.message||'خطا');message.textContent='ذخیره شد. صفحه برای دریافت اطلاعات جدید بازخوانی می‌شود.';setTimeout(function(){location.reload()},500)}).catch(function(err){message.className='hmn-settings-message error';message.textContent=err.message})})});
/* Row edit & delete for providers and services (delegated; the modals are built later by the footer script) */
function hmnModalFor(kind){var form=document.querySelector('.hmn-modal form[data-hmn-settings="'+kind+'"]');return form?form.closest('.hmn-modal'):null}
function hmnFillEdit(kind,id){var row=document.querySelector((kind==='provider'?'[data-hmn-provider-table]':'[data-hmn-service-table]')+' tr[data-id="'+id+'"]'),modal=hmnModalFor(kind);if(!row||!modal)return;var form=modal.querySelector('form');form.reset();delete form.dataset.hmnEditId;form.dataset.hmnEditId=id;modal.querySelector('h2').textContent=kind==='provider'?'ویرایش پزشک':'ویرایش سرویس';if(kind==='provider'){form.elements.first_name.value=row.dataset.first||'';form.elements.last_name.value=row.dataset.last||'';form.elements.email.value=row.dataset.email||'';form.elements.phone.value=row.dataset.phone||'';var selected=JSON.parse(row.dataset.services||'[]');form.querySelectorAll('input[name="services[]"]').forEach(function(box){box.checked=selected.indexOf(parseInt(box.value,10))>-1})}else{form.elements.name.value=row.dataset.name||'';form.elements.duration.value=row.dataset.duration||15;form.elements.price.value=row.dataset.price||0;form.elements.color.value=row.dataset.color||'#5b4cf0';form.elements.attendants_number.value=row.dataset.attendants||1;var provs=JSON.parse(row.dataset.providers||'[]');form.querySelectorAll('input[name="providers[]"]').forEach(function(box){box.checked=provs.indexOf(parseInt(box.value,10))>-1})}modal.hidden=false}
document.addEventListener('click',function(e){var edit=e.target.closest('[data-edit-provider],[data-edit-service]');if(edit){hmnFillEdit(edit.dataset.editProvider?'provider':'service',edit.dataset.editProvider||edit.dataset.editService);return}var del=e.target.closest('[data-delete-provider],[data-delete-service]');if(del){var kind=del.dataset.deleteProvider?'provider':'service',id=del.dataset.deleteProvider||del.dataset.deleteService,name=del.dataset.name||'';if(!confirm('مورد «'+name+'» حذف شود؟ این عمل قابل بازگشت نیست.'))return;fetch(ajax,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:new URLSearchParams({action:'hmn_crm_entity_delete',nonce:nonce,kind:kind,id:id})}).then(function(r){return r.json()}).then(function(r){if(!r.success)throw Error(r.data&&r.data.message||'خطا');location.reload()}).catch(function(err){alert(err.message)});return}var launch=e.target.closest('.hmn-modal-launch');if(launch){var card=launch.closest('.hmn-settings-card');if(!card)return;var kind=card.querySelector('[data-hmn-provider-table]')?'provider':(card.querySelector('[data-hmn-service-table]')?'service':'');if(!kind)return;var form=document.querySelector('form[data-hmn-settings="'+kind+'"]');if(form){delete form.dataset.hmnEditId;form.reset();var modal=hmnModalFor(kind);if(modal)modal.querySelector('h2').textContent=kind==='provider'?'افزودن پزشک':'افزودن سرویس'}}});
/* When a form carries an edit id, submit an update instead of a create (document-level capture beats the per-form create handler). */
document.addEventListener('submit',function(e){var form=e.target;if(!form.matches||!form.matches('form[data-hmn-settings]'))return;var editId=form.dataset.hmnEditId;if(!editId)return;e.preventDefault();e.stopImmediatePropagation();var message=form.querySelector('.hmn-settings-message'),data=new FormData(form);data.append('action','hmn_crm_entity_update');data.append('nonce',nonce);data.append('kind',form.dataset.hmnSettings);data.append('id',editId);message.className='hmn-settings-message';message.textContent='در حال ذخیره تغییرات…';fetch(ajax,{method:'POST',body:new URLSearchParams(data)}).then(function(r){return r.json()}).then(function(r){if(!r.success)throw Error(r.data&&r.data.message||'خطا');location.reload()}).catch(function(err){message.className='hmn-settings-message error';message.textContent=err.message})},true);
})();</script><?php wp_footer(); ?><?php HMN_CRM_Dashboard::portal_theme_script(); ?></body></html><?php
	}

	public function save_ajax() {
		if ( ! HMN_CRM_Core::can( HMN_CRM_Core::CAP_MANAGE_SETTINGS ) || ! check_ajax_referer( 'hmn_crm_settings', 'nonce', false ) ) { wp_send_json_error( array( 'message' => 'دسترسی نامعتبر است.' ), 403 ); }
		$kind = sanitize_key( wp_unslash( $_POST['kind'] ?? '' ) );
		if ( 'service' === $kind ) { $this->save_service(); }
		if ( 'provider' === $kind ) { $this->save_provider(); }
		if ( 'sms' === $kind ) { $this->save_sms(); }
		if ( 'ea' === $kind ) { $this->save_ea(); }
		wp_send_json_error( array( 'message' => 'درخواست نامعتبر است.' ), 400 );
	}

	/** Update an existing provider or service in the scheduling engine. */
	public function entity_update_ajax() {
		if ( ! HMN_CRM_Core::can( HMN_CRM_Core::CAP_MANAGE_SETTINGS ) || ! check_ajax_referer( 'hmn_crm_settings', 'nonce', false ) ) { wp_send_json_error( array( 'message' => 'دسترسی نامعتبر است.' ), 403 ); }
		$kind = sanitize_key( wp_unslash( $_POST['kind'] ?? '' ) );
		$id = absint( $_POST['id'] ?? 0 );
		if ( ! $id || ! in_array( $kind, array( 'provider', 'service' ), true ) ) { wp_send_json_error( array( 'message' => 'درخواست نامعتبر است.' ), 400 ); }

		if ( 'provider' === $kind ) {
			$first = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
			$last = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
			$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
			if ( ! $first || ! $last || ! is_email( $email ) ) { wp_send_json_error( array( 'message' => 'نام، نام خانوادگی و ایمیل الزامی است.' ), 400 ); }
			$service_ids = array_values( array_filter( array_unique( array_map( 'absint', (array) ( $_POST['services'] ?? array() ) ) ) ) );
			$known = self::known_service_ids();
			if ( $known instanceof WP_Error ) { wp_send_json_error( array( 'message' => $known->get_error_message() ), 502 ); }
			if ( array_diff( $service_ids, $known ) ) { wp_send_json_error( array( 'message' => 'سرویس انتخاب‌شده معتبر نیست.' ), 400 ); }
			$payload = array( 'firstName' => $first, 'lastName' => $last, 'email' => $email, 'phone' => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ), 'services' => $service_ids );
			$result = HMN_CRM_EasyAppointments::request( 'PUT', 'providers/' . $id, $payload );
			if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 502 ); }
			wp_send_json_success();
		}

		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		if ( ! $name ) { wp_send_json_error( array( 'message' => 'نام سرویس الزامی است.' ), 400 ); }
		$provider_ids = array_values( array_filter( array_unique( array_map( 'absint', (array) ( $_POST['providers'] ?? array() ) ) ) ) );
		$known_providers = self::known_provider_ids();
		if ( $known_providers instanceof WP_Error ) { wp_send_json_error( array( 'message' => $known_providers->get_error_message() ), 502 ); }
		if ( array_diff( $provider_ids, $known_providers ) ) { wp_send_json_error( array( 'message' => 'پزشک انتخاب‌شده معتبر نیست.' ), 400 ); }
		$payload = array( 'name' => $name, 'duration' => max( 1, absint( $_POST['duration'] ?? 15 ) ), 'price' => max( 0, (float) ( $_POST['price'] ?? 0 ) ), 'currency' => 'IRR', 'description' => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ), 'color' => sanitize_hex_color( wp_unslash( $_POST['color'] ?? '' ) ) ?: '#5b4cf0', 'slotInterval' => max( 0, absint( $_POST['slot_interval'] ?? 0 ) ), 'attendantsNumber' => max( 1, absint( $_POST['attendants_number'] ?? 1 ) ), 'providers' => $provider_ids );
		$result = HMN_CRM_EasyAppointments::request( 'PUT', 'services/' . $id, $payload );
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 502 ); }
		wp_send_json_success();
	}

	/** Delete a provider or service from the scheduling engine. */
	public function entity_delete_ajax() {
		if ( ! HMN_CRM_Core::can( HMN_CRM_Core::CAP_MANAGE_SETTINGS ) || ! check_ajax_referer( 'hmn_crm_settings', 'nonce', false ) ) { wp_send_json_error( array( 'message' => 'دسترسی نامعتبر است.' ), 403 ); }
		$kind = sanitize_key( wp_unslash( $_POST['kind'] ?? '' ) );
		$id = absint( $_POST['id'] ?? 0 );
		if ( ! $id || ! in_array( $kind, array( 'provider', 'service' ), true ) ) { wp_send_json_error( array( 'message' => 'درخواست نامعتبر است.' ), 400 ); }
		if ( 'service' === $kind && class_exists( 'HMN_CRM_EasyAppointments' ) ) {
			$appointments = HMN_CRM_EasyAppointments::request( 'GET', 'appointments', null, array( 'from' => wp_date( 'Y-m-d' ), 'till' => '2100-01-01', 'length' => 1 ) );
			if ( is_wp_error( $appointments ) ) { wp_send_json_error( array( 'message' => $appointments->get_error_message() ), 502 ); }
			$upcoming = 0;
			foreach ( (array) $appointments as $appointment ) { if ( absint( $appointment['serviceId'] ?? 0 ) === $id ) { $upcoming++; } }
			if ( $upcoming ) { wp_send_json_error( array( 'message' => 'برای این سرویس نوبت آینده ثبت شده است؛ اول نوبت‌ها را لغو کنید.' ), 409 ); }
		}
		$result = HMN_CRM_EasyAppointments::request( 'DELETE', ( 'provider' === $kind ? 'providers/' : 'services/' ) . $id );
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 502 ); }
		wp_send_json_success();
	}

	/** Valid service IDs from the engine, or WP_Error on failure. */
	private static function known_service_ids() {
		$services = HMN_CRM_EasyAppointments::request( 'GET', 'services', null, array( 'length' => 500 ) );
		if ( is_wp_error( $services ) ) { return $services; }
		return array_map( 'absint', wp_list_pluck( is_array( $services ) ? $services : array(), 'id' ) );
	}

	/** Valid provider IDs from the engine, or WP_Error on failure. */
	private static function known_provider_ids() {
		$providers = HMN_CRM_EasyAppointments::request( 'GET', 'providers', null, array( 'length' => 500 ) );
		if ( is_wp_error( $providers ) ) { return $providers; }
		return array_map( 'absint', wp_list_pluck( is_array( $providers ) ? $providers : array(), 'id' ) );
	}

	/** Read-only health check: never changes EA data or exposes the API key. */
	public function test_ea_ajax() {
		if ( ! HMN_CRM_Core::can( HMN_CRM_Core::CAP_MANAGE_SETTINGS ) || ! check_ajax_referer( 'hmn_crm_settings', 'nonce', false ) ) { wp_send_json_error( array( 'message' => 'دسترسی نامعتبر است.' ), 403 ); }
		$result = HMN_CRM_EasyAppointments::request( 'GET', 'providers', null, array( 'length' => 1 ) );
		if ( is_wp_error( $result ) ) {
			$status = (int) ( $result->get_error_data()['status'] ?? 0 );
			$message = $result->get_error_message();
			if ( 401 === $status || 403 === $status ) { $message = 'موتور کلید اتصال را نپذیرفت (HTTP ' . $status . '). کلید اتصال را بررسی کنید.'; }
			elseif ( $status ) { $message = 'موتور پاسخ خطا داد (HTTP ' . $status . '): ' . $message; }
			wp_send_json_error( array( 'message' => $message ), 502 );
		}
		wp_send_json_success( array( 'message' => 'اتصال موتور برقرار است.' ) );
	}

	private function save_service() {
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ); if ( ! $name ) { wp_send_json_error( array( 'message' => 'نام سرویس الزامی است.' ), 400 ); }
		$provider_ids = array_values( array_filter( array_unique( array_map( 'absint', (array) ( $_POST['providers'] ?? array() ) ) ) ) );
		if ( $provider_ids ) {
			$providers = HMN_CRM_EasyAppointments::request( 'GET', 'providers', null, array( 'length' => 500 ) );
			if ( is_wp_error( $providers ) ) { wp_send_json_error( array( 'message' => $providers->get_error_message() ), 502 ); }
			$known_ids = array_map( 'absint', wp_list_pluck( is_array( $providers ) ? $providers : array(), 'id' ) );
			if ( array_diff( $provider_ids, $known_ids ) ) { wp_send_json_error( array( 'message' => 'پزشک انتخاب‌شده در موتور معتبر نیست؛ سرویس ثبت نشد.' ), 400 ); }
		}
		$payload = array( 'name' => $name, 'duration' => max( 1, absint( $_POST['duration'] ?? 15 ) ), 'price' => max( 0, (float) ( $_POST['price'] ?? 0 ) ), 'currency' => 'IRR', 'description' => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ), 'location' => '', 'color' => sanitize_hex_color( wp_unslash( $_POST['color'] ?? '' ) ) ?: '#5b4cf0', 'slotInterval' => max( 0, absint( $_POST['slot_interval'] ?? 15 ) ), 'attendantsNumber' => max( 1, absint( $_POST['attendants_number'] ?? 1 ) ), 'isPrivate' => false, 'providers' => $provider_ids );
		$result = HMN_CRM_EasyAppointments::request( 'POST', 'services', $payload ); if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 502 ); } wp_send_json_success();
	}

	private function save_provider() {
		$first = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) ); $last = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) ); $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ); $username = sanitize_user( wp_unslash( $_POST['username'] ?? '' ), true ); $password = (string) ( $_POST['password'] ?? '' );
		if ( ! $first || ! $last || ! is_email( $email ) ) { wp_send_json_error( array( 'message' => 'نام، نام خانوادگی و ایمیل الزامی است.' ), 400 ); }
		// The scheduling engine requires technical credentials for a provider, but this never creates a CRM login.
		if ( ! $username ) { $username = 'hmn_provider_' . wp_rand( 100000, 999999 ); }
		if ( strlen( $password ) < 8 ) { $password = wp_generate_password( 24, true, true ); }
		$payload = array( 'firstName' => $first, 'lastName' => $last, 'email' => $email, 'phone' => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ), 'timezone' => 'Asia/Tehran', 'language' => 'english', 'services' => array_map( 'absint', (array) ( $_POST['services'] ?? array() ) ), 'settings' => array( 'username' => $username, 'password' => $password, 'notifications' => true, 'calendarView' => 'default' ) );
		$result = HMN_CRM_EasyAppointments::request( 'POST', 'providers', $payload ); if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 502 ); } wp_send_json_success();
	}

	private function save_sms() {
		$current = get_option( HMN_CRM_SMS_Settings::OPTION_NAME, array() ); $current = is_array( $current ) ? $current : array();
		foreach ( array( 'melipayamak_api_key', 'melipayamak_sender' ) as $key ) { $current[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) ); }
		foreach ( array( 'melipayamak_otp_body_id', 'melipayamak_booking_body_id', 'melipayamak_booking_edit_body_id', 'melipayamak_booking_cancel_body_id', 'melipayamak_booking_reminder_body_id' ) as $key ) { $current[ $key ] = absint( $_POST[ $key ] ?? 0 ); }
		update_option( HMN_CRM_SMS_Settings::OPTION_NAME, $current ); wp_send_json_success();
	}

	private function save_ea() {
		$settings = array( 'base_url' => esc_url_raw( untrailingslashit( wp_unslash( $_POST['base_url'] ?? '' ) ) ), 'api_key' => sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) ), 'hide_provider' => empty( $_POST['hide_provider'] ) ? 1 : 0, 'default_provider_id' => absint( $_POST['default_provider_id'] ?? 0 ) );
		if ( ! $settings['base_url'] || ! $settings['api_key'] ) { wp_send_json_error( array( 'message' => 'آدرس و کلید API الزامی هستند.' ), 400 ); }
		if ( $settings['hide_provider'] && ! $settings['default_provider_id'] ) { wp_send_json_error( array( 'message' => 'وقتی انتخاب پزشک در فرم سایت مخفی است، پزشک پیش‌فرض را انتخاب کنید.' ), 400 ); }
		update_option( HMN_CRM_EasyAppointments::OPTION_NAME, $settings ); wp_send_json_success();
	}

	private static function providers_table( $providers, $services ) { ?>
<table class="hmn-settings-table" data-hmn-provider-table><thead><tr><th>نام</th><th>ایمیل</th><th>تلفن</th><th>سرویس‌ها</th><th>عملیات</th></tr></thead><tbody>
<?php if ( empty( $providers ) ) : ?><tr><td colspan="5" style="text-align:center;color:var(--muted)">هنوز پزشکی ثبت نشده است.</td></tr><?php else: foreach ( $providers as $provider ) : $provider_id = absint( $provider['id'] ?? 0 ); $provider_services = is_array( $provider['services'] ?? null ) ? array_map( 'absint', $provider['services'] ) : array(); ?>
<tr data-id="<?php echo esc_attr( $provider_id ); ?>" data-first="<?php echo esc_attr( $provider['firstName'] ?? '' ); ?>" data-last="<?php echo esc_attr( $provider['lastName'] ?? '' ); ?>" data-email="<?php echo esc_attr( $provider['email'] ?? '' ); ?>" data-phone="<?php echo esc_attr( $provider['phone'] ?? '' ); ?>" data-services="<?php echo esc_attr( wp_json_encode( $provider_services ) ); ?>"><td><strong><?php echo esc_html( trim( ( $provider['firstName'] ?? '' ) . ' ' . ( $provider['lastName'] ?? '' ) ) ); ?></strong></td><td><?php echo esc_html( $provider['email'] ?? '' ); ?></td><td dir="ltr"><?php echo esc_html( $provider['phone'] ?: '—' ); ?></td><td><?php echo esc_html( self::provider_service_labels( $provider_services, $services ) ); ?></td><td><div class="hmn-row-actions"><button type="button" class="hmn-row-action" data-edit-provider="<?php echo esc_attr( $provider_id ); ?>">ویرایش</button><button type="button" class="hmn-row-action is-danger" data-delete-provider="<?php echo esc_attr( $provider_id ); ?>" data-name="<?php echo esc_attr( trim( ( $provider['firstName'] ?? '' ) . ' ' . ( $provider['lastName'] ?? '' ) ) ); ?>">حذف</button></div></td></tr>
<?php endforeach; endif; ?>
</tbody></table><?php }

	/** Human-readable service list for one provider row. */
	private static function provider_service_labels( $provider_services, $services ) {
		$labels = array();
		foreach ( (array) $services as $service ) {
			if ( in_array( absint( $service['id'] ?? 0 ), (array) $provider_services, true ) ) { $labels[] = sanitize_text_field( $service['name'] ?? '' ); }
		}
		return $labels ? implode( '، ', $labels ) : '—';
	}

	private static function services_table( $services, $providers ) { ?>
<table class="hmn-settings-table" data-hmn-service-table><thead><tr><th>نام</th><th>مدت</th><th>هزینه</th><th>پزشکان</th><th>عملیات</th></tr></thead><tbody>
<?php if ( empty( $services ) ) : ?><tr><td colspan="5" style="text-align:center;color:var(--muted)">هنوز سرویسی ثبت نشده است.</td></tr><?php else: foreach ( $services as $service ) : $service_id = absint( $service['id'] ?? 0 ); $service_providers = is_array( $service['providers'] ?? null ) ? array_map( 'absint', $service['providers'] ) : array(); ?>
<tr data-id="<?php echo esc_attr( $service_id ); ?>" data-name="<?php echo esc_attr( $service['name'] ?? '' ); ?>" data-duration="<?php echo esc_attr( $service['duration'] ?? 15 ); ?>" data-price="<?php echo esc_attr( $service['price'] ?? 0 ); ?>" data-color="<?php echo esc_attr( $service['color'] ?? '#5b4cf0' ); ?>" data-attendants="<?php echo esc_attr( $service['attendantsNumber'] ?? 1 ); ?>" data-providers="<?php echo esc_attr( wp_json_encode( $service_providers ) ); ?>"><td><strong><?php echo esc_html( $service['name'] ?? '' ); ?></strong></td><td><?php echo esc_html( $service['duration'] ?? '' ); ?> دقیقه</td><td><?php echo esc_html( $service['price'] ?? 0 ); ?></td><td><?php echo esc_html( self::service_provider_labels( $service_providers, $providers ) ); ?></td><td><div class="hmn-row-actions"><button type="button" class="hmn-row-action" data-edit-service="<?php echo esc_attr( $service_id ); ?>">ویرایش</button><button type="button" class="hmn-row-action is-danger" data-delete-service="<?php echo esc_attr( $service_id ); ?>" data-name="<?php echo esc_attr( $service['name'] ?? '' ); ?>">حذف</button></div></td></tr>
<?php endforeach; endif; ?>
</tbody></table><?php }

	/** Human-readable provider list for one service row. */
	private static function service_provider_labels( $service_providers, $providers ) {
		$labels = array();
		foreach ( (array) $providers as $provider ) {
			if ( in_array( absint( $provider['id'] ?? 0 ), (array) $service_providers, true ) ) { $labels[] = trim( sanitize_text_field( ( $provider['firstName'] ?? '' ) . ' ' . ( $provider['lastName'] ?? '' ) ) ); }
		}
		return $labels ? implode( '، ', $labels ) : '—';
	}
}
new HMN_CRM_Settings();
