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
    
    /**
     * Singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        add_action('init', array($this, 'load_textdomain'));
    }
    
    /**
     * Plugin başlatma
     */
    public function init() {
        if (!$this->check_requirements()) {
            return;
        }
        
        // HPOS uyumluluğu
        $this->declare_hpos_compatibility();
        
        // Hook'ları başlat
        $this->init_hooks();
    }
    
    /**
     * Dil dosyalarını yükle
     */
    public function load_textdomain() {
        load_plugin_textdomain('auto-license-delivery', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    /**
     * Gereksinimler kontrolü
     */
    private function check_requirements() {
        // PHP versiyon kontrolü
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            add_action('admin_notices', array($this, 'php_version_notice'));
            return false;
        }
        
        // WooCommerce kontrolü
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return false;
        }
        
        // WooCommerce versiyon kontrolü
        if (defined('WC_VERSION') && version_compare(WC_VERSION, '6.0', '<')) {
            add_action('admin_notices', array($this, 'woocommerce_version_notice'));
            return false;
        }
        
        return true;
    }
    
    /**
     * HPOS uyumluluğu bildirimi
     */
    private function declare_hpos_compatibility() {
        add_action('before_woocommerce_init', function() {
            if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                    'custom_order_tables', 
                    __FILE__, 
                    true
                );
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                    'cart_checkout_blocks', 
                    __FILE__, 
                    true
                );
            }
        });
    }
    
    /**
     * Hook'ları başlat
     */
    private function init_hooks() {
        // Admin hooks
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
            add_action('admin_init', array($this, 'handle_license_activation'));
            add_action('admin_init', array($this, 'handle_admin_actions'));
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'plugin_action_links'));
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
        add_action('wp_ajax_ald_delete_license', array($this, 'ajax_delete_license'));
        
        // Order details
        add_action('woocommerce_order_details_after_order_table', array($this, 'display_order_licenses'));
        add_action('woocommerce_email_order_meta', array($this, 'add_license_to_email'), 10, 3);
    }
    
    /**
     * Plugin aktivasyonu
     */
    public function activate() {
        $this->create_tables();
        $this->create_upload_dir();
        flush_rewrite_rules();
        
        // Aktivasyon versiyonunu kaydet
        update_option('ald_version', ALD_VERSION);
        update_option('ald_activation_time', current_time('mysql'));
    }
    
    /**
     * Plugin deaktivasyonu
     */
    public function deactivate() {
        flush_rewrite_rules();
    }
    
    /**
     * Upload dizini oluştur
     */
    private function create_upload_dir() {
        $upload_dir = wp_upload_dir();
        $ald_dir = $upload_dir['basedir'] . '/auto-license-delivery';
        
        if (!file_exists($ald_dir)) {
            wp_mkdir_p($ald_dir);
            
            // .htaccess dosyası oluştur
            $htaccess_content = "deny from all\n";
            file_put_contents($ald_dir . '/.htaccess', $htaccess_content);
            
            // index.php dosyası oluştur
            $index_content = "<?php\n// Silence is golden\n";
            file_put_contents($ald_dir . '/index.php', $index_content);
        }
    }
    
    /**
     * Veritabanı tablolarını oluştur
     */
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
            download_count int(11) DEFAULT 0,
            ip_address varchar(45) NULL,
            user_agent text NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY product_id (product_id),
            KEY customer_id (customer_id),
            KEY status (status),
            KEY customer_email (customer_email)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Settings tablosu
        $settings_table = $wpdb->prefix . 'ald_settings';
        $sql2 = "CREATE TABLE IF NOT EXISTS $settings_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            setting_key varchar(255) NOT NULL,
            setting_value longtext NULL,
            autoload varchar(20) DEFAULT 'yes',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY setting_key (setting_key)
        ) $charset_collate;";
        
        dbDelta($sql2);
    }
    
    /**
     * PHP versiyon uyarısı
     */
    public function php_version_notice() {
        echo '<div class="error"><p>';
        printf(
            esc_html__('Auto License Delivery requires PHP 7.4 or higher. You are running version %s.', 'auto-license-delivery'),
            PHP_VERSION
        );
        echo '</p></div>';
    }
    
    /**
     * WooCommerce eksik uyarısı
     */
    public function woocommerce_missing_notice() {
        echo '<div class="error"><p>';
        printf(
            wp_kses_post(__('Auto License Delivery requires WooCommerce to be installed and active. You can download %s here.', 'auto-license-delivery')),
            '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>'
        );
        echo '</p></div>';
    }
    
    /**
     * WooCommerce versiyon uyarısı
     */
    public function woocommerce_version_notice() {
        echo '<div class="error"><p>';
        printf(
            esc_html__('Auto License Delivery requires WooCommerce 6.0 or higher. You are running version %s.', 'auto-license-delivery'),
            defined('WC_VERSION') ? WC_VERSION : 'Unknown'
        );
        echo '</p></div>';
    }
    
    /**
     * Plugin action links
     */
    public function plugin_action_links($links) {
        $action_links = array(
            'settings' => '<a href="' . admin_url('admin.php?page=auto-license-delivery') . '">' . esc_html__('Settings', 'auto-license-delivery') . '</a>',
        );
        
        return array_merge($action_links, $links);
    }
    
    /**
     * Admin menüsü ekle
     */
    public function add_admin_menu() {
        add_menu_page(
            esc_html__('License Keys Management', 'auto-license-delivery'),
            esc_html__('License Keys', 'auto-license-delivery'),
            'manage_woocommerce',
            'auto-license-delivery',
            array($this, 'admin_page'),
            'dashicons-admin-network',
            56
        );
        
        // Alt menüler
        add_submenu_page(
            'auto-license-delivery',
            esc_html__('Dashboard', 'auto-license-delivery'),
            esc_html__('Dashboard', 'auto-license-delivery'),
            'manage_woocommerce',
            'auto-license-delivery',
            array($this, 'admin_page')
        );
        
        add_submenu_page(
            'auto-license-delivery',
            esc_html__('All Licenses', 'auto-license-delivery'),
            esc_html__('All Licenses', 'auto-license-delivery'),
            'manage_woocommerce',
            'auto-license-delivery-all',
            array($this, 'all_licenses_page')
        );
        
        add_submenu_page(
            'auto-license-delivery',
            esc_html__('Settings', 'auto-license-delivery'),
            esc_html__('Settings', 'auto-license-delivery'),
            'manage_woocommerce',
            'auto-license-delivery-settings',
            array($this, 'settings_page')
        );
    }
    
    /**
     * Admin scripts ve styles
     */
    public function admin_scripts($hook) {
        if (strpos($hook, 'auto-license-delivery') === false) {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '3.9.1', true);
        wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', array(), '11.0.0', true);
        
        // Inline CSS for modern design
        wp_add_inline_style('wp-admin', $this->get_admin_css());
        
        // Inline JS for functionality
        wp_add_inline_script('jquery', $this->get_admin_js());
        
        wp_localize_script('jquery', 'ald_ajax', array(
            'url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ald_admin_nonce'),
            'strings' => array(
                'loading' => esc_html__('Loading...', 'auto-license-delivery'),
                'success' => esc_html__('Success!', 'auto-license-delivery'),
                'error' => esc_html__('Error occurred!', 'auto-license-delivery'),
                'confirm_delete' => esc_html__('Are you sure you want to delete this?', 'auto-license-delivery'),
                'license_saved' => esc_html__('License keys saved successfully!', 'auto-license-delivery'),
                'select_product' => esc_html__('Please select a product!', 'auto-license-delivery'),
            )
        ));
    }
    
    /**
     * Admin CSS
     */
    private function get_admin_css() {
        return '
        .ald-dashboard {
            background: #f8f9fa;
            padding: 20px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            min-height: calc(100vh - 32px);
        }
        .ald-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .ald-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }
        .ald-card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 25px;
            font-size: 18px;
            font-weight: 600;
            position: relative;
            overflow: hidden;
        }
        .ald-card-header::before {
            content: "";
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }
        .ald-card:hover .ald-card-header::before {
            left: 100%;
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
            transition: all 0.3s ease;
            border-top: 4px solid transparent;
        }
        .ald-stat-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        .ald-stat-card.primary { border-top-color: #667eea; }
        .ald-stat-card.success { border-top-color: #28a745; }
        .ald-stat-card.warning { border-top-color: #ffc107; }
        .ald-stat-card.danger { border-top-color: #dc3545; }
        
        .ald-stat-number {
            font-size: 42px;
            font-weight: bold;
            margin-bottom: 10px;
            background: linear-gradient(45deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .ald-stat-label {
            color: #666;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 500;
        }
        .ald-primary { color: #667eea; }
        .ald-success { color: #28a745; }
        .ald-warning { color: #ffc107; }
        .ald-danger { color: #dc3545; }
        
        .ald-form-group {
            margin-bottom: 25px;
        }
        .ald-form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }
        .ald-form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            font-family: inherit;
        }
        .ald-form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            transform: translateY(-1px);
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
            font-size: 14px;
            position: relative;
            overflow: hidden;
        }
        .ald-btn:before {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }
        .ald-btn:hover:before {
            width: 300px;
            height: 300px;
        }
        .ald-btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        .ald-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }
        .ald-btn-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }
        .ald-btn-danger {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3);
        }
        
        .ald-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .ald-table th,
        .ald-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .ald-table th {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            font-weight: 600;
            color: #333;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .ald-table tbody tr {
            transition: background-color 0.3s ease;
        }
        .ald-table tbody tr:hover {
            background: #f8f9fa;
        }
        
        .ald-license-key {
            font-family: "Courier New", monospace;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid #ddd;
            font-size: 13px;
            font-weight: 500;
        }
        
        .ald-progress {
            background: #e9ecef;
            border-radius: 10px;
            height: 20px;
            overflow: hidden;
            margin-top: 10px;
            position: relative;
        }
        .ald-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #28a745, #20c997);
            transition: width 0.6s ease;
            position: relative;
        }
        .ald-progress-bar::after {
            content: "";
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: progress-shine 2s infinite;
        }
        
        @keyframes progress-shine {
            0% { left: -100%; }
            100% { left: 100%; }
        }
        
        .ald-badge {
            display: inline-block;
            padding: 4px 8px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            border-radius: 4px;
            letter-spacing: 0.5px;
        }
        .ald-badge-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .ald-badge-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        .ald-badge-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .ald-loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .ald-welcome-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .ald-welcome-banner::before {
            content: "";
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: float 6s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }
        
        @media (max-width: 768px) {
            .ald-stats-grid {
                grid-template-columns: 1fr;
            }
            .ald-card-body {
                padding: 15px;
            }
            .ald-dashboard {
                padding: 10px;
            }
            .ald-table {
                font-size: 12px;
            }
            .ald-table th,
            .ald-table td {
                padding: 10px 8px;
            }
        }
        
        /* Tooltip styles */
        .ald-tooltip {
            position: absolute;
            background: #333;
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            z-index: 9999;
            max-width: 200px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        .ald-tooltip::after {
            content: "";
            position: absolute;
            top: 100%;
            left: 50%;
            margin-left: -5px;
            border-width: 5px;
            border-style: solid;
            border-color: #333 transparent transparent transparent;
        }
        
        /* Chart container */
        .ald-chart-container {
            position: relative;
            height: 300px;
            margin: 20px 0;
        }
        
        ';
    }
    
    /**
     * Admin JavaScript
     */
    private function get_admin_js() {
        return '
        jQuery(document).ready(function($) {
            // Sayaç animasyonu
            $(".ald-stat-number").each(function() {
                var $this = $(this);
                var target = parseInt($this.text()) || 0;
                $({ count: 0 }).animate({ count: target }, {
                    duration: 2000,
                    easing: "swing",
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
                    $("#license_stats").html("<div class=\"ald-loading\"></div> Loading stats...");
                    
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
                            } else {
                                $("#license_stats").html("<p style=\"color: red;\">Error loading stats</p>");
                            }
                        },
                        error: function() {
                            $("#license_stats").html("<p style=\"color: red;\">Connection error</p>");
                        }
                    });
                } else {
                    $("#license_stats").html("");
                    $("#license_keys_textarea").val("");
                }
            });
            
            // Lisans anahtarlarını kaydet
            $("#save_license_keys").click(function() {
                var $btn = $(this);
                var productId = $("#product_select").val();
                var licenseKeys = $("#license_keys_textarea").val();
                
                if (!productId) {
                    Swal.fire({
                        icon: "warning",
                        title: "Warning",
                        text: ald_ajax.strings.select_product
                    });
                    return;
                }
                
                $btn.prop("disabled", true).html("<span class=\"ald-loading\"></span> Saving...");
                
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
                            Swal.fire({
                                icon: "success",
                                title: "Success!",
                                text: ald_ajax.strings.license_saved,
                                timer: 2000,
                                showConfirmButton: false
                            });
                            $("#product_select").trigger("change");
                        } else {
                            Swal.fire({
                                icon: "error",
                                title: "Error",
                                text: response.data || "An error occurred"
                            });
                        }
                    },
                    error: function() {
                        Swal.fire({
                            icon: "error",
                            title: "Error",
                            text: "Connection error occurred"
                        });
                    },
                    complete: function() {
                        $btn.prop("disabled", false).html("💾 Save License Keys");
                    }
                });
            });
            
            // Tooltip functionality
            $("[data-tooltip]").hover(function(e) {
                var tooltip = $("<div class=\"ald-tooltip\">" + $(this).data("tooltip") + "</div>");
                $("body").append(tooltip);
                
                var offset = $(this).offset();
                tooltip.css({
                    top: offset.top - tooltip.outerHeight() - 10,
                    left: offset.left + ($(this).outerWidth() / 2) - (tooltip.outerWidth() / 2)
                });
            }, function() {
                $(".ald-tooltip").remove();
            });
            
            // Copy to clipboard functionality
            $(document).on("click", ".ald-copy-license", function() {
                var licenseKey = $(this).data("license");
                navigator.clipboard.writeText(licenseKey).then(function() {
                    Swal.fire({
                        icon: "success",
                        title: "Copied!",
                        text: "License key copied to clipboard",
                        timer: 1500,
                        showConfirmButton: false
                    });
                });
            });
            
            // Delete license functionality
            $(document).on("click", ".ald-delete-license", function() {
                var licenseId = $(this).data("license-id");
                var $row = $(this).closest("tr");
                
                Swal.fire({
                    title: "Are you sure?",
                    text: "This action cannot be undone!",
                    icon: "warning",
                    showCancelButton: true,
                    confirmButtonColor: "#dc3545",
                    cancelButtonColor: "#6c757d",
                    confirmButtonText: "Yes, delete it!"
                }).then((result) => {
                    if (result.isConfirmed) {
                        $.ajax({
                            url: ald_ajax.url,
                            type: "POST",
                            data: {
                                action: "ald_delete_license",
                                license_id: licenseId,
                                nonce: ald_ajax.nonce
                            },
                            success: function(response) {
                                if (response.success) {
                                    $row.fadeOut(300, function() {
                                        $(this).remove();
                                    });
                                    Swal.fire("Deleted!", "License has been deleted.", "success");
                                } else {
                                    Swal.fire("Error!", response.data || "An error occurred", "error");
                                }
                            }
                        });
                    }
                });
            });
            
            // Progress bar animation
            $(".ald-progress-bar").each(function() {
                var width = $(this).data("width") || 0;
                $(this).animate({ width: width + "%" }, 1500);
            });
            
            // Auto-refresh stats every 30 seconds
            setInterval(function() {
                if ($("#product_select").val()) {
                    $("#product_select").trigger("change");
                }
            }, 30000);
        });
        ';
    }
}

// Include additional files
require_once ALD_PLUGIN_DIR . 'includes/functions.php';
require_once ALD_PLUGIN_DIR . 'includes/woocommerce.php';

// Extend main class with additional functionality
class AutoLicenseDelivery_Enhanced extends AutoLicenseDelivery_Functions {
    use AutoLicenseDelivery_WooCommerce;
}

// Override the main class
class AutoLicenseDelivery extends AutoLicenseDelivery_Enhanced {
    // Class is now complete with all functionality
}

// Plugin'i başlat
AutoLicenseDelivery::get_instance();