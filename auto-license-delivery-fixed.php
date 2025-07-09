<?php
/**
 * Plugin Name: Auto License Delivery - Fixed
 * Plugin URI: https://example.com/auto-license-delivery
 * Description: Otomatik lisans anahtarı teslim sistemi - WordPress 6.4+ ve WooCommerce 8.4+ uyumlu
 * Version: 2.1.0
 * Requires at least: 5.0
 * Tested up to: 6.4
 * WC requires at least: 6.0
 * WC tested up to: 8.4
 * Author: Auto License Team
 * Text Domain: auto-license-delivery
 * Domain Path: /languages
 */

// Güvenlik kontrolü
if (!defined('ABSPATH')) {
    exit;
}

// Ana sabitleri tanımla
define('ALD_VERSION', '2.1.0');
define('ALD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ALD_PLUGIN_URL', plugin_dir_url(__FILE__));

// WooCommerce kontrolü
add_action('plugins_loaded', 'ald_check_woocommerce_dependency');

function ald_check_woocommerce_dependency() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'ald_woocommerce_missing_notice');
        return;
    }
    
    // Ana sınıfı yükle
    ald_init_plugin();
}

function ald_woocommerce_missing_notice() {
    echo '<div class="error"><p><strong>Auto License Delivery:</strong> Bu eklenti WooCommerce gerektirir. Lütfen WooCommerce\'i kurun ve aktifleştirin.</p></div>';
}

