<?php
/*
Plugin Name: Comprehensive Plugin & Core Lifecycle Syslog & Mail Logger + PHP 8 Auto-Patcher
Description: Logs all WordPress core/plugin/theme/translation updates and lifecycle events to syslog, applies post-upgrade PHP 8 compatibility fixes for legacy WordPress installations, and emails dpavlin+wordpress@ffzg.hr only on errors or patched sites.
Version: 3.1
Author: FFZG Infrastructure
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

if ( ! defined( 'FFZG_WP_UPDATE_NOTIFY_EMAIL' ) ) {
    define( 'FFZG_WP_UPDATE_NOTIFY_EMAIL', 'dpavlin+wordpress@ffzg.hr' );
}

$GLOBALS['ffzg_initial_wp_version']   = isset( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : 'unknown';
$GLOBALS['ffzg_post_upgrade_patches'] = array();
$GLOBALS['ffzg_core_notified']        = false;

/**
 * Returns actor/trigger description for the current execution context.
 *
 * @return string
 */
function ffzg_get_actor_info() {
    $user_info = 'System / wp-cron';
    if ( defined( 'WP_CLI' ) && WP_CLI ) {
        $user_info = 'WP-CLI';
    } elseif ( function_exists( 'wp_get_current_user' ) ) {
        $current_user = wp_get_current_user();
        if ( $current_user && $current_user->ID > 0 ) {
            $user_info = $current_user->user_login . ' (ID: ' . $current_user->ID . ')';
        }
    }
    if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
        $user_info .= ' [' . $_SERVER['REMOTE_ADDR'] . ']';
    }
    return $user_info;
}

/**
 * Reads the on-disk WordPress version from wp-includes/version.php.
 *
 * @return string
 */
