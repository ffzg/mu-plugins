<?php
/*
Plugin Name: Plugin Lifecycle Syslog Logger
Description: Logs plugin activation, deactivation, and uninstallation events to syslog.
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Logs a message to syslog.
 *
 * @param string $action The action being performed (e.g., 'Activated', 'Deactivated').
 * @param string $plugin_file The path to the plugin file relative to the plugins directory.
 */
function log_plugin_change_to_syslog( $action, $plugin_file ) {
    // Get the plugin data
    if ( ! function_exists( 'get_plugin_data' ) ) {
        require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
    }

    $plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file );
    $plugin_name = $plugin_data['Name'];
    $plugin_version = $plugin_data['Version'];

    // Get the current user, if available
    $user_info = 'System';
    if ( function_exists('wp_get_current_user') ) {
        $current_user = wp_get_current_user();
        if ( $current_user->ID > 0 ) {
            $user_info = $current_user->user_login . ' (ID: ' . $current_user->ID . ')';
        } elseif ( defined('WP_CLI') && WP_CLI ) {
            $user_info = 'WP-CLI';
        }
    }

    // Prepare the log message
    $message = sprintf(
        'WordPress %s Plugin %s: "%s" (Version: %s). Action performed by: %s.',
	get_option('siteurl'),
        $action,
        $plugin_name,
        $plugin_version,
        $user_info
    );

    // Send to syslog
    if ( openlog( 'wordpress', LOG_PID | LOG_PERROR, LOG_USER ) ) {
        syslog( LOG_AUTH, $message );
        closelog();
    }
}

/**
 * Hook for when a plugin is activated.
 *
 * @param string $plugin The path to the plugin file.
 */
add_action( 'activated_plugin', function( $plugin ) {
    log_plugin_change_to_syslog( 'Activated', $plugin );
});

/**
 * Hook for when a plugin is deactivated.
 *
 * @param string $plugin The path to the plugin file.
 */
add_action( 'deactivated_plugin', function( $plugin ) {
    log_plugin_change_to_syslog( 'Deactivated', $plugin );
});

/**
 * Hook for when a plugin is uninstalled.
 * This hook is fired before WordPress deletes the plugin files.
 */
add_action( 'pre_uninstall_plugin', function( $plugin ) {
    log_plugin_change_to_syslog( 'Uninstalled', $plugin );
});

/**
 * Hooks for plugin installation. This is more complex as there isn't one single hook.
 * 'upgrader_process_complete' is a generic hook for all upgrades.
 */
add_action( 'upgrader_process_complete', function( $upgrader_object, $options ) {
    // Check if it was a plugin installation
    if ( $options['type'] === 'plugin' && $options['action'] === 'install' ) {
        // The hook provides the destination path of the plugin
        $plugin_path = $upgrader_object->plugin_info();
        if ( ! empty( $plugin_path ) ) {
            log_plugin_change_to_syslog( 'Installed', $plugin_path );
        }
    }
}, 10, 2 );
