<?php

class WP_Agent_Updater_Core {

    public function init() {
        add_filter('http_request_args', [$this, 'allow_private_repo_downloads'], 10, 2);
        add_filter('upgrader_source_selection', [$this, 'fix_plugin_folder_name'], 10, 4);
    }

    private function log($message) {
        $log_file = WP_CONTENT_DIR . '/wp-agent-updater-debug.log';
        $timestamp = date('Y-m-d H:i:s');
        error_log("[$timestamp] $message\n", 3, $log_file);
    }

    public function allow_private_repo_downloads($args, $url) {
        if (stripos($url, 'github.com') !== false ||
            stripos($url, 'raw.githubusercontent.com') !== false ||
            stripos($url, 'downloads.wordpress.org') !== false ||
            stripos($url, 'api.wordpress.org') !== false) {
            $args['sslverify'] = false;
            $args['timeout'] = 300;
        }
        return $args;
    }

    public function fix_plugin_folder_name($source, $remote_source, $upgrader, $hook_extra = []) {
        global $wp_filesystem;
        
        $this->log("Fix Plugin Folder Name triggered. Source: $source");
        $this->log("Remote Source: $remote_source");

        $slug = '';
        
        // Check for plugins
        if (isset($hook_extra['plugin'])) {
            $slug = dirname($hook_extra['plugin']);
            if ($slug === '.') $slug = basename($hook_extra['plugin'], '.php');
            $this->log("Detected plugin update for slug: $slug");
        } 
        // Check for themes
        elseif (isset($hook_extra['theme'])) {
            $slug = $hook_extra['theme'];
            $this->log("Detected theme update for slug: $slug");
        } 
        else {
            $this->log("No plugin/theme info in hook_extra. Skipping folder fix.");
            return $source;
        }

        $corrected_source = trailingslashit($remote_source) . $slug . '/';
        $current_source = trailingslashit($source);
        
        // Normalize paths for comparison
        $current_source_norm = str_replace('\\', '/', $current_source);
        $corrected_source_norm = str_replace('\\', '/', $corrected_source);

        if ($current_source_norm === $corrected_source_norm) {
            $this->log("Source matches target ($slug). No rename needed.");
            return $source;
        }

        $this->log("Mismatch detected! Attempting rename...");
        $this->log("From: $current_source");
        $this->log("To:   $corrected_source");

        // Method 1: Standard Move
        $result = $wp_filesystem->move($source, $corrected_source);
        
        if ($result) {
            $this->log("SUCCESS: Folder renamed successfully.");
            return $corrected_source;
        } 

        $this->log("WARNING: Standard move failed. Trying advanced recovery...");

        // Method 2: Check if target exists (collision) and delete it
        if ($wp_filesystem->exists($corrected_source)) {
             $this->log("Target folder already exists. Deleting it to avoid conflicts...");
             $wp_filesystem->delete($corrected_source, true);
             
             // Retry move
             $result_retry = $wp_filesystem->move($source, $corrected_source);
             if ($result_retry) {
                 $this->log("SUCCESS: Rename successful after deleting existing target.");
                 return $corrected_source;
             }
        }

        // Method 3: Copy and Delete (Fallback)
        $this->log("Move failed. Attempting Copy+Delete strategy...");
        
        // Recursive copy
        $copy_result = copy_dir($source, $corrected_source);
        
        if (is_wp_error($copy_result)) {
             $this->log("Copy failed: " . $copy_result->get_error_message());
        } elseif ($copy_result) {
             $this->log("Copy successful. Deleting original source...");
             $wp_filesystem->delete($source, true);
             return $corrected_source;
        } else {
             $this->log("Copy returned false (unknown error).");
        }
        
        $this->log("CRITICAL ERROR: Could not rename folder. Update might create a duplicate or fail.");
        return $source;
    }

    public function handle_clear_repo_cache() {
        try {
            // Pulisci cache WordPress standard
            delete_site_transient('update_plugins');
            delete_site_transient('update_themes');
            delete_site_transient('update_core');
            
            // Forza refresh dei dati aggiornamenti
            if (function_exists('wp_update_plugins')) {
                wp_update_plugins();
            }
            
            if (function_exists('wp_update_themes')) {
                wp_update_themes();
            }
            
            return rest_ensure_response([
                'success' => true,
                'message' => 'Repository cache cleared successfully',
                'timestamp' => current_time('mysql')
            ]);
            
        } catch (Exception $e) {
            return rest_ensure_response([
                'success' => false,
                'message' => 'Error clearing cache: ' . $e->getMessage()
            ]);
        }
    }

