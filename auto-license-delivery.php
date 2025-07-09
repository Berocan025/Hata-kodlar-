<?php
/**
 * Plugin Name: WooCommerce için Otomatik Lisans Teslimatı
 * Plugin URI: 
 * Description: WooCommerce için otomatik lisans anahtarı teslimat eklentisi
 * Version: 1.0.0
 * Author: Wiozen
 * Author URI: www.wiozen.com
 * Text Domain: otomatik-lisans-teslimati
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Sabit lisans anahtarı (şifrelenmiş)
define('ALD_LICENSE_KEY', base64_encode('WİO-4142-1544-1151-4441'));

// Telegram Bot Token ve Chat ID
define('ALD_TELEGRAM_BOT_TOKEN', '');
define('ALD_TELEGRAM_CHAT_ID', '');

// Lisans kontrolü
function ald_check_license() {
    $license_key = get_option('ald_license_key');
    if (!$license_key) {
        add_action('admin_notices', 'ald_license_notice');
        return false;
    }
    return true;
}

// Lisans aktivasyon bildirimi
function ald_license_notice() {
    ?>
    <div class="notice notice-warning is-dismissible">
        <div style="text-align: center; padding: 20px;">
            <h2><?php _e('Auto License Delivery Eklentisi Lisans Aktivasyonu', 'auto-license-delivery'); ?></h2>
            <p><?php _e('Eklentiyi kullanabilmek için lütfen lisans anahtarınızı girin.', 'auto-license-delivery'); ?></p>
            <form method="post" action="" style="max-width: 400px; margin: 20px auto;">
                <?php wp_nonce_field('ald_activate_license'); ?>
                <input type="text" name="ald_license_key" placeholder="<?php _e('Lisans Anahtarını Girin', 'auto-license-delivery'); ?>" required style="width: 100%; padding: 10px; margin-bottom: 10px;">
                <input type="submit" name="ald_activate_license" class="button button-primary" value="<?php _e('Aktifleştir', 'auto-license-delivery'); ?>" style="width: 100%;">
            </form>
        </div>
    </div>
    <?php
}

// Lisans aktivasyonu
function ald_activate_license() {
    if (isset($_POST['ald_activate_license'])) {
        check_admin_referer('ald_activate_license');
        
        $license_key = sanitize_text_field($_POST['ald_license_key']);
        
        if (base64_encode($license_key) === ALD_LICENSE_KEY) {
            // Lisans anahtarını şifreleyerek kaydet
            $encrypted_key = ald_encrypt_license_key($license_key);
            update_option('ald_license_key', $encrypted_key);
            
            // Telegram bildirimi gönder
            $site_url = esc_url(get_site_url());
            $message = "🔔 Yeni Eklenti Aktivasyonu!\n\n";
            $message .= "Site: {$site_url}\n";
            $message .= "Eklenti: Auto License Delivery\n";
            $message .= "Tarih: " . current_time('mysql');
            
            wp_remote_post('https://api.telegram.org/bot' . ALD_TELEGRAM_BOT_TOKEN . '/sendMessage', array(
                'body' => array(
                    'chat_id' => ALD_TELEGRAM_CHAT_ID,
                    'text' => $message,
                    'parse_mode' => 'HTML'
                ),
                'timeout' => 15,
                'sslverify' => true
            ));
            
            echo '<div class="notice notice-success"><p>' . esc_html__('Lisans başarıyla aktifleştirildi!', 'auto-license-delivery') . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>' . esc_html__('Geçersiz lisans anahtarı!', 'auto-license-delivery') . '</p></div>';
        }
    }
}
add_action('admin_init', 'ald_activate_license');

// Güvenlik kontrolleri
function ald_security_checks() {
    // AJAX isteklerini kontrol et
    if (defined('DOING_AJAX') && DOING_AJAX) {
        // Nonce kontrolü sadece belirli AJAX işlemleri için yapılacak
        $allowed_actions = array('ald_get_license_keys', 'ald_save_license_keys', 'ald_send_customer_license');
        if (isset($_POST['action']) && in_array($_POST['action'], $allowed_actions)) {
            if (!check_ajax_referer('ald_nonce', 'nonce', false)) {
                wp_send_json_error('Invalid nonce');
            }
        }
    }

    // Admin yetkisi kontrolü
    if (!current_user_can('manage_options')) {
        wp_die(__('Bu sayfaya erişim yetkiniz bulunmamaktadır.', 'auto-license-delivery'));
    }
}
add_action('admin_init', 'ald_security_checks');

// Admin paneli için stil ekle
function ald_admin_styles() {
    // Sadece eklenti sayfasında stil uygula
    if (!isset($_GET['page']) || $_GET['page'] !== 'auto-license-delivery') {
        return;
    }

    wp_enqueue_style('google-fonts', 'https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
    
    // Özel CSS'i güvenli bir şekilde ekle
    $custom_css = get_option('ald_custom_css', '');
    if (!empty($custom_css)) {
        wp_add_inline_style('google-fonts', wp_strip_all_tags($custom_css));
    }
    
    ?>
    <style>
        .wrap {
            font-family: 'Poppins', sans-serif;
            max-width: 1200px;
            margin: 20px auto;
            padding: 0 20px;
        }
        .card {
            background: #fff;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            margin-bottom: 20px;
            min-width: 100% !important;
            padding: 20px;
            border-radius: 4px;
        }
        .card h2 {
            margin-top: 0;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        .form-table th {
            width: 200px;
        }
        .wp-list-table {
            margin-top: 20px;
        }
        @media screen and (max-width: 782px) {
            .wrap {
                padding: 0 10px;
            }
            .form-table th {
                width: auto;
                display: block;
                padding-bottom: 0;
            }
            .form-table td {
                display: block;
                padding-top: 0;
            }
        }
    </style>
    <?php
}
add_action('admin_head', 'ald_admin_styles');

// Özel CSS kaydetme işlemi
function ald_save_custom_css() {
    if (isset($_POST['ald_save_custom_css']) && check_admin_referer('ald_save_custom_css')) {
        $custom_css = isset($_POST['custom_css']) ? sanitize_textarea_field($_POST['custom_css']) : '';
        update_option('ald_custom_css', $custom_css);
        echo '<div class="notice notice-success"><p>' . esc_html__('Özel CSS başarıyla kaydedildi!', 'auto-license-delivery') . '</p></div>';
    }
}
add_action('admin_init', 'ald_save_custom_css');

// Admin sayfasına özel CSS alanı ekle
function ald_add_custom_css_field() {
    ?>
    <div class="card">
        <h2><?php _e('Özel CSS', 'auto-license-delivery'); ?></h2>
        <form method="post" action="">
            <?php wp_nonce_field('ald_save_custom_css'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="custom_css"><?php _e('CSS Kodları', 'auto-license-delivery'); ?></label>
                    </th>
                    <td>
                        <textarea name="custom_css" id="custom_css" rows="10" class="large-text code"><?php echo esc_textarea(get_option('ald_custom_css', '')); ?></textarea>
                        <p class="description"><?php _e('Özel CSS kodlarınızı buraya ekleyebilirsiniz.', 'auto-license-delivery'); ?></p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="ald_save_custom_css" class="button button-primary" value="<?php _e('Kaydet', 'auto-license-delivery'); ?>">
            </p>
        </form>
    </div>
    <?php
}

// XSS koruması için çıktı temizleme
function ald_sanitize_output($output) {
    return wp_kses_post($output);
}

// Lisans anahtarını şifrele
function ald_encrypt_license_key($key) {
    return wp_hash($key);
}

// Lisans anahtarını doğrula
function ald_verify_license_key($key) {
    $stored_key = get_option('ald_license_key');
    return wp_verify_nonce($key, $stored_key);
}

// WooCommerce aktif mi kontrol et
function ald_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'ald_woocommerce_missing_notice');
        return false;
    }
    return true;
}

function ald_woocommerce_missing_notice() {
    ?>
    <div class="error">
        <p><?php _e('Auto License Delivery eklentisi için WooCommerce yüklü ve aktif olmalıdır.', 'auto-license-delivery'); ?></p>
    </div>
    <?php
}

// Veri temizleme işlemleri
function ald_handle_data_cleanup() {
    if (isset($_POST['ald_cleanup_all']) && check_admin_referer('ald_cleanup_all')) {
        global $wpdb;
        
        // Tüm lisans anahtarlarını temizle
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_license_keys'");
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_sent_license_keys'");
        
        // Lisans anahtarı seçeneğini temizle
        delete_option('ald_license_key');
        
        echo '<div class="notice notice-success"><p>' . esc_html__('Tüm veriler başarıyla temizlendi!', 'auto-license-delivery') . '</p></div>';
    }
    
    if (isset($_POST['ald_cleanup_selected']) && check_admin_referer('ald_cleanup_selected')) {
        if (isset($_POST['selected_products']) && is_array($_POST['selected_products'])) {
            foreach ($_POST['selected_products'] as $product_id) {
                delete_post_meta($product_id, '_license_keys');
                delete_post_meta($product_id, '_sent_license_keys');
            }
            echo '<div class="notice notice-success"><p>' . esc_html__('Seçili ürünlerin verileri başarıyla temizlendi!', 'auto-license-delivery') . '</p></div>';
        }
    }
}
add_action('admin_init', 'ald_handle_data_cleanup');

// Eklenti başlatma
function ald_init() {
    if (!ald_check_woocommerce()) {
        return;
    }

    if (!ald_check_license()) {
        return;
    }

    // Admin menüsü ekle
    add_action('admin_menu', 'ald_add_admin_menu');
    
    // Lisans anahtarı meta alanını ekle
    add_action('woocommerce_product_options_general_product_data', 'ald_add_license_key_field');
    add_action('woocommerce_process_product_meta', 'ald_save_license_key_field');

    // Sipariş tamamlandığında lisans anahtarını gönder
    add_action('woocommerce_order_status_completed', 'ald_deliver_license_key');

    // Siparişlerim sayfasına lisans anahtarlarını ekle
    add_action('woocommerce_order_details_after_order_table', 'ald_display_license_keys_in_order');
    add_action('woocommerce_my_account_my_orders_column_order-total', 'ald_display_license_keys_in_orders_list');

    // Admin panelinde gönderilen lisans anahtarlarını göster
    add_action('woocommerce_product_data_tabs', 'ald_add_license_keys_tab');
    add_action('woocommerce_product_data_panels', 'ald_add_license_keys_panel');

    // Müşteri paneli menüsüne lisans anahtarları ekle
    add_filter('woocommerce_account_menu_items', 'ald_add_my_account_menu_item');
}
add_action('plugins_loaded', 'ald_init');

// HPOS uyumluluğu için
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('order_cache', __FILE__, true);
    }
});

// Admin menüsü ekle
function ald_add_admin_menu() {
    add_menu_page(
        __('Lisans Anahtarları', 'auto-license-delivery'),
        __('Lisans Anahtarları', 'auto-license-delivery'),
        'manage_options',
        'auto-license-delivery',
        'ald_admin_page',
        'dashicons-lock',
        56
    );
}

// Admin sayfası içeriği
function ald_admin_page() {
    ald_handle_data_cleanup();
    
    // Ürünleri getir
    $products = wc_get_products(array(
        'limit' => -1,
        'status' => 'publish',
        'type' => array('simple', 'variable')
    ));

    // Müşterileri getir
    $customers = get_users(array('role' => 'customer'));
    ?>
    <div class="wrap">
        <h1><?php _e('Lisans Anahtarları Yönetimi', 'auto-license-delivery'); ?></h1>
        
        <div class="card">
            <h2><?php _e('Veri Temizleme', 'auto-license-delivery'); ?></h2>
            <form method="post" action="" style="margin-bottom: 20px;">
                <?php wp_nonce_field('ald_cleanup_all'); ?>
                <p class="submit">
                    <input type="submit" name="ald_cleanup_all" class="button button-secondary" value="<?php _e('Tüm Verileri Sıfırla', 'auto-license-delivery'); ?>" onclick="return confirm('<?php _e('Bu işlem tüm lisans anahtarlarını ve gönderim kayıtlarını silecektir. Devam etmek istediğinize emin misiniz?', 'auto-license-delivery'); ?>');">
                </p>
            </form>
            
            <form method="post" action="">
                <?php wp_nonce_field('ald_cleanup_selected'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label><?php _e('Seçili Ürünleri Temizle', 'auto-license-delivery'); ?></label>
                        </th>
                        <td>
                            <select name="selected_products[]" multiple style="width: 100%; height: 150px;">
                                <?php foreach ($products as $product) : ?>
                                    <option value="<?php echo $product->get_id(); ?>"><?php echo $product->get_name(); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('Seçili ürünlerin lisans anahtarlarını ve gönderim kayıtlarını temizler.', 'auto-license-delivery'); ?></p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="ald_cleanup_selected" class="button button-secondary" value="<?php _e('Seçili Ürünleri Temizle', 'auto-license-delivery'); ?>" onclick="return confirm('<?php _e('Seçili ürünlerin lisans anahtarları ve gönderim kayıtları silinecektir. Devam etmek istediğinize emin misiniz?', 'auto-license-delivery'); ?>');">
                </p>
            </form>
        </div>

        <div class="card">
            <h2><?php _e('Lisans Anahtarı Ekle', 'auto-license-delivery'); ?></h2>
            <form method="post" action="">
                <?php wp_nonce_field('ald_save_license_keys'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="product_id"><?php _e('Ürün Seçin', 'auto-license-delivery'); ?></label>
                        </th>
                        <td>
                            <select name="product_id" id="product_id" required>
                                <option value=""><?php _e('Ürün seçin...', 'auto-license-delivery'); ?></option>
                                <?php foreach ($products as $product) : ?>
                                    <option value="<?php echo $product->get_id(); ?>"><?php echo $product->get_name(); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="license_keys"><?php _e('Lisans Anahtarları', 'auto-license-delivery'); ?></label>
                        </th>
                        <td>
                            <textarea name="license_keys" id="license_keys" rows="10" class="large-text" placeholder="<?php _e('Her satıra bir lisans anahtarı yazın', 'auto-license-delivery'); ?>"></textarea>
                            <p class="description"><?php _e('Her satıra bir lisans anahtarı yazın. Satın alındığında otomatik olarak müşteriye gönderilecektir.', 'auto-license-delivery'); ?></p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="ald_save_license_keys" class="button button-primary" value="<?php _e('Kaydet', 'auto-license-delivery'); ?>">
                </p>
            </form>
        </div>

        <div class="card">
            <h2><?php _e('Müşteriye Özel Lisans Gönder', 'auto-license-delivery'); ?></h2>
            <form method="post" action="">
                <?php wp_nonce_field('ald_send_customer_license'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="customer_id"><?php _e('Müşteri Seçin', 'auto-license-delivery'); ?></label>
                        </th>
                        <td>
                            <select name="customer_id" id="customer_id" required>
                                <option value=""><?php _e('Müşteri seçin...', 'auto-license-delivery'); ?></option>
                                <?php foreach ($customers as $customer) : ?>
                                    <option value="<?php echo $customer->ID; ?>"><?php echo $customer->display_name . ' (' . $customer->user_email . ')'; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="customer_product_id"><?php _e('Ürün Seçin', 'auto-license-delivery'); ?></label>
                        </th>
                        <td>
                            <select name="customer_product_id" id="customer_product_id" required>
                                <option value=""><?php _e('Ürün seçin...', 'auto-license-delivery'); ?></option>
                                <?php foreach ($products as $product) : ?>
                                    <option value="<?php echo $product->get_id(); ?>"><?php echo $product->get_name(); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="customer_license_key"><?php _e('Lisans Anahtarı', 'auto-license-delivery'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="customer_license_key" id="customer_license_key" class="regular-text" required>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="ald_send_customer_license" class="button button-primary" value="<?php _e('Gönder', 'auto-license-delivery'); ?>">
                </p>
            </form>
        </div>

        <div class="card">
            <h2><?php _e('Gönderilen Lisans Anahtarları', 'auto-license-delivery'); ?></h2>
            <form method="get" action="">
                <input type="hidden" name="page" value="auto-license-delivery">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="filter_product"><?php _e('Ürün Filtrele', 'auto-license-delivery'); ?></label>
                        </th>
                        <td>
                            <select name="filter_product" id="filter_product">
                                <option value=""><?php _e('Tüm Ürünler', 'auto-license-delivery'); ?></option>
                                <?php foreach ($products as $product) : ?>
                                    <option value="<?php echo $product->get_id(); ?>" <?php selected(isset($_GET['filter_product']) ? $_GET['filter_product'] : '', $product->get_id()); ?>>
                                        <?php echo $product->get_name(); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="submit" class="button" value="<?php _e('Filtrele', 'auto-license-delivery'); ?>">
                        </td>
                    </tr>
                </table>
            </form>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Ürün', 'auto-license-delivery'); ?></th>
                        <th><?php _e('Sipariş ID', 'auto-license-delivery'); ?></th>
                        <th><?php _e('Müşteri', 'auto-license-delivery'); ?></th>
                        <th><?php _e('Lisans Anahtarı', 'auto-license-delivery'); ?></th>
                        <th><?php _e('Tarih', 'auto-license-delivery'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $filter_product = isset($_GET['filter_product']) ? intval($_GET['filter_product']) : 0;
                    foreach ($products as $product) {
                        if ($filter_product && $filter_product != $product->get_id()) {
                            continue;
                        }
                        
                        $sent_keys = get_post_meta($product->get_id(), '_sent_license_keys', true);
                        if (!empty($sent_keys) && is_array($sent_keys)) {
                            foreach ($sent_keys as $sent_key) {
                                $customer = isset($sent_key['customer_id']) ? get_user_by('id', $sent_key['customer_id']) : null;
                                echo '<tr>';
                                echo '<td>' . $product->get_name() . '</td>';
                                echo '<td>' . $sent_key['order_id'] . '</td>';
                                echo '<td>' . ($customer ? $customer->display_name . ' (' . $customer->user_email . ')' : '-') . '</td>';
                                echo '<td><code>' . esc_html($sent_key['key']) . '</code></td>';
                                echo '<td>' . $sent_key['date'] . '</td>';
                                echo '</tr>';
                            }
                        }
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        $('#product_id').change(function() {
            var productId = $(this).val();
            if (productId) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ald_get_product_license_keys',
                        product_id: productId,
                        nonce: '<?php echo wp_create_nonce('ald_get_license_keys'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#license_keys').val(response.data);
                        }
                    }
                });
            }
        });
    });
    </script>
    <?php
}

// AJAX ile ürün lisans anahtarlarını getir
add_action('wp_ajax_ald_get_product_license_keys', 'ald_get_product_license_keys');
function ald_get_product_license_keys() {
    check_ajax_referer('ald_get_license_keys', 'nonce');
    
    $product_id = intval($_POST['product_id']);
    $license_keys = get_post_meta($product_id, '_license_keys', true);
    
    wp_send_json_success($license_keys);
}

// Ürün sayfasına lisans anahtarı alanı ekle
function ald_add_license_key_field() {
    global $woocommerce, $post;
    
    echo '<div class="options_group">';
    
    woocommerce_wp_textarea_input(
        array(
            'id'          => '_license_keys',
            'label'       => __('Lisans Anahtarları', 'auto-license-delivery'),
            'placeholder' => __('Her satıra bir lisans anahtarı yazın', 'auto-license-delivery'),
            'desc_tip'    => true,
            'description' => __('Her satıra bir lisans anahtarı yazın. Satın alındığında otomatik olarak müşteriye gönderilecektir.', 'auto-license-delivery')
        )
    );
    
    echo '</div>';
}

// Lisans anahtarları sekmesi ekle
function ald_add_license_keys_tab($tabs) {
    $tabs['license_keys'] = array(
        'label'    => __('Lisans Anahtarları', 'auto-license-delivery'),
        'target'   => 'license_keys_product_data',
        'class'    => array('show_if_simple', 'show_if_variable'),
        'priority' => 70
    );
    return $tabs;
}

// Lisans anahtarları paneli ekle
function ald_add_license_keys_panel() {
    global $post;
    ?>
    <div id="license_keys_product_data" class="panel woocommerce_options_panel">
        <?php
        // Gönderilen lisans anahtarlarını göster
        $sent_keys = get_post_meta($post->ID, '_sent_license_keys', true);
        if (!empty($sent_keys)) {
            echo '<div class="options_group">';
            echo '<h4>' . __('Gönderilen Lisans Anahtarları', 'auto-license-delivery') . '</h4>';
            echo '<table class="widefat">';
            echo '<thead><tr><th>' . __('Sipariş ID', 'auto-license-delivery') . '</th><th>' . __('Lisans Anahtarı', 'auto-license-delivery') . '</th><th>' . __('Tarih', 'auto-license-delivery') . '</th></tr></thead>';
            echo '<tbody>';
            
            foreach ($sent_keys as $sent_key) {
                echo '<tr>';
                echo '<td>' . $sent_key['order_id'] . '</td>';
                echo '<td><code>' . esc_html($sent_key['key']) . '</code></td>';
                echo '<td>' . $sent_key['date'] . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
            echo '</div>';
        } else {
            echo '<div class="options_group">';
            echo '<p>' . __('Henüz gönderilmiş lisans anahtarı bulunmuyor.', 'auto-license-delivery') . '</p>';
            echo '</div>';
        }
        ?>
    </div>
    <?php
}

// Lisans anahtarlarını kaydet
function ald_save_license_key_field($post_id) {
    $license_keys = isset($_POST['_license_keys']) ? sanitize_textarea_field($_POST['_license_keys']) : '';
    update_post_meta($post_id, '_license_keys', $license_keys);
}

// Lisans anahtarını gönder
function ald_deliver_license_key($order_id) {
    $order = wc_get_order($order_id);
    
    foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();
        $license_keys = get_post_meta($product_id, '_license_keys', true);
        
        if (!empty($license_keys)) {
            $keys_array = array_filter(explode("\n", $license_keys));
            
            if (!empty($keys_array)) {
                $license_key = array_shift($keys_array);
                
                // Lisans anahtarını sipariş meta verilerine kaydet
                $order->update_meta_data('_license_key_' . $product_id, $license_key);
                $order->save();
                
                // Gönderilen lisans anahtarını kaydet
                $sent_keys = get_post_meta($product_id, '_sent_license_keys', true);
                if (!is_array($sent_keys)) {
                    $sent_keys = array();
                }
                
                $sent_keys[] = array(
                    'order_id' => $order_id,
                    'key' => $license_key,
                    'date' => current_time('mysql'),
                    'customer_id' => $order->get_customer_id()
                );
                
                update_post_meta($product_id, '_sent_license_keys', $sent_keys);
                
                // Lisans anahtarını sipariş notlarına ekle
                $order->add_order_note(
                    sprintf(__('Lisans Anahtarı: %s', 'auto-license-delivery'), $license_key),
                    false
                );
                
                // Müşteriye e-posta gönder
                $customer_email = $order->get_billing_email();
                $subject = sprintf(__('Sipariş #%s için Lisans Anahtarınız', 'auto-license-delivery'), $order_id);
                
                // HTML formatında e-posta
                $message = sprintf(
                    '<p>Merhaba %s,</p>
                    <p>Siparişiniz için lisans anahtarınız:</p>
                    <p style="background: #f5f5f5; padding: 15px; border-radius: 5px; font-family: monospace; font-size: 16px;">%s</p>
                    <p>Saygılarımızla,<br>%s</p>',
                    $order->get_billing_first_name(),
                    $license_key,
                    get_bloginfo('name')
                );
                
                $headers = array('Content-Type: text/html; charset=UTF-8');
                wp_mail($customer_email, $subject, $message, $headers);
                
                // Kullanılan anahtarı listeden çıkar
                update_post_meta($product_id, '_license_keys', implode("\n", $keys_array));
            }
        }
    }
}

// Sipariş detay sayfasında lisans anahtarlarını göster
function ald_display_license_keys_in_order($order) {
    $order = wc_get_order($order->get_id());
    
    echo '<h2>' . __('Lisans Anahtarları', 'auto-license-delivery') . '</h2>';
    echo '<table class="woocommerce-table woocommerce-table--license-keys">';
    echo '<thead><tr><th>' . __('Ürün', 'auto-license-delivery') . '</th><th>' . __('Lisans Anahtarı', 'auto-license-delivery') . '</th></tr></thead>';
    echo '<tbody>';
    
    foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();
        $license_key = $order->get_meta('_license_key_' . $product_id);
        
        if (!empty($license_key)) {
            echo '<tr>';
            echo '<td>' . $item->get_name() . '</td>';
            echo '<td><code>' . esc_html($license_key) . '</code></td>';
            echo '</tr>';
        }
    }
    
    echo '</tbody></table>';
}

// Siparişlerim listesinde lisans anahtarlarını göster
function ald_display_license_keys_in_orders_list($order) {
    $order = wc_get_order($order->get_id());
    
    foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();
        $license_key = $order->get_meta('_license_key_' . $product_id);
        
        if (!empty($license_key)) {
            echo '<br><small>' . __('Lisans Anahtarı:', 'auto-license-delivery') . ' <code>' . esc_html($license_key) . '</code></small>';
        }
    }
}

// Müşteri paneli lisans anahtarları sayfası
function ald_my_account_license_keys() {
    $customer_id = get_current_user_id();
    $products = wc_get_products(array(
        'limit' => -1,
        'status' => 'publish',
        'type' => array('simple', 'variable')
    ));
    
    echo '<h2>' . __('Lisans Anahtarlarım', 'auto-license-delivery') . '</h2>';
    echo '<table class="woocommerce-table woocommerce-table--license-keys">';
    echo '<thead><tr><th>' . __('Ürün', 'auto-license-delivery') . '</th><th>' . __('Lisans Anahtarı', 'auto-license-delivery') . '</th><th>' . __('Tarih', 'auto-license-delivery') . '</th></tr></thead>';
    echo '<tbody>';
    
    foreach ($products as $product) {
        $sent_keys = get_post_meta($product->get_id(), '_sent_license_keys', true);
        if (!empty($sent_keys) && is_array($sent_keys)) {
            foreach ($sent_keys as $sent_key) {
                if (isset($sent_key['customer_id']) && $sent_key['customer_id'] == $customer_id) {
                    echo '<tr>';
                    echo '<td>' . $product->get_name() . '</td>';
                    echo '<td><code>' . esc_html($sent_key['key']) . '</code></td>';
                    echo '<td>' . $sent_key['date'] . '</td>';
                    echo '</tr>';
                }
            }
        }
    }
    
    echo '</tbody></table>';
}
add_action('woocommerce_account_license-keys_endpoint', 'ald_my_account_license_keys');

// Müşteri paneli endpoint ekle
function ald_add_endpoints() {
    add_rewrite_endpoint('license-keys', EP_ROOT | EP_PAGES);
}
add_action('init', 'ald_add_endpoints');

// Müşteri paneli menüsüne lisans anahtarları ekle
function ald_add_my_account_menu_item($items) {
    $items['license-keys'] = __('Lisans Anahtarlarım', 'auto-license-delivery');
    return $items;
}

// İndirme sayfasına lisans anahtarlarını ekle
function ald_add_downloads_license_keys($downloads) {
    $customer_id = get_current_user_id();
    $products = wc_get_products(array(
        'limit' => -1,
        'status' => 'publish',
        'type' => array('simple', 'variable')
    ));
    
    foreach ($products as $product) {
        $sent_keys = get_post_meta($product->get_id(), '_sent_license_keys', true);
        if (!empty($sent_keys) && is_array($sent_keys)) {
            foreach ($sent_keys as $sent_key) {
                if (isset($sent_key['customer_id']) && $sent_key['customer_id'] == $customer_id) {
                    $downloads[] = array(
                        'download_url' => '#',
                        'download_id' => 'license-' . $sent_key['key'],
                        'product_id' => $product->get_id(),
                        'product_name' => $product->get_name(),
                        'download_name' => sprintf(__('Lisans Anahtarı: %s', 'auto-license-delivery'), $sent_key['key']),
                        'order_id' => $sent_key['order_id'],
                        'order_key' => $sent_key['order_id'],
                        'downloads_remaining' => '',
                        'access_expires' => '',
                        'expires' => '',
                        'file' => array(
                            'name' => sprintf(__('Lisans Anahtarı: %s', 'auto-license-delivery'), $sent_key['key']),
                            'file' => '',
                            'id' => 'license-' . $sent_key['key']
                        )
                    );
                }
            }
        }
    }
    
    return $downloads;
}
add_filter('woocommerce_account_downloads', 'ald_add_downloads_license_keys'); 