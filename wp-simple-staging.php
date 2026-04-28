<?php
/**
 * Plugin Name: Simple Staging
 * Description: Create and manage a staging copy of your WordPress site in a subdirectory.
 * Version:     1.13.0
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Author:      Toon Peters
 * Author URI:  https://github.com/phalox
 * License:     GPL-2.0-or-later
 * Text Domain: simple-staging
 */

namespace SimpleStaging;

defined('ABSPATH') || exit;

define('SMSNG_DIR',     plugin_dir_path(__FILE__));
define('SMSNG_URL',     plugin_dir_url(__FILE__));
define('SMSNG_VERSION', '1.13.0');
define('SMSNG_STATE',    'smsng_clone');
define('SMSNG_SETTINGS', 'smsng_settings');
define('SMSNG_NONCE',    'smsng_action');

foreach ([
    'class-job',
    'class-copy-tables',
    'class-copy-files',
    'class-configure',
    'class-delete',
    'class-admin',
] as $file) {
    require_once SMSNG_DIR . 'includes/' . $file . '.php';
}

Admin::boot();
