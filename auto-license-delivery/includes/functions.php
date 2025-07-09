<?php
/**
 * Ana Plugin Fonksiyonlarının Devamı
 */

// Güvenlik kontrolü
if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

// Ana sınıfın devamı - bu dosya ana dosyaya include edilecek
class AutoLicenseDelivery_Functions {
    
    /**
     * Lisans aktivasyonu işleme
     */
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
            $this->admin_notice('License activated successfully!', 'success');
        } else {
            $this->admin_notice('Invalid license key!', 'error');
        }
    }
    
    /**
     * Admin işlemlerini handle et
     */
    public function handle_admin_actions() {
        // Burada diğer admin işlemleri
    }
    
    /**
     * AJAX: Lisans istatistiklerini getir
     */
    public function ajax_get_license_stats() {
        check_ajax_referer('ald_admin_nonce', 'nonce');
        
        $product_id = intval($_POST['product_id']);
        $stats = $this->get_product_license_stats($product_id);
        
        // HTML içeriğini oluştur
        $html = $this->generate_license_stats_html($stats);
        
        wp_send_json_success(array(
            'html' => $html,
            'remaining_keys' => $stats['remaining_keys'],
            'stats' => $stats
        ));
    }
    
    /**
     * AJAX: Lisans anahtarlarını kaydet
     */
    public function ajax_save_license_keys() {
        check_ajax_referer('ald_admin_nonce', 'nonce');
        
        $product_id = intval($_POST['product_id']);
        $license_keys = sanitize_textarea_field($_POST['license_keys']);
        
        $keys_array = array_filter(explode("\n", $license_keys));
        $keys_array = array_map('trim', $keys_array);
        $keys_array = array_unique($keys_array); // Duplicate'leri kaldır
        
        update_post_meta($product_id, '_ald_license_keys', $keys_array);
        
        wp_send_json_success('License keys saved successfully!');
    }
    
    /**
     * AJAX: Manuel lisans gönder
     */
    public function ajax_send_manual_license() {
        check_ajax_referer('ald_admin_nonce', 'nonce');
        
        $customer_id = intval($_POST['customer_id']);
        $product_id = intval($_POST['product_id']);
        $license_key = sanitize_text_field($_POST['license_key']);
        
        if ($customer_id && $product_id && $license_key) {
            $result = $this->send_manual_license($customer_id, $product_id, $license_key);
            
            if ($result) {
                wp_send_json_success('License sent successfully!');
            } else {
                wp_send_json_error('Failed to send license');
            }
        }
        
        wp_send_json_error('Invalid parameters');
    }
    
    /**
     * AJAX: Lisans sil
     */
    public function ajax_delete_license() {
        check_ajax_referer('ald_admin_nonce', 'nonce');
        
        $license_id = intval($_POST['license_id']);
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'ald_license_history';
        
        $result = $wpdb->delete($table_name, array('id' => $license_id), array('%d'));
        
        if ($result) {
            wp_send_json_success('License deleted successfully!');
        } else {
            wp_send_json_error('Failed to delete license');
        }
    }
    
    /**
     * Ürün lisans istatistiklerini getir
     */
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
            "SELECT * FROM $table_name WHERE product_id = %d ORDER BY created_at DESC LIMIT 10",
            $product_id
        ));
        
        return array(
            'total_keys' => count($license_keys),
            'remaining_keys' => $license_keys,
            'sold_count' => intval($sold_count),
            'sold_licenses' => $sold_licenses,
            'usage_percentage' => count($license_keys) > 0 ? round(($sold_count / (count($license_keys) + $sold_count)) * 100, 2) : 0
        );
    }
    
    /**
     * Lisans istatistikleri HTML'i oluştur
     */
    private function generate_license_stats_html($stats) {
        ob_start();
        ?>
        <div class="ald-stats-container" style="margin: 20px 0;">
            <div class="ald-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
                <div class="ald-stat-card primary">
                    <div class="ald-stat-number"><?php echo $stats['total_keys']; ?></div>
                    <div class="ald-stat-label">Available Keys</div>
                </div>
                <div class="ald-stat-card danger">
                    <div class="ald-stat-number"><?php echo $stats['sold_count']; ?></div>
                    <div class="ald-stat-label">Sold Keys</div>
                </div>
                <div class="ald-stat-card warning">
                    <div class="ald-stat-number"><?php echo $stats['usage_percentage']; ?>%</div>
                    <div class="ald-stat-label">Usage Rate</div>
                </div>
            </div>
            
            <div class="ald-progress">
                <div class="ald-progress-bar" data-width="<?php echo $stats['usage_percentage']; ?>" style="width: 0%;"></div>
            </div>
            
            <?php if (!empty($stats['sold_licenses'])): ?>
            <div style="margin-top: 20px;">
                <h4>Recent Sales:</h4>
                <table class="ald-table" style="font-size: 12px;">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>License Key</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($stats['sold_licenses'], 0, 5) as $license): ?>
                        <tr>
                            <td><?php echo esc_html($license->order_id); ?></td>
                            <td><code class="ald-license-key" style="font-size: 10px;"><?php echo esc_html(substr($license->license_key, 0, 20)) . '...'; ?></code></td>
                            <td><?php echo date('M j, Y', strtotime($license->created_at)); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Admin notice göster
     */
    private function admin_notice($message, $type = 'success') {
        add_action('admin_notices', function() use ($message, $type) {
            echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html($message) . '</p></div>';
        });
    }
    
    /**
     * Ana admin sayfası
     */
    public function admin_page() {
        if (!get_option('ald_license_activated')) {
            $this->license_activation_page();
            return;
        }
        
        $this->main_admin_page();
    }
    
    /**
     * Lisans aktivasyon sayfası
     */
    private function license_activation_page() {
        ?>
        <div class="ald-dashboard">
            <div class="ald-welcome-banner">
                <h1 style="margin: 0; font-size: 28px;">🔐 License Activation Required</h1>
                <p style="margin: 10px 0 0 0; font-size: 16px; opacity: 0.9;">Please enter your license key to unlock all features</p>
            </div>
            <div class="ald-card" style="max-width: 500px; margin: 0 auto;">
                <div class="ald-card-header">
                    🔑 Enter License Key
                </div>
                <div class="ald-card-body">
                    <form method="post" action="">
                        <?php wp_nonce_field('ald_activate_license', 'ald_nonce'); ?>
                        <div class="ald-form-group">
                            <label class="ald-form-label">License Key:</label>
                            <input type="text" name="license_key" class="ald-form-control" placeholder="WİO-4142-1544-1151-4441" required style="text-align: center; font-family: monospace;">
                            <small style="color: #666; font-size: 12px;">Enter the license key provided to you</small>
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
    
    /**
     * Ana admin dashboard
     */
    private function main_admin_page() {
        // İstatistikler
        $total_products = $this->get_total_products_with_licenses();
        $total_licenses = $this->get_total_licenses();
        $total_sold = $this->get_total_sold_licenses();
        $products = $this->get_products_with_licenses();
        
        ?>
        <div class="ald-dashboard">
            <div class="ald-welcome-banner">
                <h1 style="margin: 0; font-size: 32px;">📊 License Keys Dashboard</h1>
                <p style="margin: 10px 0 0 0; font-size: 16px; opacity: 0.9;">Manage your license keys and track sales performance</p>
            </div>
            
            <!-- İstatistik Kartları -->
            <div class="ald-stats-grid">
                <div class="ald-stat-card primary">
                    <div class="ald-stat-number"><?php echo $total_products; ?></div>
                    <div class="ald-stat-label">Products with Licenses</div>
                </div>
                <div class="ald-stat-card success">
                    <div class="ald-stat-number"><?php echo $total_licenses; ?></div>
                    <div class="ald-stat-label">Total License Keys</div>
                </div>
                <div class="ald-stat-card warning">
                    <div class="ald-stat-number"><?php echo $total_sold; ?></div>
                    <div class="ald-stat-label">Sold Licenses</div>
                </div>
                <div class="ald-stat-card danger">
                    <div class="ald-stat-number"><?php echo ($total_licenses - $total_sold); ?></div>
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
                                <option value="<?php echo $product->ID; ?>"><?php echo esc_html($product->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div id="license_stats" style="min-height: 50px;"></div>
                    
                    <div class="ald-form-group">
                        <label class="ald-form-label">License Keys (one per line):</label>
                        <textarea id="license_keys_textarea" class="ald-form-control" rows="10" placeholder="Enter license keys, one per line...&#10;KEY-1234-5678-9ABC&#10;KEY-9876-5432-1DEF&#10;KEY-ABCD-EFGH-IJKL"></textarea>
                        <small style="color: #666; font-size: 12px;">💡 Tip: Paste your license keys here, each on a separate line. Duplicates will be automatically removed.</small>
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
            
            <!-- Hızlı Eylemler -->
            <div class="ald-card">
                <div class="ald-card-header">
                    ⚡ Quick Actions
                </div>
                <div class="ald-card-body">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                        <a href="<?php echo admin_url('admin.php?page=auto-license-delivery-all'); ?>" class="ald-btn ald-btn-primary">
                            📋 View All Licenses
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=auto-license-delivery-settings'); ?>" class="ald-btn ald-btn-primary">
                            ⚙️ Settings
                        </a>
                        <a href="<?php echo admin_url('edit.php?post_type=product'); ?>" class="ald-btn ald-btn-primary">
                            📦 Manage Products
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Tüm lisanslar sayfası
     */
    public function all_licenses_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ald_license_history';
        
        // Sayfalama
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Toplam kayıt sayısı
        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $total_pages = ceil($total_items / $per_page);
        
        // Veriler
        $licenses = $wpdb->get_results($wpdb->prepare(
            "SELECT h.*, p.post_title as product_name, u.display_name as customer_name, u.user_email as customer_email
             FROM $table_name h
             LEFT JOIN {$wpdb->posts} p ON h.product_id = p.ID
             LEFT JOIN {$wpdb->users} u ON h.customer_id = u.ID
             ORDER BY h.created_at DESC
             LIMIT %d OFFSET %d",
            $per_page, $offset
        ));
        
        ?>
        <div class="ald-dashboard">
            <div class="ald-welcome-banner">
                <h1 style="margin: 0; font-size: 28px;">📋 All License Keys</h1>
                <p style="margin: 10px 0 0 0; font-size: 16px; opacity: 0.9;">Complete overview of all issued licenses</p>
            </div>
            
            <div class="ald-card">
                <div class="ald-card-header">
                    📊 License History (<?php echo $total_items; ?> total)
                </div>
                <div class="ald-card-body">
                    <?php if (empty($licenses)): ?>
                        <p style="text-align: center; color: #666; margin: 40px 0;">No licenses found.</p>
                    <?php else: ?>
                        <table class="ald-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Customer</th>
                                    <th>License Key</th>
                                    <th>Order ID</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($licenses as $license): ?>
                                <tr>
                                    <td><?php echo esc_html($license->product_name); ?></td>
                                    <td>
                                        <?php echo esc_html($license->customer_name); ?><br>
                                        <small style="color: #666;"><?php echo esc_html($license->customer_email); ?></small>
                                    </td>
                                    <td>
                                        <code class="ald-license-key"><?php echo esc_html($license->license_key); ?></code>
                                    </td>
                                    <td><?php echo esc_html($license->order_id); ?></td>
                                    <td><?php echo date('M j, Y H:i', strtotime($license->created_at)); ?></td>
                                    <td>
                                        <button class="ald-btn ald-btn-success ald-copy-license" data-license="<?php echo esc_attr($license->license_key); ?>" style="padding: 6px 12px; font-size: 12px;">
                                            📋 Copy
                                        </button>
                                        <button class="ald-btn ald-btn-danger ald-delete-license" data-license-id="<?php echo $license->id; ?>" style="padding: 6px 12px; font-size: 12px;">
                                            🗑️ Delete
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <!-- Sayfalama -->
                        <?php if ($total_pages > 1): ?>
                        <div style="margin-top: 20px; text-align: center;">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="<?php echo admin_url('admin.php?page=auto-license-delivery-all&paged=' . $i); ?>" 
                                   class="ald-btn <?php echo ($i == $current_page) ? 'ald-btn-primary' : 'ald-btn-success'; ?>" 
                                   style="margin: 0 5px; padding: 8px 12px;">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Ayarlar sayfası
     */
    public function settings_page() {
        ?>
        <div class="ald-dashboard">
            <div class="ald-welcome-banner">
                <h1 style="margin: 0; font-size: 28px;">⚙️ Settings</h1>
                <p style="margin: 10px 0 0 0; font-size: 16px; opacity: 0.9;">Configure your license delivery settings</p>
            </div>
            
            <div class="ald-card">
                <div class="ald-card-header">
                    📧 Email Settings
                </div>
                <div class="ald-card-body">
                    <p>Email customization options will be available in the next version.</p>
                </div>
            </div>
            
            <div class="ald-card">
                <div class="ald-card-header">
                    🔧 General Settings
                </div>
                <div class="ald-card-body">
                    <p>General configuration options will be available in the next version.</p>
                </div>
            </div>
        </div>
        <?php
    }
    
    // Diğer yardımcı metodlar...
    
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
            echo '<div style="text-align: center; padding: 40px; color: #666;">';
            echo '<p style="font-size: 18px; margin: 0;">🎯 No license sales yet</p>';
            echo '<p style="margin: 10px 0 0 0;">Sales will appear here once customers start purchasing your licensed products.</p>';
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
        echo '<th>Actions</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($recent_sales as $sale) {
            echo '<tr>';
            echo '<td>' . esc_html($sale->product_name) . '</td>';
            echo '<td>' . esc_html($sale->customer_name) . '</td>';
            echo '<td><code class="ald-license-key">' . esc_html($sale->license_key) . '</code></td>';
            echo '<td>' . date('M j, Y H:i', strtotime($sale->created_at)) . '</td>';
            echo '<td>';
            echo '<button class="ald-btn ald-btn-success ald-copy-license" data-license="' . esc_attr($sale->license_key) . '" style="padding: 6px 12px; font-size: 12px;">📋</button>';
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
    }
}