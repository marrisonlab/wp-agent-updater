<?php

class WP_Agent_Updater_Admin {

    private $core;

    public function __construct() {
        $this->core = new WP_Agent_Updater_Core();
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_head', [$this, 'fix_menu_icon']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_wp_agent_updater_toggle_agent', [$this, 'toggle_agent_callback']);
        add_action('wp_ajax_wp_agent_updater_force_sync', [$this, 'handle_force_sync']);
        
        $plugin_basename = plugin_basename(WP_AGENT_UPDATER_PATH . 'wp-agent-updater.php');
        add_filter('plugin_action_links_' . $plugin_basename, [$this, 'add_action_links']);
    }

    public function add_action_links($links) {
        $settings_link = '<a href="admin.php?page=wp-agent-updater">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function toggle_agent_callback() {
        check_ajax_referer('wp_agent_updater_toggle', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $active = isset($_POST['active']) && $_POST['active'] === 'true' ? 'yes' : 'no';
        $option = isset($_POST['option_key']) ? sanitize_text_field($_POST['option_key']) : 'wp_agent_updater_active';
        
        // Allow-list for options
        if (!in_array($option, ['wp_agent_updater_active'])) {
             wp_send_json_error('Invalid option key');
        }
        
        update_option($option, $active);
        
        wp_send_json_success(['active' => $active === 'yes', 'option' => $option]);
    }

    public function handle_force_sync() {
        check_ajax_referer('wp_agent_updater_toggle', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $result = $this->core->sync_with_master();
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success('Sync completed successfully');
        }
    }

    public function add_menu() {
        add_menu_page(
            'WP Agent',
            'WP Agent',
            'manage_options',
            'wp-agent-updater',
            [$this, 'render_page'],
            plugin_dir_url(__FILE__) . 'icon.svg'
        );
        
        add_submenu_page(
            'wp-agent-updater',
            'Settings',
            'Settings',
            'manage_options',
            'wp-agent-updater',
            [$this, 'render_page']
        );

        add_submenu_page(
            'wp-agent-updater',
            'Backups',
            'Backups',
            'manage_options',
            'wp-agent-updater-backups',
            [$this, 'render_backups_page']
        );
    }

    public function fix_menu_icon() {
        $icon_url = plugin_dir_url(__FILE__) . 'icon.svg?v=1.0.11';
        ?>
        <style>
            #adminmenu .toplevel_page_wp-agent-updater .wp-menu-image img {
                display: none;
            }
            #adminmenu .toplevel_page_wp-agent-updater .wp-menu-image {
                background-color: #a7aaad;
                -webkit-mask: url('<?php echo esc_url($icon_url); ?>') no-repeat center center;
                mask: url('<?php echo esc_url($icon_url); ?>') no-repeat center center;
                -webkit-mask-size: 20px 20px;
                mask-size: 20px 20px;
            }
            #adminmenu .toplevel_page_wp-agent-updater:hover .wp-menu-image {
                background-color: #72aee6;
            }
            #adminmenu .toplevel_page_wp-agent-updater.wp-has-current-submenu .wp-menu-image {
                background-color: #fff;
            }
        </style>
        <?php
    }

    public function render_backups_page() {
        $backups_instance = WP_Agent_Updater_Backups::get_instance();
        
        // Handle Actions
        if (isset($_POST['action']) && isset($_POST['backup_file'])) {
            check_admin_referer('wp_agent_updater_backup_action');
            
            $file = sanitize_text_field($_POST['backup_file']);
            
            if ($_POST['action'] === 'restore') {
                $res = $backups_instance->restore_backup($file);
                if (is_wp_error($res)) {
                    echo '<div class="notice notice-error is-dismissible"><p>Restore error: ' . $res->get_error_message() . '</p></div>';
                } else {
                    echo '<div class="notice notice-success is-dismissible"><p>Restore completed successfully!</p></div>';
                }
            } elseif ($_POST['action'] === 'delete') {
                if ($backups_instance->delete_backup($file)) {
                    echo '<div class="notice notice-success is-dismissible"><p>Backup deleted.</p></div>';
                } else {
                    echo '<div class="notice notice-error is-dismissible"><p>Error deleting backup.</p></div>';
                }
            }
        }
        
        $backups = $backups_instance->get_backups();
        ?>
        <div class="wrap">
            <h1>Backup Management</h1>
            <p>Here you can manage automatic backups created before updates.</p>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Name / Slug</th>
                        <th>Type</th>
                        <th>Date</th>
                        <th>Size</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($backups)): ?>
                        <tr><td colspan="5" style="color: white; background-color: #d63638;">No backups found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($backups as $b): ?>
                            <tr>
                                <td><strong><?php echo esc_html($b['slug']); ?></strong><br><small><?php echo esc_html($b['filename']); ?></small></td>
                                <td><?php echo esc_html($b['type']); ?></td>
                                <td><?php echo esc_html($b['date']); ?></td>
                                <td><?php echo esc_html($b['size']); ?></td>
                                <td>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to restore this backup? Current files will be overwritten.');">
                                        <?php wp_nonce_field('wp_agent_updater_backup_action'); ?>
                                        <input type="hidden" name="action" value="restore">
                                        <input type="hidden" name="backup_file" value="<?php echo esc_attr($b['filename']); ?>">
                                        <button type="submit" class="button button-primary">Restore</button>
                                    </form>
                                    &nbsp;
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Permanently delete this backup?');">
                                        <?php wp_nonce_field('wp_agent_updater_backup_action'); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="backup_file" value="<?php echo esc_attr($b['filename']); ?>">
                                        <button type="submit" class="button button-link-delete" style="color: #a00;">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function register_settings() {
        register_setting('wp_agent_updater_options', 'wp_agent_updater_active');
        register_setting('wp_agent_updater_options', 'wp_agent_updater_master_url', [
            'sanitize_callback' => [$this, 'sanitize_master_url']
        ]);
    }

    public function sanitize_master_url($value) {
        if (empty($value)) {
            return get_option('wp_agent_updater_master_url');
        }
        return esc_url_raw($value);
    }

    public function render_page() {
        if (isset($_POST['force_sync'])) {
            $result = $this->core->sync_with_master();
            if (is_wp_error($result)) {
                echo '<div class="notice notice-error"><p>Sync failed: ' . $result->get_error_message() . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>Sync successful!</p></div>';
            }
        }

        $plugin_basename = plugin_basename(WP_AGENT_UPDATER_PATH . 'wp-agent-updater.php');
        ?>
        <style>
            /* Layout complessivo */
            .wp-agent-updater-dashboard {
                margin-top: 10px;
            }
            .wp-agent-updater-dashboard-intro {
                max-width: 900px;
                margin-bottom: 15px;
                color: #555d66;
            }
            .wp-agent-updater-dashboard-grid {
                display: grid;
                grid-template-columns: minmax(0, 2.2fr) minmax(0, 1.4fr);
                grid-gap: 20px;
                align-items: flex-start;
                max-width: 1100px;
            }
            @media (max-width: 960px) {
                .wp-agent-updater-dashboard-grid {
                    grid-template-columns: 1fr;
                }
            }

            /* Card generiche */
            .wp-agent-updater-card {
                background: #fff;
                border: 1px solid #dcdcde;
                box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
                padding: 20px 24px;
                border-radius: 4px;
            }
            .wp-agent-updater-card + .wp-agent-updater-card {
                margin-top: 20px;
            }
            .wp-agent-updater-card-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 16px;
            }
            .wp-agent-updater-card-title {
                display: flex;
                align-items: center;
                gap: 8px;
                margin: 0;
                font-size: 16px;
            }
            .wp-agent-updater-card-title .dashicons {
                font-size: 20px;
                width: 20px;
                height: 20px;
                color: #2271b1;
            }
            .wp-agent-updater-card-subtitle {
                margin: 0 0 12px;
                font-size: 13px;
                color: #555d66;
            }

            /* Badge stato servizio */
            .wp-agent-updater-status-badge {
                padding: 4px 10px;
                border-radius: 999px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: .03em;
                border: 1px solid transparent;
            }
            .status-active {
                background: #e3f7e9;
                color: #1e7e34;
                border-color: #c3e6cb;
            }
            .status-inactive {
                background: #fbeaea;
                color: #a71d2a;
                border-color: #f5c6cb;
            }

            /* Switch Styles */
            .wp-agent-updater-switch {
                position: relative;
                display: inline-block;
                width: 60px;
                height: 34px;
                margin-right: 8px;
            }
            .wp-agent-updater-switch input {
                opacity: 0;
                width: 0;
                height: 0;
            }
            .slider {
                position: absolute;
                cursor: pointer;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: #ccd0d4;
                -webkit-transition: .2s;
                transition: .2s;
                border-radius: 34px;
            }
            .slider:before {
                position: absolute;
                content: "";
                height: 26px;
                width: 26px;
                left: 4px;
                bottom: 4px;
                background-color: #fff;
                -webkit-transition: .2s;
                transition: .2s;
                border-radius: 50%;
                box-shadow: 0 1px 2px rgba(0,0,0,.2);
            }
            input:checked + .slider {
                background-color: #2271b1;
            }
            input:focus + .slider {
                box-shadow: 0 0 0 1px #2271b1;
            }
            input:checked + .slider:before {
                -webkit-transform: translateX(26px);
                -ms-transform: translateX(26px);
                transform: translateX(26px);
            }

            /* Tabelle impostazioni */
            .wp-agent-updater-card .form-table th {
                width: 180px;
            }
            .wp-agent-updater-inline-field {
                display: flex;
                align-items: center;
                gap: 12px;
                flex-wrap: wrap;
            }
        </style>

        <div class="wrap">
            <h1 class="wp-heading-inline">WP Agent Updater</h1>
            <p class="wp-agent-updater-dashboard-intro">
                Manage service status, Master connection, and quick update actions from a single clear and compact dashboard.
            </p>

            <div class="wp-agent-updater-dashboard">
                <div class="wp-agent-updater-dashboard-grid">
                    <div class="wp-agent-updater-dashboard-main">
                        <div class="wp-agent-updater-card">
                            <div class="wp-agent-updater-card-header">
                                <div class="wp-agent-updater-card-title">
                                    <span class="dashicons dashicons-admin-site"></span>
                                    <span>Master Configuration</span>
                                </div>
                            </div>
                            <p class="wp-agent-updater-card-subtitle">
                                Set the Master site URL that will control this agent. The URL is securely stored.
                            </p>

                            <form method="post" action="options.php">
                                <?php settings_fields('wp_agent_updater_options'); ?>
                                <?php do_settings_sections('wp_agent_updater_options'); ?>

                                <table class="form-table">
                                    <tr valign="top">
                                        <th scope="row">Master Site URL</th>
                                        <td>
                                            <?php 
                                            $master_url = get_option('wp_agent_updater_master_url'); 
                                            $placeholder = $master_url ? '••••••••••••••••' : 'https://master-site.com';
                                            ?>
                                            <input type="password" name="wp_agent_updater_master_url" value="" class="regular-text" placeholder="<?php echo esc_attr($placeholder); ?>" autocomplete="new-password" />
                                            <p class="description">Enter the full Master site URL (HTTPS recommended).</p>
                                            <?php if ($master_url): ?>
                                                <p class="description" style="color: #46b450; margin-top: 5px;">
                                                    <span class="dashicons dashicons-yes"></span> URL configured correctly.
                                                </p>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </table>
                                <?php submit_button('Save URL Configuration'); ?>
                            </form>
                        </div>

                        <?php if (get_option('wp_agent_updater_master_url')): ?>
                        <div class="wp-agent-updater-card">
                            <div class="wp-agent-updater-card-header">
                                <div class="wp-agent-updater-card-title">
                                    <span class="dashicons dashicons-cloud"></span>
                                    <span>Service Status</span>
                                </div>
                                <span id="service-status-text" class="wp-agent-updater-status-badge <?php echo get_option('wp_agent_updater_active') === 'yes' ? 'status-active' : 'status-inactive'; ?>">
                                    <?php echo get_option('wp_agent_updater_active') === 'yes' ? 'ACTIVE' : 'INACTIVE'; ?>
                                </span>
                            </div>
                            <p class="wp-agent-updater-card-subtitle">
                                Enable WP Agent Updater to allow the Master to monitor the site and perform centralized updates.
                            </p>

                            <table class="form-table">
                                <tr valign="top">
                                    <th scope="row">Agent Service</th>
                                    <td>
                                        <div class="wp-agent-updater-inline-field">
                                            <label class="wp-agent-updater-switch">
                                                <input type="checkbox" id="agent_active_switch" name="wp_agent_updater_active" value="yes" <?php checked(get_option('wp_agent_updater_active'), 'yes'); ?>>
                                                <span class="slider"></span>
                                            </label>
                                            <span><?php echo get_option('wp_agent_updater_active') === 'yes' ? esc_html__('Service active', 'wp-agent-updater') : esc_html__('Service inactive', 'wp-agent-updater'); ?></span>
                                            <span id="save-spinner" class="spinner" style="float:none;"></span>
                                        </div>
                                        <p class="description">Changes are saved automatically via AJAX, without reloading the page.</p>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="wp-agent-updater-dashboard-side">
                        <div class="wp-agent-updater-card">
                            <div class="wp-agent-updater-card-header">
                                <div class="wp-agent-updater-card-title">
                                    <span class="dashicons dashicons-controls-repeat"></span>
                                    <span>Quick Actions</span>
                                </div>
                            </div>
                            <p class="wp-agent-updater-card-subtitle">
                                Perform an immediate sync and quickly check the installed plugin version.
                            </p>

                            <form method="post" style="margin-bottom: 16px;">
                                <input type="hidden" name="force_sync" value="1">
                                <?php submit_button('Force Sync', 'secondary'); ?>
                            </form>

                            <hr style="margin: 16px 0; border: 0; border-top: 1px solid #eee;">

                            <h3 style="margin-top: 0;">Plugin Updates</h3>
                            <p>Installed Version: <strong><?php echo get_plugin_data(WP_AGENT_UPDATER_PATH . 'wp-agent-updater.php')['Version']; ?></strong></p>
                            <p style="margin-bottom: 0;">
                                <a href="<?php echo wp_nonce_url(admin_url('plugins.php?force-check=1&plugin=' . $plugin_basename), 'wp-agent-updater-force-check-' . $plugin_basename); ?>" class="button button-primary">
                                    Check for updates on GitHub
                                </a>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            function handleSwitch(selector, optionKey, spinnerSelector, statusTextSelector) {
                $(selector).on('change', function() {
                    var isChecked = $(this).is(':checked');
                    var $spinner = $(spinnerSelector);
                    var $statusText = statusTextSelector ? $(statusTextSelector) : null;
                    
                    $spinner.addClass('is-active');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wp_agent_updater_toggle_agent',
                            active: isChecked,
                            option_key: optionKey,
                            nonce: '<?php echo wp_create_nonce("wp_agent_updater_toggle"); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                if ($statusText) {
                                    if (isChecked) {
                                        $statusText.text('ATTIVO').removeClass('status-inactive').addClass('status-active');
                                        // Auto-sync on enable
                                        $.post(ajaxurl, {
                                            action: 'wp_agent_updater_force_sync',
                                            nonce: '<?php echo wp_create_nonce("wp_agent_updater_toggle"); ?>'
                                        }).always(function() {
                                            $spinner.removeClass('is-active');
                                        });
                                    } else {
                                        $statusText.text('INATTIVO').removeClass('status-active').addClass('status-inactive');
                                        $spinner.removeClass('is-active');
                                    }
                                } else {
                                    $spinner.removeClass('is-active');
                                }
                            } else {
                                $spinner.removeClass('is-active');
                                alert('Errore salvataggio: ' + (response.data || 'Errore sconosciuto'));
                                // Revert switch if failed
                                $(selector).prop('checked', !isChecked);
                            }
                        },
                        error: function() {
                            $spinner.removeClass('is-active');
                            alert('Errore di connessione');
                            $(selector).prop('checked', !isChecked);
                        }
                    });
                });
            }

            // Init Switch
            handleSwitch('#agent_active_switch', 'wp_agent_updater_active', '#save-spinner', '#service-status-text');
        });
        </script>
        <?php
    }
}