function ffzg_get_disk_wp_version() {
    $ver_file = ABSPATH . 'wp-includes/version.php';
    if ( is_readable( $ver_file ) ) {
        $content = @file_get_contents( $ver_file );
        if ( is_string( $content ) && preg_match( '/^\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/m', $content, $m ) ) {
            return $m[1];
        }
    }
    return isset( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : 'unknown';
}

/**
 * Main logging function to send a formatted message to syslog.
 *
 * @param string $message  The core message to log.
 * @param int    $priority The syslog priority level (e.g., LOG_INFO, LOG_WARNING).
 */
function log_event_to_syslog( $message, $priority = LOG_INFO ) {
    $user_info    = ffzg_get_actor_info();
    $site_url     = function_exists( 'get_option' ) ? get_option( 'siteurl' ) : 'unknown';
    $full_message = sprintf( 'WordPress Event: %s | Site: %s | Performed by: %s.', $message, $site_url, $user_info );

    if ( openlog( 'wordpress', LOG_PID, LOG_USER ) ) {
        syslog( LOG_AUTH, $full_message );
        closelog();
    }
}

/**
 * Applies PHP 8 compatibility fixes to legacy WordPress core/theme/plugin files in $base_dir.
 * Safe and idempotent: only modifies files containing known unpatched PHP < 8 patterns.
 *
 * @param string $base_dir Root directory of the WordPress installation (defaults to ABSPATH).
 * @return array List of human-readable patch descriptions applied during this call.
 */
function ffzg_apply_post_upgrade_php8_fixes( $base_dir = ABSPATH ) {
    $base_dir = rtrim( $base_dir, '/\\' ) . '/';
    $applied  = array();

    $apply_patch = function( $rel_path, $rules ) use ( $base_dir, &$applied ) {
        $full_path = $base_dir . $rel_path;
        if ( ! is_file( $full_path ) || ! is_readable( $full_path ) ) {
            return;
        }
        $orig = @file_get_contents( $full_path );
        if ( ! is_string( $orig ) || $orig === '' ) {
            return;
        }
        $code        = $orig;
        $matched_ids = array();

        foreach ( $rules as $rule_id => $fn ) {
            $updated = $fn( $code );
            if ( is_string( $updated ) && $updated !== $code ) {
                $code          = $updated;
                $matched_ids[] = $rule_id;
            }
        }

        if ( empty( $matched_ids ) ) {
            return;
        }

        $rules_str = implode( ', ', $matched_ids );
        if ( @file_put_contents( $full_path, $code ) !== false ) {
            if ( function_exists( 'opcache_invalidate' ) ) {
                @opcache_invalidate( $full_path, true );
            }
            $entry = sprintf( '%s [%s]', $rel_path, $rules_str );
            $applied[] = $entry;
            $GLOBALS['ffzg_post_upgrade_patches'][] = $entry;
            log_event_to_syslog( 'Post-Upgrade PHP8 Fix APPLIED: ' . $entry, LOG_NOTICE );
        } else {
            $entry = sprintf( '%s [WRITE FAILED: %s]', $rel_path, $rules_str );
            $applied[] = $entry;
            $GLOBALS['ffzg_post_upgrade_patches'][] = $entry;
            log_event_to_syslog( 'Post-Upgrade PHP8 Fix FAILED (permissions): ' . $entry, LOG_ERR );
        }
    };

    // 1. wp-includes/class-wp-query.php or wp-includes/query.php
    $q_rel = is_file( $base_dir . 'wp-includes/class-wp-query.php' )
        ? 'wp-includes/class-wp-query.php'
        : 'wp-includes/query.php';
    $apply_patch( $q_rel, array(
        'core_wp_query_p' => function( $c ) {
            return str_replace(
                "! is_scalar( \$qv['p'] ) || \$qv['p'] < 0",
                "! is_scalar( \$qv['p'] ) || (int) \$qv['p'] < 0",
                $c
            );
        },
        'core_wp_query_w' => function( $c ) {
            return str_replace(
                "if ( '' != \$qv['w'] ) {",
                "if ( ! empty( \$qv['w'] ) ) {",
                $c
            );
        },
        'core_query_ref_new' => function( $c ) {
            return str_replace( '=& new ', '= new ', $c );
        },
    ) );

    // 2. wp-admin/includes/file.php
    $apply_patch( 'wp-admin/includes/file.php', array(
        'core_file_closedir' => function( $c ) {
            return str_replace(
                "}\n\t}\n\t@closedir( \$dir );\n\n\treturn \$files;",
                "}\n\t\t@closedir( \$dir );\n\t}\n\n\treturn \$files;",
                $c
            );
        },
    ) );

    // 3. wp-includes/theme.php
    $apply_patch( 'wp-includes/theme.php', array(
        'core_theme_bool' => function( $c ) {
            return str_replace(
                "if ( \$args['theme_root'] ) {",
                "if ( ! empty( \$args['theme_root'] ) ) {",
                $c
            );
        },
    ) );

    // 4. wp-includes/class-wp-widget.php
    $apply_patch( 'wp-includes/class-wp-widget.php', array(
        'core_widget_bridge' => function( $c ) {
            $old_sig = "public function __construct( \$id_base, \$name, \$widget_options = array(), \$control_options = array() ) {\n\t\t\$this->id_base = empty(\$id_base)";
            $bridge  = "public function __construct( \$id_base = false, \$name = '', \$widget_options = array(), \$control_options = array() ) {\n"
                     . "\t\tif ( false === \$id_base && empty( \$name ) ) {\n"
                     . "\t\t\t\$class_name = get_class( \$this );\n"
                     . "\t\t\tif ( method_exists( \$this, \$class_name ) ) {\n"
                     . "\t\t\t\tcall_user_func_array( array( \$this, \$class_name ), func_get_args() );\n"
                     . "\t\t\t\treturn;\n"
                     . "\t\t\t}\n"
                     . "\t\t}\n"
                     . "\t\t\$this->id_base = empty(\$id_base)";
            return str_replace( $old_sig, $bridge, $c );
        },
    ) );

    // 5. wp-includes/class-wp-feed-cache.php
    $apply_patch( 'wp-includes/class-wp-feed-cache.php', array(
        'core_feed_cache_static' => function( $c ) {
            return preg_replace(
                '/public\s+function\s+create\(\s*\$location,\s*\$filename,\s*\$extension\s*\)/',
                'public static function create( $location, $filename, $extension )',
                $c
            );
        },
    ) );

    // 6. wp-includes/spl-autoload-compat.php
    $apply_patch( 'wp-includes/spl-autoload-compat.php', array(
        'core_spl_autoload' => function( $c ) {
            if ( strpos( $c, 'function __autoload(' ) !== false && strpos( $c, '/* function __autoload(' ) === false ) {
                $c = str_replace(
                    'function __autoload( $classname ) {',
                    "/* Deprecated in PHP 7.2, removed in PHP 8.0 */\n\t/* function __autoload( \$classname ) {",
                    $c
                );
                $c = str_replace(
                    "call_user_func( \$autoloader, \$classname );\n\n\t\t\t// If it has been autoloaded, stop processing.\n\t\t\tif ( class_exists( \$classname, false ) ) {\n\t\t\t\treturn;\n\t\t\t}\n\t\t}\n\t}",
                    "call_user_func( \$autoloader, \$classname );\n\n\t\t\t// If it has been autoloaded, stop processing.\n\t\t\tif ( class_exists( \$classname, false ) ) {\n\t\t\t\treturn;\n\t\t\t}\n\t\t}\n\t} */",
                    $c
                );
            }
            return $c;
        },
    ) );

    // 7. Suki theme PHP 8 magic method visibility
    $apply_patch( 'wp-content/themes/suki/inc/class-suki.php', array(
        'suki_magic_methods' => function( $c ) {
            $c = str_replace( 'private final function __clone()', 'private function __clone()', $c );
            $c = str_replace( 'private final function __wakeup()', 'public function __wakeup()', $c );
            return $c;
        },
    ) );

    // 8. Yoast SEO static property lookup
    $yoast_target = "\t\tif ( ! \\class_exists( \$class_name ) || ! \\property_exists( \$class_name, \$property ) ) {\n"
                  . "\t\t\treturn \$default;\n"
                  . "\t\t}\n\n"
                  . "\t\t\$properties = \\get_class_vars( \$class_name );\n\n"
                  . "\t\treturn \$properties[ \$property ];";
    $yoast_repl   = "\t\tif ( ! \\class_exists( \$class_name ) || ! \\property_exists( \$class_name, \$property ) ) {\n"
                  . "\t\t\treturn \$default;\n"
                  . "\t\t}\n\n"
                  . "\t\tif ( isset( \$class_name::\$\$property ) ) {\n"
                  . "\t\t\treturn \$class_name::\$\$property;\n"
                  . "\t\t}\n\n"
                  . "\t\t\$properties = \\get_class_vars( \$class_name );\n\n"
                  . "\t\treturn \$properties[ \$property ] ?? \$default;";
    foreach ( array( 'wp-content/plugins/wordpress-seo/lib/model.php', 'wp-content/plugins/wordpress-seo/src/orm/yoast-model.php' ) as $yoast_rel ) {
        $apply_patch( $yoast_rel, array(
            'yoast_model' => function( $c ) use ( $yoast_target, $yoast_repl ) {
                return str_replace( $yoast_target, $yoast_repl, $c );
            },
        ) );
    }

    return array_values( array_unique( $GLOBALS['ffzg_post_upgrade_patches'] ) );
}

/**
 * Sends a data-dense engineering notification email to FFZG_WP_UPDATE_NOTIFY_EMAIL
 * only when the update failed or post-upgrade PHP 8 patches were applied.
 *
 * @param string $type        Update type (core, plugin, theme, translation).
 * @param string $action      Update action (update, install).
 * @param string $status      SUCCESS or FAILED (...).
 * @param array  $items       List of updated item descriptions.
 * @param array  $patches     List of post-upgrade PHP 8 patches applied.
 */
function ffzg_send_update_notification( $type, $action, $status, $items, $patches ) {
    $is_error    = ( strpos( $status, 'FAILED' ) === 0 );
    $has_patches = ! empty( $patches );

    if ( ! $is_error && ! $has_patches ) {
        return;
    }

    $hostname = function_exists( 'gethostname' ) ? gethostname() : php_uname( 'n' );
    $site_url = function_exists( 'get_option' ) ? get_option( 'siteurl' ) : 'unknown';
    $init_ver = isset( $GLOBALS['ffzg_initial_wp_version'] ) ? $GLOBALS['ffzg_initial_wp_version'] : 'unknown';
    $disk_ver = ffzg_get_disk_wp_version();
    $wp_ver   = ( $init_ver !== $disk_ver ) ? sprintf( '%s -> %s', $init_ver, $disk_ver ) : $disk_ver;
    $actor    = ffzg_get_actor_info();

    $items_summary = ! empty( $items ) ? implode( ', ', $items ) : $type;
    $patch_tag     = ! empty( $patches ) ? sprintf( ' [PHP8-PATCHED:%d]', count( $patches ) ) : '';
    $status_tag    = ( strpos( $status, 'FAILED' ) === 0 ) ? ' [FAILED]' : '';

    $subject = sprintf(
        '[WP-UPDATE]%s%s %s %s - %s %s: %s (WP %s)',
        $status_tag,
        $patch_tag,
        $hostname,
        $site_url,
        $type,
        $action,
        $items_summary,
        $wp_ver
    );

    $lines   = array();
    $lines[] = 'Timestamp:       ' . date( 'c' );
    $lines[] = 'Host:            ' . $hostname . ( ! empty( $_SERVER['SERVER_ADDR'] ) ? ' (' . $_SERVER['SERVER_ADDR'] . ')' : '' );
    $lines[] = 'Site URL:        ' . $site_url;
    $lines[] = 'ABSPATH:         ' . ABSPATH;
    $lines[] = 'WordPress:       ' . $wp_ver;
    $lines[] = 'PHP Version:     ' . PHP_VERSION . ' (' . PHP_SAPI . ')';
    $lines[] = 'Actor / Trigger: ' . $actor;
    $lines[] = 'Event:           ' . $type . ' / ' . $action;
    $lines[] = 'Status:          ' . $status;
    $lines[] = '';
    $lines[] = 'Items:';
    if ( ! empty( $items ) ) {
        foreach ( $items as $item ) {
            $lines[] = '  - ' . $item;
        }
    } else {
        $lines[] = '  - (none specified)';
    }
    $lines[] = '';
    $lines[] = 'Post-Upgrade PHP 8 Fixes:';
    if ( ! empty( $patches ) ) {
        foreach ( $patches as $p ) {
            $lines[] = '  - ' . $p;
        }
    } else {
        $lines[] = '  - None needed (0 files modified)';
    }

    $body = implode( "\n", $lines ) . "\n";
    $to   = FFZG_WP_UPDATE_NOTIFY_EMAIL;

    $sent = false;
    if ( function_exists( 'wp_mail' ) ) {
        $sent = @wp_mail( $to, $subject, $body );
    }
    if ( ! $sent ) {
        $sent = @mail( $to, $subject, $body );
    }

    if ( ! $sent ) {
        log_event_to_syslog( sprintf( 'Update notification mail FAILED to %s for %s/%s', $to, $type, $action ), LOG_WARNING );
    }
}

/**
 * Formats and logs a plugin status change.
 *
 * @param string $action      The action being performed (e.g., 'Activated', 'Deactivated').
 * @param string $plugin_file The path to the plugin file relative to the plugins directory.
 */
function log_plugin_status_change( $action, $plugin_file ) {
    if ( ! function_exists( 'get_plugin_data' ) && is_file( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $full_file      = WP_PLUGIN_DIR . '/' . $plugin_file;
    $plugin_data    = ( function_exists( 'get_plugin_data' ) && is_file( $full_file ) ) ? get_plugin_data( $full_file, false, false ) : array();
    $plugin_name    = ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : $plugin_file;
    $plugin_version = ! empty( $plugin_data['Version'] ) ? $plugin_data['Version'] : 'N/A';

    $message = sprintf(
        'Plugin %s: "%s" [%s] (Version: %s)',
        $action,
        $plugin_name,
        $plugin_file,
        $plugin_version
    );

    log_event_to_syslog( $message, LOG_INFO );
}

// --- HOOKS FOR PLUGIN LIFECYCLE ---

add_action( 'activated_plugin', function( $plugin ) {
    log_plugin_status_change( 'Activated', $plugin );
}, 10, 1 );

add_action( 'deactivated_plugin', function( $plugin ) {
    log_plugin_status_change( 'Deactivated', $plugin );
}, 10, 1 );

add_action( 'pre_uninstall_plugin', function( $plugin ) {
    log_plugin_status_change( 'Uninstalled', $plugin );
}, 10, 1 );

// --- HOOKS FOR CORE, PLUGIN, THEME & TRANSLATION UPGRADES ---

/**
 * Patch newly copied core files before update_core() makes its loopback HTTP call to upgrade.php?step=upgrade_db.
 */
add_filter( 'http_request_args', function( $parsed_args, $url ) {
    if ( is_string( $url ) && strpos( $url, 'upgrade.php?step=upgrade_db' ) !== false ) {
        ffzg_apply_post_upgrade_php8_fixes( ABSPATH );
    }
    return $parsed_args;
}, 10, 2 );

/**
 * Fires inside update_core() immediately after new core files are in place.
 */
add_action( '_core_updated_successfully', function( $new_wp_version ) {
    $GLOBALS['ffzg_core_updated_version'] = $new_wp_version;
    ffzg_apply_post_upgrade_php8_fixes( ABSPATH );
}, 10, 1 );

/**
 * Fallback shutdown handler if _core_updated_successfully fired without reaching upgrader_process_complete.
 */
add_action( 'shutdown', function() {
    if ( ! empty( $GLOBALS['ffzg_core_updated_version'] ) && empty( $GLOBALS['ffzg_core_notified'] ) ) {
        $GLOBALS['ffzg_core_notified'] = true;
        $patches = ffzg_apply_post_upgrade_php8_fixes( ABSPATH );
        $init_v  = isset( $GLOBALS['ffzg_initial_wp_version'] ) ? $GLOBALS['ffzg_initial_wp_version'] : 'unknown';
        $new_v   = $GLOBALS['ffzg_core_updated_version'];
        $item    = sprintf( 'WordPress Core (%s -> %s)', $init_v, $new_v );
        log_event_to_syslog( sprintf( 'Core Updated: %s | PHP8 patches: %d', $item, count( $patches ) ), LOG_INFO );
        ffzg_send_update_notification( 'core', 'update', 'SUCCESS', array( $item ), $patches );
    }
} );

/**
 * Generic hook for all upgrades/installations (core, plugin, theme, translation).
 */
add_action( 'upgrader_process_complete', function( $upgrader_object, $options ) {
    $type   = isset( $options['type'] ) ? $options['type'] : 'unknown';
    $action = isset( $options['action'] ) ? $options['action'] : 'update';

    // Always run post-upgrade PHP 8 compatibility checks/fixes first.
    $patches = ffzg_apply_post_upgrade_php8_fixes( ABSPATH );

    if ( $type === 'core' ) {
        $GLOBALS['ffzg_core_notified'] = true;
    }

    $is_err = ( is_object( $upgrader_object ) && isset( $upgrader_object->result ) && is_wp_error( $upgrader_object->result ) );
    if ( ! $is_err && $type === 'core' && empty( $GLOBALS['ffzg_core_updated_version'] ) ) {
        $is_err = true;
        $status = 'FAILED: update_core() did not complete (_core_updated_successfully not reached)';
    } else {
        $status = $is_err ? ( 'FAILED: ' . $upgrader_object->result->get_error_message() ) : 'SUCCESS';
    }
    $items  = array();

    if ( $type === 'core' ) {
        $init_v  = isset( $GLOBALS['ffzg_initial_wp_version'] ) ? $GLOBALS['ffzg_initial_wp_version'] : 'unknown';
        $disk_v  = ffzg_get_disk_wp_version();
        $items[] = sprintf( 'WordPress Core (%s -> %s)', $init_v, $disk_v );
    } elseif ( $type === 'plugin' ) {
        if ( ! function_exists( 'get_plugin_data' ) && is_file( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugin_list = array();
        if ( ! empty( $options['plugins'] ) && is_array( $options['plugins'] ) ) {
            $plugin_list = $options['plugins'];
        } elseif ( ! empty( $options['plugin'] ) ) {
            $plugin_list = array( $options['plugin'] );
        } elseif ( is_object( $upgrader_object ) && method_exists( $upgrader_object, 'plugin_info' ) ) {
            $pinfo = $upgrader_object->plugin_info();
            if ( ! empty( $pinfo ) ) {
                $plugin_list = array( $pinfo );
            }
        }
        foreach ( $plugin_list as $pfile ) {
            $full = WP_PLUGIN_DIR . '/' . $pfile;
            if ( function_exists( 'get_plugin_data' ) && is_file( $full ) ) {
                $pd      = get_plugin_data( $full, false, false );
                $pname   = ! empty( $pd['Name'] ) ? $pd['Name'] : $pfile;
                $pver    = ! empty( $pd['Version'] ) ? $pd['Version'] : 'N/A';
                $items[] = sprintf( '"%s" [%s] (Version: %s)', $pname, $pfile, $pver );
            } else {
                $items[] = $pfile;
            }
        }
    } elseif ( $type === 'theme' ) {
        $theme_list = array();
        if ( ! empty( $options['themes'] ) && is_array( $options['themes'] ) ) {
            $theme_list = $options['themes'];
        } elseif ( ! empty( $options['theme'] ) ) {
            $theme_list = array( $options['theme'] );
        } elseif ( is_object( $upgrader_object ) && method_exists( $upgrader_object, 'theme_info' ) ) {
            $tinfo = $upgrader_object->theme_info();
            if ( is_object( $tinfo ) && method_exists( $tinfo, 'get_stylesheet' ) ) {
                $theme_list = array( $tinfo->get_stylesheet() );
            } elseif ( is_string( $tinfo ) && $tinfo !== '' ) {
                $theme_list = array( $tinfo );
            }
        }
        foreach ( $theme_list as $tslug ) {
            if ( function_exists( 'wp_get_theme' ) ) {
                $tobj = wp_get_theme( $tslug );
                if ( is_object( $tobj ) && $tobj->exists() ) {
                    $items[] = sprintf( '"%s" [%s] (Version: %s)', $tobj->get( 'Name' ), $tslug, $tobj->get( 'Version' ) );
                    continue;
                }
            }
            $items[] = $tslug;
        }
    } elseif ( $type === 'translation' ) {
        if ( ! empty( $options['translations'] ) && is_array( $options['translations'] ) ) {
            foreach ( $options['translations'] as $tr ) {
                if ( is_array( $tr ) ) {
                    $items[] = sprintf(
                        '%s:%s (%s, v%s)',
                        isset( $tr['type'] ) ? $tr['type'] : 'unknown',
                        isset( $tr['slug'] ) ? $tr['slug'] : 'default',
                        isset( $tr['language'] ) ? $tr['language'] : '?',
                        isset( $tr['version'] ) ? $tr['version'] : '?'
                    );
                }
            }
        }
    }

    $syslog_msg = sprintf(
        'Upgrader %s %s (%s): %s | PHP8 patches: %d',
        $type,
        $action,
        $status,
        ! empty( $items ) ? implode( ', ', $items ) : $type,
        count( $patches )
    );
    log_event_to_syslog( $syslog_msg, $is_err ? LOG_WARNING : LOG_INFO );
    ffzg_send_update_notification( $type, $action, $status, $items, $patches );
}, 10, 2 );

/**
 * Catch early automatic update failures (e.g., copy_failed_for_update_core_file) that return before upgrader_process_complete.
 */
add_action( 'automatic_updates_complete', function( $update_results ) {
    $patches = ffzg_apply_post_upgrade_php8_fixes( ABSPATH );
    if ( empty( $update_results ) || ! is_array( $update_results ) ) {
        return;
    }
    if ( ! empty( $update_results['core'] ) && is_array( $update_results['core'] ) && empty( $GLOBALS['ffzg_core_notified'] ) ) {
        $GLOBALS['ffzg_core_notified'] = true;
        foreach ( $update_results['core'] as $res ) {
            $err_msg = 'Automatic core update failed';
            if ( is_object( $res ) && isset( $res->result ) && is_wp_error( $res->result ) ) {
                $err_msg = $res->result->get_error_message() . ' [' . $res->result->get_error_code() . ']';
            }
            $name = ( is_object( $res ) && ! empty( $res->name ) ) ? $res->name : 'WordPress Core';
            log_event_to_syslog( sprintf( 'Automatic Core Update FAILED: %s (%s) | PHP8 patches: %d', $name, $err_msg, count( $patches ) ), LOG_WARNING );
            ffzg_send_update_notification( 'core', 'auto-update', 'FAILED: ' . $err_msg, array( $name ), $patches );
        }
    }
}, 10, 1 );
