<?php
/**
 * WooCommerce Entegrasyonu ve Müşteri Paneli Fonksiyonları
 */

// Güvenlik kontrolü
if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

// WooCommerce entegrasyonu için trait
trait AutoLicenseDelivery_WooCommerce {
    
    /**
     * Ürün sayfasına lisans alanı ekle
     */
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
            'label' => __('License Keys (one per line)', 'auto-license-delivery'),
            'placeholder' => __('Enter license keys, one per line...', 'auto-license-delivery'),
            'desc_tip' => true,
            'description' => __('License keys will be automatically delivered to customers upon order completion.', 'auto-license-delivery'),
            'value' => implode("\n", $license_keys),
            'custom_attributes' => array(
                'rows' => 8,
                'style' => 'font-family: monospace; font-size: 12px;'
            )
        ));
        
        $sold_count = $this->get_product_sold_licenses_count($post->ID);
        
        echo '<div class="form-field" style="background: #f9f9f9; padding: 15px; border-radius: 5px; margin-top: 10px;">';
        echo '<h4 style="margin: 0 0 10px 0; color: #333;">📊 License Statistics</h4>';
        echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px;">';
        echo '<div style="text-align: center;">';
        echo '<div style="font-size: 24px; font-weight: bold; color: #28a745;">' . count($license_keys) . '</div>';
        echo '<div style="font-size: 12px; color: #666;">Available</div>';
        echo '</div>';
        echo '<div style="text-align: center;">';
        echo '<div style="font-size: 24px; font-weight: bold; color: #dc3545;">' . $sold_count . '</div>';
        echo '<div style="font-size: 12px; color: #666;">Sold</div>';
        echo '</div>';
        echo '<div style="text-align: center;">';
        $percentage = (count($license_keys) + $sold_count) > 0 ? round(($sold_count / (count($license_keys) + $sold_count)) * 100, 1) : 0;
        echo '<div style="font-size: 24px; font-weight: bold; color: #ffc107;">' . $percentage . '%</div>';
        echo '<div style="font-size: 12px; color: #666;">Usage Rate</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        
        echo '</div>';
    }
    
    /**
     * Ürün lisans anahtarlarını kaydet
     */
    public function save_license_field($post_id) {
        if (!isset($_POST['_ald_license_keys_text'])) {
            return;
        }
        
        // Güvenlik kontrolleri
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        $license_keys = sanitize_textarea_field($_POST['_ald_license_keys_text']);
        $keys_array = array_filter(explode("\n", $license_keys));
        $keys_array = array_map('trim', $keys_array);
        $keys_array = array_unique($keys_array); // Duplicate'leri kaldır
        
        update_post_meta($post_id, '_ald_license_keys', $keys_array);
    }
    
    /**
     * Ürün için satılan lisans sayısını getir
     */
    private function get_product_sold_licenses_count($product_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ald_license_history';
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE product_id = %d",
            $product_id
        ));
    }
    
    /**
     * Sipariş tamamlandığında lisans teslimatı
     */
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
            
            // Zaten gönderilmiş mi kontrol et
            if ($this->is_license_already_sent($order_id, $product_id)) {
                continue;
            }
            
            $license_keys = get_post_meta($product_id, '_ald_license_keys', true);
            $license_keys = is_array($license_keys) ? $license_keys : array();
            
            if (!empty($license_keys)) {
                $license_key = array_shift($license_keys);
                
                // Kullanılan anahtarı listeden kaldır
                update_post_meta($product_id, '_ald_license_keys', $license_keys);
                
                // Veritabanına kaydet
                $this->save_delivered_license($order, $product_id, $license_key);
                
                // E-posta gönder
                $this->send_license_email($order, $product, $license_key);
                
                // Sipariş notuna ekle
                $order->add_order_note(
                    sprintf(__('License key delivered: %s', 'auto-license-delivery'), $license_key),
                    false
                );
                
                // Meta data ekle (HPOS uyumlu)
                $order->update_meta_data('_ald_license_' . $product_id, $license_key);
                $order->save();
            }
        }
    }
    
    /**
     * Lisans zaten gönderilmiş mi kontrol et
     */
    private function is_license_already_sent($order_id, $product_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ald_license_history';
        
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE order_id = %s AND product_id = %d",
            $order_id, $product_id
        ));
        
        return !empty($existing);
    }
    
    /**
     * Teslim edilen lisansı kaydet
     */
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
                'ip_address' => $this->get_client_ip(),
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '',
                'created_at' => current_time('mysql')
            ),
            array('%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );
    }
    
    /**
     * Lisans e-postası gönder
     */
    private function send_license_email($order, $product, $license_key) {
        $customer_email = $order->get_billing_email();
        $customer_name = $order->get_billing_first_name();
        $subject = sprintf(
            __('[%s] Your License Key for %s', 'auto-license-delivery'),
            get_bloginfo('name'), 
            $product->get_name()
        );
        
        $message = $this->get_license_email_template($customer_name, $product->get_name(), $license_key, $order->get_id());
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        );
        
        return wp_mail($customer_email, $subject, $message, $headers);
    }
    
    /**
     * E-posta şablonu
     */
    private function get_license_email_template($customer_name, $product_name, $license_key, $order_id) {
        $site_name = get_bloginfo('name');
        $site_url = home_url();
        
        return sprintf('
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>License Key Delivery</title>
            <style>
                body { 
                    font-family: Arial, sans-serif; 
                    line-height: 1.6; 
                    color: #333; 
                    margin: 0; 
                    padding: 0; 
                    background: #f8f9fa;
                }
                .container { 
                    max-width: 600px; 
                    margin: 20px auto; 
                    background: white;
                    border-radius: 12px;
                    overflow: hidden;
                    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
                }
                .header { 
                    background: linear-gradient(135deg, #667eea 0%%, #764ba2 100%%); 
                    color: white; 
                    padding: 30px 20px; 
                    text-align: center;
                }
                .header h1 {
                    margin: 0;
                    font-size: 28px;
                }
                .content {
                    padding: 30px 20px;
                }
                .license-box { 
                    background: linear-gradient(135deg, #e8f5e8 0%%, #f8f9fa 100%%); 
                    border: 2px solid #28a745; 
                    padding: 25px; 
                    border-radius: 12px; 
                    margin: 25px 0; 
                    text-align: center;
                }
                .license-key { 
                    font-family: "Courier New", monospace; 
                    font-size: 20px; 
                    font-weight: bold; 
                    color: #28a745; 
                    word-break: break-all;
                    background: white;
                    padding: 15px;
                    border-radius: 8px;
                    border: 1px solid #c3e6cb;
                    margin-top: 10px;
                }
                .info-box {
                    background: #f8f9fa;
                    padding: 20px;
                    border-radius: 8px;
                    margin: 20px 0;
                }
                .footer { 
                    background: #f8f9fa;
                    padding: 20px; 
                    text-align: center;
                    font-size: 14px; 
                    color: #666;
                    border-top: 1px solid #eee;
                }
                .btn {
                    display: inline-block;
                    padding: 12px 24px;
                    background: linear-gradient(135deg, #667eea 0%%, #764ba2 100%%);
                    color: white;
                    text-decoration: none;
                    border-radius: 8px;
                    font-weight: bold;
                    margin: 10px 0;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>🔑 License Key Delivery</h1>
                </div>
                
                <div class="content">
                    <p>Hello <strong>%s</strong>,</p>
                    
                    <p>Thank you for your purchase! Your license key for <strong>%s</strong> is ready and waiting for you.</p>
                    
                    <div class="license-box">
                        <h3 style="margin: 0 0 15px 0; color: #28a745;">Your License Key:</h3>
                        <div class="license-key">%s</div>
                    </div>
                    
                    <div class="info-box">
                        <h4 style="margin: 0 0 10px 0;">📋 Order Details:</h4>
                        <p style="margin: 5px 0;"><strong>Order ID:</strong> #%s</p>
                        <p style="margin: 5px 0;"><strong>Product:</strong> %s</p>
                        <p style="margin: 5px 0;"><strong>Date:</strong> %s</p>
                    </div>
                    
                    <p>💡 <strong>Important:</strong> Please save this license key in a safe place. You can also view your license keys anytime in your account dashboard.</p>
                    
                    <div style="text-align: center; margin: 30px 0;">
                        <a href="%s/my-account/license-keys/" class="btn">View My License Keys</a>
                    </div>
                    
                    <p>If you have any questions or need assistance, please don\'t hesitate to contact our support team.</p>
                </div>
                
                <div class="footer">
                    <p>Best regards,<br><strong>%s</strong></p>
                    <p><a href="%s" style="color: #667eea;">%s</a></p>
                    <p style="font-size: 12px; margin-top: 20px;">This email was sent automatically. Please do not reply to this email.</p>
                </div>
            </div>
        </body>
        </html>',
            esc_html($customer_name),
            esc_html($product_name),
            esc_html($license_key),
            esc_html($order_id),
            esc_html($product_name),
            date('F j, Y'),
            esc_url($site_url),
            esc_html($site_name),
            esc_url($site_url),
            esc_html($site_url)
        );
    }
    
    /**
     * Client IP adresini güvenli şekilde al
     */
    private function get_client_ip() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * Müşteri hesabı endpoint'leri ekle
     */
    public function add_account_endpoints() {
        add_rewrite_endpoint('license-keys', EP_ROOT | EP_PAGES);
    }
    
    /**
     * Müşteri hesabı menüsüne lisans anahtarları ekle
     */
    public function add_account_menu_item($items) {
        $new_items = array();
        
        foreach ($items as $key => $item) {
            $new_items[$key] = $item;
            
            // Downloads'tan sonra ekle
            if ('downloads' === $key) {
                $new_items['license-keys'] = __('My License Keys', 'auto-license-delivery');
            }
        }
        
        return $new_items;
    }
    
    /**
     * Müşteri lisans anahtarları sayfası içeriği
     */
    public function license_keys_content() {
        $customer_id = get_current_user_id();
        
        if (!$customer_id) {
            wc_print_notice(__('Please log in to view your license keys.', 'auto-license-delivery'), 'error');
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
        
        // Inline CSS
        echo '<style>
        .ald-customer-licenses {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 12px;
            margin: 20px 0;
        }
        .ald-customer-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .ald-customer-header h2 {
            color: #333;
            font-size: 28px;
            margin: 0 0 10px 0;
        }
        .ald-customer-subtitle {
            color: #666;
            font-size: 16px;
            margin: 0;
        }
        .ald-license-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }
        .ald-license-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }
        .ald-license-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        .ald-product-name {
            font-size: 18px;
            font-weight: bold;
            color: #333;
            margin: 0;
        }
        .ald-license-date {
            color: #666;
            font-size: 14px;
        }
        .ald-license-key-container {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: 2px solid #28a745;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
            position: relative;
        }
        .ald-license-key-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }
        .ald-license-key-value {
            font-family: "Courier New", monospace;
            font-size: 16px;
            font-weight: bold;
            color: #28a745;
            word-break: break-all;
            margin: 0;
        }
        .ald-copy-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #28a745;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            transition: background 0.3s ease;
        }
        .ald-copy-btn:hover {
            background: #218838;
        }
        .ald-no-licenses {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }
        .ald-no-licenses-icon {
            font-size: 48px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        @media (max-width: 768px) {
            .ald-customer-licenses {
                padding: 15px;
            }
            .ald-license-card {
                padding: 20px;
            }
            .ald-license-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .ald-license-date {
                margin-top: 5px;
            }
        }
        </style>';
        
        echo '<div class="ald-customer-licenses">';
        echo '<div class="ald-customer-header">';
        echo '<h2>🔑 My License Keys</h2>';
        echo '<p class="ald-customer-subtitle">Manage and access all your purchased license keys</p>';
        echo '</div>';
        
        if (empty($licenses)) {
            echo '<div class="ald-no-licenses">';
            echo '<div class="ald-no-licenses-icon">🎯</div>';
            echo '<h3>No License Keys Found</h3>';
            echo '<p>You haven\'t purchased any licensed products yet.<br>License keys will appear here after your purchase.</p>';
            echo '<a href="' . esc_url(wc_get_page_permalink('shop')) . '" class="button" style="margin-top: 20px;">Browse Products</a>';
            echo '</div>';
        } else {
            foreach ($licenses as $license) {
                echo '<div class="ald-license-card">';
                echo '<div class="ald-license-header">';
                echo '<h3 class="ald-product-name">' . esc_html($license->product_name) . '</h3>';
                echo '<span class="ald-license-date">' . date('F j, Y', strtotime($license->created_at)) . '</span>';
                echo '</div>';
                
                echo '<div class="ald-license-key-container">';
                echo '<div class="ald-license-key-label">License Key:</div>';
                echo '<div class="ald-license-key-value">' . esc_html($license->license_key) . '</div>';
                echo '<button class="ald-copy-btn" onclick="copyLicenseKey(\'' . esc_js($license->license_key) . '\')">📋 Copy</button>';
                echo '</div>';
                
                if (!empty($license->order_id)) {
                    echo '<div style="font-size: 14px; color: #666; margin-top: 10px;">';
                    echo '<strong>Order ID:</strong> #' . esc_html($license->order_id);
                    echo '</div>';
                }
                echo '</div>';
            }
        }
        
        echo '</div>';
        
        // JavaScript
        echo '<script>
        function copyLicenseKey(key) {
            navigator.clipboard.writeText(key).then(function() {
                // Success feedback
                const button = event.target;
                const originalText = button.innerHTML;
                button.innerHTML = "✅ Copied!";
                button.style.background = "#28a745";
                
                setTimeout(function() {
                    button.innerHTML = originalText;
                    button.style.background = "#28a745";
                }, 2000);
            }).catch(function() {
                // Fallback for older browsers
                const textArea = document.createElement("textarea");
                textArea.value = key;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand("copy");
                document.body.removeChild(textArea);
                
                alert("License key copied to clipboard!");
            });
        }
        </script>';
    }
    
    /**
     * Sipariş detaylarında lisans anahtarlarını göster
     */
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
            echo '<h2>' . __('License Keys', 'auto-license-delivery') . '</h2>';
            echo '<table class="woocommerce-table woocommerce-table--order-downloads shop_table shop_table_responsive order_downloads">';
            echo '<thead>';
            echo '<tr>';
            echo '<th>' . __('Product', 'auto-license-delivery') . '</th>';
            echo '<th>' . __('License Key', 'auto-license-delivery') . '</th>';
            echo '<th>' . __('Actions', 'auto-license-delivery') . '</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            
            foreach ($licenses as $license) {
                echo '<tr>';
                echo '<td data-title="' . __('Product', 'auto-license-delivery') . '">' . esc_html($license->product_name) . '</td>';
                echo '<td data-title="' . __('License Key', 'auto-license-delivery') . '">';
                echo '<code style="background: #f8f9fa; padding: 8px 12px; border-radius: 4px; font-family: monospace;">' . esc_html($license->license_key) . '</code>';
                echo '</td>';
                echo '<td data-title="' . __('Actions', 'auto-license-delivery') . '">';
                echo '<button onclick="copyLicenseKey(\'' . esc_js($license->license_key) . '\')" class="button" style="font-size: 12px;">📋 Copy</button>';
                echo '</td>';
                echo '</tr>';
            }
            
            echo '</tbody>';
            echo '</table>';
            
            // Copy script
            echo '<script>
            function copyLicenseKey(key) {
                navigator.clipboard.writeText(key).then(function() {
                    alert("License key copied to clipboard!");
                });
            }
            </script>';
        }
    }
    
    /**
     * E-postalara lisans anahtarları ekle
     */
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
                echo "\n" . __('LICENSE KEYS:', 'auto-license-delivery') . "\n";
                echo str_repeat('-', 30) . "\n";
                foreach ($licenses as $license) {
                    echo $license->product_name . ': ' . $license->license_key . "\n";
                }
            } else {
                echo '<h3 style="color: #333; margin: 20px 0 10px 0;">' . __('License Keys', 'auto-license-delivery') . '</h3>';
                echo '<table cellspacing="0" cellpadding="10" style="width: 100%; border: 1px solid #eee; border-collapse: collapse;" border="1" bordercolor="#eee">';
                echo '<thead>';
                echo '<tr style="background: #f8f9fa;">';
                echo '<th style="text-align:left; border: 1px solid #eee; padding: 12px;">' . __('Product', 'auto-license-delivery') . '</th>';
                echo '<th style="text-align:left; border: 1px solid #eee; padding: 12px;">' . __('License Key', 'auto-license-delivery') . '</th>';
                echo '</tr>';
                echo '</thead>';
                echo '<tbody>';
                
                foreach ($licenses as $license) {
                    echo '<tr>';
                    echo '<td style="text-align:left; border: 1px solid #eee; padding: 12px;">' . esc_html($license->product_name) . '</td>';
                    echo '<td style="text-align:left; border: 1px solid #eee; padding: 12px;"><code style="background: #f8f9fa; padding: 6px 8px; border-radius: 4px; font-family: monospace;">' . esc_html($license->license_key) . '</code></td>';
                    echo '</tr>';
                }
                
                echo '</tbody>';
                echo '</table>';
            }
        }
    }
}