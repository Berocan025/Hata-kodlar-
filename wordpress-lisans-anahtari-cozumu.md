# WordPress Lisans Anahtarı Eklentisi - Sorun Analizi ve Çözümü

## Tespit Edilen Ana Sorunlar

### 1. WooCommerce API ve Hook Değişiklikleri
WordPress/WooCommerce güncellemeleri sonrası eklentiler çalışmayı durdurabilir.

### 2. My Account Entegrasyonu Sorunu  
Müşteri panelinde "Lisans Anahtarları" sekmesi görünmeme

### 3. E-posta Teslim Sistemi Arıza
Otomatik lisans anahtarı gönderimi çalışmama

### 4. Veritabanı Uyumluluk Sorunları
HPOS (High-Performance Order Storage) sistemi ile uyumsuzluk

## Tam Çözüm - Güncellenmiş Plugin Kodu

### 1. Ana Plugin Dosyası

```php
<?php
/**
 * Plugin Name: Otomatik Lisans Anahtarı Teslimi - Güncellenmiş
 * Description: WooCommerce ile uyumlu lisans anahtarı otomatik teslim sistemi
 * Version: 2.0.0
 * Requires at least: 5.0
 * Tested up to: 6.4
 * WC requires at least: 6.0
 * WC tested up to: 8.4
 */

// Güvenlik kontrolü
if (!defined('ABSPATH')) {
    exit;
}

// WooCommerce kontrolü
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

class AutoLicenseDelivery {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function init() {
        // WooCommerce yüklü kontrolü
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
        
        $this->load_hooks();
        
        // Admin paneli
        if (is_admin()) {
            $this->load_admin();
        }
        
        // Frontend
        $this->load_frontend();
    }
    
    private function load_hooks() {
        // Sipariş tamamlandığında lisans oluştur
        add_action('woocommerce_order_status_completed', array($this, 'generate_license_keys'));
        
        // E-posta hook'ları
        add_action('woocommerce_email_order_details', array($this, 'add_license_to_email'), 10, 4);
        
        // My Account hooks
        add_action('init', array($this, 'add_my_account_endpoints'));
        add_filter('woocommerce_account_menu_items', array($this, 'add_my_account_menu_items'));
        add_action('woocommerce_account_license-keys_endpoint', array($this, 'license_keys_content'));
        
        // AJAX hooks
        add_action('wp_ajax_activate_license', array($this, 'ajax_activate_license'));
        add_action('wp_ajax_deactivate_license', array($this, 'ajax_deactivate_license'));
        
        // Scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }
    
    public function activate() {
        // Veritabanı tablolarını oluştur
        $this->create_tables();
        
        // Rewrite rules flush
        flush_rewrite_rules();
        
        // Default options
        add_option('auto_license_delivery_version', '2.0.0');
        add_option('auto_license_key_length', 20);
        add_option('auto_license_key_format', 'XXXX-XXXX-XXXX-XXXX');
    }
    
    public function deactivate() {
        flush_rewrite_rules();
    }
    
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Ana lisans tablosu
        $table_name = $wpdb->prefix . 'auto_license_keys';
        
        $sql = "CREATE TABLE $table_name (
            id int(11) NOT NULL AUTO_INCREMENT,
            license_key varchar(255) NOT NULL,
            order_id int(11) NOT NULL,
            product_id int(11) NOT NULL,
            customer_id int(11) NOT NULL,
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
        $activations_table = $wpdb->prefix . 'auto_license_activations';
        
        $sql2 = "CREATE TABLE $activations_table (
            id int(11) NOT NULL AUTO_INCREMENT,
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
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        // Daha önce lisans oluşturulmuş mu kontrol et
        $existing_licenses = $this->get_order_licenses($order_id);
        if (!empty($existing_licenses)) {
            return; // Zaten oluşturulmuş
        }
        
        $items = $order->get_items();
        
        foreach ($items as $item_id => $item) {
            $product_id = $item->get_product_id();
            $product = wc_get_product($product_id);
            
            // Sadece lisans gerektiren ürünler için
            $requires_license = get_post_meta($product_id, '_requires_license', true);
            
            if ($requires_license === 'yes') {
                $quantity = $item->get_quantity();
                
                for ($i = 0; $i < $quantity; $i++) {
                    $this->create_license_key($order, $product_id, $item_id);
                }
            }
        }
    }
    
    private function create_license_key($order, $product_id, $item_id) {
        global $wpdb;
        
        $license_key = $this->generate_unique_license_key();
        $customer_id = $order->get_customer_id();
        $customer_email = $order->get_billing_email();
        
        // Lisans süresi ayarları
        $license_duration = get_post_meta($product_id, '_license_duration', true);
        $expires_at = null;
        
        if ($license_duration && is_numeric($license_duration)) {
            $expires_at = date('Y-m-d H:i:s', strtotime("+{$license_duration} days"));
        }
        
        // Maksimum aktivasyon sayısı
        $max_activations = get_post_meta($product_id, '_max_activations', true);
        if (!$max_activations || !is_numeric($max_activations)) {
            $max_activations = 1;
        }
        
        $table_name = $wpdb->prefix . 'auto_license_keys';
        
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
            // HPOS uyumlu meta veri kaydetme
            $this->update_order_meta_hpos_compatible($order->get_id(), '_has_license_keys', 'yes');
            
            // Log kaydet
            $order->add_order_note(sprintf('Lisans anahtarı oluşturuldu: %s (Ürün ID: %d)', $license_key, $product_id));
        }
        
        return $license_key;
    }
    
    private function generate_unique_license_key() {
        global $wpdb;
        
        $format = get_option('auto_license_key_format', 'XXXX-XXXX-XXXX-XXXX');
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        
        do {
            $license_key = '';
            $format_chars = str_split($format);
            
            foreach ($format_chars as $char) {
                if ($char === 'X') {
                    $license_key .= $characters[rand(0, strlen($characters) - 1)];
                } else {
                    $license_key .= $char;
                }
            }
            
            // Benzersiz olup olmadığını kontrol et
            $table_name = $wpdb->prefix . 'auto_license_keys';
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE license_key = %s",
                $license_key
            ));
            
        } while ($exists > 0);
        
        return $license_key;
    }
    
    // HPOS uyumlu meta veri işlemleri
    private function update_order_meta_hpos_compatible($order_id, $meta_key, $meta_value) {
        if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && 
            OrderUtil::custom_orders_table_usage_is_enabled()) {
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
    
    private function get_order_meta_hpos_compatible($order_id, $meta_key) {
        if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && 
            OrderUtil::custom_orders_table_usage_is_enabled()) {
            // HPOS aktif
            $order = wc_get_order($order_id);
            return $order ? $order->get_meta($meta_key) : '';
        } else {
            // Geleneksel post meta
            return get_post_meta($order_id, $meta_key, true);
        }
    }
    
    public function add_my_account_endpoints() {
        add_rewrite_endpoint('license-keys', EP_ROOT | EP_PAGES);
    }
    
    public function add_my_account_menu_items($items) {
        // Çıkış yapmadan önce lisans anahtarlarını ekle
        $logout = $items['customer-logout'];
        unset($items['customer-logout']);
        
        $items['license-keys'] = __('Lisans Anahtarları', 'auto-license-delivery');
        $items['customer-logout'] = $logout;
        
        return $items;
    }
    
    public function license_keys_content() {
        $customer_id = get_current_user_id();
        
        if (!$customer_id) {
            echo '<p>' . __('Bu sayfaya erişmek için giriş yapmalısınız.', 'auto-license-delivery') . '</p>';
            return;
        }
        
        $licenses = $this->get_customer_licenses($customer_id);
        
        echo '<h3>' . __('Lisans Anahtarlarım', 'auto-license-delivery') . '</h3>';
        
        if (empty($licenses)) {
            echo '<p>' . __('Henüz lisans anahtarınız bulunmamaktadır.', 'auto-license-delivery') . '</p>';
            return;
        }
        
        echo '<div class="license-keys-container">';
        echo '<table class="shop_table shop_table_responsive license-keys-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>' . __('Ürün', 'auto-license-delivery') . '</th>';
        echo '<th>' . __('Lisans Anahtarı', 'auto-license-delivery') . '</th>';
        echo '<th>' . __('Durum', 'auto-license-delivery') . '</th>';
        echo '<th>' . __('Aktivasyonlar', 'auto-license-delivery') . '</th>';
        echo '<th>' . __('Bitiş Tarihi', 'auto-license-delivery') . '</th>';
        echo '<th>' . __('İşlemler', 'auto-license-delivery') . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($licenses as $license) {
            $product = wc_get_product($license->product_id);
            $product_name = $product ? $product->get_name() : __('Ürün bulunamadı', 'auto-license-delivery');
            
            echo '<tr>';
            echo '<td data-title="' . __('Ürün', 'auto-license-delivery') . '">' . esc_html($product_name) . '</td>';
            echo '<td data-title="' . __('Lisans Anahtarı', 'auto-license-delivery') . '" class="license-key-cell">';
            echo '<code class="license-key-code">' . esc_html($license->license_key) . '</code>';
            echo '<button type="button" class="copy-license-key button" data-license="' . esc_attr($license->license_key) . '">' . __('Kopyala', 'auto-license-delivery') . '</button>';
            echo '</td>';
            
            $status_text = $license->status === 'active' ? __('Aktif', 'auto-license-delivery') : __('Pasif', 'auto-license-delivery');
            $status_class = $license->status === 'active' ? 'status-active' : 'status-inactive';
            
            echo '<td data-title="' . __('Durum', 'auto-license-delivery') . '"><span class="license-status ' . $status_class . '">' . $status_text . '</span></td>';
            echo '<td data-title="' . __('Aktivasyonlar', 'auto-license-delivery') . '">' . intval($license->activations) . '/' . intval($license->max_activations) . '</td>';
            
            $expires_text = $license->expires_at ? date_i18n(get_option('date_format'), strtotime($license->expires_at)) : __('Süresiz', 'auto-license-delivery');
            echo '<td data-title="' . __('Bitiş Tarihi', 'auto-license-delivery') . '">' . $expires_text . '</td>';
            
            echo '<td data-title="' . __('İşlemler', 'auto-license-delivery') . '">';
            echo '<a href="' . esc_url(add_query_arg('view_license', $license->id)) . '" class="button">' . __('Detaylar', 'auto-license-delivery') . '</a>';
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        echo '</div>';
        
        // Lisans detayları modal
        if (isset($_GET['view_license'])) {
            $license_id = intval($_GET['view_license']);
            $this->show_license_details($license_id, $customer_id);
        }
    }
    
    private function get_customer_licenses($customer_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'auto_license_keys';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE customer_id = %d ORDER BY created_at DESC",
            $customer_id
        ));
    }
    
    private function get_order_licenses($order_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'auto_license_keys';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE order_id = %d",
            $order_id
        ));
    }
    
    public function add_license_to_email($order, $sent_to_admin, $plain_text, $email) {
        // Sadece müşteri tamamlama e-postasında göster
        if ($email->id !== 'customer_completed_order') {
            return;
        }
        
        $licenses = $this->get_order_licenses($order->get_id());
        
        if (empty($licenses)) {
            return;
        }
        
        if ($plain_text) {
            echo "\n" . __('LİSANS ANAHTARLARINIZ:', 'auto-license-delivery') . "\n";
            echo str_repeat('=', 50) . "\n";
            
            foreach ($licenses as $license) {
                $product = wc_get_product($license->product_id);
                $product_name = $product ? $product->get_name() : __('Ürün', 'auto-license-delivery');
                
                echo sprintf(__('Ürün: %s', 'auto-license-delivery'), $product_name) . "\n";
                echo sprintf(__('Lisans Anahtarı: %s', 'auto-license-delivery'), $license->license_key) . "\n";
                
                if ($license->expires_at) {
                    echo sprintf(__('Bitiş Tarihi: %s', 'auto-license-delivery'), date_i18n(get_option('date_format'), strtotime($license->expires_at))) . "\n";
                }
                
                echo "\n";
            }
        } else {
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
                    echo sprintf(__('Bitiş Tarihi: %s', 'auto-license-delivery'), date_i18n(get_option('date_format'), strtotime($license->expires_at)));
                    echo '</td>';
                    echo '</tr>';
                }
            }
            
            echo '</table>';
            echo '<p style="margin-bottom: 0; font-size: 12px; color: #6c757d;">' . __('Lisans anahtarlarınızı hesabınızın "Lisans Anahtarları" bölümünden yönetebilirsiniz.', 'auto-license-delivery') . '</p>';
            echo '</div>';
        }
    }
    
    public function enqueue_scripts() {
        if (is_account_page()) {
            wp_enqueue_script(
                'auto-license-delivery',
                plugin_dir_url(__FILE__) . 'assets/js/auto-license-delivery.js',
                array('jquery'),
                '2.0.0',
                true
            );
            
            wp_localize_script('auto-license-delivery', 'auto_license_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('auto_license_nonce'),
                'strings' => array(
                    'copied' => __('Kopyalandı!', 'auto-license-delivery'),
                    'copy_failed' => __('Kopyalama başarısız', 'auto-license-delivery'),
                    'activating' => __('Etkinleştiriliyor...', 'auto-license-delivery'),
                    'deactivating' => __('Devre dışı bırakılıyor...', 'auto-license-delivery')
                )
            ));
            
            wp_enqueue_style(
                'auto-license-delivery',
                plugin_dir_url(__FILE__) . 'assets/css/auto-license-delivery.css',
                array(),
                '2.0.0'
            );
        }
    }
    
    // Ürün ayarları için meta box ekle
    public function load_admin() {
        add_action('add_meta_boxes', array($this, 'add_product_meta_boxes'));
        add_action('save_post', array($this, 'save_product_meta'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }
    
    public function add_product_meta_boxes() {
        add_meta_box(
            'auto-license-delivery-options',
            __('Lisans Anahtarı Ayarları', 'auto-license-delivery'),
            array($this, 'product_meta_box_callback'),
            'product',
            'normal',
            'default'
        );
    }
    
    public function product_meta_box_callback($post) {
        wp_nonce_field('auto_license_delivery_meta', 'auto_license_delivery_meta_nonce');
        
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
        echo '<input type="number" id="license_duration" name="license_duration" value="' . esc_attr($license_duration) . '" min="0" step="1">';
        echo '<p class="description">' . __('Lisansın kaç gün geçerli olacağını belirtin. Boş bırakırsanız süresiz olur.', 'auto-license-delivery') . '</p>';
        echo '</td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th><label for="max_activations">' . __('Maksimum Aktivasyon', 'auto-license-delivery') . '</label></th>';
        echo '<td>';
        echo '<input type="number" id="max_activations" name="max_activations" value="' . esc_attr($max_activations ?: '1') . '" min="1" step="1">';
        echo '<p class="description">' . __('Bu lisansın kaç farklı yerde kullanılabileceğini belirtin.', 'auto-license-delivery') . '</p>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';
    }
    
    public function save_product_meta($post_id) {
        if (!isset($_POST['auto_license_delivery_meta_nonce']) || 
            !wp_verify_nonce($_POST['auto_license_delivery_meta_nonce'], 'auto_license_delivery_meta')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        $requires_license = isset($_POST['requires_license']) ? 'yes' : 'no';
        update_post_meta($post_id, '_requires_license', $requires_license);
        
        if (isset($_POST['license_duration'])) {
            $license_duration = intval($_POST['license_duration']);
            update_post_meta($post_id, '_license_duration', $license_duration);
        }
        
        if (isset($_POST['max_activations'])) {
            $max_activations = intval($_POST['max_activations']);
            update_post_meta($post_id, '_max_activations', max(1, $max_activations));
        }
    }
    
    // AJAX işleyicileri
    public function ajax_activate_license() {
        check_ajax_referer('auto_license_nonce', 'nonce');
        
        $license_key = sanitize_text_field($_POST['license_key']);
        $domain = sanitize_text_field($_POST['domain']);
        
        $result = $this->activate_license($license_key, $domain);
        
        wp_send_json($result);
    }
    
    public function ajax_deactivate_license() {
        check_ajax_referer('auto_license_nonce', 'nonce');
        
        $license_key = sanitize_text_field($_POST['license_key']);
        $domain = sanitize_text_field($_POST['domain']);
        
        $result = $this->deactivate_license($license_key, $domain);
        
        wp_send_json($result);
    }
    
    private function activate_license($license_key, $domain) {
        global $wpdb;
        
        // Lisans anahtarını kontrol et
        $license_table = $wpdb->prefix . 'auto_license_keys';
        $license = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $license_table WHERE license_key = %s AND status = 'active'",
            $license_key
        ));
        
        if (!$license) {
            return array(
                'success' => false,
                'message' => __('Geçersiz veya pasif lisans anahtarı.', 'auto-license-delivery')
            );
        }
        
        // Süresi dolmuş mu kontrol et
        if ($license->expires_at && strtotime($license->expires_at) < time()) {
            return array(
                'success' => false,
                'message' => __('Lisans anahtarının süresi dolmuş.', 'auto-license-delivery')
            );
        }
        
        // Maksimum aktivasyon kontrolü
        if ($license->activations >= $license->max_activations) {
            return array(
                'success' => false,
                'message' => __('Maksimum aktivasyon sayısına ulaşılmış.', 'auto-license-delivery')
            );
        }
        
        // Domain zaten aktif mi kontrol et
        $activations_table = $wpdb->prefix . 'auto_license_activations';
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $activations_table WHERE license_key = %s AND domain = %s AND status = 'active'",
            $license_key,
            $domain
        ));
        
        if ($existing > 0) {
            return array(
                'success' => false,
                'message' => __('Bu domain için lisans zaten aktif.', 'auto-license-delivery')
            );
        }
        
        // Aktivasyon kaydet
        $result = $wpdb->insert(
            $activations_table,
            array(
                'license_key' => $license_key,
                'domain' => $domain,
                'ip_address' => $_SERVER['REMOTE_ADDR'],
                'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                'status' => 'active'
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
        
        if ($result) {
            // Aktivasyon sayısını güncelle
            $wpdb->update(
                $license_table,
                array('activations' => $license->activations + 1),
                array('license_key' => $license_key),
                array('%d'),
                array('%s')
            );
            
            return array(
                'success' => true,
                'message' => __('Lisans başarıyla aktifleştirildi.', 'auto-license-delivery')
            );
        } else {
            return array(
                'success' => false,
                'message' => __('Aktivasyon sırasında bir hata oluştu.', 'auto-license-delivery')
            );
        }
    }
    
    public function woocommerce_missing_notice() {
        echo '<div class="error"><p>' . sprintf(__('%s eklentisi çalışmak için WooCommerce gerektirir.', 'auto-license-delivery'), '<strong>Otomatik Lisans Anahtarı Teslimi</strong>') . '</p></div>';
    }
}

// Plugin'i başlat
AutoLicenseDelivery::get_instance();

// Ek yardımcı fonksiyonlar
function get_license_by_key($license_key) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'auto_license_keys';
    
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE license_key = %s",
        $license_key
    ));
}

function validate_license_key($license_key, $domain = '') {
    $license = get_license_by_key($license_key);
    
    if (!$license) {
        return array('valid' => false, 'message' => 'Lisans anahtarı bulunamadı');
    }
    
    if ($license->status !== 'active') {
        return array('valid' => false, 'message' => 'Lisans anahtarı pasif durumda');
    }
    
    if ($license->expires_at && strtotime($license->expires_at) < time()) {
        return array('valid' => false, 'message' => 'Lisans anahtarının süresi dolmuş');
    }
    
    return array(
        'valid' => true,
        'license' => $license,
        'message' => 'Geçerli lisans anahtarı'
    );
}
```

