<?php
/*
Plugin Name: HMN CRM
Description: اتوماسیون نوبت‌دهی 
Version: 1.9.0
Author: Houman
*/

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'includes/class-core.php';

function hmn_crm_init() {
    return HMN_CRM_Core::get_instance();
}
hmn_crm_init();