    public function get_master_url() {
        return get_option('wp_agent_updater_master_url');
    }

    public function is_active() {
        return get_option('wp_agent_updater_active') === 'yes';
    }

    public function sync_with_master() {
        if (!$this->is_active()) {
            return new WP_Error('disabled', 'Agent is disabled');
        }

        $master_url = $this->get_master_url();
        if (empty($master_url)) {
            return new WP_Error('no_url', 'Master URL not configured');
        }

        $data = $this->gather_site_data();

        $response = wp_remote_post($master_url . '/wp-json/wp-master-updater/v1/sync', [
            'body' => json_encode($data),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 30,
            'sslverify' => false
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body;
    }

    public function gather_site_data() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins');
        
        // Use existing transients only (no forced external calls)
        $plugin_updates = get_site_transient('update_plugins');
        $plugin_updates_response = [];
        if (is_object($plugin_updates) && isset($plugin_updates->response) && is_array($plugin_updates->response)) {
            $plugin_updates_response = $plugin_updates->response;
        }
        
        $plugins_active = [];
        $plugins_inactive = [];
        $plugins_need_update = [];

        foreach ($all_plugins as $path => $plugin) {
            $info = [
                'name' => $plugin['Name'],
                'path' => $path,
                'version' => $plugin['Version']
            ];

            if (in_array($path, $active_plugins)) {
                $plugins_active[] = $info;
            } else {
                $plugins_inactive[] = $info;
            }

            if (isset($plugin_updates_response[$path])) {
                $new_version = $plugin_updates_response[$path]->new_version;
                $this->log("Sync Data: $path needs update. New version reported: $new_version");
                
                $plugins_need_update[] = array_merge($info, [
                    'new_version' => $new_version
                ]);
            }
        }

        $all_themes = wp_get_themes();
        $theme_updates = get_site_transient('update_themes');
        $theme_updates_response = [];
        if (is_object($theme_updates) && isset($theme_updates->response) && is_array($theme_updates->response)) {
            $theme_updates_response = $theme_updates->response;
        }
        
        $themes_installed = [];
        $themes_need_update = [];

        foreach ($all_themes as $slug => $theme) {
            $info = [
                'name' => $theme->get('Name'),
                'slug' => $slug,
                'version' => $theme->get('Version')
            ];
            $themes_installed[] = $info;

            if (isset($theme_updates_response[$slug])) {
                $themes_need_update[] = array_merge($info, [
                    'new_version' => $theme_updates_response[$slug]['new_version']
                ]);
            }
        }

        // Translations: rely only on existing core transient (no external checks)
        $trans_updates = get_site_transient('update_core');
        $translations_need_update = is_object($trans_updates) && !empty($trans_updates->translations);

        // Backups
        $backups = [];
        if (class_exists('WP_Agent_Updater_Backups')) {
            $backups = WP_Agent_Updater_Backups::get_instance()->get_backups();
        }

        return [
            'site_url' => get_site_url(),
            'site_name' => get_bloginfo('name'),
            'plugins_active' => $plugins_active,
            'plugins_inactive' => $plugins_inactive,
            'plugins_need_update' => $plugins_need_update,
            'themes_installed' => $themes_installed,
            'themes_need_update' => $themes_need_update,
            'translations_need_update' => $translations_need_update,
            'backups' => $backups
        ];
    }
    
    private function find_plugin_file($slug, $name = '') {
        if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $all_plugins = get_plugins();
        
        // 1. Cerca per dirname (cartella dello slug)
        foreach ($all_plugins as $file => $data) {
            if (dirname($file) === $slug) {
                $this->log("Found plugin match by dirname: $slug -> $file");
                return $file;
            }
        }

        // 1.5 Singular/Plural Dirname Match (Fix for WooCommerce Subscription vs Subscriptions)
        $slug_s = $slug . 's';
        $slug_no_s = rtrim($slug, 's');
        
        foreach ($all_plugins as $file => $data) {
            $dir = dirname($file);
            if ($dir === $slug_s || $dir === $slug_no_s) {
                $this->log("Found plugin match by Singular/Plural Dirname: $slug -> $file");
                return $file;
            }
        }

        // 1.6 Fuzzy Dirname Match
        $norm_slug = strtolower(preg_replace('/[^a-z0-9]/i', '', $slug));
        foreach ($all_plugins as $file => $data) {
            $dir = dirname($file);
            if ($dir === '.') $dir = basename($file, '.php');
            
            $norm_dir = strtolower(preg_replace('/[^a-z0-9]/i', '', $dir));
            if ($norm_dir === $norm_slug && !empty($norm_dir)) {
                $this->log("Found plugin match by Fuzzy Dirname: $slug -> $file");
                return $file;
            }
        }
        
        // 1.7 WooCommerce Subscriptions Specific Fix (Hardcoded for reliability)
        $wc_match = (stripos($slug, 'woocommerce-subscription') !== false) || (stripos($slug, 'woocommerce-subscriptions') !== false) || (!empty($name) && stripos($name, 'WooCommerce Subscriptions') !== false);
        if ($wc_match) {
            foreach ($all_plugins as $file => $data) {
                // Check both Name and Directory
                if (stripos($data['Name'], 'WooCommerce Subscriptions') !== false || 
                    stripos(dirname($file), 'woocommerce-subscriptions') !== false ||
                    stripos($file, 'woocommerce-subscriptions') !== false ||
                    (isset($data['TextDomain']) && stripos($data['TextDomain'], 'woocommerce-subscriptions') !== false)) {
                    $this->log("Found WC Subscriptions specific match: $slug -> $file");
                    return $file;
                }
            }
        }

        // 2. Cerca per nome esatto (se fornito)
        if (!empty($name)) {
            foreach ($all_plugins as $file => $data) {
                if ($data['Name'] === $name) {
                    $this->log("Found plugin match by Name: '$name' -> $file");
                    return $file;
                }
            }
        }

        // 3. Fallback: cerca se il file inizia con lo slug
        foreach ($all_plugins as $file => $data) {
            if (strpos($file, $slug . '/') === 0 || $file === $slug . '.php') {
                $this->log("Found plugin match by fallback (slug start): $slug -> $file");
                return $file;
            }
        }

        // 4. Fuzzy Name Match (Normalization)
        if (!empty($name)) {
            $normalized_search = strtolower(preg_replace('/[^a-z0-9]/i', '', $name));
            foreach ($all_plugins as $file => $data) {
                $normalized_plugin = strtolower(preg_replace('/[^a-z0-9]/i', '', $data['Name']));
                if ($normalized_search === $normalized_plugin) {
                    $this->log("Found plugin match by Fuzzy Name: '$name' (~ '{$data['Name']}') -> $file");
                    return $file;
                }
            }
        }

        // 5. Fallback: TextDomain Match
        // This is useful when the folder name is completely different but the TextDomain matches the slug
        foreach ($all_plugins as $file => $data) {
            if (isset($data['TextDomain']) && $data['TextDomain'] === $slug) {
                $this->log("Found plugin match by TextDomain: $slug -> $file");
                return $file;
            }
        }

        // 6. Fuzzy TextDomain Match
        $normalized_slug = strtolower(preg_replace('/[^a-z0-9]/i', '', $slug));
        foreach ($all_plugins as $file => $data) {
            if (isset($data['TextDomain'])) {
                $normalized_td = strtolower(preg_replace('/[^a-z0-9]/i', '', $data['TextDomain']));
                if ($normalized_td === $normalized_slug && !empty($normalized_td)) {
                    $this->log("Found plugin match by Fuzzy TextDomain: $slug (~ {$data['TextDomain']}) -> $file");
                    return $file;
                }
            }
        }

        // 7. Singular/Plural Name Match (Fix for WooCommerce Subscription vs Subscriptions)
        if (!empty($name)) {
             $norm_name = strtolower(preg_replace('/[^a-z0-9]/i', '', $name));
             // Try adding 's'
             $norm_s = $norm_name . 's';
             // Try removing 's'
             $norm_no_s = rtrim($norm_name, 's');
             
             foreach ($all_plugins as $file => $data) {
                 $p_norm = strtolower(preg_replace('/[^a-z0-9]/i', '', $data['Name']));
                 if ($p_norm === $norm_s || $p_norm === $norm_no_s) {
                      $this->log("Found plugin match by Singular/Plural: $name -> $file");
                      return $file;
                 }
             }
        }

        // $this->log("WARNING: Could not find installed plugin file for slug: $slug (Name: $name)");
        return false;
    }

    // --- Update Routine ---

    public function perform_full_update_routine($clear_cache = true, $update_translations = true) {


        // Ensure necessary files are loaded for upgrader
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-language-pack-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/theme.php';

        $this->disable_gravityforms_autoupdate();

        // 1. Pulisci cache se richiesto
        if ($clear_cache) {
            $this->log("Force clearing cache requested - clearing WordPress update transients...");
            $this->clear_cache(true);
            $plugins_tr = get_site_transient('update_plugins');
            $this->log("After cache clear, update_plugins transient has " . count((array)($plugins_tr->response ?? [])) . " updates");
        }
        
        // 2. Update all plugins as reported by WordPress
        $this->update_plugins();
        $this->clear_cache(false);
        
        // 3. Update all themes as reported by WordPress
        $this->update_themes();
        $this->clear_cache(false);
        
        // 9. Update Translations - ALWAYS unless explicitly disabled
        if ($update_translations) {
            $this->log("Updating translations...");
            $this->update_translations();
        } else {
            $this->log("Translation updates skipped (explicitly disabled).");
        }
        
        // Final Full Refresh before Sync
        $this->clear_cache(true);
        
        // 11. Send data to master
        $this->log("Update routine completed. Syncing with master...");
        return $this->sync_with_master();
    }

    private function disable_gravityforms_autoupdate() {
        if (class_exists('GFForms') && method_exists('GFForms', 'maybe_auto_update')) {
            remove_filter('auto_update_plugin', ['GFForms', 'maybe_auto_update'], 10);
            remove_filter('auto_update_plugin', ['GFForms', 'maybe_auto_update'], 9);
            remove_filter('auto_update_plugin', ['GFForms', 'maybe_auto_update'], 20);
            remove_filter('auto_update_plugin', ['GFForms', 'maybe_auto_update'], 999);
        }
    }

    private function update_plugins() {
        if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        
        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
        $updates = get_site_transient('update_plugins');
        if (empty($updates->response)) return;
        
        $plugins_to_update = [];

        foreach ($updates->response as $file => $data) {
            $package_url = isset($data->package) ? $data->package : 'N/A';
            $this->log("Checking plugin: $file | Package: $package_url");
            $plugins_to_update[] = $file;
        }

        if (!empty($plugins_to_update)) {
            $this->log("Updating plugins: " . implode(', ', $plugins_to_update));
            $upgrader->bulk_upgrade($plugins_to_update);
        }
    }

    private function update_themes() {
        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $upgrader = new Theme_Upgrader(new Automatic_Upgrader_Skin());
        $updates = get_site_transient('update_themes');
        if (empty($updates->response)) return;
        $themes_to_update = [];

        foreach ($updates->response as $slug => $data) {
            $themes_to_update[] = $slug;
        }

        if (!empty($themes_to_update)) {
            $this->log("Updating themes: " . implode(', ', $themes_to_update));
            $upgrader->bulk_upgrade($themes_to_update);
        }
    }

    private function update_translations() {
        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        include_once ABSPATH . 'wp-admin/includes/translation-install.php';
        include_once ABSPATH . 'wp-admin/includes/update.php';

        // 1. Force a check for new language packs
        // wp_update_languages() exists only in WP 5.9+, so we use wp_clean_update_cache instead
        $this->log('Clearing translation cache and checking for updates...');
        wp_clean_update_cache();
        
        // 2. Get the actual list of translation updates
        $this->log('Fetching available translation updates...');
        $updates = wp_get_translation_updates();
        
        if (empty($updates)) {
            $this->log('No translation updates available.');
            return;
        }

        $this->log(count($updates) . ' translation updates found.');
        foreach ($updates as $update) {
            $this->log('Queued for update: ' . $update->slug . ' (' . $update->type . ')');
        }

        // 3. Perform the upgrade
        $this->log('Starting translation bulk upgrade...');
        $upgrader = new Language_Pack_Upgrader(new Automatic_Upgrader_Skin());
        $result = $upgrader->bulk_upgrade($updates);

        // 4. Log the result
        if (is_wp_error($result)) {
            $this->log('Translation update failed: ' . $result->get_error_message());
        } elseif (is_array($result)) {
            if (empty($result)) {
                $this->log('Translation upgrade returned empty, but no WP_Error. This might indicate that updates were attempted but failed silently.');
            } else {
                $this->log('Translation update completed. Result count: ' . count($result));
                foreach($result as $i => $res) {
                    if (is_wp_error($res)) {
                        $this->log("Error updating item $i: " . $res->get_error_message());
                    }
                }
            }
        } else {
            $this->log('Translation update finished with an unexpected result type.');
        }
    }

    private function clear_cache($full_sync = true) {
        wp_cache_flush();
        wp_clean_plugins_cache(true);
        wp_clean_themes_cache(true);
        
        if ($full_sync) {
            delete_site_transient('update_plugins');
            delete_site_transient('update_themes');
            wp_update_plugins();
            wp_update_themes();
        }
    }
}