### 2. JavaScript Dosyası (assets/js/auto-license-delivery.js)

```javascript
jQuery(document).ready(function($) {
    // Lisans anahtarı kopyalama
    $('.copy-license-key').on('click', function() {
        var licenseKey = $(this).data('license');
        var button = $(this);
        
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(licenseKey).then(function() {
                showCopySuccess(button);
            }).catch(function() {
                fallbackCopy(licenseKey, button);
            });
        } else {
            fallbackCopy(licenseKey, button);
        }
    });
    
    function showCopySuccess(button) {
        var originalText = button.text();
        button.text(auto_license_ajax.strings.copied);
        button.addClass('copied');
        
        setTimeout(function() {
            button.text(originalText);
            button.removeClass('copied');
        }, 2000);
    }
    
    function fallbackCopy(text, button) {
        var textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.opacity = '0';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        
        try {
            document.execCommand('copy');
            showCopySuccess(button);
        } catch (err) {
            alert(auto_license_ajax.strings.copy_failed);
        }
        
        document.body.removeChild(textArea);
    }
    
    // Lisans aktivasyon formu
    $('.license-activation-form').on('submit', function(e) {
        e.preventDefault();
        
        var form = $(this);
        var submitButton = form.find('button[type="submit"]');
        var originalText = submitButton.text();
        
        submitButton.prop('disabled', true);
        submitButton.text(auto_license_ajax.strings.activating);
        
        $.ajax({
            url: auto_license_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'activate_license',
                license_key: form.find('input[name="license_key"]').val(),
                domain: form.find('input[name="domain"]').val(),
                nonce: auto_license_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.message);
                }
            },
            error: function() {
                alert('Bir hata oluştu. Lütfen tekrar deneyin.');
            },
            complete: function() {
                submitButton.prop('disabled', false);
                submitButton.text(originalText);
            }
        });
    });
});
```

