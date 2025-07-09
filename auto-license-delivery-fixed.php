<?php
/**
 * Plugin Name: WooCommerce Auto License Delivery - Enhanced
 * Description: Modern, güvenli ve şık lisans anahtarı teslim sistemi
 * Version: 2.1.0
 * Author: Enhanced by AI
 * Text Domain: auto-license-delivery
 * WC requires at least: 6.0
 * WC tested up to: 8.4
 */

if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

// Plugin sabitleri
define('ALD_VERSION', '2.1.0');
define('ALD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ALD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ALD_LICENSE_KEY', hash('sha256', 'WİO-4142-1544-1151-4441'));

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
    }
    
    public function init() {
        if (!$this->check_requirements()) {
            return;
        }
        
        load_plugin_textdomain('auto-license-delivery', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // HPOS uyumluluğu
        add_action('before_woocommerce_init', function() {
            if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            }
        });
        
        $this->init_hooks();
    }
    
    private function check_requirements() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>Auto License Delivery requires WooCommerce to be installed.</p></div>';
            });
            return false;
        }
        return true;
    }
    
    private function init_hooks() {
        // Admin hooks
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
            add_action('admin_init', array($this, 'handle_license_activation'));
            add_action('admin_init', array($this, 'handle_admin_actions'));
        }
        
        // WooCommerce hooks
        add_action('woocommerce_product_options_general_product_data', array($this, 'add_license_field'));
        add_action('woocommerce_process_product_meta', array($this, 'save_license_field'));
        add_action('woocommerce_order_status_completed', array($this, 'deliver_license'));
        add_action('woocommerce_order_status_processing', array($this, 'deliver_license'));
        
        // My Account hooks
        add_filter('woocommerce_account_menu_items', array($this, 'add_account_menu_item'));
        add_action('init', array($this, 'add_account_endpoints'));
        add_action('woocommerce_account_license-keys_endpoint', array($this, 'license_keys_content'));
        
        // AJAX hooks
        add_action('wp_ajax_ald_get_license_stats', array($this, 'ajax_get_license_stats'));
        add_action('wp_ajax_ald_save_license_keys', array($this, 'ajax_save_license_keys'));
        add_action('wp_ajax_ald_send_manual_license', array($this, 'ajax_send_manual_license'));
        
        // Order details
        add_action('woocommerce_order_details_after_order_table', array($this, 'display_order_licenses'));
    }
    
    public function activate() {
        $this->create_tables();
        flush_rewrite_rules();
    }
    
    private function create_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'ald_license_history';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id varchar(50) NOT NULL,
            product_id bigint(20) NOT NULL,
            customer_id bigint(20) NOT NULL,
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
        if ('toplevel_page_auto-license-delivery' !== $hook) {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '3.9.1', true);
        
        // Inline CSS for modern design
        wp_add_inline_style('wp-admin', $this->get_admin_css());
        
        // Inline JS for functionality
        wp_add_inline_script('jquery', $this->get_admin_js());
        
        wp_localize_script('jquery', 'ald_ajax', array(
            'url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ald_admin_nonce')
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
            padding: 20px;
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
        }
        .ald-stat-label {
            color: #666;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .ald-primary { color: #667eea; }
        .ald-success { color: #28a745; }
        .ald-warning { color: #ffc107; }
        .ald-danger { color: #dc3545; }
        
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
        .ald-btn-success {
            background: #28a745;
            color: white;
        }
        .ald-btn-danger {
            background: #dc3545;
            color: white;
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
        
        .ald-alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .ald-alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .ald-alert-danger {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        
        .ald-progress {
            background: #e9ecef;
            border-radius: 10px;
            height: 20px;
            overflow: hidden;
            margin-top: 10px;
        }
        .ald-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #28a745, #20c997);
            transition: width 0.3s ease;
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
            // Sayaç animasyonu
            $(".ald-stat-number").each(function() {
                var $this = $(this);
                var target = parseInt($this.text());
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
            
            // Ürün seçildiğinde lisans istatistiklerini getir
            $("#product_select").change(function() {
                var productId = $(this).val();
                if (productId) {
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
                                $("#license_stats").html(response.data);
                                $("#license_keys_textarea").val(response.data.remaining_keys.join("\\n"));
                            }
                        }
                    });
                }
            });
            
            // Lisans anahtarlarını kaydet
            $("#save_license_keys").click(function() {
                var productId = $("#product_select").val();
                var licenseKeys = $("#license_keys_textarea").val();
                
                if (!productId) {
                    alert("Lütfen bir ürün seçin!");
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
                            alert("Lisans anahtarları başarıyla kaydedildi!");
                            $("#product_select").trigger("change");
                        } else {
                            alert("Hata: " + response.data);
                        }
                    }
                });
            });
            
            // Tooltip
            $("[data-tooltip]").hover(function() {
                var tooltip = $("<div class=\"ald-tooltip\">" + $(this).data("tooltip") + "</div>");
                $("body").append(tooltip);
                tooltip.css({
                    position: "absolute",
                    top: $(this).offset().top - 35,
                    left: $(this).offset().left,
                    background: "#333",
                    color: "white",
                    padding: "5px 10px",
                    borderRadius: "4px",
                    fontSize: "12px",
                    zIndex: 9999
                });
            }, function() {
                $(".ald-tooltip").remove();
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
            $this->admin_notice('License activated successfully!', 'success');
        } else {
            $this->admin_notice('Invalid license key!', 'error');
        }
    }
    
    public function handle_admin_actions() {
        // Manuel lisans gönderimi vb. işlemler buraya
    }
    
    // AJAX: Lisans istatistiklerini getir
    public function ajax_get_license_stats() {
        check_ajax_referer('ald_admin_nonce', 'nonce');
        
        $product_id = intval($_POST['product_id']);
        $stats = $this->get_product_license_stats($product_id);
        
        wp_send_json_success($stats);
    }
    
    // AJAX: Lisans anahtarlarını kaydet
    public function ajax_save_license_keys() {
        check_ajax_referer('ald_admin_nonce', 'nonce');
        
        $product_id = intval($_POST['product_id']);
        $license_keys = sanitize_textarea_field($_POST['license_keys']);
        
        $keys_array = array_filter(explode("\n", $license_keys));
        $keys_array = array_map('trim', $keys_array);
        
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
        
        $sold_licenses = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE product_id = %d ORDER BY created_at DESC",
            $product_id
        ));
        
        return array(
            'total_keys' => count($license_keys),
            'remaining_keys' => $license_keys,
            'sold_count' => $sold_count,
            'sold_licenses' => $sold_licenses
        );
    }
    
    private function admin_notice($message, $type = 'success') {
        add_action('admin_notices', function() use ($message, $type) {
            echo '<div class="notice notice-' . $type . '"><p>' . esc_html($message) . '</p></div>';
        });
    }
    
    // Admin Sayfa İçeriği
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
                        <button type="submit" name="ald_activate_license" class="ald-btn ald-btn-primary">
                            Activate License
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function main_admin_page() {
        // Genel istatistikler
        $total_products = $this->get_total_products_with_licenses();
        $total_licenses = $this->get_total_licenses();
        $total_sold = $this->get_total_sold_licenses();
        $products = $this->get_products_with_licenses();
        
        ?>
        <div class="ald-dashboard">
            <h1 style="color: #333; margin-bottom: 30px;">📊 License Keys Dashboard</h1>
            
            <!-- İstatistik Kartları -->
            <div class="ald-stats-grid">
                <div class="ald-stat-card">
                    <div class="ald-stat-number ald-primary"><?php echo $total_products; ?></div>
                    <div class="ald-stat-label">Products with Licenses</div>
                </div>
                <div class="ald-stat-card">
                    <div class="ald-stat-number ald-success"><?php echo $total_licenses; ?></div>
                    <div class="ald-stat-label">Total License Keys</div>
                </div>
                <div class="ald-stat-card">
                    <div class="ald-stat-number ald-warning"><?php echo $total_sold; ?></div>
                    <div class="ald-stat-label">Sold Licenses</div>
                </div>
                <div class="ald-stat-card">
                    <div class="ald-stat-number ald-danger"><?php echo ($total_licenses - $total_sold); ?></div>
                    <div class="ald-stat-label">Remaining Licenses</div>
                </div>
            </div>
            
            <!-- Lisans Yönetimi -->
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
                                <option value="<?php echo $product->ID; ?>"><?php echo $product->post_title; ?></option>
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
            
            <!-- Son Satışlar -->
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
            SELECT DISTINCT p.ID, p.post_title 
            FROM {$wpdb->posts} p 
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
            WHERE p.post_type = 'product' 
            AND pm.meta_key = '_ald_license_keys'
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
            echo '<p>No license sales yet.</p>';
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
            echo '<td><span class="ald-license-key">' . esc_html($sale->license_key) . '</span></td>';
            echo '<td>' . date('Y-m-d H:i', strtotime($sale->created_at)) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
    }
    
    // Ürün sayfasına lisans alanı ekle
    public function add_license_field() {
        global $post;
        
        echo '<div class="options_group">';
        
        $license_keys = get_post_meta($post->ID, '_ald_license_keys', true);
        $license_keys = is_array($license_keys) ? $license_keys : array();
        
        woocommerce_wp_textarea_input(array(
            'id' => '_ald_license_keys_text',
            'label' => 'License Keys (one per line)',
            'placeholder' => 'Enter license keys, one per line...',
            'desc_tip' => true,
            'description' => 'License keys will be automatically delivered to customers.',
            'value' => implode("\n", $license_keys)
        ));
        
        echo '<p class="form-field"><strong>Current Status:</strong> ' . count($license_keys) . ' license keys available</p>';
        echo '</div>';
    }
    
    public function save_license_field($post_id) {
        if (isset($_POST['_ald_license_keys_text'])) {
            $license_keys = sanitize_textarea_field($_POST['_ald_license_keys_text']);
            $keys_array = array_filter(explode("\n", $license_keys));
            $keys_array = array_map('trim', $keys_array);
            update_post_meta($post_id, '_ald_license_keys', $keys_array);
        }
    }
    
    // Lisans teslimatı
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
            
            // Zaten gönderilmiş mi?
            global $wpdb;
            $table_name = $wpdb->prefix . 'ald_license_history';
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_name WHERE order_id = %s AND product_id = %d",
                $order_id, $product_id
            ));
            
            if ($existing) {
                continue;
            }
            
            $license_keys = get_post_meta($product_id, '_ald_license_keys', true);
            $license_keys = is_array($license_keys) ? $license_keys : array();
            
            if (!empty($license_keys)) {
                $license_key = array_shift($license_keys);
                
                // Kullanılan anahtarı kaldır
                update_post_meta($product_id, '_ald_license_keys', $license_keys);
                
                // Veritabanına kaydet
                $wpdb->insert(
                    $table_name,
                    array(
                        'order_id' => $order_id,
                        'product_id' => $product_id,
                        'customer_id' => $order->get_customer_id(),
                        'license_key' => $license_key,
                        'status' => 'sent',
                        'created_at' => current_time('mysql')
                    ),
                    array('%s', '%d', '%d', '%s', '%s', '%s')
                );
                
                // E-posta gönder
                $this->send_license_email($order, $product, $license_key);
                
                // Sipariş notuna ekle
                $order->add_order_note(
                    sprintf('License key delivered: %s', $license_key),
                    false
                );
            }
        }
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
    
    // Müşteri hesabı
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
        
        echo '<style>
        .license-keys-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        .license-keys-table th,
        .license-keys-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .license-keys-table th {
            background: #f8f9fa;
            font-weight: bold;
        }
        .license-key-code {
            font-family: monospace;
            background: #e9ecef;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 14px;
            border: 1px solid #ced4da;
        }
        .no-licenses {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        </style>';
        
        echo '<h2>🔑 My License Keys</h2>';
        
        if (empty($licenses)) {
            echo '<div class="no-licenses">You have no license keys yet.</div>';
            return;
        }
        
        echo '<table class="license-keys-table">';
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
            echo '<td><code class="license-key-code">' . esc_html($license->license_key) . '</code></td>';
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
}

// Plugin'i başlat
AutoLicenseDelivery::get_instance();