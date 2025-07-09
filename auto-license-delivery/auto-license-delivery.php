<?php
/**
 * Plugin Name: WooCommerce Auto License Delivery - Enhanced
 * Plugin URI: https://github.com/wiozen/auto-license-delivery
 * Description: Modern, güvenli ve şık lisans anahtarı teslim sistemi. WooCommerce siparişleri tamamlandığında otomatik olarak lisans anahtarları müşterilere gönderilir.
 * Version: 2.1.0
 * Author: Wiozen Enhanced
 * Author URI: https://www.wiozen.com
 * Text Domain: auto-license-delivery
 * Domain Path: /languages
 * Requires at least: 5.6
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 8.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Network: false
 */

// Güvenlik kontrolü
if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

// Plugin sabitleri
define('ALD_VERSION', '2.1.0');
define('ALD_PLUGIN_FILE', __FILE__);
define('ALD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ALD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ALD_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('ALD_LICENSE_KEY', hash('sha256', 'WİO-4142-1544-1151-4441'));

/**
 * Ana Plugin Sınıfı
 */
class AutoLicenseDelivery {
    
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
        
        if (defined('WC_VERSION') && version_compare(WC_VERSION, '6.0', '<')) {
            add_action('admin_notices', array($this, 'woocommerce_version_notice'));
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
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'plugin_action_links'));
        }
        
        add_action('woocommerce_product_options_general_product_data', array($this, 'add_license_field'));
        add_action('woocommerce_process_product_meta', array($this, 'save_license_field'));
        add_action('woocommerce_order_status_completed', array($this, 'deliver_license'));
        add_action('woocommerce_order_status_processing', array($this, 'deliver_license'));
        
        add_filter('woocommerce_account_menu_items', array($this, 'add_account_menu_item'));
        add_action('init', array($this, 'add_account_endpoints'));
        add_action('woocommerce_account_license-keys_endpoint', array($this, 'license_keys_content'));
        
        add_action('wp_ajax_ald_get_license_stats', array($this, 'ajax_get_license_stats'));
        add_action('wp_ajax_ald_save_license_keys', array($this, 'ajax_save_license_keys'));
        
        add_action('woocommerce_order_details_after_order_table', array($this, 'display_order_licenses'));
        add_action('woocommerce_email_order_meta', array($this, 'add_license_to_email'), 10, 3);
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
        
        $table_name = $wpdb->prefix . 'ald_license_history';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id varchar(50) NOT NULL,
            product_id bigint(20) NOT NULL,
            customer_id bigint(20) NOT NULL,
            customer_email varchar(255) NOT NULL,
            license_key varchar(255) NOT NULL,
            status varchar(20) DEFAULT 'sent',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY product_id (product_id),
            KEY customer_id (customer_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public function php_version_notice() {
        echo '<div class="error"><p>Auto License Delivery requires PHP 7.4 or higher. You are running version ' . PHP_VERSION . '.</p></div>';
    }
    
    public function woocommerce_missing_notice() {
        echo '<div class="error"><p>Auto License Delivery requires WooCommerce to be installed and active.</p></div>';
    }
    
    public function woocommerce_version_notice() {
        echo '<div class="error"><p>Auto License Delivery requires WooCommerce 6.0 or higher.</p></div>';
    }
    
    public function plugin_action_links($links) {
        $action_links = array(
            'settings' => '<a href="' . admin_url('admin.php?page=auto-license-delivery') . '">Settings</a>',
        );
        return array_merge($action_links, $links);
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'License Keys Management',
            'License Keys',
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
        wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', array(), '11.0.0', true);
        
        wp_add_inline_style('wp-admin', $this->get_admin_css());
        wp_add_inline_script('jquery', $this->get_admin_js());
        
        wp_localize_script('jquery', 'ald_ajax', array(
            'url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ald_admin_nonce'),
            'strings' => array(
                'loading' => 'Loading...',
                'success' => 'Success!',
                'error' => 'Error occurred!',
                'license_saved' => 'License keys saved successfully!',
                'select_product' => 'Please select a product!',
            )
        ));
    }
    
    private function get_admin_css() {
        return '
        .ald-dashboard {
            background: #f8f9fa;
            padding: 20px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        .ald-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            overflow: hidden;
        }
        .ald-card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 25px;
            font-size: 18px;
            font-weight: 600;
        }
        .ald-card-body {
            padding: 25px;
        }
        .ald-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .ald-stat-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }
        .ald-stat-card:hover {
            transform: translateY(-5px);
        }
        .ald-stat-number {
            font-size: 36px;
            font-weight: bold;
            margin-bottom: 10px;
            color: #667eea;
        }
        .ald-stat-label {
            color: #666;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .ald-form-group {
            margin-bottom: 20px;
        }
        .ald-form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        .ald-form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }
        .ald-form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .ald-btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }
        .ald-btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .ald-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        .ald-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .ald-table th,
        .ald-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .ald-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        .ald-table tbody tr:hover {
            background: #f8f9fa;
        }
        .ald-license-key {
            font-family: "Courier New", monospace;
            background: #f8f9fa;
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid #ddd;
            font-size: 13px;
        }
        @media (max-width: 768px) {
            .ald-stats-grid {
                grid-template-columns: 1fr;
            }
            .ald-card-body {
                padding: 15px;
            }
        }
        ';
    }
    
    private function get_admin_js() {
        return '
        jQuery(document).ready(function($) {
            $(".ald-stat-number").each(function() {
                var $this = $(this);
                var target = parseInt($this.text()) || 0;
                $({ count: 0 }).animate({ count: target }, {
                    duration: 2000,
                    step: function() {
                        $this.text(Math.floor(this.count));
                    },
                    complete: function() {
                        $this.text(target);
                    }
                });
            });
            
            $("#product_select").change(function() {
                var productId = $(this).val();
                if (productId) {
                    $("#license_stats").html("<div>Loading stats...</div>");
                    
                    $.ajax({
                        url: ald_ajax.url,
                        type: "POST",
                        data: {
                            action: "ald_get_license_stats",
                            product_id: productId,
                            nonce: ald_ajax.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                $("#license_stats").html(response.data.html);
                                $("#license_keys_textarea").val(response.data.remaining_keys.join("\\n"));
                            }
                        }
                    });
                }
            });
            
            $("#save_license_keys").click(function() {
                var productId = $("#product_select").val();
                var licenseKeys = $("#license_keys_textarea").val();
                
                if (!productId) {
                    Swal.fire("Warning", ald_ajax.strings.select_product, "warning");
                    return;
                }
                
                $.ajax({
                    url: ald_ajax.url,
                    type: "POST",
                    data: {
                        action: "ald_save_license_keys",
                        product_id: productId,
                        license_keys: licenseKeys,
                        nonce: ald_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            Swal.fire("Success!", ald_ajax.strings.license_saved, "success");
                            $("#product_select").trigger("change");
                        } else {
                            Swal.fire("Error", response.data || "An error occurred", "error");
                        }
                    }
                });
            });
        });
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
        
        if (hash('sha256', $license_key) === ALD_LICENSE_KEY) {
            update_option('ald_license_activated', true);
            update_option('ald_license_key_hash', hash('sha256', $license_key));
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success"><p>License activated successfully!</p></div>';
            });
        } else {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>Invalid license key!</p></div>';
            });
        }
    }
    
    public function ajax_get_license_stats() {
        check_ajax_referer('ald_admin_nonce', 'nonce');
        
        $product_id = intval($_POST['product_id']);
        $stats = $this->get_product_license_stats($product_id);
        
        $html = $this->generate_license_stats_html($stats);
        
        wp_send_json_success(array(
            'html' => $html,
            'remaining_keys' => $stats['remaining_keys'],
            'stats' => $stats
        ));
    }
    
    public function ajax_save_license_keys() {
        check_ajax_referer('ald_admin_nonce', 'nonce');
        
        $product_id = intval($_POST['product_id']);
        $license_keys = sanitize_textarea_field($_POST['license_keys']);
        
        $keys_array = array_filter(explode("\n", $license_keys));
        $keys_array = array_map('trim', $keys_array);
        $keys_array = array_unique($keys_array);
        
        update_post_meta($product_id, '_ald_license_keys', $keys_array);
        
        wp_send_json_success('License keys saved successfully!');
    }
    
    private function get_product_license_stats($product_id) {
        $license_keys = get_post_meta($product_id, '_ald_license_keys', true);
        $license_keys = is_array($license_keys) ? $license_keys : array();
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'ald_license_history';
        
        $sold_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE product_id = %d",
            $product_id
        ));
        
        return array(
            'total_keys' => count($license_keys),
            'remaining_keys' => $license_keys,
            'sold_count' => intval($sold_count),
            'usage_percentage' => count($license_keys) > 0 ? round(($sold_count / (count($license_keys) + $sold_count)) * 100, 2) : 0
        );
    }
    
    private function generate_license_stats_html($stats) {
        ob_start();
        ?>
        <div style="margin: 20px 0;">
            <div class="ald-stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px;">
                <div class="ald-stat-card">
                    <div class="ald-stat-number"><?php echo $stats['total_keys']; ?></div>
                    <div class="ald-stat-label">Available Keys</div>
                </div>
                <div class="ald-stat-card">
                    <div class="ald-stat-number"><?php echo $stats['sold_count']; ?></div>
                    <div class="ald-stat-label">Sold Keys</div>
                </div>
                <div class="ald-stat-card">
                    <div class="ald-stat-number"><?php echo $stats['usage_percentage']; ?>%</div>
                    <div class="ald-stat-label">Usage Rate</div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
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
            <div class="ald-card" style="max-width: 500px; margin: 50px auto;">
                <div class="ald-card-header">
                    🔐 License Activation Required
                </div>
                <div class="ald-card-body">
                    <p>Please enter your license key to activate the plugin:</p>
                    <form method="post" action="">
                        <?php wp_nonce_field('ald_activate_license', 'ald_nonce'); ?>
                        <div class="ald-form-group">
                            <label class="ald-form-label">License Key:</label>
                            <input type="text" name="license_key" class="ald-form-control" placeholder="WİO-4142-1544-1151-4441" required>
                        </div>
                        <button type="submit" name="ald_activate_license" class="ald-btn ald-btn-primary" style="width: 100%;">
                            🚀 Activate License
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function main_admin_page() {
        $total_products = $this->get_total_products_with_licenses();
        $total_licenses = $this->get_total_licenses();
        $total_sold = $this->get_total_sold_licenses();
        $products = $this->get_products_with_licenses();
        
        ?>
        <div class="ald-dashboard">
            <h1 style="color: #333; margin-bottom: 30px;">📊 License Keys Dashboard</h1>
            
                         <div class="ald-stats-grid">
                 <div class="ald-stat-card">
                     <div class="ald-stat-number"><?php echo $total_products; ?></div>
                     <div class="ald-stat-label">Licensed Products</div>
                 </div>
                 <div class="ald-stat-card">
                     <div class="ald-stat-number"><?php echo $total_licenses; ?></div>
                     <div class="ald-stat-label">Total License Keys</div>
                 </div>
                <div class="ald-stat-card">
                    <div class="ald-stat-number"><?php echo $total_sold; ?></div>
                    <div class="ald-stat-label">Sold Licenses</div>
                </div>
                <div class="ald-stat-card">
                    <div class="ald-stat-number"><?php echo ($total_licenses - $total_sold); ?></div>
                    <div class="ald-stat-label">Remaining Licenses</div>
                </div>
            </div>
            
            <div class="ald-card">
                <div class="ald-card-header">
                    🔑 License Key Management
                </div>
                <div class="ald-card-body">
                    <div class="ald-form-group">
                        <label class="ald-form-label">Select Product:</label>
                        <select id="product_select" class="ald-form-control">
                            <option value="">Choose a product...</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?php echo $product->ID; ?>"><?php echo esc_html($product->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div id="license_stats"></div>
                    
                    <div class="ald-form-group">
                        <label class="ald-form-label">License Keys (one per line):</label>
                        <textarea id="license_keys_textarea" class="ald-form-control" rows="10" placeholder="Enter license keys, one per line..."></textarea>
                    </div>
                    
                    <button id="save_license_keys" class="ald-btn ald-btn-primary">
                        💾 Save License Keys
                    </button>
                </div>
            </div>
            
            <div class="ald-card">
                <div class="ald-card-header">
                    📈 Recent License Sales
                </div>
                <div class="ald-card-body">
                    <?php $this->display_recent_sales(); ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function get_total_products_with_licenses() {
        global $wpdb;
        return $wpdb->get_var("
            SELECT COUNT(DISTINCT post_id) 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_ald_license_keys' 
            AND meta_value != '' 
            AND meta_value != 'a:0:{}'
        ");
    }
    
    private function get_total_licenses() {
        global $wpdb;
        $results = $wpdb->get_results("
            SELECT meta_value 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_ald_license_keys'
        ");
        
        $total = 0;
        foreach ($results as $result) {
            $keys = maybe_unserialize($result->meta_value);
            if (is_array($keys)) {
                $total += count($keys);
            }
        }
        
        return $total;
    }
    
    private function get_total_sold_licenses() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ald_license_history';
        return $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    }
    
    private function get_products_with_licenses() {
        global $wpdb;
        return $wpdb->get_results("
            SELECT p.ID, p.post_title 
            FROM {$wpdb->posts} p 
            WHERE p.post_type = 'product' 
            AND p.post_status = 'publish'
            ORDER BY p.post_title
        ");
    }
    
    private function display_recent_sales() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ald_license_history';
        
        $recent_sales = $wpdb->get_results("
            SELECT h.*, p.post_title as product_name, u.display_name as customer_name
            FROM $table_name h
            LEFT JOIN {$wpdb->posts} p ON h.product_id = p.ID
            LEFT JOIN {$wpdb->users} u ON h.customer_id = u.ID
            ORDER BY h.created_at DESC
            LIMIT 10
        ");
        
        if (empty($recent_sales)) {
            echo '<div style="text-align: center; padding: 40px; color: #666;">';
            echo '<p>🎯 No license sales yet</p>';
            echo '<p>Sales will appear here once customers start purchasing your licensed products.</p>';
            echo '</div>';
            return;
        }
        
        echo '<table class="ald-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Product</th>';
        echo '<th>Customer</th>';
        echo '<th>License Key</th>';
        echo '<th>Date</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($recent_sales as $sale) {
            echo '<tr>';
            echo '<td>' . esc_html($sale->product_name) . '</td>';
            echo '<td>' . esc_html($sale->customer_name) . '</td>';
            echo '<td><code class="ald-license-key">' . esc_html($sale->license_key) . '</code></td>';
            echo '<td>' . date('M j, Y H:i', strtotime($sale->created_at)) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
    }
    
    // WooCommerce Integration
    public function add_license_field() {
        global $post;
        
        if (!$post || $post->post_type !== 'product') {
            return;
        }
        
        echo '<div class="options_group">';
        
        $license_keys = get_post_meta($post->ID, '_ald_license_keys', true);
        $license_keys = is_array($license_keys) ? $license_keys : array();
        
        woocommerce_wp_textarea_input(array(
            'id' => '_ald_license_keys_text',
            'label' => 'License Keys (one per line)',
            'placeholder' => 'Enter license keys, one per line...',
            'desc_tip' => true,
            'description' => 'License keys will be automatically delivered to customers upon order completion.',
            'value' => implode("\n", $license_keys),
            'custom_attributes' => array(
                'rows' => 8,
                'style' => 'font-family: monospace; font-size: 12px;'
            )
        ));
        
        echo '<p class="form-field"><strong>Current Status:</strong> ' . count($license_keys) . ' license keys available</p>';
        echo '</div>';
    }
    
    public function save_license_field($post_id) {
        if (!isset($_POST['_ald_license_keys_text'])) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        $license_keys = sanitize_textarea_field($_POST['_ald_license_keys_text']);
        $keys_array = array_filter(explode("\n", $license_keys));
        $keys_array = array_map('trim', $keys_array);
        $keys_array = array_unique($keys_array);
        
        update_post_meta($post_id, '_ald_license_keys', $keys_array);
    }
    
    public function deliver_license($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $product = wc_get_product($product_id);
            
            if (!$product) {
                continue;
            }
            
            if ($this->is_license_already_sent($order_id, $product_id)) {
                continue;
            }
            
            $license_keys = get_post_meta($product_id, '_ald_license_keys', true);
            $license_keys = is_array($license_keys) ? $license_keys : array();
            
            if (!empty($license_keys)) {
                $license_key = array_shift($license_keys);
                
                update_post_meta($product_id, '_ald_license_keys', $license_keys);
                
                $this->save_delivered_license($order, $product_id, $license_key);
                
                $this->send_license_email($order, $product, $license_key);
                
                $order->add_order_note(
                    sprintf('License key delivered: %s', $license_key),
                    false
                );
                
                $order->update_meta_data('_ald_license_' . $product_id, $license_key);
                $order->save();
            }
        }
    }
    
    private function is_license_already_sent($order_id, $product_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ald_license_history';
        
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE order_id = %s AND product_id = %d",
            $order_id, $product_id
        ));
        
        return !empty($existing);
    }
    
    private function save_delivered_license($order, $product_id, $license_key) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ald_license_history';
        
        return $wpdb->insert(
            $table_name,
            array(
                'order_id' => $order->get_id(),
                'product_id' => $product_id,
                'customer_id' => $order->get_customer_id(),
                'customer_email' => $order->get_billing_email(),
                'license_key' => $license_key,
                'status' => 'sent',
                'created_at' => current_time('mysql')
            ),
            array('%s', '%d', '%d', '%s', '%s', '%s', '%s')
        );
    }
    
    private function send_license_email($order, $product, $license_key) {
        $customer_email = $order->get_billing_email();
        $customer_name = $order->get_billing_first_name();
        $subject = sprintf('[%s] Your License Key for %s', get_bloginfo('name'), $product->get_name());
        
        $message = sprintf('
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <h2 style="color: #333;">🔑 Your License Key</h2>
            <p>Hello %s,</p>
            <p>Thank you for your purchase! Here is your license key for <strong>%s</strong>:</p>
            <div style="background: #f8f9fa; padding: 20px; border: 2px solid #007cba; border-radius: 8px; margin: 20px 0; text-align: center;">
                <code style="font-size: 18px; font-weight: bold; color: #007cba;">%s</code>
            </div>
            <p>You can also view your license keys anytime in your account dashboard.</p>
            <p>Best regards,<br>%s</p>
        </div>
        ', $customer_name, $product->get_name(), $license_key, get_bloginfo('name'));
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($customer_email, $subject, $message, $headers);
    }
    
    // Customer Account
    public function add_account_endpoints() {
        add_rewrite_endpoint('license-keys', EP_ROOT | EP_PAGES);
    }
    
    public function add_account_menu_item($items) {
        $new_items = array();
        foreach ($items as $key => $item) {
            $new_items[$key] = $item;
            if ('downloads' === $key) {
                $new_items['license-keys'] = 'My License Keys';
            }
        }
        return $new_items;
    }
    
    public function license_keys_content() {
        $customer_id = get_current_user_id();
        
        if (!$customer_id) {
            wc_print_notice('Please log in to view your license keys.', 'error');
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'ald_license_history';
        
        $licenses = $wpdb->get_results($wpdb->prepare(
            "SELECT h.*, p.post_title as product_name 
             FROM $table_name h 
             LEFT JOIN {$wpdb->posts} p ON h.product_id = p.ID 
             WHERE h.customer_id = %d 
             ORDER BY h.created_at DESC",
            $customer_id
        ));
        
        echo '<h2>🔑 My License Keys</h2>';
        
        if (empty($licenses)) {
            echo '<div style="text-align: center; padding: 40px; color: #666;">You have no license keys yet.</div>';
            return;
        }
        
        echo '<table class="shop_table shop_table_responsive" style="margin-top: 20px;">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Product Name</th>';
        echo '<th>License Key</th>';
        echo '<th>Purchase Date</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($licenses as $license) {
            echo '<tr>';
            echo '<td><strong>' . esc_html($license->product_name) . '</strong></td>';
            echo '<td><code style="background: #e9ecef; padding: 8px 12px; border-radius: 4px; font-family: monospace;">' . esc_html($license->license_key) . '</code></td>';
            echo '<td>' . date('F j, Y', strtotime($license->created_at)) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
    }
    
    public function display_order_licenses($order) {
        $order_id = $order->get_id();
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'ald_license_history';
        
        $licenses = $wpdb->get_results($wpdb->prepare(
            "SELECT h.*, p.post_title as product_name 
             FROM $table_name h 
             LEFT JOIN {$wpdb->posts} p ON h.product_id = p.ID 
             WHERE h.order_id = %s",
            $order_id
        ));
        
        if (!empty($licenses)) {
            echo '<h2>🔑 License Keys</h2>';
            echo '<table class="woocommerce-table woocommerce-table--order-downloads shop_table shop_table_responsive order_downloads">';
            echo '<thead>';
            echo '<tr>';
            echo '<th>Product</th>';
            echo '<th>License Key</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            
            foreach ($licenses as $license) {
                echo '<tr>';
                echo '<td>' . esc_html($license->product_name) . '</td>';
                echo '<td><code style="background: #f8f9fa; padding: 8px; border-radius: 4px;">' . esc_html($license->license_key) . '</code></td>';
                echo '</tr>';
            }
            
            echo '</tbody>';
            echo '</table>';
        }
    }
    
    public function add_license_to_email($order, $sent_to_admin, $plain_text) {
        if ($sent_to_admin) {
            return;
        }
        
        $order_id = $order->get_id();
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'ald_license_history';
        
        $licenses = $wpdb->get_results($wpdb->prepare(
            "SELECT h.*, p.post_title as product_name 
             FROM $table_name h 
             LEFT JOIN {$wpdb->posts} p ON h.product_id = p.ID 
             WHERE h.order_id = %s",
            $order_id
        ));
        
        if (!empty($licenses)) {
            if ($plain_text) {
                echo "\nLICENSE KEYS:\n";
                echo str_repeat('-', 30) . "\n";
                foreach ($licenses as $license) {
                    echo $license->product_name . ': ' . $license->license_key . "\n";
                }
            } else {
                echo '<h3>License Keys</h3>';
                echo '<table style="width: 100%; border-collapse: collapse;">';
                echo '<thead>';
                echo '<tr>';
                echo '<th style="text-align:left; padding: 12px; border: 1px solid #eee;">Product</th>';
                echo '<th style="text-align:left; padding: 12px; border: 1px solid #eee;">License Key</th>';
                echo '</tr>';
                echo '</thead>';
                echo '<tbody>';
                
                foreach ($licenses as $license) {
                    echo '<tr>';
                    echo '<td style="padding: 12px; border: 1px solid #eee;">' . esc_html($license->product_name) . '</td>';
                    echo '<td style="padding: 12px; border: 1px solid #eee;"><code>' . esc_html($license->license_key) . '</code></td>';
                    echo '</tr>';
                }
                
                echo '</tbody>';
                echo '</table>';
            }
        }
    }
}

// Plugin'i başlat
AutoLicenseDelivery::get_instance();