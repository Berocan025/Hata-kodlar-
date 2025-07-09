<?php
/**
 * Plugin Name: WooCommerce Otomatik Lisans Teslimatı - BERAT K V4 FINAL
 * Version: 4.0.0
 * Description: V4 - Tüm hatalar düzeltildi, güzel 24 saat bekleme sistemi
 * Author: BERAT K - 0539 511 56 32
 */

if (!defined('ABSPATH')) exit;

define('ALD_VERSION', '4.0.0');
define('ALD_LICENSE_KEY', base64_encode('WİO-4142-1544-1151-4441'));

class AutoLicenseDelivery_V4_FINAL {
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
    }
    
    public function init() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>V4 WooCommerce gerektirir! BERAT K - wa.me/905395115632</p></div>';
            });
            return;
        }
        
        $this->init_hooks();
    }
    
    private function init_hooks() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_admin_actions'));
        add_action('woocommerce_order_status_completed', array($this, 'deliver_license'));
        add_action('woocommerce_order_status_processing', array($this, 'deliver_license'));
        add_action('woocommerce_order_details_after_order_table', array($this, 'display_beautiful_waiting_message'), 15);
    }
    
    public function activate() {
        $this->create_tables();
        update_option('ald_version', ALD_VERSION);
    }
    
    private function create_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'ald_license_history';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id varchar(50) NOT NULL,
            product_id bigint(20) NOT NULL,
            customer_id bigint(20) NOT NULL,
            customer_email varchar(255) NOT NULL,
            license_key varchar(255) DEFAULT '',
            status varchar(20) DEFAULT 'sent',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        $pending_table = $wpdb->prefix . 'ald_pending_customers';
        $pending_sql = "CREATE TABLE IF NOT EXISTS $pending_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id varchar(50) NOT NULL,
            product_id bigint(20) NOT NULL,
            customer_id bigint(20) NOT NULL,
            customer_email varchar(255) NOT NULL,
            product_name varchar(255) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            status varchar(20) DEFAULT 'pending',
            PRIMARY KEY (id),
            UNIQUE KEY unique_order_product (order_id, product_id)
        ) $charset_collate;";
        
        dbDelta($pending_sql);
    }
    
    public function add_admin_menu() {
        add_menu_page('License V4', 'Lisans V4', 'manage_woocommerce', 'auto-license-delivery', array($this, 'admin_page'), 'dashicons-admin-network', 56);
    }
    
    public function admin_page() {
        if (!get_option('ald_license_activated')) {
            echo '<div style="background:#f8f9fa;padding:20px"><div style="max-width:500px;margin:0 auto;background:white;border-radius:12px;padding:25px"><h2>V4 License Activation</h2><form method="post">';
            wp_nonce_field('ald_activate_license', 'ald_nonce');
            echo '<input type="text" name="license_key" placeholder="WİO-4142-1544-1151-4441" style="width:100%;padding:12px;margin:10px 0;border:2px solid #ddd;border-radius:8px" required>';
            echo '<button type="submit" name="ald_activate_license" style="width:100%;padding:12px;background:#667eea;color:white;border:none;border-radius:8px">Activate V4</button></form>';
            echo '<p style="text-align:center;margin-top:20px">BERAT K - 0539 511 56 32</p></div></div>';
            return;
        }
        
        echo '<div style="background:#f8f9fa;padding:20px"><h1>V4 License Dashboard</h1>';
        echo '<div style="background:white;padding:25px;border-radius:12px;margin-bottom:20px"><h3>V4 License Management</h3>';
        echo '<form method="post">';
        wp_nonce_field('ald_save_license_keys', 'ald_nonce');
        echo '<select name="product_id" style="width:100%;padding:12px;margin:10px 0;border:2px solid #ddd;border-radius:8px" required>';
        echo '<option value="">Choose product...</option>';
        foreach (wc_get_products(array('limit' => -1)) as $product) {
            echo '<option value="' . $product->get_id() . '">' . esc_html($product->get_name()) . '</option>';
        }
        echo '</select>';
        echo '<textarea name="license_keys" rows="8" placeholder="License keys, one per line" style="width:100%;padding:12px;margin:10px 0;border:2px solid #ddd;border-radius:8px"></textarea>';
        echo '<button type="submit" name="ald_save_license_keys" style="padding:12px 24px;background:#667eea;color:white;border:none;border-radius:8px">Save Keys</button>';
        echo '</form></div>';
        
        echo '<div style="background:white;padding:25px;border-radius:12px"><h3>Manual License Delivery</h3>';
        echo '<form method="post">';
        wp_nonce_field('ald_send_manual_license', 'ald_nonce');
        echo '<select name="customer_id" style="width:100%;padding:12px;margin:10px 0;border:2px solid #ddd;border-radius:8px" required>';
        echo '<option value="">Select customer...</option>';
        foreach (get_users(array('role' => 'customer')) as $customer) {
            echo '<option value="' . $customer->ID . '">' . esc_html($customer->display_name . ' (' . $customer->user_email . ')') . '</option>';
        }
        echo '</select>';
        echo '<select name="customer_product_id" style="width:100%;padding:12px;margin:10px 0;border:2px solid #ddd;border-radius:8px" required>';
        echo '<option value="">Select product...</option>';
        foreach (wc_get_products(array('limit' => -1)) as $product) {
            echo '<option value="' . $product->get_id() . '">' . esc_html($product->get_name()) . '</option>';
        }
        echo '</select>';
        echo '<input type="text" name="customer_license_key" placeholder="License key" style="width:100%;padding:12px;margin:10px 0;border:2px solid #ddd;border-radius:8px" required>';
        echo '<button type="submit" name="ald_send_manual_license" style="padding:12px 24px;background:#28a745;color:white;border:none;border-radius:8px">Send License (Remove 24h Wait)</button>';
        echo '</form></div></div>';
    }
    
    public function handle_admin_actions() {
        if (isset($_POST['ald_activate_license']) && wp_verify_nonce($_POST['ald_nonce'], 'ald_activate_license')) {
            $license_key = sanitize_text_field($_POST['license_key']);
            if (base64_encode($license_key) === ALD_LICENSE_KEY) {
                update_option('ald_license_activated', true);
                add_action('admin_notices', function() { echo '<div class="notice notice-success"><p>V4 activated!</p></div>'; });
            }
        }
        
        if (isset($_POST['ald_save_license_keys']) && wp_verify_nonce($_POST['ald_nonce'], 'ald_save_license_keys')) {
            $product_id = intval($_POST['product_id']);
            $license_keys = sanitize_textarea_field($_POST['license_keys']);
            if ($product_id && $license_keys) {
                $keys_array = array_unique(array_map('trim', array_filter(explode("\n", $license_keys))));
                update_post_meta($product_id, '_license_keys', $keys_array);
                add_action('admin_notices', function() { echo '<div class="notice notice-success"><p>Keys saved!</p></div>'; });
            }
        }
        
        if (isset($_POST['ald_send_manual_license']) && wp_verify_nonce($_POST['ald_nonce'], 'ald_send_manual_license')) {
            $customer_id = intval($_POST['customer_id']);
            $product_id = intval($_POST['customer_product_id']);
            $license_key = sanitize_text_field($_POST['customer_license_key']);
            if ($this->send_manual_license($customer_id, $product_id, $license_key)) {
                add_action('admin_notices', function() { echo '<div class="notice notice-success"><p>License sent!</p></div>'; });
            }
        }
    }
    
    private function send_manual_license($customer_id, $product_id, $license_key) {
        global $wpdb;
        $customer = get_user_by('id', $customer_id);
        $product = wc_get_product($product_id);
        if (!$customer || !$product) return false;
        
        $pending_table = $wpdb->prefix . 'ald_pending_customers';
        $wpdb->update($pending_table, array('status' => 'processed'), array('customer_id' => $customer_id, 'product_id' => $product_id, 'status' => 'pending'), array('%s'), array('%d', '%d', '%s'));
        
        $table_name = $wpdb->prefix . 'ald_license_history';
        $wpdb->insert($table_name, array('order_id' => 'MANUAL-' . time(), 'product_id' => $product_id, 'customer_id' => $customer->ID, 'customer_email' => $customer->user_email, 'license_key' => $license_key, 'status' => 'sent', 'created_at' => current_time('mysql')), array('%s', '%d', '%d', '%s', '%s', '%s', '%s'));
        
        $subject = sprintf('[%s] V4 - License Key Ready', get_bloginfo('name'));
        $message = sprintf('<h2>Your license key is ready!</h2><p>Product: %s</p><p><strong>License Key: %s</strong></p><p>24h wait removed!</p>', $product->get_name(), $license_key);
        $headers = array('Content-Type: text/html; charset=UTF-8');
        return wp_mail($customer->user_email, $subject, $message, $headers);
    }
    
    public function deliver_license($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $license_keys = get_post_meta($product_id, '_license_keys', true);
            $license_keys = is_array($license_keys) ? $license_keys : array();
            
            if (!empty($license_keys)) {
                $license_key = array_shift($license_keys);
                update_post_meta($product_id, '_license_keys', $license_keys);
                $this->save_delivered_license($order, $product_id, $license_key);
                $this->send_license_email_auto($order, wc_get_product($product_id), $license_key);
            } else {
                $this->add_to_pending_customers($order, $product_id);
            }
        }
    }
    
    private function save_delivered_license($order, $product_id, $license_key) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ald_license_history';
        return $wpdb->insert($table_name, array('order_id' => $order->get_id(), 'product_id' => $product_id, 'customer_id' => $order->get_customer_id(), 'customer_email' => $order->get_billing_email(), 'license_key' => $license_key, 'status' => 'sent', 'created_at' => current_time('mysql')), array('%s', '%d', '%d', '%s', '%s', '%s', '%s'));
    }
    
    private function add_to_pending_customers($order, $product_id) {
        global $wpdb;
        $pending_table = $wpdb->prefix . 'ald_pending_customers';
        $product = wc_get_product($product_id);
        
        $wpdb->replace($pending_table, array('order_id' => $order->get_id(), 'product_id' => $product_id, 'customer_id' => $order->get_customer_id(), 'customer_email' => $order->get_billing_email(), 'product_name' => $product ? $product->get_name() : 'Unknown', 'created_at' => current_time('mysql'), 'status' => 'pending'), array('%s', '%d', '%d', '%s', '%s', '%s', '%s'));
        
        $this->send_pending_email($order, $product);
    }
    
    private function send_pending_email($order, $product) {
        $customer = $order->get_user();
        if (!$customer) return;
        
        $subject = sprintf('[%s] V4 - License Being Prepared (24H)', get_bloginfo('name'));
        $message = sprintf('<div style="background:#fff3cd;border:2px solid #ffc107;border-radius:15px;padding:25px;text-align:center"><h2 style="color:#856404">⏳ License Preparation Started</h2><p style="color:#856404">Your license key for <strong>%s</strong> is being prepared.</p><p style="color:#856404"><strong>Maximum delivery time: 24 hours</strong></p><p style="color:#856404">You may receive it earlier!</p></div>', $product->get_name());
        $headers = array('Content-Type: text/html; charset=UTF-8');
        return wp_mail($customer->user_email, $subject, $message, $headers);
    }
    
    private function send_license_email_auto($order, $product, $license_key) {
        $customer = $order->get_user();
        if (!$customer) return;
        
        $subject = sprintf('[%s] V4 - License Key Ready', get_bloginfo('name'));
        $message = sprintf('<h2>Your license key is ready!</h2><p>Product: %s</p><p><strong>License Key: %s</strong></p>', $product->get_name(), $license_key);
        $headers = array('Content-Type: text/html; charset=UTF-8');
        return wp_mail($customer->user_email, $subject, $message, $headers);
    }
    
    public function display_beautiful_waiting_message($order) {
        $customer_id = get_current_user_id();
        if (!$customer_id) return;
        
        global $wpdb;
        $pending_table = $wpdb->prefix . 'ald_pending_customers';
        $pending = $wpdb->get_results($wpdb->prepare("SELECT * FROM $pending_table WHERE customer_id = %d AND status = 'pending' AND order_id = %s", $customer_id, $order->get_id()));
        
        if ($pending) {
            foreach ($pending as $item) {
                $created_time = strtotime($item->created_at);
                $current_time = current_time('timestamp');
                $elapsed_hours = ($current_time - $created_time) / 3600;
                $remaining_hours = max(0, 24 - $elapsed_hours);
                $progress_percentage = min(100, ($elapsed_hours / 24) * 100);
                
                echo '<div style="background:linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);border:2px solid #ffc107;border-radius:15px;padding:25px;margin:20px 0;text-align:center;position:relative;overflow:hidden">
                    <div style="position:absolute;top:-50%;left:-50%;width:200%;height:200%;background:linear-gradient(45deg, transparent, rgba(255,255,255,0.1), transparent);animation:shine 3s infinite"></div>
                    <h3 style="font-size:24px;color:#856404;margin:0 0 15px 0;font-weight:bold;position:relative;z-index:1">⏳ ' . esc_html($item->product_name) . '</h3>
                    <div style="font-size:16px;color:#856404;margin:0 0 20px 0;line-height:1.6;position:relative;z-index:1">
                        Lisans anahtarınız hazırlanıyor ve 24 saat içerisinde teslim edilecektir.<br>
                        <strong>Not:</strong> 24 saatten önce de gelebilir!
                    </div>
                    <div style="font-size:32px;font-weight:bold;color:#dc3545;margin:15px 0;font-family:monospace;position:relative;z-index:1">
                        ' . sprintf('%02d:%02d kaldı', floor($remaining_hours), ($remaining_hours - floor($remaining_hours)) * 60) . '
                    </div>
                    <div style="width:100%;height:8px;background:rgba(255,255,255,0.3);border-radius:4px;overflow:hidden;margin:20px 0;position:relative;z-index:1">
                        <div style="height:100%;background:linear-gradient(90deg, #28a745, #20c997);border-radius:4px;transition:width 1s ease;width:' . $progress_percentage . '%"></div>
                    </div>
                    <small style="color:#856404;position:relative;z-index:1">
                        📅 Başlangıç: ' . date('d.m.Y H:i', $created_time) . '<br>
                        ⏰ Tahmini Teslim: ' . date('d.m.Y H:i', $created_time + (24 * 3600)) . '
                    </small>
                </div>
                
                <style>
                @keyframes shine {
                    0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
                    100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
                }
                </style>
                
                <script>
                jQuery(document).ready(function($) {
                    setInterval(function() {
                        var now = Math.floor(Date.now() / 1000);
                        var created = ' . $created_time . ';
                        var elapsed = now - created;
                        var remaining = Math.max(0, (24 * 3600) - elapsed);
                        
                        if (remaining > 0) {
                            var hours = Math.floor(remaining / 3600);
                            var minutes = Math.floor((remaining % 3600) / 60);
                            $(".ald-countdown-timer").text(String(hours).padStart(2, "0") + ":" + String(minutes).padStart(2, "0") + " kaldı");
                            var progress = Math.min(100, (elapsed / (24 * 3600)) * 100);
                            $(".ald-progress-fill").css("width", progress + "%");
                        } else {
                            $(".ald-countdown-timer").text("⏰ Süre doldu - Yakında teslim edilecek!");
                        }
                    }, 60000);
                });
                </script>';
            }
        }
    }
}

function ald_init_v4() {
    AutoLicenseDelivery_V4_FINAL::get_instance();
}
add_action('plugins_loaded', 'ald_init_v4');
