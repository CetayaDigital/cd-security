<?php
/*
Plugin Name: CD Security
Description: Custom security enhancement for WordPress sites hosted by Cetaya Digital. Manages a centralized block list on Google Drive.
Version: 1.0
Author: Cetaya Digital
*/

// Security check to prevent direct access to the file
defined('ABSPATH') or die('No script kiddies please!');

// Hook to run before any other task
add_action('init', 'cd_security_check_new_user', -999);

// Hook into user registration process
add_action('user_register', 'cd_security_validate_new_user', 10, 1);

// Add settings link to the plugin
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'cd_security_settings_link');

function cd_security_settings_link($links) {
    $settings_link = '<a href="options-general.php?page=cd-security">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
}

function cd_security_check_new_user() {
    // Hook into user registration process
    add_action('user_register', 'cd_security_validate_new_user', 10, 1);
}

function cd_security_validate_new_user($user_id) {
    // Retrieve user data
    $user_info = get_userdata($user_id);
    $user_email = $user_info->user_email;
    $user_login = $user_info->user_login;
    $user_registered = $user_info->user_registered;

    // Fetch the block list from Google Sheets
    $block_list = cd_security_fetch_block_list();

    // Check if the user details are in the block list
    if (cd_security_is_user_blocked($user_login, $user_email, $user_registered, $block_list)) {
        // Delete the user
        wp_delete_user($user_id);
        error_log("User deleted: $user_login");
    } else {
        error_log("User not deleted: $user_login");
    }
}

function cd_security_fetch_block_list() {
    $google_sheet_url = 'https://docs.google.com/spreadsheets/d/1iO9mqsqWqkESMdvcoYIWtCjT6BM_w30V04dWwaXqJlI/pub?output=csv'; // Ensure this URL is the CSV export link

    // Fetch the CSV data from Google Sheets
    $response = wp_remote_get($google_sheet_url);
    if (is_wp_error($response)) {
        error_log("Failed to fetch block list: " . $response->get_error_message());
        return [];
    }

    $data = wp_remote_retrieve_body($response);
    $lines = explode("\n", $data);
    $block_list = [];

    foreach ($lines as $line) {
        $columns = str_getcsv($line);
        if (isset($columns[0]) && isset($columns[1])) {
            $block_list[] = [
                'username' => trim($columns[0]),
                'domain' => trim($columns[1])
            ];
        }
    }

    error_log("Block list fetched: " . print_r($block_list, true));
    return $block_list;
}

function cd_security_is_user_blocked($user_login, $user_email, $user_registered, $block_list) {
    foreach ($block_list as $block_entry) {
        $blocked_username = $block_entry['username'];
        $blocked_domain = $block_entry['domain'];

        // Check if username is blocked
        if (strcasecmp($user_login, $blocked_username) == 0) {
            error_log("Blocked by username: $user_login");
            return true;
        }

        // Check if email domain is blocked
        if ($user_email) {
            $email_domain = substr(strrchr($user_email, "@"), 1);
            error_log("Checking email domain: $email_domain against blocked domain: $blocked_domain");
            if (strcasecmp($email_domain, $blocked_domain) == 0) {
                error_log("Blocked by email domain: $user_email");
                return true;
            }
        }
    }

    // Check if email is missing
    if (empty($user_email)) {
        error_log("Blocked due to missing email: $user_login");
        return true;
    }

    // Check if user_registered is invalid
    if ($user_registered === '0000-00-00 00:00:00') {
        error_log("Blocked due to invalid registration date: $user_login");
        return true;
    }

    return false;
}

// Admin menu page
add_action('admin_menu', 'cd_security_admin_menu');

function cd_security_admin_menu() {
    add_menu_page(
        'CD Security',
        'CD Security',
        'manage_options',
        'cd-security',
        'cd_security_admin_page'
    );
}

function cd_security_admin_page() {
    ?>
    <div class="wrap">
        <h1>CD Security</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('cd_security_settings_group');
            do_settings_sections('cd-security');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

add_action('admin_init', 'cd_security_settings_init');

function cd_security_settings_init() {
    register_setting('cd_security_settings_group', 'cd_security_auto_update', array(
        'type' => 'boolean',
        'sanitize_callback' => 'absint',
        'default' => 0,
    ));

    add_settings_section(
        'cd_security_settings_section',
        'CD Security Settings',
        'cd_security_settings_section_callback',
        'cd-security'
    );

    add_settings_field(
        'cd_security_auto_update',
        'Enable Automatic Updates',
        'cd_security_auto_update_callback',
        'cd-security',
        'cd_security_settings_section'
    );
}

function cd_security_settings_section_callback() {
    echo 'Manage settings for CD Security plugin.';
}

function cd_security_auto_update_callback() {
    $option = get_option('cd_security_auto_update', 0);
    ?>
    <input type="checkbox" name="cd_security_auto_update" value="1" <?php checked(1, $option, true); ?> />
    <?php
}

// Check for plugin updates
add_filter('site_transient_update_plugins', 'cd_security_check_for_update');

function cd_security_check_for_update($transient) {
    if (empty($transient->checked)) {
        return $transient;
    }

    // Get the plugin data
    $plugin_data = get_plugin_data(__FILE__);
    $plugin_version = $plugin_data['Version'];

    // URL to the update endpoint
    $update_url = 'https://srv702-files.hstgr.io/f97f20b073621030/files/public_html/CD%20Security/plugin-update.php'; // Replace with your actual update endpoint URL

    // Get update information
    $response = wp_remote_get($update_url);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        error_log("Failed to fetch update information: " . wp_remote_retrieve_response_message($response));
        return $transient;
    }

    $data = json_decode(wp_remote_retrieve_body($response));
    if (is_null($data)) {
        error_log("Failed to decode update information");
        return $transient;
    }

    if (!empty($data->version) && version_compare($plugin_version, $data->version, '<')) {
        $transient->response[plugin_basename(__FILE__)] = (object) array(
            'slug' => 'cd-security',
            'new_version' => $data->version,
            'url' => $update_url,
            'package' => !empty($data->download_url) ? $data->download_url : '',
        );
    }

    return $transient;
}

// Enable automatic updates for the plugin based on the setting
add_filter('auto_update_plugin', 'cd_security_auto_update', 10, 2);

function cd_security_auto_update($update, $item) {
    $auto_update_enabled = get_option('cd_security_auto_update', false);

    // Check the plugin slug and the setting for automatic updates
    if (isset($item->slug) && $item->slug === 'cd-security') {
        return $auto_update_enabled;
    }
    return $update;
}
?>
