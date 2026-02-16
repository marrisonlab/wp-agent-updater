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
        add_action('wp_ajax_wp_agent_updater_update_scan_frequency', [$this, 'update_scan_frequency']);
        add_action('wp_ajax_wp_agent_updater_update_poll_interval', [$this, 'update_poll_interval']);
        
        $plugin_basename = plugin_basename(WP_AGENT_UPDATER_PATH . 'wp-agent-updater.php');
        add_filter('plugin_action_links_' . $plugin_basename, [$this, 'add_action_links']);
    }

    private function render_header($title) {
        $logo_url = plugin_dir_url(__FILE__) . 'logo.svg';
        ?>
        <!-- Invisible H1 to catch WordPress notifications and prevent them from being injected into our custom header -->
        <h1 class="wp-heading-inline" style="display:none;"></h1>
        
        <div class="mmu-header">
            <div class="mmu-header-title">
                <div class="mmu-title-text"><?php echo esc_html($title); ?></div>
            </div>
            <div class="mmu-header-logo">
                <img src="<?php echo esc_url($logo_url); ?>" alt="Marrison Logo">
                <a href="https://marrisonlab.com" target="_blank" class="marrison-link">Powered by Marrisonlab</a>
            </div>
        </div>
        <style>
            .mmu-header {
                height: 120px;
                background: linear-gradient(to top right, #3f2154, #11111e);
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 0 40px;
                margin-bottom: 20px;
                border-radius: 4px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
                color: #fff;
                box-sizing: border-box;
            }
            .mmu-header-title .mmu-title-text {
                color: #fff !important;
                margin: 0 !important;
                padding: 0 !important;
                font-size: 28px !important;
                font-weight: 600 !important;
                line-height: 1.2 !important;
            }
            .mmu-header-logo {
                display: flex;
                flex-direction: column;
                align-items: flex-start;
                justify-content: center;
            }
            .mmu-header-logo img {
                width: 180px;
                height: auto;
                display: block;
                margin-bottom: 2px;
            }
            .marrison-link {
                color: #fd5ec0 !important;
                font-size: 11px !important;
                text-decoration: none !important;
                font-weight: 400 !important;
                font-style: italic !important;
                transition: color 0.2s ease;
            }
            .marrison-link:hover {
                color: #fff !important;
                text-decoration: underline !important;
            }
        </style>
        <?php
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
    
    public function update_scan_frequency() {
        check_ajax_referer('wp_agent_updater_toggle', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        $freq = isset($_POST['frequency']) ? sanitize_text_field($_POST['frequency']) : 'hourly';
        $allowed = ['15min','30min','hourly','twicedaily','daily'];
        if (!in_array($freq, $allowed, true)) {
            wp_send_json_error('Invalid frequency');
        }
        update_option('wp_agent_updater_scan_frequency', $freq);
        wp_clear_scheduled_hook('wp_agent_updater_scheduled_scan');
        if (!wp_next_scheduled('wp_agent_updater_scheduled_scan')) {
            wp_schedule_event(time(), $freq, 'wp_agent_updater_scheduled_scan');
        }
        wp_send_json_success(['frequency' => $freq]);
    }

    public function add_menu() {
        add_menu_page(
            'WP Agent',
            'WP Agent',
            'manage_options',
            'wp-agent-updater',
            [$this, 'render_page'],
            plugin_dir_url(__FILE__) . 'menu-icon.svg?v=' . time()
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
        $icon_url = plugin_dir_url(__FILE__) . 'menu-icon.svg?v=' . time();
        ?>
        <style>
            #adminmenu .toplevel_page_wp-agent-updater .wp-menu-image img {
                display: none !important;
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
            <?php $this->render_header('Backup Management'); ?>
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
        register_setting('wp_agent_updater_options', 'wp_agent_updater_scan_frequency');
        register_setting('wp_agent_updater_options', 'wp_agent_updater_poll_interval');
        register_setting('wp_agent_updater_options', 'wp_agent_updater_master_token', [
            'sanitize_callback' => [$this, 'sanitize_master_token']
        ]);
    }

    public function sanitize_master_url($value) {
        if (empty($value)) {
            return get_option('wp_agent_updater_master_url');
        }
        return esc_url_raw($value);
    }
    
    public function sanitize_master_token($value) {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return get_option('wp_agent_updater_master_token');
        }
        return $value;
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
                margin-top: 20px;
            }
            .wp-agent-updater-dashboard-intro {
                max-width: 900px;
                margin-bottom: 20px;
                color: #646970;
                font-size: 13px;
            }
            .wp-agent-updater-dashboard-grid {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 24px;
                align-items: flex-start;
                max-width: 100%;
            }
            @media (max-width: 960px) {
                .wp-agent-updater-dashboard-grid {
                    grid-template-columns: 1fr;
                }
            }

            /* Card generiche - Stile Master Guide */
            .wp-agent-updater-card {
                background: #ffffff;
                border-radius: 4px;
                border: 1px solid #dcdcde;
                padding: 20px 24px;
                margin-bottom: 20px;
                box-sizing: border-box;
            }
            .wp-agent-updater-card h2 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #f0f0f1;
                font-size: 16px;
                font-weight: 600;
                margin-bottom: 16px;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .wp-agent-updater-card-subtitle {
                margin: 0 0 16px;
                font-size: 13px;
                color: #646970;
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

            /* Switch Styles - Master Style */
            .wp-agent-updater-switch {
                position: relative;
                display: inline-block;
                width: 42px;
                height: 22px;
                margin-right: 10px;
                vertical-align: middle;
            }
            .wp-agent-updater-switch input {
                opacity: 0;
                width: 0;
                height: 0;
                position: absolute;
            }
            .slider {
                position: absolute;
                cursor: pointer;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: #dcdcde;
                -webkit-transition: .2s;
                transition: .2s;
                border-radius: 999px;
                box-shadow: inset 0 0 0 1px rgba(0,0,0,0.08);
            }
            .slider:before {
                position: absolute;
                content: "";
                height: 16px;
                width: 16px;
                left: 3px;
                bottom: 3px;
                background-color: #ffffff;
                -webkit-transition: .2s;
                transition: .2s;
                border-radius: 50%;
                box-shadow: 0 1px 2px rgba(0,0,0,0.15);
            }
            input:checked + .slider {
                background-color: #2271b1;
            }
            input:focus + .slider {
                box-shadow: 0 0 0 1px #2271b1;
            }
            input:checked + .slider:before {
                -webkit-transform: translateX(18px);
                -ms-transform: translateX(18px);
                transform: translateX(18px);
            }

            /* Tabelle impostazioni */
            .wp-agent-updater-card .form-table th {
                width: auto;
                padding-left: 0;
                vertical-align: top;
                min-width: 150px;
            }
            .wp-agent-updater-card .form-table td {
                padding-left: 0;
                padding-top: 10px;
            }
            .wp-agent-updater-inline-field {
                display: flex;
                align-items: center;
                gap: 10px;
                flex-wrap: wrap;
            }
        </style>

        <div class="wrap">
            <?php $this->render_header('WP Agent Updater'); ?>
                     <div class="wp-agent-updater-dashboard">
                <div class="wp-agent-updater-dashboard-grid">
                    
                    <!-- Colonna 1: Master Configuration (e Status se attivo) -->
                    <div>
                        <div class="wp-agent-updater-card">
                            <h2>
                                <span>Master Configuration</span>
                            </h2>
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
                                    <tr valign="top">
                                        <th scope="row">Master API Token</th>
                                        <td>
                                            <?php 
                                            $master_token = get_option('wp_agent_updater_master_token'); 
                                            $placeholder_token = $master_token ? '••••••••••••••••' : 'Enter API token';
                                            ?>
                                            <input type="password" name="wp_agent_updater_master_token" value="" class="regular-text" placeholder="<?php echo esc_attr($placeholder_token); ?>" autocomplete="new-password" />
                                            <p class="description">Copy the API token configured on the Master. Used to authenticate polling and sync.</p>
                                            <?php if ($master_token): ?>
                                                <p class="description" style="color: #46b450; margin-top: 5px;">
                                                    <span class="dashicons dashicons-yes"></span> Token configured.
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
                            <h2>
                                <span>Service Status</span>
                                <span id="service-status-text" class="wp-agent-updater-status-badge <?php echo get_option('wp_agent_updater_active') === 'yes' ? 'status-active' : 'status-inactive'; ?>">
                                    <?php echo get_option('wp_agent_updater_active') === 'yes' ? 'ACTIVE' : 'INACTIVE'; ?>
                                </span>
                            </h2>
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

                    <!-- Colonna 2: Quick Actions -->
                    <div class="wp-agent-updater-card">
                        <h2>
                            <span>Quick Actions</span>
                        </h2>
                        <p class="wp-agent-updater-card-subtitle">
                            Perform an immediate sync.
                        </p>

                        <form method="post">
                            <input type="hidden" name="force_sync" value="1">
                            <?php submit_button('Force Sync', 'secondary'); ?>
                        </form>
                        
                        <hr style="margin:16px 0;border:none;border-top:1px solid #f0f0f1;">
                        <h2 style="border:none;margin-top:0;">
                            <span>Scheduled Scan</span>
                        </h2>
                        <p class="wp-agent-updater-card-subtitle">
                            Configure how often the agent refreshes cached status.
                        </p>
                        <?php $freq = get_option('wp_agent_updater_scan_frequency', 'hourly'); ?>
                        <div class="wp-agent-updater-inline-field">
                            <select id="wp-agent-updater-scan-frequency">
                                <option value="15min" <?php selected($freq, '15min'); ?>>Every 15 Minutes</option>
                                <option value="30min" <?php selected($freq, '30min'); ?>>Every 30 Minutes</option>
                                <option value="hourly" <?php selected($freq, 'hourly'); ?>>Hourly</option>
                                <option value="twicedaily" <?php selected($freq, 'twicedaily'); ?>>Twice Daily</option>
                                <option value="daily" <?php selected($freq, 'daily'); ?>>Daily</option>
                            </select>
                            <span id="scan-frequency-spinner" class="spinner" style="float:none;"></span>
                        </div>
                        
                        <hr style="margin:16px 0;border:none;border-top:1px solid #f0f0f1;">
                        <h2 style="border:none;margin-top:0;">
                            <span>Master Polling</span>
                        </h2>
                        <p class="wp-agent-updater-card-subtitle">
                            Control how often the Agent checks for push/update requests from the Master.
                        </p>
                        <?php $poll = get_option('wp_agent_updater_poll_interval', 'disabled'); ?>
                        <div class="wp-agent-updater-inline-field">
                            <select id="wp-agent-updater-poll-interval">
                                <option value="disabled" <?php selected($poll, 'disabled'); ?>>Disabled</option>
                                <option value="2min" <?php selected($poll, '2min'); ?>>Every 2 Minutes</option>
                                <option value="5min" <?php selected($poll, '5min'); ?>>Every 5 Minutes</option>
                                <option value="10min" <?php selected($poll, '10min'); ?>>Every 10 Minutes</option>
                                <option value="30min" <?php selected($poll, '30min'); ?>>Every 30 Minutes</option>
                            </select>
                            <span id="poll-interval-spinner" class="spinner" style="float:none;"></span>
                        </div>
                    </div>

                    <!-- Colonna 3: Plugin Updates -->
                    <div class="wp-agent-updater-card">
                        <h2>
                            <span>Plugin Updates</span>
                        </h2>
                        <p class="wp-agent-updater-card-subtitle">
                            Check for plugin updates.
                        </p>
                        
                        <p>Installed Version: <strong><?php echo get_plugin_data(WP_AGENT_UPDATER_PATH . 'wp-agent-updater.php')['Version']; ?></strong></p>
                        <p>
                            <a href="<?php echo wp_nonce_url(admin_url('plugins.php?force-check=1&plugin=' . $plugin_basename), 'wp-agent-updater-force-check-' . $plugin_basename); ?>" class="button button-primary">
                                Check for updates on GitHub
                            </a>
                        </p>
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
            
            $('#wp-agent-updater-scan-frequency').on('change', function() {
                var val = $(this).val();
                var $spinner = $('#scan-frequency-spinner');
                $spinner.addClass('is-active');
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wp_agent_updater_update_scan_frequency',
                        frequency: val,
                        nonce: '<?php echo wp_create_nonce("wp_agent_updater_toggle"); ?>'
                    }
                }).always(function() {
                    $spinner.removeClass('is-active');
                });
            });
            
            $('#wp-agent-updater-poll-interval').on('change', function() {
                var val = $(this).val();
                var $spinner = $('#poll-interval-spinner');
                $spinner.addClass('is-active');
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wp_agent_updater_update_poll_interval',
                        poll_interval: val,
                        nonce: '<?php echo wp_create_nonce("wp_agent_updater_toggle"); ?>'
                    }
                }).always(function() {
                    $spinner.removeClass('is-active');
                });
            });
        });
        </script>
        <?php
    }

    public function update_poll_interval() {
        check_ajax_referer('wp_agent_updater_toggle', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        $val = isset($_POST['poll_interval']) ? sanitize_text_field($_POST['poll_interval']) : 'disabled';
        $allowed = ['disabled','2min','5min','10min','30min'];
        if (!in_array($val, $allowed, true)) {
            wp_send_json_error('Invalid interval');
        }
        update_option('wp_agent_updater_poll_interval', $val);
        wp_clear_scheduled_hook('wp_agent_updater_poll_master');
        if ($val !== 'disabled' && !wp_next_scheduled('wp_agent_updater_poll_master')) {
            wp_schedule_event(time(), $val, 'wp_agent_updater_poll_master');
        }
        wp_send_json_success(['poll_interval' => $val]);
    }
}