function ald_init_plugin() {
    class AutoLicenseDelivery {
        
        private static $instance = null;
        
        public static function get_instance() {
            if (null === self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }
        
        private function __construct() {
            $this->init_hooks();
            
            // Aktivasyon ve deaktivasyon hook'ları
            register_activation_hook(__FILE__, array($this, 'activate'));
            register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        }
        
        private function init_hooks() {
            // Başlatma hook'ları
            add_action('init', array($this, 'init'));
            add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
            
            // WooCommerce hooks
            add_action('woocommerce_order_status_completed', array($this, 'generate_license_keys'));
            add_action('woocommerce_email_order_details', array($this, 'add_license_to_email'), 10, 4);
            
            // My Account hooks - WordPress init'te çalıştır
            add_action('init', array($this, 'add_my_account_endpoints'));
            add_filter('woocommerce_account_menu_items', array($this, 'add_my_account_menu_items'));
            add_action('woocommerce_account_license-keys_endpoint', array($this, 'license_keys_content'));
            
            // Admin hooks
            if (is_admin()) {
                add_action('add_meta_boxes', array($this, 'add_product_meta_boxes'));
                add_action('save_post', array($this, 'save_product_meta'));
            }
            
            // AJAX hooks
            add_action('wp_ajax_copy_license_key', array($this, 'ajax_copy_license_key'));
            add_action('wp_ajax_nopriv_validate_license', array($this, 'ajax_validate_license'));
            
            // Dil dosyalarını yükle
            add_action('init', array($this, 'load_textdomain'));
        }
        
        public function init() {
            // Query var'ları ekle
            add_rewrite_endpoint('license-keys', EP_ROOT | EP_PAGES);
        }
        
        public function activate() {
            // Veritabanı tablolarını oluştur
            $this->create_tables();
            
            // Rewrite rules flush
            add_rewrite_endpoint('license-keys', EP_ROOT | EP_PAGES);
            flush_rewrite_rules();
            
            // Varsayılan seçenekleri ayarla
            add_option('ald_version', ALD_VERSION);
            add_option('ald_license_key_format', 'XXXX-XXXX-XXXX-XXXX');
            add_option('ald_key_length', 16);
        }
        
        public function deactivate() {
            flush_rewrite_rules();
        }
        
        public function load_textdomain() {
            load_plugin_textdomain('auto-license-delivery', false, dirname(plugin_basename(__FILE__)) . '/languages');
        }
        
        private function create_tables() {
            global $wpdb;
            
            $charset_collate = $wpdb->get_charset_collate();
            
            // Ana lisans tablosu
            $table_name = $wpdb->prefix . 'ald_license_keys';
            
            $sql = "CREATE TABLE IF NOT EXISTS $table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                license_key varchar(255) NOT NULL,
                order_id bigint(20) NOT NULL,
                product_id bigint(20) NOT NULL,
                customer_id bigint(20) NOT NULL,
                customer_email varchar(255) NOT NULL,
                status varchar(20) DEFAULT 'active',
                activations int(11) DEFAULT 0,
                max_activations int(11) DEFAULT 1,
                expires_at datetime NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY license_key (license_key),
                KEY order_id (order_id),
                KEY customer_id (customer_id),
                KEY status (status)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
            
            // Aktivasyon tablosu
            $activations_table = $wpdb->prefix . 'ald_license_activations';
            
            $sql2 = "CREATE TABLE IF NOT EXISTS $activations_table (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                license_key varchar(255) NOT NULL,
                domain varchar(255) NOT NULL,
                ip_address varchar(45) NOT NULL,
                user_agent text,
                activated_at datetime DEFAULT CURRENT_TIMESTAMP,
                last_check datetime DEFAULT CURRENT_TIMESTAMP,
                status varchar(20) DEFAULT 'active',
                PRIMARY KEY (id),
                KEY license_key (license_key),
                KEY domain (domain),
                KEY status (status)
            ) $charset_collate;";
            
            dbDelta($sql2);
        }
        
        public function generate_license_keys($order_id) {
            // Geçerli order objesi al
            $order = wc_get_order($order_id);
            
            if (!$order || !is_a($order, 'WC_Order')) {
                return;
            }
            
            // Daha önce lisans oluşturulmuş mu kontrol et
            if ($this->order_has_licenses($order_id)) {
                return;
            }
            
            $items = $order->get_items();
            
            foreach ($items as $item_id => $item) {
                $product_id = $item->get_product_id();
                $product = wc_get_product($product_id);
                
                if (!$product) {
                    continue;
                }
                
                // Ürün lisans gerektiriyor mu kontrol et
                $requires_license = get_post_meta($product_id, '_requires_license', true);
                
                if ($requires_license === 'yes') {
                    $quantity = $item->get_quantity();
                    
                    for ($i = 0; $i < $quantity; $i++) {
                        $this->create_license_key($order, $product_id);
                    }
                }
            }
        }
        
        private function order_has_licenses($order_id) {
            global $wpdb;
            
            $table_name = $wpdb->prefix . 'ald_license_keys';
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE order_id = %d",
                $order_id
            ));
            
            return $count > 0;
        }
        
        private function create_license_key($order, $product_id) {
            global $wpdb;
            
            $license_key = $this->generate_unique_license_key();
            
            // HPOS uyumlu müşteri bilgileri
            $customer_id = $order->get_customer_id();
            $customer_email = $order->get_billing_email();
            
            // Lisans ayarları
            $license_duration = get_post_meta($product_id, '_license_duration', true);
            $expires_at = null;
            
            if ($license_duration && is_numeric($license_duration) && $license_duration > 0) {
                $expires_at = date('Y-m-d H:i:s', strtotime("+{$license_duration} days"));
            }
            
            $max_activations = get_post_meta($product_id, '_max_activations', true);
            if (!$max_activations || !is_numeric($max_activations)) {
                $max_activations = 1;
            }
            
            // Lisans anahtarını veritabanına kaydet
            $table_name = $wpdb->prefix . 'ald_license_keys';
            
            $result = $wpdb->insert(
                $table_name,
                array(
                    'license_key' => $license_key,
                    'order_id' => $order->get_id(),
                    'product_id' => $product_id,
                    'customer_id' => $customer_id,
                    'customer_email' => $customer_email,
                    'status' => 'active',
                    'max_activations' => $max_activations,
                    'expires_at' => $expires_at
                ),
                array('%s', '%d', '%d', '%d', '%s', '%s', '%d', '%s')
            );
            
            if ($result) {
                // Order note ekle (HPOS uyumlu)
                $order->add_order_note(
                    sprintf(__('Lisans anahtarı oluşturuldu: %s', 'auto-license-delivery'), $license_key)
                );
                
                // HPOS uyumlu meta veri kaydet
                $this->update_order_meta($order->get_id(), '_has_license_keys', 'yes');
            }
            
            return $license_key;
        }
        
        private function generate_unique_license_key() {
            global $wpdb;
            
            $format = get_option('ald_license_key_format', 'XXXX-XXXX-XXXX-XXXX');
            $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            $table_name = $wpdb->prefix . 'ald_license_keys';
            
            do {
                $license_key = '';
                $format_chars = str_split($format);
                
                foreach ($format_chars as $char) {
                    if ($char === 'X') {
                        $license_key .= $characters[wp_rand(0, strlen($characters) - 1)];
                    } else {
                        $license_key .= $char;
                    }
                }
                
                // Benzersiz olup olmadığını kontrol et
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_name WHERE license_key = %s",
                    $license_key
                ));
                
            } while ($exists > 0);
            
            return $license_key;
        }
        
        // HPOS uyumlu meta veri işlemleri
        private function update_order_meta($order_id, $meta_key, $meta_value) {
            if (class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil') && 
                \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
                // HPOS aktif
                $order = wc_get_order($order_id);
                if ($order) {
                    $order->update_meta_data($meta_key, $meta_value);
                    $order->save();
                }
            } else {
                // Geleneksel post meta
                update_post_meta($order_id, $meta_key, $meta_value);
            }
        }
        
        private function get_order_meta($order_id, $meta_key, $single = true) {
            if (class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil') && 
                \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
                // HPOS aktif
                $order = wc_get_order($order_id);
                return $order ? $order->get_meta($meta_key, $single) : '';
            } else {
                // Geleneksel post meta
                return get_post_meta($order_id, $meta_key, $single);
            }
        }
        
        public function add_my_account_endpoints() {
            add_rewrite_endpoint('license-keys', EP_ROOT | EP_PAGES);
        }
        
        public function add_my_account_menu_items($items) {
            // Çıkış yapmadan önce lisans anahtarlarını ekle
            $logout = $items['customer-logout'] ?? '';
            unset($items['customer-logout']);
            
            $items['license-keys'] = __('Lisans Anahtarları', 'auto-license-delivery');
            
            if ($logout) {
                $items['customer-logout'] = $logout;
            }
            
            return $items;
        }
        
        public function license_keys_content() {
            $customer_id = get_current_user_id();
            
            if (!$customer_id) {
                wc_print_notice(__('Bu sayfayı görüntülemek için giriş yapmalısınız.', 'auto-license-delivery'), 'error');
                return;
            }
            
            $licenses = $this->get_customer_licenses($customer_id);
            
            wc_get_template('myaccount/license-keys.php', array(
                'licenses' => $licenses,
                'customer_id' => $customer_id
            ), '', ALD_PLUGIN_DIR . 'templates/');
        }
        
        private function get_customer_licenses($customer_id) {
            global $wpdb;
            
            $table_name = $wpdb->prefix . 'ald_license_keys';
            
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table_name WHERE customer_id = %d ORDER BY created_at DESC",
                $customer_id
            ));
        }
        
        public function add_license_to_email($order, $sent_to_admin, $plain_text, $email) {
            // Sadece müşteri tamamlama e-postasında göster
            if ($email->id !== 'customer_completed_order' || $sent_to_admin) {
                return;
            }
            
            $licenses = $this->get_order_licenses($order->get_id());
            
            if (empty($licenses)) {
                return;
            }
            
            if ($plain_text) {
                $this->display_licenses_plain_text($licenses);
            } else {
                $this->display_licenses_html($licenses);
            }
        }
        
        private function get_order_licenses($order_id) {
            global $wpdb;
            
            $table_name = $wpdb->prefix . 'ald_license_keys';
            
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table_name WHERE order_id = %d",
                $order_id
            ));
        }
        
        private function display_licenses_plain_text($licenses) {
            echo "\n" . __('LİSANS ANAHTARLARINIZ:', 'auto-license-delivery') . "\n";
            echo str_repeat('=', 50) . "\n";
            
            foreach ($licenses as $license) {
                $product = wc_get_product($license->product_id);
                $product_name = $product ? $product->get_name() : __('Ürün', 'auto-license-delivery');
                
                echo sprintf(__('Ürün: %s', 'auto-license-delivery'), $product_name) . "\n";
                echo sprintf(__('Lisans Anahtarı: %s', 'auto-license-delivery'), $license->license_key) . "\n";
                
                if ($license->expires_at) {
                    echo sprintf(__('Bitiş Tarihi: %s', 'auto-license-delivery'), 
                        wp_date(get_option('date_format'), strtotime($license->expires_at))) . "\n";
                }
                
                echo "\n";
            }
        }
        
        private function display_licenses_html($licenses) {
            echo '<div style="margin: 20px 0; padding: 15px; background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px;">';
            echo '<h3 style="margin-top: 0; color: #495057;">' . __('Lisans Anahtarlarınız', 'auto-license-delivery') . '</h3>';
            echo '<table style="width: 100%; border-collapse: collapse;">';
            
            foreach ($licenses as $license) {
                $product = wc_get_product($license->product_id);
                $product_name = $product ? $product->get_name() : __('Ürün', 'auto-license-delivery');
                
                echo '<tr>';
                echo '<td style="padding: 10px; border: 1px solid #dee2e6; background-color: #fff;"><strong>' . esc_html($product_name) . '</strong></td>';
                echo '<td style="padding: 10px; border: 1px solid #dee2e6; background-color: #fff; font-family: monospace; font-size: 14px;">' . esc_html($license->license_key) . '</td>';
                echo '</tr>';
                
                if ($license->expires_at) {
                    echo '<tr>';
                    echo '<td style="padding: 5px 10px; border: 1px solid #dee2e6; background-color: #f8f9fa; font-size: 12px;" colspan="2">';
                    echo sprintf(__('Bitiş Tarihi: %s', 'auto-license-delivery'), 
                        wp_date(get_option('date_format'), strtotime($license->expires_at)));
                    echo '</td>';
                    echo '</tr>';
                }
            }
            
            echo '</table>';
            echo '<p style="margin-bottom: 0; font-size: 12px; color: #6c757d;">' . 
                __('Lisans anahtarlarınızı hesabınızın "Lisans Anahtarları" bölümünden yönetebilirsiniz.', 'auto-license-delivery') . '</p>';
            echo '</div>';
        }
        
        public function add_product_meta_boxes() {
            add_meta_box(
                'ald-license-options',
                __('Lisans Anahtarı Ayarları', 'auto-license-delivery'),
                array($this, 'product_meta_box_callback'),
                'product',
                'normal',
                'default'
            );
        }
        
        public function product_meta_box_callback($post) {
            wp_nonce_field('ald_product_meta', 'ald_product_meta_nonce');
            
            $requires_license = get_post_meta($post->ID, '_requires_license', true);
            $license_duration = get_post_meta($post->ID, '_license_duration', true);
            $max_activations = get_post_meta($post->ID, '_max_activations', true);
            
            echo '<table class="form-table">';
            
            echo '<tr>';
            echo '<th><label for="requires_license">' . __('Lisans Anahtarı Gerektirir', 'auto-license-delivery') . '</label></th>';
            echo '<td>';
            echo '<input type="checkbox" id="requires_license" name="requires_license" value="yes" ' . checked($requires_license, 'yes', false) . '>';
            echo '<p class="description">' . __('Bu ürün için otomatik lisans anahtarı oluşturulsun.', 'auto-license-delivery') . '</p>';
            echo '</td>';
            echo '</tr>';
            
            echo '<tr>';
            echo '<th><label for="license_duration">' . __('Lisans Süresi (Gün)', 'auto-license-delivery') . '</label></th>';
            echo '<td>';
            echo '<input type="number" id="license_duration" name="license_duration" value="' . esc_attr($license_duration) . '" min="0" step="1" style="width: 100px;">';
            echo '<p class="description">' . __('Lisansın kaç gün geçerli olacağını belirtin. Boş bırakırsanız süresiz olur.', 'auto-license-delivery') . '</p>';
            echo '</td>';
            echo '</tr>';
            
            echo '<tr>';
            echo '<th><label for="max_activations">' . __('Maksimum Aktivasyon', 'auto-license-delivery') . '</label></th>';
            echo '<td>';
            echo '<input type="number" id="max_activations" name="max_activations" value="' . esc_attr($max_activations ?: '1') . '" min="1" step="1" style="width: 100px;">';
            echo '<p class="description">' . __('Bu lisansın kaç farklı yerde kullanılabileceğini belirtin.', 'auto-license-delivery') . '</p>';
            echo '</td>';
            echo '</tr>';
            
            echo '</table>';
        }
        
        public function save_product_meta($post_id) {
            // Güvenlik kontrolleri
            if (!isset($_POST['ald_product_meta_nonce']) || 
                !wp_verify_nonce($_POST['ald_product_meta_nonce'], 'ald_product_meta')) {
                return;
            }
            
            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                return;
            }
            
            if (!current_user_can('edit_post', $post_id)) {
                return;
            }
            
            // Meta verileri kaydet
            $requires_license = isset($_POST['requires_license']) ? 'yes' : 'no';
            update_post_meta($post_id, '_requires_license', $requires_license);
            
            if (isset($_POST['license_duration'])) {
                $license_duration = intval($_POST['license_duration']);
                update_post_meta($post_id, '_license_duration', $license_duration);
            }
            
            if (isset($_POST['max_activations'])) {
                $max_activations = max(1, intval($_POST['max_activations']));
                update_post_meta($post_id, '_max_activations', $max_activations);
            }
        }
        
        public function enqueue_scripts() {
            if (is_wc_endpoint_url('license-keys') || is_account_page()) {
                wp_enqueue_script(
                    'ald-frontend',
                    ALD_PLUGIN_URL . 'assets/js/frontend.js',
                    array('jquery'),
                    ALD_VERSION,
                    true
                );
                
                wp_localize_script('ald-frontend', 'ald_ajax', array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('ald_ajax_nonce'),
                    'strings' => array(
                        'copied' => __('Kopyalandı!', 'auto-license-delivery'),
                        'copy_failed' => __('Kopyalama başarısız!', 'auto-license-delivery')
                    )
                ));
                
                wp_enqueue_style(
                    'ald-frontend',
                    ALD_PLUGIN_URL . 'assets/css/frontend.css',
                    array(),
                    ALD_VERSION
                );
            }
        }
        
        public function ajax_copy_license_key() {
            check_ajax_referer('ald_ajax_nonce', 'nonce');
            
            $license_key = sanitize_text_field($_POST['license_key'] ?? '');
            
            if (empty($license_key)) {
                wp_send_json_error(__('Geçersiz lisans anahtarı', 'auto-license-delivery'));
            }
            
            wp_send_json_success(array(
                'message' => __('Lisans anahtarı kopyalandı', 'auto-license-delivery')
            ));
        }
        
        public function ajax_validate_license() {
            // Public API endpoint - nonce kontrolü yok
            $license_key = sanitize_text_field($_POST['license_key'] ?? '');
            
            if (empty($license_key)) {
                wp_send_json_error(array(
                    'code' => 'INVALID_KEY',
                    'message' => __('Lisans anahtarı gerekli', 'auto-license-delivery')
                ));
            }
            
            global $wpdb;
            $table_name = $wpdb->prefix . 'ald_license_keys';
            
            $license = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE license_key = %s",
                $license_key
            ));
            
            if (!$license) {
                wp_send_json_error(array(
                    'code' => 'NOT_FOUND',
                    'message' => __('Lisans anahtarı bulunamadı', 'auto-license-delivery')
                ));
            }
            
            if ($license->status !== 'active') {
                wp_send_json_error(array(
                    'code' => 'INACTIVE',
                    'message' => __('Lisans anahtarı pasif durumda', 'auto-license-delivery')
                ));
            }
            
            if ($license->expires_at && strtotime($license->expires_at) < time()) {
                wp_send_json_error(array(
                    'code' => 'EXPIRED',
                    'message' => __('Lisans anahtarının süresi dolmuş', 'auto-license-delivery')
                ));
            }
            
            $product = wc_get_product($license->product_id);
            
            wp_send_json_success(array(
                'license_key' => $license->license_key,
                'status' => $license->status,
                'product_name' => $product ? $product->get_name() : '',
                'activations' => intval($license->activations),
                'max_activations' => intval($license->max_activations),
                'expires_at' => $license->expires_at,
                'created_at' => $license->created_at
            ));
        }
    }
    
    // Plugin'i başlat
    AutoLicenseDelivery::get_instance();
}

