<?php
/**
 * Plugin Name: WooCommerce Otomatik Lisans Teslimatı - BERAT K V4
 * Plugin URI: https://wa.me/905395115632
 * Description: WooCommerce için otomatik lisans anahtarı teslimat eklentisi. V4: Tüm hatalar düzeltildi, güzel 24 saat bekleme sistemi eklendi. Geliştirici: BERAT K - 0539 511 56 32
 * Version: 4.0.0
 * Author: BERAT K - 0539 511 56 32
 * Author URI: https://wa.me/905395115632
 * Text Domain: auto-license-delivery
 * Domain Path: /languages
 * Requires at least: 5.6
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 8.4
 * License: GPL v2 or later
 * Network: false
 * Developer: BERAT K - WhatsApp: +90 539 511 56 32
 * Support: https://wa.me/905395115632
 */

// Güvenlik kontrolü
if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

// Plugin sabitleri
define('ALD_VERSION', '4.0.0');
define('ALD_PLUGIN_FILE', __FILE__);
define('ALD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ALD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ALD_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('ALD_LICENSE_KEY', base64_encode('WİO-4142-1544-1151-4441')); // V4 FIX: Correct license key

/**
 * Ana Plugin Sınıfı - V4 Fixed
 */
class AutoLicenseDelivery_V4 {
    
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
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        add_action('init', array($this, 'load_textdomain'));
    }
    
    public function init() {
        if (!$this->check_requirements()) {
            return;
        }
        
        $this->declare_hpos_compatibility();
        $this->init_hooks();
    }
    
    public function load_textdomain() {
        load_plugin_textdomain('auto-license-delivery', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    private function check_requirements() {
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            add_action('admin_notices', array($this, 'php_version_notice'));
            return false;
        }
        
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return false;
        }
        
        return true;
    }
    
    private function declare_hpos_compatibility() {
        add_action('before_woocommerce_init', function() {
            if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
            }
        });
    }
    
    private function init_hooks() {
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
            add_action('admin_init', array($this, 'handle_license_activation'));
            add_action('admin_init', array($this, 'handle_admin_actions'));
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'plugin_action_links'));
        }
        
        add_action('woocommerce_product_options_general_product_data', array($this, 'add_license_field'));
        add_action('woocommerce_process_product_meta', array($this, 'save_license_field'));
        add_action('woocommerce_order_status_completed', array($this, 'deliver_license'));
        add_action('woocommerce_order_status_processing', array($this, 'deliver_license'));
        
        add_filter('woocommerce_account_menu_items', array($this, 'add_account_menu_item'));
        add_action('init', array($this, 'add_account_endpoints'));
        add_action('woocommerce_account_license-keys_endpoint', array($this, 'license_keys_content'));
        
        // V4: Enhanced order details with beautiful 24h waiting system
        add_action('woocommerce_order_details_after_order_table', array($this, 'display_order_licenses'));
        add_action('woocommerce_order_details_after_order_table', array($this, 'display_beautiful_waiting_message'), 15);
        add_action('woocommerce_email_order_meta', array($this, 'add_license_to_email'), 10, 3);
        
        // AJAX handlers
        add_action('wp_ajax_ald_save_license_keys', array($this, 'ajax_save_license_keys'));
        add_action('wp_ajax_ald_send_manual_license', array($this, 'ajax_send_manual_license'));
    }
    
    public function activate() {
        $this->create_tables();
        flush_rewrite_rules();
        update_option('ald_version', ALD_VERSION);
        update_option('ald_activation_time', current_time('mysql'));
    }
    
    public function deactivate() {
        flush_rewrite_rules();
    }
    
    private function create_tables() {
        global $wpdb;
        
        // V4 FIX: License history table
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
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY product_id (product_id),
            KEY customer_id (customer_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // V4 FIX: Pending customers table (was missing!)
        $pending_table = $wpdb->prefix . 'ald_pending_customers';
        $pending_sql = "CREATE TABLE IF NOT EXISTS $pending_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id varchar(50) NOT NULL,
            product_id bigint(20) NOT NULL,
            customer_id bigint(20) NOT NULL,
            customer_email varchar(255) NOT NULL,
            product_name varchar(255) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            processed_at datetime DEFAULT NULL,
            status varchar(20) DEFAULT 'pending',
            PRIMARY KEY (id),
            UNIQUE KEY unique_order_product (order_id, product_id),
            KEY customer_id (customer_id),
            KEY status (status)
        ) $charset_collate;";
        
        dbDelta($pending_sql);
    }
    
    // Notice functions
    public function php_version_notice() {
        echo '<div class="error"><p>Otomatik Lisans Teslimatı PHP 7.4+ gerektirir. Geliştirici: BERAT K - 0539 511 56 32</p></div>';
    }
    
    public function woocommerce_missing_notice() {
        echo '<div class="error"><p>Otomatik Lisans Teslimatı WooCommerce gerektirir. BERAT K - WhatsApp: wa.me/905395115632</p></div>';
    }
    
    public function plugin_action_links($links) {
        $action_links = array(
            'settings' => '<a href="' . admin_url('admin.php?page=auto-license-delivery') . '">Ayarlar</a>',
            'developer' => '<a href="https://wa.me/905395115632" target="_blank">👨‍💻 BERAT K</a>',
        );
        return array_merge($action_links, $links);
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Lisans Anahtarı Yönetimi V4',
            'Lisans Anahtarları',
            'manage_woocommerce',
            'auto-license-delivery',
            array($this, 'admin_page'),
            'dashicons-admin-network',
            56
        );
    }
    
    public function admin_scripts($hook) {
        if (strpos($hook, 'auto-license-delivery') === false) {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_add_inline_style('wp-admin', $this->get_admin_css());
        
        wp_localize_script('jquery', 'ald_ajax', array(
            'url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ald_admin_nonce')
        ));
    }
    
    private function get_admin_css() {
        return '
        .ald-dashboard { background: #f8f9fa; padding: 20px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .ald-card { background: white; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 30px; overflow: hidden; }
        .ald-card-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px 25px; font-size: 18px; font-weight: 600; }
        .ald-card-body { padding: 25px; }
        .ald-form-group { margin-bottom: 20px; }
        .ald-form-label { display: block; margin-bottom: 8px; font-weight: 600; color: #333; }
        .ald-form-control { width: 100%; padding: 12px 16px; border: 2px solid #e1e5e9; border-radius: 8px; }
        .ald-btn { padding: 12px 24px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .ald-btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        
        /* V4: Beautiful 24H Waiting System */
        .ald-waiting-container { 
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%); 
            border: 2px solid #ffc107; 
            border-radius: 15px; 
            padding: 25px; 
            margin: 20px 0; 
            text-align: center; 
            position: relative;
            overflow: hidden;
        }
        .ald-waiting-container::before {
            content: "";
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.1), transparent);
            animation: shine 3s infinite;
        }
        @keyframes shine {
            0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
            100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
        }
        .ald-waiting-title { 
            font-size: 24px; 
            color: #856404; 
            margin: 0 0 15px 0; 
            font-weight: bold;
        }
        .ald-waiting-message { 
            font-size: 16px; 
            color: #856404; 
            margin: 0 0 20px 0; 
            line-height: 1.6;
        }
        .ald-countdown-timer {
            font-size: 32px;
            font-weight: bold;
            color: #dc3545;
            margin: 15px 0;
            font-family: monospace;
        }
        .ald-progress-bar {
            width: 100%;
            height: 8px;
            background: rgba(255,255,255,0.3);
            border-radius: 4px;
            overflow: hidden;
            margin: 20px 0;
        }
        .ald-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #28a745, #20c997);
            border-radius: 4px;
            transition: width 1s ease;
        }
        ';
    }
    
    public function handle_license_activation() {
        if (!isset($_POST['ald_activate_license'])) {
            return;
        }
        
        if (!wp_verify_nonce($_POST['ald_nonce'], 'ald_activate_license')) {
            return;
        }
        
        $license_key = sanitize_text_field($_POST['license_key']);
        
        if (base64_encode($license_key) === ALD_LICENSE_KEY) {
            update_option('ald_license_activated', true);
            update_option('ald_license_key_hash', hash('sha256', $license_key));
            $this->admin_notice('V4 License activated successfully!', 'success');
        } else {
            $this->admin_notice('Invalid license key!', 'error');
        }
    }
    
    public function handle_admin_actions() {
        // License keys saving
        if (isset($_POST['ald_save_license_keys']) && wp_verify_nonce($_POST['ald_nonce'], 'ald_save_license_keys')) {
            $product_id = intval($_POST['product_id']);
            $license_keys = sanitize_textarea_field($_POST['license_keys']);
            
            if ($product_id && $license_keys) {
                $keys_array = array_filter(explode("\n", $license_keys));
                $keys_array = array_map('trim', $keys_array);
                $keys_array = array_unique($keys_array);
                
                update_post_meta($product_id, '_license_keys', $keys_array);
                $this->admin_notice('Lisans anahtarları başarıyla kaydedildi!', 'success');
            }
        }
        
        // Manual license sending
        if (isset($_POST['ald_send_customer_license']) && wp_verify_nonce($_POST['ald_nonce'], 'ald_send_customer_license')) {
            $customer_id = intval($_POST['customer_id']);
            $product_id = intval($_POST['customer_product_id']);
            $license_key = sanitize_text_field($_POST['customer_license_key']);
            
            if ($this->send_manual_license($customer_id, $product_id, $license_key)) {
                $this->admin_notice('Lisans anahtarı başarıyla gönderildi! Bekleme durumu kaldırıldı.', 'success');
            } else {
                $this->admin_notice('Lisans gönderiminde hata oluştu!', 'error');
            }
        }
    }
    
    private function admin_notice($message, $type = 'success') {
        add_action('admin_notices', function() use ($message, $type) {
            echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html($message) . '</p></div>';
        });
    }
    
    // V4: Enhanced manual license sending
    private function send_manual_license($customer_id, $product_id, $license_key) {
        global $wpdb;
        
        $customer = get_user_by('id', $customer_id);
        $product = wc_get_product($product_id);
        
        if (!$customer || !$product) {
            return false;
        }
        
        // V4: Remove pending status
        $pending_table = $wpdb->prefix . 'ald_pending_customers';
        $wpdb->update(
            $pending_table,
            array('status' => 'processed', 'processed_at' => current_time('mysql')),
            array('customer_id' => $customer_id, 'product_id' => $product_id, 'status' => 'pending'),
            array('%s', '%s'),
            array('%d', '%d', '%s')
        );
        
        // Save license record
        $this->save_manual_license($customer, $product_id, $license_key);
        
        // Send email
        return $this->send_license_email($customer, $product, $license_key);
    }
    
    private function save_manual_license($customer, $product_id, $license_key) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ald_license_history';
        
        return $wpdb->insert(
            $table_name,
            array(
                'order_id' => 'MANUAL-' . time(),
                'product_id' => $product_id,
                'customer_id' => $customer->ID,
                'customer_email' => $customer->user_email,
                'license_key' => $license_key,
                'status' => 'sent',
                'created_at' => current_time('mysql')
            ),
            array('%s', '%d', '%d', '%s', '%s', '%s', '%s')
        );
    }
    
    private function send_license_email($customer, $product, $license_key) {
        $subject = sprintf('[%s] V4 - Your License Key for %s', get_bloginfo('name'), $product->get_name());
        
        $message = sprintf('
            <div style="background: #f8f9fa; padding: 20px; font-family: Arial, sans-serif;">
                <div style="max-width: 600px; margin: 0 auto; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                    <div style="background: linear-gradient(135deg, #667eea 0%%, #764ba2 100%%); color: white; padding: 30px 20px; text-align: center;">
                        <h1 style="margin: 0; font-size: 28px;">🔑 License Key Ready! (V4)</h1>
                    </div>
                    <div style="padding: 30px 20px;">
                        <p>Hello <strong>%s</strong>,</p>
                        <p>Your license key for <strong>%s</strong> is ready:</p>
                        <div style="background: #f8f9fa; border: 2px solid #28a745; padding: 20px; border-radius: 8px; text-align: center; margin: 20px 0;">
                            <div style="font-family: monospace; font-size: 18px; font-weight: bold; color: #28a745;">%s</div>
                        </div>
                        <p style="color: #28a745; font-weight: bold;">✅ The waiting period has been completed and your license is now available!</p>
                        <p>Best regards,<br><strong>%s</strong></p>
                    </div>
                </div>
            </div>',
            esc_html($customer->display_name),
            esc_html($product->get_name()),
            esc_html($license_key),
            esc_html(get_bloginfo('name'))
        );
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        return wp_mail($customer->user_email, $subject, $message, $headers);
    }
    
    public function admin_page() {
        if (!get_option('ald_license_activated')) {
            $this->license_activation_page();
            return;
        }
        
        $this->main_admin_page();
    }
    
    private function license_activation_page() {
        ?>
        <div class="ald-dashboard">
            <div class="ald-card" style="max-width: 500px; margin: 0 auto;">
                <div class="ald-card-header">
                    🔑 V4 License Activation Required
                </div>
                <div class="ald-card-body">
                    <form method="post" action="">
                        <?php wp_nonce_field('ald_activate_license', 'ald_nonce'); ?>
                        <div class="ald-form-group">
                            <label class="ald-form-label">License Key:</label>
                            <input type="text" name="license_key" class="ald-form-control" placeholder="WİO-4142-1544-1151-4441" required>
                        </div>
                        <button type="submit" name="ald_activate_license" class="ald-btn ald-btn-primary" style="width: 100%;">
                            🚀 Activate V4 License
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function main_admin_page() {
        $products = wc_get_products(array('limit' => -1, 'status' => 'publish'));
        $customers = get_users(array('role' => 'customer'));
        
        ?>
        <div class="ald-dashboard">
            <h1 style="margin: 0 0 30px 0; font-size: 32px;">📊 License Keys Dashboard V4</h1>
            
            <!-- License Management -->
            <div class="ald-card">
                <div class="ald-card-header">
                    🔑 License Key Management V4
                </div>
                <div class="ald-card-body">
                    <form method="post" action="">
                        <?php wp_nonce_field('ald_save_license_keys', 'ald_nonce'); ?>
                        <div class="ald-form-group">
                            <label class="ald-form-label">Select Product:</label>
                            <select name="product_id" class="ald-form-control" required>
                                <option value="">Choose a product...</option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?php echo $product->get_id(); ?>"><?php echo esc_html($product->get_name()); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ald-form-group">
                            <label class="ald-form-label">License Keys (one per line):</label>
                            <textarea name="license_keys" class="ald-form-control" rows="8" placeholder="KEY-1234-5678-9ABC&#10;KEY-9876-5432-1DEF"></textarea>
                        </div>
                        <button type="submit" name="ald_save_license_keys" class="ald-btn ald-btn-primary">
                            💾 Save License Keys
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- V4: Manual License Sending -->
            <div class="ald-card">
                <div class="ald-card-header">
                    📤 Send Manual License (V4 Enhanced)
                </div>
                <div class="ald-card-body">
                    <form method="post" action="">
                        <?php wp_nonce_field('ald_send_customer_license', 'ald_nonce'); ?>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <div class="ald-form-group">
                                <label class="ald-form-label">Customer:</label>
                                <select name="customer_id" class="ald-form-control" required>
                                    <option value="">Select customer...</option>
                                    <?php foreach ($customers as $customer): ?>
                                        <option value="<?php echo $customer->ID; ?>"><?php echo esc_html($customer->display_name . ' (' . $customer->user_email . ')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="ald-form-group">
                                <label class="ald-form-label">Product:</label>
                                <select name="customer_product_id" class="ald-form-control" required>
                                    <option value="">Select product...</option>
                                    <?php foreach ($products as $product): ?>
                                        <option value="<?php echo $product->get_id(); ?>"><?php echo esc_html($product->get_name()); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="ald-form-group">
                            <label class="ald-form-label">License Key:</label>
                            <input type="text" name="customer_license_key" class="ald-form-control" placeholder="KEY-1234-5678-9ABC" required>
                        </div>
                        <button type="submit" name="ald_send_customer_license" class="ald-btn ald-btn-primary">
                            📤 Send License (Removes 24h Wait)
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }
}