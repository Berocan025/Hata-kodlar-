<?php
/**
 * Plugin Uninstall Script
 * 
 * This file is called when the plugin is uninstalled.
 * It removes all plugin data from the database.
 */

// Exit if uninstall not called from WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Check if user has permission to uninstall plugins
if (!current_user_can('activate_plugins')) {
    exit;
}

// Remove plugin options
delete_option('ald_license_activated');
delete_option('ald_license_key_hash');
delete_option('ald_version');
delete_option('ald_activation_time');
delete_option('ald_custom_css');

// Remove all license keys from products
global $wpdb;

// Remove license keys meta from products
$wpdb->delete(
    $wpdb->postmeta,
    array('meta_key' => '_ald_license_keys'),
    array('%s')
);

// Drop custom tables
$table_name = $wpdb->prefix . 'ald_license_history';
$wpdb->query("DROP TABLE IF EXISTS $table_name");

$settings_table = $wpdb->prefix . 'ald_settings';
$wpdb->query("DROP TABLE IF EXISTS $settings_table");

// Remove upload directory
$upload_dir = wp_upload_dir();
$ald_dir = $upload_dir['basedir'] . '/auto-license-delivery';

if (is_dir($ald_dir)) {
    // Remove all files in the directory
    $files = glob($ald_dir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    // Remove the directory
    rmdir($ald_dir);
}

// Clear any cached data
wp_cache_flush();

// Remove rewrite rules
flush_rewrite_rules();