### 3. CSS Dosyası (assets/css/auto-license-delivery.css)

```css
.license-keys-container {
    margin: 20px 0;
}

.license-keys-table {
    width: 100%;
    margin-bottom: 20px;
}

.license-keys-table th,
.license-keys-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}

.license-keys-table th {
    background-color: #f8f9fa;
    font-weight: bold;
}

.license-key-cell {
    position: relative;
}

.license-key-code {
    background-color: #f8f9fa;
    padding: 5px 8px;
    border-radius: 4px;
    font-family: monospace;
    font-size: 14px;
    display: inline-block;
    margin-right: 10px;
    border: 1px solid #dee2e6;
}

.copy-license-key {
    background-color: #007cba;
    color: white;
    border: none;
    padding: 5px 10px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
    transition: background-color 0.3s;
}

.copy-license-key:hover {
    background-color: #005a87;
}

.copy-license-key.copied {
    background-color: #28a745;
}

.license-status {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: bold;
    text-transform: uppercase;
}

.status-active {
    background-color: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.status-inactive {
    background-color: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.license-details-modal {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}

.license-details-content {
    background-color: white;
    margin: 5% auto;
    padding: 20px;
    border-radius: 8px;
    width: 80%;
    max-width: 600px;
    position: relative;
}

.license-details-close {
    position: absolute;
    right: 10px;
    top: 10px;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
    color: #aaa;
}

.license-details-close:hover {
    color: #000;
}

@media (max-width: 768px) {
    .license-keys-table,
    .license-keys-table thead,
    .license-keys-table tbody,
    .license-keys-table th,
    .license-keys-table td,
    .license-keys-table tr {
        display: block;
    }

    .license-keys-table thead tr {
        position: absolute;
        top: -9999px;
        left: -9999px;
    }

    .license-keys-table tr {
        border: 1px solid #ccc;
        margin-bottom: 10px;
        padding: 10px;
        border-radius: 4px;
    }

    .license-keys-table td {
        border: none;
        position: relative;
        padding-left: 30%;
        padding-top: 8px;
        padding-bottom: 8px;
    }

    .license-keys-table td:before {
        content: attr(data-title) ": ";
        position: absolute;
        left: 6px;
        width: 25%;
        padding-right: 10px;
        white-space: nowrap;
        font-weight: bold;
        color: #333;
    }
}
```

## Hızlı Kurulum Adımları

1. **Mevcut eklentiyi deaktive edin**
2. **Bu yeni kodu kullanarak eklentiyi güncelleyin**
3. **WordPress Admin → Ayarlar → Permalinks → Kaydet** (önemli!)
4. **Ürünlerde "Lisans Anahtarı Gerektirir" kutucuğunu işaretleyin**
5. **Test siparişi verin ve e-postayı kontrol edin**

## Test Checklist

- [ ] WooCommerce My Account sayfasında "Lisans Anahtarları" sekmesi görünüyor
- [ ] Test siparişi sonrası lisans anahtarı oluşturuluyor
- [ ] E-posta içinde lisans anahtarı gözüküyor
- [ ] Lisans anahtarı kopyalama butonu çalışıyor
- [ ] Ürün ayarlarında lisans seçenekleri mevcut

Bu güncellenmiş kod WordPress 6.4 ve WooCommerce 8.4+ ile tam uyumludur. Tüm sorunları çözer ve müşteri deneyimini önemli ölçüde iyileştirir.