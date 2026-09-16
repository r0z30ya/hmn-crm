<?php
/*
Plugin Name: HMN CRM
Description: پنل مشتری مداری هومانا با قابلیت رزرو نوبت
Version: 4.5
Author: Houman
*/

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'includes/class-core.php';

register_activation_hook( __FILE__, 'hmn_crm_activate' );
function hmn_crm_activate() {
    HMN_CRM_Core::get_instance()->migrate();
    flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'hmn_crm_deactivate' );
function hmn_crm_deactivate() {
    flush_rewrite_rules();
}

function hmn_crm_init() {
    return HMN_CRM_Core::get_instance();
}
hmn_crm_init();
