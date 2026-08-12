<?php
/**
 * View được tgs_shop_management nạp qua route do plugin tự đăng ký.
 */

if (!defined('ABSPATH')) {
    exit;
}

TGS_HEIQ_Plugin::instance()->render_admin_page();
