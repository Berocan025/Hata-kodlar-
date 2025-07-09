<?php
/**
 * Plugin Name: WooCommerce Otomatik Lisans Teslimatı - BERAT K V4 FIXED
 * Version: 4.0.0
 * Description: V4 - Tüm hatalar düzeltildi, güzel 24 saat bekleme sistemi eklendi
 * Author: BERAT K - 0539 511 56 32
 */

if (!defined("ABSPATH")) exit("Direct access forbidden.");

define("ALD_VERSION", "4.0.0");
define("ALD_LICENSE_KEY", base64_encode("WİO-4142-1544-1151-4441")); // V4 FIX

class AutoLicenseDelivery_V4_FIXED {
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action("plugins_loaded", array($this, "init"));
        register_activation_hook(__FILE__, array($this, "activate"));
    }
    
    public function init() {
        if (!class_exists("WooCommerce")) {
            add_action("admin_notices", function() {
                echo "<div class=\"error\"><p>V4 requires WooCommerce! BERAT K - wa.me/905395115632</p></div>";
            });
            return;
        }
        
        $this->init_hooks();
    }
    
    private function init_hooks() {
        add_action("admin_menu", array($this, "add_admin_menu"));
        add_action("admin_init", array($this, "handle_admin_actions"));
        add_action("woocommerce_product_options_general_product_data", array($this, "add_license_field"));
        add_action("woocommerce_process_product_meta", array($this, "save_license_field"));
        add_action("woocommerce_order_status_completed", array($this, "deliver_license"));
        add_action("woocommerce_order_status_processing", array($this, "deliver_license"));
        add_filter("woocommerce_account_menu_items", array($this, "add_account_menu_item"));
        add_action("init", array($this, "add_account_endpoints"));
        add_action("woocommerce_account_license-keys_endpoint", array($this, "license_keys_content"));
        add_action("woocommerce_order_details_after_order_table", array($this, "display_order_licenses"));
        add_action("woocommerce_order_details_after_order_table", array($this, "display_beautiful_waiting_message"), 15);
    }
    
    public function activate() {
        $this->create_tables();
        flush_rewrite_rules();
        update_option("ald_version", ALD_VERSION);
    }
    
    private function create_tables() {
        global $wpdb;
        
        // License history table
        $table_name = $wpdb->prefix . "ald_license_history";
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id varchar(50) NOT NULL,
            product_id bigint(20) NOT NULL,
            customer_id bigint(20) NOT NULL,
            customer_email varchar(255) NOT NULL,
            license_key varchar(255) DEFAULT \"\",
            status varchar(20) DEFAULT \"sent\",
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY product_id (product_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . "wp-admin/includes/upgrade.php");
        dbDelta($sql);
        
        // V4 FIX: Pending customers table (was missing!)
        $pending_table = $wpdb->prefix . "ald_pending_customers";
        $pending_sql = "CREATE TABLE IF NOT EXISTS $pending_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id varchar(50) NOT NULL,
            product_id bigint(20) NOT NULL,
            customer_id bigint(20) NOT NULL,
            customer_email varchar(255) NOT NULL,
            product_name varchar(255) DEFAULT \"\",
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            processed_at datetime DEFAULT NULL,
            status varchar(20) DEFAULT \"pending\",
            PRIMARY KEY (id),
            UNIQUE KEY unique_order_product (order_id, product_id)
        ) $charset_collate;";
        
        dbDelta($pending_sql);
    }