// Template dosyası yardımcı fonksiyonu
if (!function_exists('ald_get_template')) {
    function ald_get_template($template_name, $args = array()) {
        $template_path = ALD_PLUGIN_DIR . 'templates/' . $template_name;
        
        if (file_exists($template_path)) {
            extract($args);
            include $template_path;
        }
    }
}

// Yardımcı fonksiyonlar
if (!function_exists('ald_get_license_by_key')) {
    function ald_get_license_by_key($license_key) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ald_license_keys';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE license_key = %s",
            $license_key
        ));
    }
}

if (!function_exists('ald_validate_license')) {
    function ald_validate_license($license_key) {
        $license = ald_get_license_by_key($license_key);
        
        if (!$license) {
            return array('valid' => false, 'message' => __('Lisans anahtarı bulunamadı', 'auto-license-delivery'));
        }
        
        if ($license->status !== 'active') {
            return array('valid' => false, 'message' => __('Lisans anahtarı pasif durumda', 'auto-license-delivery'));
        }
        
        if ($license->expires_at && strtotime($license->expires_at) < time()) {
            return array('valid' => false, 'message' => __('Lisans anahtarının süresi dolmuş', 'auto-license-delivery'));
        }
        
        return array(
            'valid' => true,
            'license' => $license,
            'message' => __('Geçerli lisans anahtarı', 'auto-license-delivery')
        );
    }
}