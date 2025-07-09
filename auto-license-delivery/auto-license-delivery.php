<?php
/**
 * Plugin Name: WooCommerce Otomatik Lisans Teslimatı - BERAT K Geliştirme
 * Plugin URI: https://wa.me/905395115632
 * Description: Modern, güvenli ve şık lisans anahtarı teslim sistemi. WooCommerce siparişleri tamamlandığında otomatik olarak lisans anahtarları müşterilere gönderilir. Geliştirici: BERAT K - 0539 511 56 32
 * Version: 2.1.0
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
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Network: false
 * Developer: BERAT K - WhatsApp: +90 539 511 56 32
 * Support: https://wa.me/905395115632
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
define('ALD_LICENSE_KEY', hash('sha256', 'BERAT-K-DEVELOPER-LICENSE-KEY'));
// Geliştirici: BERAT K - 0539 511 56 32 - WhatsApp: https://wa.me/905395115632

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
        add_action('wp_ajax_ald_get_pending_customers', array($this, 'ajax_get_pending_customers'));
        add_action('wp_ajax_ald_process_pending_customers', array($this, 'ajax_process_pending_customers'));
        
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
            license_key varchar(255) DEFAULT '',
            status varchar(20) DEFAULT 'sent',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY product_id (product_id),
            KEY customer_id (customer_id),
            KEY status (status)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public function php_version_notice() {
        echo '<div class="error"><p>Otomatik Lisans Teslimatı PHP 7.4 veya üzeri gerektirir. Şu anda ' . PHP_VERSION . ' kullanıyorsunuz. Geliştirici: BERAT K - 0539 511 56 32</p></div>';
    }
    
    public function woocommerce_missing_notice() {
        echo '<div class="error"><p>Otomatik Lisans Teslimatı WooCommerce\'in yüklü ve aktif olmasını gerektirir. Destek: BERAT K - WhatsApp ile iletişime geçin.</p></div>';
    }
    
    public function woocommerce_version_notice() {
        echo '<div class="error"><p>Otomatik Lisans Teslimatı WooCommerce 6.0 veya üzeri gerektirir. İletişim: BERAT K - 0539 511 56 32</p></div>';
    }
    
    public function plugin_action_links($links) {
        $action_links = array(
            'settings' => '<a href="' . admin_url('admin.php?page=auto-license-delivery') . '">Ayarlar</a>',
            'developer' => '<a href="https://wa.me/905395115632" target="_blank">👨‍💻 BERAT K - Geliştirici</a>',
        );
        return array_merge($action_links, $links);
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Lisans Anahtarı Yönetimi - BERAT K Geliştirme',
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
        wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', array(), '11.0.0', true);
        
        wp_add_inline_style('wp-admin', $this->get_admin_css());
        wp_add_inline_script('jquery', $this->get_admin_js());
        
        wp_localize_script('jquery', 'ald_ajax', array(
            'url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ald_admin_nonce'),
            'strings' => array(
                'loading' => 'Yükleniyor...',
                'success' => 'Başarılı!',
                'error' => 'Hata oluştu! Destek: BERAT K - 0539 511 56 32',
                'license_saved' => 'Lisans anahtarları başarıyla kaydedildi!',
                'select_product' => 'Lütfen bir ürün seçin!',
                'developer' => 'Geliştirici: BERAT K - WhatsApp: +90 539 511 56 32'
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
             
             // Bekleyen müşteriler yönetimi
             $("#refresh_pending_customers").click(function() {
                 $("#pending_customers_list").html("<div style=\'text-align: center; padding: 20px;\'>⏳ Yükleniyor...</div>");
                 
                 $.ajax({
                     url: ald_ajax.url,
                     type: "POST",
                     data: {
                         action: "ald_get_pending_customers",
                         nonce: ald_ajax.nonce
                     },
                     success: function(response) {
                         if (response.success) {
                             $("#pending_customers_list").html(response.data.html);
                             $("#pending_count_badge").text(response.data.count + " bekleyen");
                             
                             // Checkbox event handlers
                             $("#select_all_pending").change(function() {
                                 $(".pending-customer-checkbox").prop("checked", this.checked);
                             });
                             
                             $("#process_selected_pending").click(function() {
                                 var selectedIds = [];
                                 $(".pending-customer-checkbox:checked").each(function() {
                                     selectedIds.push($(this).val());
                                 });
                                 
                                 if (selectedIds.length === 0) {
                                     Swal.fire("Uyarı", "Lütfen en az bir müşteri seçin!", "warning");
                                     return;
                                 }
                                 
                                 Swal.fire({
                                     title: "Emin misiniz?",
                                     text: selectedIds.length + " müşteriye lisans anahtarı gönderilecek",
                                     icon: "question",
                                     showCancelButton: true,
                                     confirmButtonText: "Evet, Gönder",
                                     cancelButtonText: "İptal"
                                 }).then((result) => {
                                     if (result.isConfirmed) {
                                         $.ajax({
                                             url: ald_ajax.url,
                                             type: "POST",
                                             data: {
                                                 action: "ald_process_pending_customers",
                                                 customer_ids: selectedIds,
                                                 nonce: ald_ajax.nonce
                                             },
                                             success: function(response) {
                                                 if (response.success) {
                                                     Swal.fire("Başarılı!", response.data, "success");
                                                     $("#refresh_pending_customers").click(); // Listeyi yenile
                                                 } else {
                                                     Swal.fire("Hata", response.data, "error");
                                                 }
                                             }
                                         });
                                     }
                                 });
                             });
                             
                             $("#refresh_pending_list").click(function() {
                                 $("#refresh_pending_customers").click();
                             });
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
                echo '<div class="notice notice-success"><p>🎉 Lisans başarıyla etkinleştirildi! Geliştirici: BERAT K - 0539 511 56 32</p></div>';
            });
        } else {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>❌ Geçersiz lisans anahtarı! Doğru anahtar için BERAT K ile iletişime geçin: WhatsApp +90 539 511 56 32</p></div>';
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
        
        // Bekleyen müşterileri kontrol et ve otomatik gönder
        $this->auto_process_pending_customers($product_id);
        
        wp_send_json_success('Lisans anahtarları başarıyla kaydedildi! 🎉 Bekleyen müşterilere otomatik gönderim yapıldı. Geliştirici: BERAT K');
    }
    
    public function ajax_get_pending_customers() {
        check_ajax_referer('ald_admin_nonce', 'nonce');
        
        $pending_customers = $this->get_pending_customers();
        $html = $this->generate_pending_customers_html($pending_customers);
        
        wp_send_json_success(array(
            'html' => $html,
            'count' => count($pending_customers)
        ));
    }
    
    public function ajax_process_pending_customers() {
        check_ajax_referer('ald_admin_nonce', 'nonce');
        
        $customer_ids = isset($_POST['customer_ids']) ? array_map('intval', $_POST['customer_ids']) : array();
        
        if (empty($customer_ids)) {
            wp_send_json_error('Müşteri seçilmedi!');
        }
        
        $processed = $this->process_selected_pending_customers($customer_ids);
        
        wp_send_json_success("$processed müşteriye lisans anahtarı gönderildi! 🎉 Geliştirici: BERAT K");
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
                    <div class="ald-stat-label">Mevcut Anahtarlar</div>
                </div>
                <div class="ald-stat-card">
                    <div class="ald-stat-number"><?php echo $stats['sold_count']; ?></div>
                    <div class="ald-stat-label">Satılan Anahtarlar</div>
                </div>
                <div class="ald-stat-card">
                    <div class="ald-stat-number"><?php echo $stats['usage_percentage']; ?>%</div>
                    <div class="ald-stat-label">Kullanım Oranı</div>
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
                    🔐 Lisans Etkinleştirme Gerekli
                </div>
                <div class="ald-card-body">
                    <p>Plugin'i etkinleştirmek için lütfen lisans anahtarınızı girin:</p>
                    <div style="background: #e3f2fd; padding: 15px; border-radius: 8px; margin: 15px 0; text-align: center;">
                        <strong>👨‍💻 Geliştirici: BERAT K</strong><br>
                        📱 <a href="https://wa.me/905395115632" target="_blank">WhatsApp: 0539 511 56 32</a>
                    </div>
                    <form method="post" action="">
                        <?php wp_nonce_field('ald_activate_license', 'ald_nonce'); ?>
                        <div class="ald-form-group">
                            <label class="ald-form-label">Lisans Anahtarı:</label>
                            <input type="text" name="license_key" class="ald-form-control" placeholder="BERAT-K-DEVELOPER-LICENSE-KEY" required>
                        </div>
                        <button type="submit" name="ald_activate_license" class="ald-btn ald-btn-primary" style="width: 100%;">
                            🚀 Lisansı Etkinleştir
                        </button>
                    </form>
                    <p style="font-size: 12px; color: #666; text-align: center; margin-top: 15px;">
                        Lisans anahtarı için BERAT K ile iletişime geçin
                    </p>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function main_admin_page() {
        $total_products = $this->get_total_products_with_licenses();
        $total_licenses = $this->get_total_licenses();
        $total_sold = $this->get_total_sold_licenses();
        $total_pending = $this->get_total_pending_licenses();
        $products = $this->get_products_with_licenses();
        
        ?>
        <div class="ald-dashboard">
            <h1 style="color: #333; margin-bottom: 30px;">📊 Lisans Anahtarları Paneli - BERAT K Geliştirme</h1>
            
            <div class="ald-stats-grid">
                 <div class="ald-stat-card">
                     <div class="ald-stat-number"><?php echo $total_products; ?></div>
                     <div class="ald-stat-label">Lisanslı Ürünler</div>
                 </div>
                 <div class="ald-stat-card">
                     <div class="ald-stat-number"><?php echo $total_licenses; ?></div>
                     <div class="ald-stat-label">Toplam Lisans Anahtarı</div>
                 </div>
                <div class="ald-stat-card">
                    <div class="ald-stat-number"><?php echo $total_sold; ?></div>
                    <div class="ald-stat-label">Satılan Lisanslar</div>
                </div>
                <div class="ald-stat-card" style="<?php echo $total_pending > 0 ? 'background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%); color: white;' : ''; ?>">
                    <div class="ald-stat-number" style="<?php echo $total_pending > 0 ? 'color: white;' : '#667eea'; ?>"><?php echo $total_pending; ?></div>
                    <div class="ald-stat-label" style="<?php echo $total_pending > 0 ? 'color: rgba(255,255,255,0.8);' : ''; ?>">⏳ Bekleyen Müşteriler</div>
                </div>
                <div class="ald-stat-card">
                    <div class="ald-stat-number"><?php echo ($total_licenses - $total_sold); ?></div>
                    <div class="ald-stat-label">Kalan Lisanslar</div>
                </div>
            </div>
            
            <div class="ald-card">
                <div class="ald-card-header">
                    🔑 Lisans Anahtarı Yönetimi
                </div>
                <div class="ald-card-body">
                    <div class="ald-form-group">
                        <label class="ald-form-label">Ürün Seçin:</label>
                        <select id="product_select" class="ald-form-control">
                            <option value="">Bir ürün seçin...</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?php echo $product->ID; ?>"><?php echo esc_html($product->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div id="license_stats"></div>
                    
                    <div class="ald-form-group">
                        <label class="ald-form-label">Lisans Anahtarları (her satırda bir tane):</label>
                        <textarea id="license_keys_textarea" class="ald-form-control" rows="10" placeholder="Lisans anahtarlarını girin, her satırda bir tane..."></textarea>
                    </div>
                    
                    <button id="save_license_keys" class="ald-btn ald-btn-primary">
                        💾 Lisans Anahtarlarını Kaydet
                    </button>
                    
                    <div style="margin-top: 15px; padding: 10px; background: #f0f8ff; border-radius: 8px; text-align: center;">
                        <small>👨‍💻 <strong>Geliştirici:</strong> BERAT K - 📱 <a href="https://wa.me/905395115632">0539 511 56 32</a></small>
                    </div>
                </div>
            </div>
            
            <div class="ald-card">
                <div class="ald-card-header">
                    ⏳ Bekleyen Müşteriler (Lisans Bekleniyor)
                </div>
                <div class="ald-card-body">
                    <div style="margin-bottom: 15px;">
                        <button id="refresh_pending_customers" class="ald-btn ald-btn-primary">🔄 Bekleyen Müşterileri Yükle</button>
                        <span id="pending_count_badge" style="background: #ff9800; color: white; padding: 5px 10px; border-radius: 15px; margin-left: 10px; font-size: 12px;">0 bekleyen</span>
                    </div>
                    <div id="pending_customers_list">
                        <div style="text-align: center; padding: 20px; color: #666;">
                            Bekleyen müşterileri görmek için yukarıdaki butona tıklayın
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="ald-card">
                <div class="ald-card-header">
                    📈 Son Lisans Satışları
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
        return $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'sent'");
    }
    
    private function get_total_pending_licenses() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ald_license_history';
        return $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'pending'");
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
    
    private function get_pending_customers() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ald_license_history';
        
        return $wpdb->get_results("
            SELECT h.*, p.post_title as product_name, u.display_name as customer_name
            FROM $table_name h
            LEFT JOIN {$wpdb->posts} p ON h.product_id = p.ID
            LEFT JOIN {$wpdb->users} u ON h.customer_id = u.ID
            WHERE h.status = 'pending'
            ORDER BY h.created_at ASC
        ");
    }
    
    private function generate_pending_customers_html($pending_customers) {
        if (empty($pending_customers)) {
            return '<div style="text-align: center; padding: 40px; color: #666;">
                        <p>🎉 Bekleyen müşteri yok!</p>
                        <p>Tüm müşterilere lisans anahtarları teslim edilmiş.</p>
                    </div>';
        }
        
        ob_start();
        echo '<table class="ald-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th><input type="checkbox" id="select_all_pending" style="margin-right: 5px;">Tümünü Seç</th>';
        echo '<th>Müşteri</th>';
        echo '<th>Ürün</th>';
        echo '<th>Sipariş ID</th>';
        echo '<th>Bekleme Süresi</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($pending_customers as $customer) {
            $waiting_time = human_time_diff(strtotime($customer->created_at), current_time('timestamp'));
            echo '<tr>';
            echo '<td><input type="checkbox" class="pending-customer-checkbox" value="' . $customer->id . '"></td>';
            echo '<td><strong>' . esc_html($customer->customer_name) . '</strong><br><small>' . esc_html($customer->customer_email) . '</small></td>';
            echo '<td>' . esc_html($customer->product_name) . '</td>';
            echo '<td>#' . esc_html($customer->order_id) . '</td>';
            echo '<td><span style="color: #ff9800;">' . $waiting_time . ' önce</span></td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        
        echo '<div style="margin-top: 20px; text-align: center;">';
        echo '<button id="process_selected_pending" class="ald-btn ald-btn-primary" style="margin-right: 10px;">✅ Seçilenlere Gönder</button>';
        echo '<button id="refresh_pending_list" class="ald-btn" style="background: #6c757d; color: white;">🔄 Listeyi Yenile</button>';
        echo '</div>';
        
        return ob_get_clean();
    }
    
    private function auto_process_pending_customers($product_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ald_license_history';
        
        // Bu ürün için bekleyen müşterileri getir
        $pending_customers = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM $table_name 
            WHERE product_id = %d AND status = 'pending'
            ORDER BY created_at ASC
        ", $product_id));
        
        if (empty($pending_customers)) {
            return 0;
        }
        
        // Mevcut lisans anahtarlarını getir
        $license_keys = get_post_meta($product_id, '_ald_license_keys', true);
        $license_keys = is_array($license_keys) ? $license_keys : array();
        
        $processed = 0;
        foreach ($pending_customers as $customer) {
            if (empty($license_keys)) {
                break; // Lisans anahtarı kalmadı
            }
            
            $license_key = array_shift($license_keys);
            
            // Müşteriyi güncelle
            $wpdb->update(
                $table_name,
                array(
                    'license_key' => $license_key,
                    'status' => 'sent',
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $customer->id),
                array('%s', '%s', '%s'),
                array('%d')
            );
            
            // E-posta gönder
            $order = wc_get_order($customer->order_id);
            $product = wc_get_product($customer->product_id);
            
            if ($order && $product) {
                $this->send_license_email($order, $product, $license_key);
                
                $order->add_order_note(
                    sprintf('🔑 Bekleyen lisans anahtarı teslim edildi: %s (BERAT K Geliştirme)', $license_key),
                    false
                );
            }
            
            $processed++;
        }
        
        // Kalan lisans anahtarlarını güncelle
        update_post_meta($product_id, '_ald_license_keys', $license_keys);
        
        return $processed;
    }
    
    private function process_selected_pending_customers($customer_ids) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ald_license_history';
        
        $processed = 0;
        
        foreach ($customer_ids as $customer_id) {
            $customer = $wpdb->get_row($wpdb->prepare("
                SELECT * FROM $table_name WHERE id = %d AND status = 'pending'
            ", $customer_id));
            
            if (!$customer) {
                continue;
            }
            
            // Lisans anahtarlarını kontrol et
            $license_keys = get_post_meta($customer->product_id, '_ald_license_keys', true);
            $license_keys = is_array($license_keys) ? $license_keys : array();
            
            if (empty($license_keys)) {
                continue; // Bu ürün için lisans yok
            }
            
            $license_key = array_shift($license_keys);
            
            // Müşteriyi güncelle
            $wpdb->update(
                $table_name,
                array(
                    'license_key' => $license_key,
                    'status' => 'sent',
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $customer->id),
                array('%s', '%s', '%s'),
                array('%d')
            );
            
            // Lisans anahtarlarını güncelle
            update_post_meta($customer->product_id, '_ald_license_keys', $license_keys);
            
            // E-posta gönder
            $order = wc_get_order($customer->order_id);
            $product = wc_get_product($customer->product_id);
            
            if ($order && $product) {
                $this->send_license_email($order, $product, $license_key);
                
                $order->add_order_note(
                    sprintf('🔑 Manuel lisans anahtarı teslim edildi: %s (BERAT K Geliştirme)', $license_key),
                    false
                );
            }
            
            $processed++;
        }
        
        return $processed;
    }
    
    private function display_recent_sales() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ald_license_history';
        
        $recent_sales = $wpdb->get_results("
            SELECT h.*, p.post_title as product_name, u.display_name as customer_name
            FROM $table_name h
            LEFT JOIN {$wpdb->posts} p ON h.product_id = p.ID
            LEFT JOIN {$wpdb->users} u ON h.customer_id = u.ID
            WHERE h.status = 'sent'
            ORDER BY h.created_at DESC
            LIMIT 10
        ");
        
        if (empty($recent_sales)) {
            echo '<div style="text-align: center; padding: 40px; color: #666;">';
            echo '<p>🎯 Henüz lisans satışı yok</p>';
            echo '<p>Müşteriler lisanslı ürünlerinizi satın aldığında satışlar burada görünecek.</p>';
            echo '<div style="margin-top: 20px; padding: 15px; background: #f0f8ff; border-radius: 8px;">';
            echo '<small>👨‍💻 <strong>Geliştirici:</strong> BERAT K - WhatsApp: <a href="https://wa.me/905395115632">0539 511 56 32</a></small>';
            echo '</div>';
            echo '</div>';
            return;
        }
        
        echo '<table class="ald-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Ürün</th>';
        echo '<th>Müşteri</th>';
        echo '<th>Lisans Anahtarı</th>';
        echo '<th>Tarih</th>';
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
            'label' => 'Lisans Anahtarları (her satırda bir tane)',
            'placeholder' => 'Lisans anahtarlarını girin, her satırda bir tane...',
            'desc_tip' => true,
            'description' => 'Sipariş tamamlandığında lisans anahtarları otomatik olarak müşterilere teslim edilecek. Geliştirici: BERAT K - 0539 511 56 32',
            'value' => implode("\n", $license_keys),
            'custom_attributes' => array(
                'rows' => 8,
                'style' => 'font-family: monospace; font-size: 12px;'
            )
        ));
        
        echo '<p class="form-field"><strong>Mevcut Durum:</strong> ' . count($license_keys) . ' lisans anahtarı mevcut | 👨‍💻 Geliştirici: BERAT K</p>';
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
            
            if ($this->is_license_already_processed($order_id, $product_id)) {
                continue;
            }
            
            $license_keys = get_post_meta($product_id, '_ald_license_keys', true);
            $license_keys = is_array($license_keys) ? $license_keys : array();
            
            if (!empty($license_keys)) {
                // Lisans mevcut - normal teslimat
                $license_key = array_shift($license_keys);
                
                update_post_meta($product_id, '_ald_license_keys', $license_keys);
                
                $this->save_delivered_license($order, $product_id, $license_key, 'sent');
                
                $this->send_license_email($order, $product, $license_key);
                
                $order->add_order_note(
                    sprintf('🔑 Lisans anahtarı teslim edildi: %s (BERAT K Geliştirme)', $license_key),
                    false
                );
                
                $order->update_meta_data('_ald_license_' . $product_id, $license_key);
                $order->save();
            } else {
                // Lisans yok - bekleme listesine al
                $this->save_pending_customer($order, $product_id);
                
                $this->send_pending_email($order, $product);
                
                $order->add_order_note(
                    sprintf('⏳ Lisans anahtarı beklemeye alındı - 24 saat içinde teslim edilecek (BERAT K Geliştirme)', ''),
                    false
                );
                
                $order->update_meta_data('_ald_license_pending_' . $product_id, 'pending');
                $order->save();
            }
        }
    }
    
    private function is_license_already_processed($order_id, $product_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ald_license_history';
        
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE order_id = %s AND product_id = %d",
            $order_id, $product_id
        ));
        
        return !empty($existing);
    }
    
    private function save_delivered_license($order, $product_id, $license_key, $status = 'sent') {
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
                'status' => $status,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%d', '%d', '%s', '%s', '%s', '%s')
        );
    }
    
    private function save_pending_customer($order, $product_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ald_license_history';
        
        return $wpdb->insert(
            $table_name,
            array(
                'order_id' => $order->get_id(),
                'product_id' => $product_id,
                'customer_id' => $order->get_customer_id(),
                'customer_email' => $order->get_billing_email(),
                'license_key' => '',
                'status' => 'pending',
                'created_at' => current_time('mysql')
            ),
            array('%s', '%d', '%d', '%s', '%s', '%s', '%s')
        );
    }
    
    private function send_pending_email($order, $product) {
        $customer_email = $order->get_billing_email();
        $customer_name = $order->get_billing_first_name();
        $subject = sprintf('[%s] %s Ürünü İçin Lisans Anahtarınız Hazırlanıyor ⏳', get_bloginfo('name'), $product->get_name());
        
        $message = sprintf('
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #f8f9fa; padding: 20px;">
            <div style="background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                <h2 style="color: #333; text-align: center; margin-bottom: 30px;">⏳ Lisans Anahtarınız Hazırlanıyor</h2>
                <p style="font-size: 16px;">Merhaba <strong>%s</strong>,</p>
                <p style="font-size: 14px; color: #666;">Satın aldığınız için teşekkür ederiz! <strong>%s</strong> ürünü için lisans anahtarınız şu anda hazırlanıyor.</p>
                
                <div style="background: linear-gradient(135deg, #ff9800 0%%, #f57c00 100%%); padding: 25px; border-radius: 12px; margin: 25px 0; text-align: center;">
                    <div style="background: white; padding: 20px; border-radius: 8px; margin: 10px 0;">
                        <h3 style="color: #333; margin: 0; font-size: 18px;">🔄 Lisans Hazırlama Süreci</h3>
                        <p style="color: #666; margin: 10px 0 0 0; font-size: 14px;">24 saat içerisinde hesabınıza tanımlanacaktır</p>
                    </div>
                    <p style="color: white; margin: 10px 0 0 0; font-size: 12px;">Size en kısa sürede ulaşacağız</p>
                </div>
                
                <div style="background: #e3f2fd; padding: 20px; border-radius: 8px; margin: 20px 0;">
                    <p style="margin: 0; font-size: 14px; color: #1976d2;">
                        💡 <strong>Bilgi:</strong> Lisans anahtarınız hazır olduğunda size e-posta ile bildirilecek ve hesap panelinizde görünecektir.
                    </p>
                </div>
                
                <div style="background: #fff3e0; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ff9800;">
                    <p style="margin: 0; font-size: 14px; color: #e65100;">
                        ⚡ <strong>Hızlı İşlem:</strong> Lisans anahtarlarımız genellikle birkaç saat içinde hazır olur.
                    </p>
                </div>
                
                <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">
                
                <div style="text-align: center; color: #666; font-size: 12px;">
                    <p><strong>👨‍💻 Geliştirici:</strong> BERAT K</p>
                    <p>📱 WhatsApp Destek: <a href="https://wa.me/905395115632" style="color: #25d366; text-decoration: none;">0539 511 56 32</a></p>
                    <p style="margin-top: 20px;">Saygılarımızla,<br><strong>%s</strong></p>
                </div>
            </div>
        </div>
        ', $customer_name, $product->get_name(), get_bloginfo('name'));
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($customer_email, $subject, $message, $headers);
    }
    
    private function send_license_email($order, $product, $license_key) {
        $customer_email = $order->get_billing_email();
        $customer_name = $order->get_billing_first_name();
        $subject = sprintf('[%s] %s Ürünü İçin Lisans Anahtarınız 🔑', get_bloginfo('name'), $product->get_name());
        
        $message = sprintf('
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #f8f9fa; padding: 20px;">
            <div style="background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                <h2 style="color: #333; text-align: center; margin-bottom: 30px;">🔑 Lisans Anahtarınız Hazır!</h2>
                <p style="font-size: 16px;">Merhaba <strong>%s</strong>,</p>
                <p style="font-size: 14px; color: #666;">Satın aldığınız için teşekkür ederiz! <strong>%s</strong> ürünü için lisans anahtarınız aşağıdadır:</p>
                
                <div style="background: linear-gradient(135deg, #667eea 0%%, #764ba2 100%%); padding: 25px; border-radius: 12px; margin: 25px 0; text-align: center;">
                    <div style="background: white; padding: 15px; border-radius: 8px; margin: 10px 0;">
                        <code style="font-size: 18px; font-weight: bold; color: #333; word-break: break-all;">%s</code>
                    </div>
                    <p style="color: white; margin: 10px 0 0 0; font-size: 12px;">Bu anahtarı güvenli bir yerde saklayın</p>
                </div>
                
                <div style="background: #e3f2fd; padding: 20px; border-radius: 8px; margin: 20px 0;">
                    <p style="margin: 0; font-size: 14px; color: #1976d2;">
                        💡 <strong>İpucu:</strong> Lisans anahtarlarınızı istediğiniz zaman hesap panelinizden görüntüleyebilirsiniz.
                    </p>
                </div>
                
                <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">
                
                <div style="text-align: center; color: #666; font-size: 12px;">
                    <p><strong>👨‍💻 Geliştirici:</strong> BERAT K</p>
                    <p>📱 WhatsApp Destek: <a href="https://wa.me/905395115632" style="color: #25d366; text-decoration: none;">0539 511 56 32</a></p>
                    <p style="margin-top: 20px;">Saygılarımızla,<br><strong>%s</strong></p>
                </div>
            </div>
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
                $new_items['license-keys'] = '🔑 Lisans Anahtarlarım';
            }
        }
        return $new_items;
    }
    
    public function license_keys_content() {
        $customer_id = get_current_user_id();
        
        if (!$customer_id) {
            wc_print_notice('Lisans anahtarlarınızı görüntülemek için lütfen giriş yapın.', 'error');
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
        
        echo '<h2 style="color: #333; margin-bottom: 20px;">🔑 Lisans Anahtarlarım</h2>';
        
        if (empty($licenses)) {
            echo '<div style="text-align: center; padding: 40px; color: #666; background: white; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">';
            echo '<p style="font-size: 18px; margin-bottom: 10px;">📋 Henüz lisans anahtarınız yok</p>';
            echo '<p>Lisanslı ürün satın aldığınızda anahtarlarınız burada görünecek.</p>';
            echo '<div style="margin-top: 20px; padding: 15px; background: #f0f8ff; border-radius: 8px;">';
            echo '<small>👨‍💻 <strong>Geliştirici:</strong> BERAT K - WhatsApp: <a href="https://wa.me/905395115632" style="color: #25d366;">0539 511 56 32</a></small>';
            echo '</div>';
            echo '</div>';
            return;
        }
        
        // Sent ve pending lisansları ayır
        $sent_licenses = array();
        $pending_licenses = array();
        
        foreach ($licenses as $license) {
            if ($license->status === 'pending') {
                $pending_licenses[] = $license;
            } else {
                $sent_licenses[] = $license;
            }
        }
        
        // Pending lisansları göster
        if (!empty($pending_licenses)) {
            echo '<div style="background: #fff3e0; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 20px; border-left: 4px solid #ff9800;">';
            echo '<div style="padding: 20px; background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%); color: white; text-align: center;">';
            echo '<h3 style="margin: 0; color: white;">⏳ Hazırlanan Lisans Anahtarları</h3>';
            echo '<p style="margin: 10px 0 0 0; font-size: 14px; opacity: 0.9;">Aşağıdaki ürünler için lisans anahtarlarınız hazırlanıyor</p>';
            echo '</div>';
            
            echo '<table class="shop_table shop_table_responsive" style="margin: 0; border: none;">';
            echo '<thead style="background: #fff3e0;">';
            echo '<tr>';
            echo '<th style="color: #e65100; padding: 15px;">Ürün Adı</th>';
            echo '<th style="color: #e65100; padding: 15px;">Durum</th>';
            echo '<th style="color: #e65100; padding: 15px;">Sipariş Tarihi</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            
            foreach ($pending_licenses as $license) {
                $waiting_time = human_time_diff(strtotime($license->created_at), current_time('timestamp'));
                echo '<tr style="border-bottom: 1px solid #ffe0b2;">';
                echo '<td style="padding: 15px;"><strong style="color: #333;">' . esc_html($license->product_name) . '</strong></td>';
                echo '<td style="padding: 15px;">';
                echo '<span style="background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%); color: white; padding: 8px 12px; border-radius: 20px; font-size: 12px; font-weight: bold;">';
                echo '⏳ 24 saat içinde hazır';
                echo '</span>';
                echo '<br><small style="color: #ff9800; margin-top: 5px; display: block;">' . $waiting_time . ' önce sipariş verildi</small>';
                echo '</td>';
                echo '<td style="padding: 15px; color: #666;">' . date('d F Y', strtotime($license->created_at)) . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody>';
            echo '</table>';
            echo '<div style="padding: 15px; background: #fff3e0; text-align: center; border-top: 1px solid #ffe0b2;">';
            echo '<small style="color: #e65100;">💡 <strong>Bilgi:</strong> Lisans anahtarınız hazır olduğunda size e-posta ile bildirilecek ve buraya eklenecektir.</small>';
            echo '</div>';
            echo '</div>';
        }
        
        // Normal lisansları göster
        if (!empty($sent_licenses)) {
            echo '<div style="background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">';
            echo '<table class="shop_table shop_table_responsive" style="margin: 0; border: none;">';
            echo '<thead style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">';
            echo '<tr>';
            echo '<th style="color: white; padding: 15px;">Ürün Adı</th>';
            echo '<th style="color: white; padding: 15px;">Lisans Anahtarı</th>';
            echo '<th style="color: white; padding: 15px;">Satın Alma Tarihi</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            
            foreach ($sent_licenses as $license) {
                echo '<tr style="border-bottom: 1px solid #f0f0f0;">';
                echo '<td style="padding: 15px;"><strong style="color: #333;">' . esc_html($license->product_name) . '</strong></td>';
                echo '<td style="padding: 15px;"><code style="background: #333; color: white; padding: 10px 15px; border-radius: 6px; font-family: monospace; font-size: 13px; word-break: break-all;">' . esc_html($license->license_key) . '</code></td>';
                echo '<td style="padding: 15px; color: #666;">' . date('d F Y', strtotime($license->created_at)) . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody>';
            echo '</table>';
            echo '<div style="padding: 15px; background: #f8f9fa; border-top: 1px solid #eee; text-align: center;">';
            echo '<small style="color: #666;">👨‍💻 <strong>Geliştirici:</strong> BERAT K - WhatsApp: <a href="https://wa.me/905395115632" style="color: #25d366; text-decoration: none;">0539 511 56 32</a></small>';
            echo '</div>';
            echo '</div>';
        }
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
            echo '<h2 style="color: #333; margin: 30px 0 20px 0;">🔑 Lisans Anahtarlarınız</h2>';
            echo '<div style="background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin: 20px 0;">';
            echo '<table class="woocommerce-table woocommerce-table--order-downloads shop_table shop_table_responsive order_downloads" style="margin: 0; border: none;">';
            echo '<thead style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">';
            echo '<tr>';
            echo '<th style="color: white; padding: 15px;">Ürün</th>';
            echo '<th style="color: white; padding: 15px;">Lisans Anahtarı</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            
            foreach ($licenses as $license) {
                echo '<tr style="border-bottom: 1px solid #f0f0f0;">';
                echo '<td style="padding: 15px;"><strong style="color: #333;">' . esc_html($license->product_name) . '</strong></td>';
                echo '<td style="padding: 15px;"><code style="background: #333; color: white; padding: 10px 15px; border-radius: 6px; font-family: monospace; font-size: 13px; word-break: break-all;">' . esc_html($license->license_key) . '</code></td>';
                echo '</tr>';
            }
            
            echo '</tbody>';
            echo '</table>';
            echo '<div style="padding: 15px; background: #f8f9fa; border-top: 1px solid #eee; text-align: center;">';
            echo '<small style="color: #666;">👨‍💻 <strong>Geliştirici:</strong> BERAT K - WhatsApp: <a href="https://wa.me/905395115632" style="color: #25d366; text-decoration: none;">0539 511 56 32</a></small>';
            echo '</div>';
            echo '</div>';
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
                echo "\n🔑 LİSANS ANAHTARLARI:\n";
                echo str_repeat('-', 40) . "\n";
                foreach ($licenses as $license) {
                    echo $license->product_name . ': ' . $license->license_key . "\n";
                }
                echo "\n👨‍💻 Geliştirici: BERAT K - WhatsApp: 0539 511 56 32\n";
            } else {
                echo '<h3 style="color: #333; margin: 30px 0 20px 0;">🔑 Lisans Anahtarlarınız</h3>';
                echo '<table style="width: 100%; border-collapse: collapse; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">';
                echo '<thead style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">';
                echo '<tr>';
                echo '<th style="color: white; text-align:left; padding: 15px; border: none;">Ürün</th>';
                echo '<th style="color: white; text-align:left; padding: 15px; border: none;">Lisans Anahtarı</th>';
                echo '</tr>';
                echo '</thead>';
                echo '<tbody style="background: white;">';
                
                foreach ($licenses as $license) {
                    echo '<tr style="border-bottom: 1px solid #f0f0f0;">';
                    echo '<td style="padding: 15px; border: none;"><strong>' . esc_html($license->product_name) . '</strong></td>';
                    echo '<td style="padding: 15px; border: none;"><code style="background: #333; color: white; padding: 8px 12px; border-radius: 4px; font-family: monospace;">' . esc_html($license->license_key) . '</code></td>';
                    echo '</tr>';
                }
                
                echo '</tbody>';
                echo '</table>';
                echo '<div style="margin-top: 20px; padding: 15px; background: #f0f8ff; border-radius: 8px; text-align: center;">';
                echo '<small style="color: #666;">👨‍💻 <strong>Geliştirici:</strong> BERAT K - WhatsApp: <a href="https://wa.me/905395115632" style="color: #25d366; text-decoration: none;">0539 511 56 32</a></small>';
                echo '</div>';
            }
        }
    }
}

// Plugin'i başlat
AutoLicenseDelivery::get_instance();