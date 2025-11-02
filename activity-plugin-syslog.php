<?php
/*
Plugin Name: Comprehensive Plugin Lifecycle Syslog Logger
Description: Logs plugin install (success and failure), activation, deactivation, and uninstallation events to syslog.
Version: 2.0
Author: Your Name
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Main logging function to send a formatted message to syslog.
 *
 * @param string $message The core message to log.
 * @param int    $priority The syslog priority level (e.g., LOG_INFO, LOG_WARNING).
 */
function log_event_to_syslog( $message, $priority = LOG_INFO ) {
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

    // Prepare the full log message
    $full_message = sprintf( 'WordPress Event: %s | Performed by: %s.', $message, $user_info );

    // Send to syslog
    if ( openlog( 'wordpress', LOG_PID, LOG_USER ) ) {
        //syslog( $priority, $full_message );
        syslog( LOG_AUTH, $full_message );
        closelog();
    }
}

/**
 * Formats and logs a plugin status change.
 *
 * @param string $action      The action being performed (e.g., 'Activated', 'Deactivated').
 * @param string $plugin_file The path to the plugin file relative to the plugins directory.
 */
function log_plugin_status_change( $action, $plugin_file ) {
    if ( ! function_exists( 'get_plugin_data' ) ) {
        require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
    }

    $plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file );
    $plugin_name = $plugin_data['Name'] ? $plugin_data['Name'] : $plugin_file; // Fallback to file
    $plugin_version = $plugin_data['Version'] ? $plugin_data['Version'] : 'N/A';

    $message = sprintf(
        'Plugin %s: "%s" (Version: %s)',
        $action,
        $plugin_name,
        $plugin_version
    );
    
    log_event_to_syslog( $message, LOG_INFO );
}


// --- HOOKS FOR PLUGIN LIFECYCLE ---

/**
 * Hook for when a plugin is activated.
 */
add_action( 'activated_plugin', function( $plugin ) {
    log_plugin_status_change( 'Activated', $plugin );
}, 10, 1 );

/**
 * Hook for when a plugin is deactivated.
 */
add_action( 'deactivated_plugin', function( $plugin ) {
    log_plugin_status_change( 'Deactivated', $plugin );
}, 10, 1 );

/**
 * Hook for when a plugin is uninstalled.
 */
add_action( 'pre_uninstall_plugin', function( $plugin ) {
    log_plugin_status_change( 'Uninstalled', $plugin );
}, 10, 1 );

/**
 * Hooks for plugin installation, catching both success and failure.
 * 'upgrader_process_complete' is a generic hook for all upgrades.
 */
add_action( 'upgrader_process_complete', function( $upgrader_object, $options ) {
    // We only care about plugin installations.
    if ( $options['type'] !== 'plugin' || $options['action'] !== 'install' ) {
        return;
    }

    // Case 1: The installation failed. The result is a WP_Error object.
    if ( is_wp_error( $upgrader_object->result ) ) {
        // Attempt to get the plugin slug being installed. It's often in the 'plugins' array.
        $plugin_slug = isset($options['plugins'][0]) ? basename($options['plugins'][0], '.zip') : 'unknown plugin';
        
        // Get the specific error message(s).
        $error_message = $upgrader_object->result->get_error_message();

        $message = sprintf(
            'Plugin Installation FAILED for "%s". Reason: %s',
            $plugin_slug,
            $error_message
        );
        
        // Log as a warning since it's an error condition.
        log_event_to_syslog( $message, LOG_WARNING );

    } 
    // Case 2: The installation succeeded.
    else {
        // The hook provides the destination path of the plugin.
        $plugin_path = $upgrader_object->plugin_info();
        if ( ! empty( $plugin_path ) ) {
            log_plugin_status_change( 'Installed', $plugin_path );
        }
    }
}, 10, 2 );
