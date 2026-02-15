<?php

class WP_Agent_Updater_Core {

    public function init() {
        // Register hooks for private updates - Priority 999 to override WP Repo
        add_filter('site_transient_update_plugins', [$this, 'check_for_private_plugin_updates'], 999);
        add_filter('site_transient_update_themes', [$this, 'check_for_private_theme_updates'], 999);
        
        // Allow insecure downloads for private repos during updates
        add_filter('http_request_args', [$this, 'allow_private_repo_downloads'], 10, 2);
        
        // Fix folder name mismatch during update (e.g. zip extracts to name-version instead of name)
        add_filter('upgrader_source_selection', [$this, 'fix_plugin_folder_name'], 10, 4);

        // Auto-clear cache when repo URLs change
        add_action('update_option_wp_agent_updater_plugins_repo', function($old_value, $new_value) {
            if ($old_value !== $new_value) {
                delete_site_transient('update_plugins');
                wp_update_plugins();
            }
        }, 10, 2);

        add_action('update_option_wp_agent_updater_themes_repo', function($old_value, $new_value) {
            if ($old_value !== $new_value) {
                delete_site_transient('update_themes');
                wp_update_themes();
            }
        }, 10, 2);
    }

    private function log($message) {
        $log_file = WP_CONTENT_DIR . '/wp-agent-updater-debug.log';
        $timestamp = date('Y-m-d H:i:s');
        error_log("[$timestamp] $message\n", 3, $log_file);
    }

    public function allow_private_repo_downloads($args, $url) {
        // Check if the URL matches one of our private repos
        $plugins_repo = get_option('wp_agent_updater_plugins_repo');
        $themes_repo = get_option('wp_agent_updater_themes_repo');
        
        if (($plugins_repo && strpos($url, $plugins_repo) !== false) || 
            ($themes_repo && strpos($url, $themes_repo) !== false) ||
            stripos($url, 'github.com') !== false ||
            stripos($url, 'raw.githubusercontent.com') !== false ||
            stripos($url, 'downloads.wordpress.org') !== false ||
            stripos($url, 'api.wordpress.org') !== false) {
            $args['sslverify'] = false;
            $args['timeout'] = 300; // Increase timeout for downloads
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

    public function force_reload_private_repos() {
        // Pulisci transient
        delete_site_transient('update_plugins');
        delete_site_transient('update_themes');
        
        // Se hai implementato un sistema di cache per le repo private, puliscilo
        $plugin_repo = get_option('wp_agent_updater_plugins_repo');
        if ($plugin_repo) {
            delete_transient('wp_agent_updater_repo_' . md5($plugin_repo));
        }
        
        $theme_repo = get_option('wp_agent_updater_themes_repo');
        if ($theme_repo) {
            delete_transient('wp_agent_updater_repo_' . md5($theme_repo));
        }
        
        // Forza WordPress a controllare di nuovo
        wp_update_plugins();
        wp_update_themes();
        
        // Rimuovi anche la cache delle richieste HTTP se presente
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
    }

    public function handle_clear_repo_cache() {
        try {
            // Pulisci cache WordPress standard
            delete_site_transient('update_plugins');
            delete_site_transient('update_themes');
            delete_site_transient('update_core');
            
            // Pulisci cache repository private
            $plugin_repo = get_option('wp_agent_updater_plugins_repo');
            if ($plugin_repo) {
                delete_transient('wp_agent_updater_repo_' . md5($plugin_repo));
                delete_transient('wp_agent_updater_plugins_' . md5($plugin_repo));
            }
            
            $theme_repo = get_option('wp_agent_updater_themes_repo');
            if ($theme_repo) {
                delete_transient('wp_agent_updater_repo_' . md5($theme_repo));
                delete_transient('wp_agent_updater_themes_' . md5($theme_repo));
            }
            
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
        
        // Save Repo Config if present
        if (isset($body['config'])) {
            if (!empty($body['config']['plugins_repo'])) {
                update_option('wp_agent_updater_plugins_repo', $body['config']['plugins_repo']);
            }
            if (!empty($body['config']['themes_repo'])) {
                update_option('wp_agent_updater_themes_repo', $body['config']['themes_repo']);
            }
        }

        // Save injected updates from master
        if (isset($body['injected_updates'])) {
            $this->log("Master sent injected updates");
            
            // Save injected plugin updates
            if (isset($body['injected_updates']['plugins'])) {
                $plugin_count = count($body['injected_updates']['plugins']);
                update_option('wp_agent_updater_master_injected_plugins', $body['injected_updates']['plugins']);
                $this->log("Saved $plugin_count injected plugin updates");
                
                // Log each injected plugin update
                foreach ($body['injected_updates']['plugins'] as $file => $update) {
                    $package = $update['package'] ?? 'MISSING';
                    $version = $update['new_version'] ?? 'UNKNOWN';
                    $this->log("  - $file -> v$version (Package: $package)");
                }
            }
            
            // Save injected theme updates
            if (isset($body['injected_updates']['themes'])) {
                $theme_count = count($body['injected_updates']['themes']);
                update_option('wp_agent_updater_master_injected_themes', $body['injected_updates']['themes']);
                $this->log("Saved $theme_count injected theme updates");
            }
        } else {
            $this->log("No injected updates received from master");
        }

        return $body;
    }

    public function gather_site_data() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Force clear cache and check updates
        // Use soft clear to avoid deleting transients and forcing slow external requests
        $this->clear_cache(false);
        // wp_update_plugins(); // REMOVED: Forces external requests
        // wp_update_themes();  // REMOVED: Forces external requests
        
        // Ensure checked list is populated by calling wp_update_plugins if needed
        // (wp_update_plugins is called in clear_cache, but we want to be sure)
        
        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins');
        
        // Refresh transient to ensure our filter runs with populated checked data
        $plugin_updates = get_site_transient('update_plugins');
        if (empty($plugin_updates->checked)) {
            // ONLY if data is missing, we force a check.
            $this->log("Transient 'checked' empty. Forcing wp_update_plugins.");
            wp_update_plugins();
            $plugin_updates = get_site_transient('update_plugins');
        }
        
        // Check for available updates
        $updates = get_site_transient('update_plugins');
        
        // Check for master-injected plugin updates
        $master_injected_updates = get_option('wp_agent_updater_master_injected_plugins', []);
        
        $plugins_active = [];
        $plugins_inactive = [];
        $plugins_need_update = [];

        foreach ($all_plugins as $file => $plugin) {
            $info = [
                'name' => $plugin['Name'],
                'path' => $file,
                'version' => $plugin['Version']
            ];

            if (is_plugin_active($file)) {
                $plugins_active[] = $info;
            } else {
                $plugins_inactive[] = $info;
            }

            $update_found = false;
            $can_update = false;
            $injected = null;

            // Check WordPress transient updates first
            if (isset($updates->response[$file])) {
                $update_found = true;
                $pkg = isset($updates->response[$file]->package) ? $updates->response[$file]->package : '';
                $can_update = !empty($pkg);
            }

            // Check master-injected updates
            if (!$update_found && isset($master_injected_updates[$file])) {
                $injected = $master_injected_updates[$file];
                $update_found = true;
                $can_update = !empty($injected['package']);
                $this->log("Using master-injected plugin update for $file: " . $info['version'] . " -> " . ($injected['new_version'] ?? ''));
            }

            if ($update_found) {
                $new_version = '';
                if ($injected) {
                    $new_version = $injected['new_version'] ?? '';
                } elseif (isset($updates->response[$file])) {
                    $new_version = $updates->response[$file]->new_version ?? '';
                }

                $plugins_need_update[] = array_merge($info, [
                    'new_version' => $new_version,
                    'can_update' => $can_update
                ]);
            }
        }

        $all_themes = wp_get_themes();
        $theme_updates = get_site_transient('update_themes');
        
        // Check for master-injected theme updates
        $master_injected_updates = get_option('wp_agent_updater_master_injected_themes', []);
        
        $themes_installed = [];
        $themes_need_update = [];

        foreach ($all_themes as $slug => $theme) {
            $info = [
                'name' => $theme->get('Name'),
                'slug' => $slug,
                'version' => $theme->get('Version')
            ];
            $themes_installed[] = $info;

            $update_found = false;
            
            // Check WordPress transient updates first
            if (isset($theme_updates->response[$slug])) {
                $themes_need_update[] = array_merge($info, [
                    'new_version' => $theme_updates->response[$slug]['new_version']
                ]);
                $update_found = true;
            }
            
            // Check master-injected updates
            if (!$update_found && isset($master_injected_updates[$slug])) {
                $injected = $master_injected_updates[$slug];
                $themes_need_update[] = array_merge($info, [
                    'new_version' => $injected['new_version'],
                    'package' => $injected['package'] ?? ''
                ]);
                $this->log("Using master-injected theme update for $slug: " . $info['version'] . " -> " . $injected['new_version']);
            }
        }

        // Translations (simplified check)
        // Check transient first to avoid external calls
        $trans_updates = get_site_transient('update_core');
        $translations_need_update = !empty($trans_updates->translations);

        if (!$translations_need_update) {
             // Fallback to standard check only if transient is empty/invalid but likely won't trigger external call if cache is valid
             if (!function_exists('wp_get_translation_updates')) {
                require_once ABSPATH . 'wp-admin/includes/update.php';
            }
            $trans_updates_list = wp_get_translation_updates();
            $translations_need_update = !empty($trans_updates_list);
        }

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

    // --- Private Repo Logic ---

    private function fetch_repo_updates($url) {
        $this->log("Fetching remote updates from: $url");
        $response = wp_remote_get(trailingslashit($url) . 'index.php', ['timeout' => 15, 'sslverify' => false]);
        
        if (is_wp_error($response)) {
            $this->log("Error fetching remote updates: " . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!is_array($data)) {
            $this->log("Invalid JSON response from repo. Body: " . substr($body, 0, 100) . "...");
            return false;
        }

        // Apply cleaning logic from marrison-custom-updater
        $cleaned_updates = [];
        foreach ($data as $u) {
            if (!isset($u['slug'])) continue;
            $u['slug'] = trim($u['slug']);
            if (isset($u['version'])) $u['version'] = trim($u['version']);
            if (isset($u['name'])) $u['name'] = trim($u['name']);
            if (isset($u['download_url'])) {
                // Trim spaces, backticks, and quotes to fix common JSON formatting errors
                $u['download_url'] = trim($u['download_url'], " \t\n\r\0\x0B`'\"");
            }
            
            // Security checks
            if (isset($u['name']) && (strpos($u['name'], '$') !== false || strpos($u['name'], '/i\'') !== false)) continue;
            if (isset($u['version']) && strpos($u['version'], '$') !== false) continue;
            
            $cleaned_updates[] = $u;
        }

        $this->log("Remote updates fetched successfully. Cleaned Count: " . count($cleaned_updates));
        return $cleaned_updates;
    }

    private function get_remote_updates($url) {
        if (empty($url)) return [];
        
        $cache_key = 'wp_agent_updater_repo_' . md5($url);
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $updates = $this->fetch_repo_updates($url);
        if ($updates !== false) {
            set_transient($cache_key, $updates, HOUR_IN_SECONDS);
            return $updates;
        }
        
        return [];
    }

    private function safe_refresh_repo($option_name) {
        $repo_url = get_option($option_name);
        if ($repo_url) {
            $updates = $this->fetch_repo_updates($repo_url);
            if ($updates !== false) {
                $cache_key = 'wp_agent_updater_repo_' . md5($repo_url);
                set_transient($cache_key, $updates, HOUR_IN_SECONDS);
                $this->log("Safe Refresh: Cache updated for $option_name");
            } else {
                $this->log("Safe Refresh: Failed to fetch $option_name. Keeping existing cache.");
            }
        }
    }

    public function check_for_private_plugin_updates($transient) {
        if (empty($transient->checked)) {
            $this->log("Private Plugin Check Skipped: 'checked' array is empty.");
            return $transient;
        }

        $repo_url = get_option('wp_agent_updater_plugins_repo');
        if (!$repo_url) return $transient;

        $remote_updates = $this->get_remote_updates($repo_url);
        if (empty($remote_updates)) return $transient;

        if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $all_plugins = get_plugins();

        // Trova il file reale di WC Subscriptions una volta sola
        $wc_file = '';
        foreach ($all_plugins as $file => $data) {
            if (
                stripos($data['Name'], 'WooCommerce Subscriptions') !== false ||
                (isset($data['TextDomain']) && stripos($data['TextDomain'], 'woocommerce-subscriptions') !== false) ||
                stripos($file, 'woocommerce-subscriptions') !== false
            ) {
                $wc_file = $file;
                break;
            }
        }

        // Diagnostic log: list installed plugins
        /*
        if (!get_transient('wp_agent_updater_debug_plugins_listed')) {
            $all = get_plugins();
            $keys = array_keys($all);
            $this->log("Installed Plugins Slugs: " . implode(', ', $keys));
            set_transient('wp_agent_updater_debug_plugins_listed', true, 60);
        }
        */

        foreach ($remote_updates as $update) {
            $slug = trim($update['slug']);
            $name = $update['name'] ?? '';
            $plugin_file = '';

            // Se la repo segnala WC Subscriptions, forziamo l'update sul file reale
            if (
                stripos($slug, 'woocommerce-subscription') !== false ||
                stripos($slug, 'woocommerce-subscriptions') !== false ||
                (!empty($name) && stripos($name, 'WooCommerce Subscriptions') !== false)
            ) {
                if ($wc_file) {
                    $plugin_file = $wc_file;
                    $this->log('WC Subscriptions hard-match: repo entry "' . $slug . '" -> local file "' . $wc_file . '"');
                } else {
                    $this->log('WC Subscriptions repo entry "' . $slug . '" ma NON installato localmente');
                    continue;
                }
            }

            if (isset($all_plugins[$slug])) {
                $plugin_file = $slug;
            } elseif (strpos($slug, '/') !== false) {
                $slug_file = $slug;
                if (substr($slug_file, -4) !== '.php') {
                    $slug_file .= '.php';
                }
                if (isset($all_plugins[$slug_file])) {
                    $plugin_file = $slug_file;
                }
            } elseif (substr($slug, -4) === '.php' && isset($all_plugins[basename($slug)])) {
                $plugin_file = basename($slug);
            }
            
            if (!$plugin_file) {
                $plugin_file = $this->find_plugin_file($slug, $name);
            }
            
            // If not found, try to clean the slug (remove " (N)" pattern common in duplicate downloads)
            if (!$plugin_file) {
                $clean_slug = $slug;
                $cleaned = false;

                // Remove " (N)" pattern
                if (preg_match('/\s\(\d+\)$/', $clean_slug)) {
                    $clean_slug = preg_replace('/\s\(\d+\)$/', '', $clean_slug);
                    $cleaned = true;
                }
                
                // Remove version suffix (e.g., -v1.2.3 or -1.2.3)
                if (preg_match('/[-_]v?\d+(\.\d+)+$/', $clean_slug)) {
                    $clean_slug = preg_replace('/[-_]v?\d+(\.\d+)+$/', '', $clean_slug);
                    $cleaned = true;
                }

                if ($cleaned) {
                    $this->log("Attempting to match with cleaned slug: $clean_slug");
                    $plugin_file = $this->find_plugin_file($clean_slug, $name);
                    
                    if (!$plugin_file) {
                         $this->log("FAILED match after cleaning: $clean_slug. Name: $name");
                    }
                }
            }
            
            if ($plugin_file && isset($transient->checked[$plugin_file])) {
                $current_version = $transient->checked[$plugin_file];
                $this->log("Checking update for $slug: Local=$current_version vs Remote={$update['version']}");
                
                if (version_compare($current_version, $update['version'], '<')) {
                    $this->log("Injecting update for $slug: $current_version -> {$update['version']}");
                    $obj = new stdClass();
                    $folder_slug = dirname($plugin_file);
                    if ($folder_slug === '.') $folder_slug = basename($plugin_file, '.php');
                    $obj->slug = $folder_slug;
                    $obj->plugin = $plugin_file;
                    $obj->new_version = $update['version'];
                    $obj->url = $update['info_url'] ?? '';
                    $obj->package = $update['download_url'];
                    $obj->icons = isset($update['icons']) ? (array)$update['icons'] : [];
                    $obj->banners = isset($update['banners']) ? (array)$update['banners'] : [];
                    $obj->banners_rtl = isset($update['banners_rtl']) ? (array)$update['banners_rtl'] : [];
                    $obj->id = '0'; 
                    $obj->tested = $update['tested'] ?? get_bloginfo('version');
                    
                    // Fix for bad repo data (e.g. requires: 10.4)
                    $remote_requires = $update['requires'] ?? '5.0';
                    if (version_compare($remote_requires, '7.0', '>')) {
                        $this->log("WARNING: Remote 'requires' version ($remote_requires) is suspiciously high. Resetting to 5.0 to allow update.");
                        $remote_requires = '5.0';
                    }
                    $obj->requires = $remote_requires;
                    
                    $obj->requires_php = $update['requires_php'] ?? '7.0';
                    
                    $transient->response[$plugin_file] = $obj;
                }
            }
        }
        return $transient;
    }

    public function check_for_private_theme_updates($transient) {
        if (empty($transient->checked)) return $transient;

        $repo_url = get_option('wp_agent_updater_themes_repo');
        if (!$repo_url) return $transient;

        $remote_updates = $this->get_remote_updates($repo_url);
        if (empty($remote_updates)) return $transient;

        $installed_themes = wp_get_themes();

        foreach ($remote_updates as $update) {
            $raw_slug = $update['slug'];
            $name = $update['name'] ?? '';
            $version = $update['version'];

            // 1. Try finding theme by exact slug or cleaned slug
            $matched_theme = null;
            $clean_slug = $raw_slug;
            
            // Clean slug logic for themes (e.g. jupiterx-v4.14.1 -> jupiterx)
            if (preg_match('/[-_]v?\d+(\.\d+)+$/', $clean_slug)) {
                $clean_slug = preg_replace('/[-_]v?\d+(\.\d+)+$/', '', $clean_slug);
            }

            // Check if theme exists with raw or clean slug
            if (isset($installed_themes[$raw_slug])) {
                $matched_theme = $installed_themes[$raw_slug];
            } elseif (isset($installed_themes[$clean_slug])) {
                $matched_theme = $installed_themes[$clean_slug];
                $this->log("Found theme match by cleaned slug: $raw_slug -> $clean_slug");
            } else {
                // Fuzzy Name Match
                if (!empty($name)) {
                    $normalized_search = strtolower(preg_replace('/[^a-z0-9]/i', '', $name));
                    foreach ($installed_themes as $t_slug => $t_obj) {
                        $normalized_local = strtolower(preg_replace('/[^a-z0-9]/i', '', $t_obj->get('Name')));
                        if ($normalized_search === $normalized_local) {
                            $matched_theme = $t_obj;
                            $this->log("Found theme match by Name: $name -> $t_slug");
                            break;
                        }
                    }
                }
            }
            
            if ($matched_theme) {
                $current_version = $matched_theme->get('Version');
                $local_slug = $matched_theme->get_stylesheet(); // The actual folder name

                $this->log("Checking update for theme $local_slug: Local=$current_version vs Remote=$version");

                if (version_compare($current_version, $version, '<')) {
                    $this->log("Injecting update for theme $local_slug: $current_version -> $version");
                    $arr = [];
                    $arr['theme'] = $local_slug; // IMPORTANT: Use local slug to ensure WP updates the right folder
                    $arr['new_version'] = $version;
                    $arr['url'] = $update['info_url'] ?? '';
                    $arr['package'] = $update['download_url'];
                    
                    $transient->response[$local_slug] = $arr;
                }
            }
        }
        return $transient;
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

        // Validate service is active and configured
        if (!$this->is_active()) {
            $this->log("Update routine aborted: Agent service is not active");
            return new WP_Error('service_inactive', 'Agent service is not active');
        }
        
        $master_url = $this->get_master_url();
        if (empty($master_url)) {
            $this->log("Update routine aborted: Master URL not configured");
            return new WP_Error('master_not_configured', 'Master URL not configured');
        }
        
        $this->log("Starting update routine - Agent active: YES, Master: $master_url");

        try {
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
                $this->log("Force clearing cache requested - clearing all transients...");
                $this->force_reload_private_repos();
                $this->log("Cache clear complete. Re-checking for updates...");
            }

            // Always check for updates (WordPress handles throttling via last_checked)
            wp_update_plugins();
            wp_update_themes();

            if ($clear_cache) {
                $plugins_tr = get_site_transient('update_plugins');
                $this->log("After cache clear, update_plugins transient has " . count((array)($plugins_tr->response ?? [])) . " updates");
            }

            $agent_file = 'wp-agent-updater/wp-agent-updater.php';
            $updates = get_site_transient('update_plugins');
            if (!empty($updates->response[$agent_file])) {
                $pkg = isset($updates->response[$agent_file]->package) ? $updates->response[$agent_file]->package : '';
                if ($pkg) {
                    $this->log("Updating Agent via package: $pkg");
                    $this->manual_upgrade_plugin($agent_file, $pkg);
                }
            }

            // 1. Update WP Repo Plugins
            $this->log("Starting WP repository plugins update...");
            $this->update_plugins('wp'); 
            $this->clear_cache(false); // Soft clear
            
            // 3. Update Private Repo Plugins
            $this->log("Starting private repository plugins update...");
            $this->update_plugins('private');
            $this->clear_cache(false); // Soft clear
            
            // 3b. Ensure any remaining pending plugin updates are applied (catch-all)
            if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
            $pending = get_site_transient('update_plugins');
            if (!empty($pending->response) && is_array($pending->response)) {
                $this->log("Final pass: applying remaining plugin updates (catch-all)");
                $updated_final = 0;
                foreach ($pending->response as $file => $data) {
                    $pkg = isset($data->package) ? $data->package : '';
                    if (!$pkg) {
                        continue;
                    }
                    $this->log("Final pass update via package for $file: $pkg");
                    $manual = $this->manual_upgrade_plugin($file, $pkg);
                    if ($manual && !is_wp_error($manual)) {
                        $updated_final++;
                    } else {
                        if (is_wp_error($manual)) {
                            $this->log("Final pass error for $file: " . $manual->get_error_message());
                        } else {
                            $this->log("Final pass unknown failure for $file");
                        }
                    }
                }
                if ($updated_final > 0) {
                    $stats = get_transient('wp_agent_updater_last_updated_plugins') ?: ['count' => 0];
                    $stats['count'] += $updated_final;
                    set_transient('wp_agent_updater_last_updated_plugins', $stats, 600);
                }
                $this->clear_cache(false); // Soft clear
            }
            
            // 5. Update WP Repo Themes
            $this->log("Starting WP repository themes update...");
            $this->update_themes('wp');
            $this->clear_cache(false); // Soft clear
            
            // 7. Update Private Repo Themes
            $this->log("Starting private repository themes update...");
            $this->update_themes('private');
            $this->clear_cache(false); // Soft clear
            
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
            $this->log("Update routine completed successfully. Syncing with master...");
            return $this->sync_with_master();
            
        } catch (Exception $e) {
            $this->log("CRITICAL ERROR in update routine: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            return new WP_Error('update_routine_exception', $e->getMessage());
        } catch (Error $e) {
            $this->log("FATAL ERROR in update routine: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            return new WP_Error('update_routine_error', $e->getMessage());
        }
    }

    private function disable_gravityforms_autoupdate() {
        if (class_exists('GFForms') && method_exists('GFForms', 'maybe_auto_update')) {
            remove_filter('auto_update_plugin', ['GFForms', 'maybe_auto_update'], 10);
            remove_filter('auto_update_plugin', ['GFForms', 'maybe_auto_update'], 9);
            remove_filter('auto_update_plugin', ['GFForms', 'maybe_auto_update'], 20);
            remove_filter('auto_update_plugin', ['GFForms', 'maybe_auto_update'], 999);
        }
    }

    private function update_plugins($source) {
        if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        
        // wp_update_plugins(); // Removed to avoid redundant external requests
        $updates = get_site_transient('update_plugins');
        
        // Get master-injected updates
        $master_injected_updates = get_option('wp_agent_updater_master_injected_plugins', []);
        
        // Combine WordPress updates with master-injected updates
        $all_updates = [];
        
        // Add WordPress transient updates
        if (!empty($updates->response)) {
            $all_updates = array_merge($all_updates, (array)$updates->response);
        }
        
        // Add master-injected updates
        if (!empty($master_injected_updates)) {
            $this->log("Processing " . count($master_injected_updates) . " master-injected plugin updates");
            foreach ($master_injected_updates as $file => $injected) {
                // Convert injected update to expected format
                $update_obj = new stdClass();
                $update_obj->package = $injected['package'] ?? '';
                $update_obj->new_version = $injected['new_version'] ?? '';
                $update_obj->slug = $injected['slug'] ?? '';
                
                if (empty($update_obj->package)) {
                    $this->log("WARNING: Master-injected update for $file has NO PACKAGE URL - skipping");
                    continue;
                }
                
                $all_updates[$file] = $update_obj;
                $this->log("Added master-injected update for $file: v{$injected['new_version']} (Package: {$update_obj->package})");
            }
        } else {
            $this->log("No master-injected plugin updates found");
        }
        
        if (empty($all_updates)) return;

        $private_slugs = $this->get_private_slugs('plugins');
        $this->log("Private Slugs found: " . implode(', ', $private_slugs));
        
        $plugins_to_update = [];

        foreach ($all_updates as $file => $data) {
            $slug = dirname($file);
            if ($slug === '.') $slug = basename($file, '.php');
            
            $is_private = in_array($slug, $private_slugs, true)
                || in_array($file, $private_slugs, true)
                || in_array(basename($file), $private_slugs, true)
                || in_array($slug . '.php', $private_slugs, true);
            $package_url = isset($data->package) ? $data->package : 'N/A';
            
            $this->log("Checking plugin: $file | Slug: $slug | Is Private: " . ($is_private ? 'YES' : 'NO') . " | Package: $package_url");
            
            if ($source === 'wp' && !$is_private) {
                $plugins_to_update[$file] = $data;
            } elseif ($source === 'private' && $is_private) {
                $plugins_to_update[$file] = $data;
            }
        }

        if (!empty($plugins_to_update)) {
            $this->log("Updating plugins ($source): " . implode(', ', array_keys($plugins_to_update)));
            $updated = 0;
            $skipped_no_package = [];
            $failed = [];
            foreach ($plugins_to_update as $file => $data) {
                $pkg = isset($data->package) ? $data->package : '';
                if (!$pkg) {
                    $this->log("Skipping $file (missing package url)");
                    $skipped_no_package[] = $file;
                    continue;
                }
                $this->log("Updating $file via package: $pkg");
                $res = $this->manual_upgrade_plugin($file, $pkg);
                if ($res && !is_wp_error($res)) {
                    $updated++;
                } else {
                    $failed[] = [
                        'file' => $file,
                        'error' => is_wp_error($res) ? $res->get_error_message() : 'unknown'
                    ];
                }
            }
            $existing_stats = get_transient('wp_agent_updater_last_updated_plugins');
            $existing_count = is_array($existing_stats) ? (int)($existing_stats['count'] ?? 0) : 0;
            set_transient('wp_agent_updater_last_updated_plugins', ['count' => $existing_count + $updated], 600);
            $existing_report = get_transient('wp_agent_updater_last_update_report');
            $existing_plugins = is_array($existing_report) && isset($existing_report['plugins']) && is_array($existing_report['plugins'])
                ? $existing_report['plugins']
                : [];
            $merged_report = [
                'plugins' => [
                    'updated' => (int)($existing_plugins['updated'] ?? 0) + $updated,
                    'skipped_no_package' => array_merge((array)($existing_plugins['skipped_no_package'] ?? []), $skipped_no_package),
                    'failed' => array_merge((array)($existing_plugins['failed'] ?? []), $failed),
                ]
            ];
            set_transient('wp_agent_updater_last_update_report', $merged_report, 600);
        }
    }

    private function update_themes($source) {
        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $upgrader = new Theme_Upgrader(new Automatic_Upgrader_Skin());
        
        // wp_update_themes(); // Removed to avoid redundant external requests
        $updates = get_site_transient('update_themes');
        
        // Get master-injected updates
        $master_injected_updates = get_option('wp_agent_updater_master_injected_themes', []);
        
        // Combine WordPress updates with master-injected updates
        $all_updates = [];
        
        // Add WordPress transient updates
        if (!empty($updates->response)) {
            $all_updates = array_merge($all_updates, (array)$updates->response);
        }
        
        // Add master-injected updates
        if (!empty($master_injected_updates)) {
            foreach ($master_injected_updates as $slug => $injected) {
                // Convert injected update to expected format
                $update_obj = new stdClass();
                $update_obj->package = $injected['package'] ?? '';
                $update_obj->new_version = $injected['new_version'] ?? '';
                $update_obj->slug = $injected['slug'] ?? '';
                $all_updates[$slug] = $update_obj;
                $this->log("Added master-injected theme update for $slug: " . $injected['new_version']);
            }
        }
        
        if (empty($all_updates)) return;

        $private_slugs = $this->get_private_slugs('themes');
        $themes_to_update = [];

        foreach ($all_updates as $slug => $data) {
            $is_private = in_array($slug, $private_slugs);
            
            if ($source === 'wp' && !$is_private) {
                $themes_to_update[] = $slug;
            } elseif ($source === 'private' && $is_private) {
                $themes_to_update[] = $slug;
            }
        }

        if (!empty($themes_to_update)) {
            $this->log("Updating themes ($source): " . implode(', ', $themes_to_update));
            $upgrader->bulk_upgrade($themes_to_update);
        }
    }

    private function get_private_slugs($type) {
        $slugs = [];
        $url = ($type === 'plugins') ? get_option('wp_agent_updater_plugins_repo') : get_option('wp_agent_updater_themes_repo');
        
        $this->log("Getting private slugs for $type from URL: " . ($url ?: 'EMPTY'));
        
        $updates = $this->get_remote_updates($url);
        
        if (empty($updates)) {
            $this->log("No updates found from remote repo for $type");
        }
        
        foreach ($updates as $u) {
            if (isset($u['slug'])) $slugs[] = $u['slug'];
        }
        return $slugs;
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
        
        $this->log('Translation updates check result: ' . (empty($updates) ? 'NONE' : count($updates) . ' found'));
        
        if (empty($updates)) {
            // Debug: Check if there are any language packs available at all
            $this->log('Debug: Checking site language and available translations...');
            $site_locale = get_locale();
            $this->log('Site locale: ' . $site_locale);
            
            // Check if WordPress core has translation updates
            $core_updates = get_core_updates();
            if (!empty($core_updates)) {
                foreach ($core_updates as $core_update) {
                    if (isset($core_update->locale) && $core_update->locale === $site_locale) {
                        $this->log('Core translation available: ' . $core_update->version);
                    }
                }
            }
            
            // Check plugins for translation updates
            $plugin_updates = get_site_transient('update_plugins');
            if (!empty($plugin_updates->translations)) {
                $this->log('Plugin translations available: ' . count($plugin_updates->translations));
                foreach ($plugin_updates->translations as $trans) {
                    $this->log('Plugin translation: ' . $trans->slug . ' -> ' . $trans->language);
                }
            }
            
            // Check themes for translation updates  
            $theme_updates = get_site_transient('update_themes');
            if (!empty($theme_updates->translations)) {
                $this->log('Theme translations available: ' . count($theme_updates->translations));
                foreach ($theme_updates->translations as $trans) {
                    $this->log('Theme translation: ' . $trans->slug . ' -> ' . $trans->language);
                }
            }
            
            $this->log('No translation updates available after detailed check.');
            return;
        }

        $this->log(count($updates) . ' translation updates found.');
        foreach ($updates as $update) {
            $this->log('Queued for update: ' . $update->slug . ' (' . $update->type . ') - Package: ' . ($update->package ?: 'MISSING'));
        }

        $this->log('Starting translation manual install (zip -> languages)...');
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $installed = 0;
        foreach ($updates as $update) {
            $pkg = isset($update->package) ? $update->package : '';
            if (!$pkg) {
                $this->log('Skipping translation update for ' . $update->slug . ': no package URL');
                continue;
            }
            $this->log('Downloading translation package: ' . $pkg);
            $tmp = download_url($pkg, 300);
            if (is_wp_error($tmp)) {
                $this->log('Translation download failed: ' . $tmp->get_error_message());
                continue;
            }
            if (!is_dir(WP_LANG_DIR)) {
                wp_mkdir_p(WP_LANG_DIR);
            }
            $unz = unzip_file($tmp, WP_LANG_DIR);
            @unlink($tmp);
            if (is_wp_error($unz)) {
                $this->log('Translation unzip failed: ' . $unz->get_error_message());
                continue;
            }
            $installed++;
            $this->log('Successfully installed translation for: ' . $update->slug);
        }
        $this->log('Translation install completed. Installed count: ' . $installed);
        set_transient('wp_agent_updater_last_updated_translations', ['count' => $installed], 600);
    }

    private function clear_cache($full_sync = true) {
        wp_cache_flush();
        wp_clean_plugins_cache(true);
        wp_clean_themes_cache(true);
        
        if ($full_sync) {
            delete_site_transient('update_plugins');
            delete_site_transient('update_themes');
            
            // Refresh Repo Transients safely (do not delete blindly)
            $this->safe_refresh_repo('wp_agent_updater_plugins_repo');
            $this->safe_refresh_repo('wp_agent_updater_themes_repo');

            wp_update_plugins();
            wp_update_themes();
        }
    }

    private function manual_upgrade_plugin($file, $package_url) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $this->log("Manual plugin upgrade start: $file | Package: $package_url");
        $tmp = download_url($package_url, 300);
        if (is_wp_error($tmp)) {
            $this->log("Manual upgrade download failed for $file: " . $tmp->get_error_message());
            return $tmp;
        }
        $dest = WP_CONTENT_DIR . '/upgrade/wp-agent-manual-' . time();
        if (!file_exists($dest)) {
            wp_mkdir_p($dest);
        }
        $unz = unzip_file($tmp, $dest);
        @unlink($tmp);
        if (is_wp_error($unz)) {
            $this->log("Manual upgrade unzip failed for $file: " . $unz->get_error_message());
            return $unz;
        }
        $slug = dirname($file);
        if ($slug === '.') $slug = basename($file, '.php');
        $target = WP_PLUGIN_DIR . '/' . $slug . '/';
        $source_dir = $this->find_plugin_source_dir($dest, $file);
        if (!$source_dir || !is_dir($source_dir)) {
            $items = scandir($dest);
            $this->log("Manual upgrade could not locate plugin source dir for $file. Dest contents: " . implode(', ', $items));
            return new WP_Error('manual_extract', 'Plugin source folder not found');
        }
        $this->log("Plugin upgrade details: file=$file, slug=$slug, target=$target, source_dir=$source_dir");
        $backup = '';
        if (is_dir($target)) {
            $backup = WP_CONTENT_DIR . '/upgrade/wp-agent-backup-' . $slug . '-' . time();
            if (!@rename($target, $backup)) {
                $this->log("Manual upgrade could not backup existing folder for $file to $backup");
                $this->php_rrmdir($target);
                $backup = '';
            } else {
                $this->log("Successfully backed up existing plugin to $backup");
            }
        }
        $ok = @rename($source_dir, $target);
        if (!$ok) {
            $this->log("Rename failed, attempting copy operation from $source_dir to $target");
            $this->php_rcopy($source_dir, $target);
            $this->php_rrmdir($source_dir);
            $expected_file = $target . '/' . basename($file);
            $ok = is_dir($target) && file_exists($expected_file);
            $this->log("Copy operation result: target_dir_exists=" . (is_dir($target) ? 'YES' : 'NO') . ", expected_file_exists=" . (file_exists($expected_file) ? 'YES' : 'NO') . " ($expected_file)");
        }
        if (!$ok) {
            if (is_dir($target)) {
                $this->log("Cleaning up failed installation at $target");
                $this->php_rrmdir($target);
            }
            if ($backup && is_dir($backup)) {
                $this->log("Restoring backup from $backup to $target");
                @rename($backup, $target);
            }
            $this->log("Manual plugin upgrade failed for $file");
            return new WP_Error('manual_install', 'Manual plugin install failed');
        }
        if ($backup && is_dir($backup)) {
            $this->php_rrmdir($backup);
        }
        $this->log("Manual plugin upgrade completed successfully: $file");
        return true;
    }

    private function find_plugin_source_dir($root, $file) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $slug = dirname($file);
        if ($slug === '.') $slug = basename($file, '.php');
        $expected_basename = basename($file);
        $installed_path = WP_PLUGIN_DIR . '/' . $file;
        $installed_name = '';
        if (file_exists($installed_path)) {
            $data = get_plugin_data($installed_path, false, false);
            if (!empty($data['Name'])) {
                $installed_name = trim($data['Name']);
            }
        }
        $best_match = '';
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $path => $info) {
                if (!$info->isFile()) {
                    continue;
                }
                if (substr($path, -4) !== '.php') {
                    continue;
                }
                $data = get_plugin_data($path, false, false);
                $name = isset($data['Name']) ? trim($data['Name']) : '';
                if ($installed_name && $name === $installed_name) {
                    return dirname($path);
                }
                if (!$best_match && $name !== '') {
                    $best_match = dirname($path);
                }
                if (basename($path) === $expected_basename) {
                    $best_match = dirname($path);
                }
            }
        } catch (Exception $e) {
            $this->log("find_plugin_source_dir iterator exception: " . $e->getMessage());
        }
        if ($best_match) {
            return $best_match;
        }
        $items = scandir($root);
        foreach ($items as $it) {
            if ($it === '.' || $it === '..') {
                continue;
            }
            $path = $root . '/' . $it;
            if (is_dir($path)) {
                return $path;
            }
        }
        return '';
    }

    private function php_rrmdir($dir) {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->php_rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function php_rcopy($src, $dst) {
        if (is_file($src)) {
            if (!is_dir(dirname($dst))) {
                wp_mkdir_p(dirname($dst));
            }
            @copy($src, $dst);
            return;
        }
        if (!is_dir($dst)) {
            wp_mkdir_p($dst);
        }
        $items = scandir($src);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $from = $src . '/' . $item;
            $to = $dst . '/' . $item;
            if (is_dir($from)) {
                $this->php_rcopy($from, $to);
            } else {
                @copy($from, $to);
            }
        }
    }

    public static function run_update_job_callback($job_id) {
        $jobs = get_option('wp_agent_updater_jobs', []);
        if (empty($jobs[$job_id])) {
            return;
        }
        $job = $jobs[$job_id];
        $jobs[$job_id]['status'] = 'running';
        update_option('wp_agent_updater_jobs', $jobs);
        @set_time_limit(600);
        @ini_set('memory_limit', '512M');
        $clear_cache = !empty($job['clear_cache']);
        $update_translations = !empty($job['update_translations']);
        $core = new self();
        $result = $core->perform_full_update_routine($clear_cache, $update_translations);
        $stats_plugins = get_transient('wp_agent_updater_last_updated_plugins');
        $stats_translations = get_transient('wp_agent_updater_last_updated_translations');
        $stats_report = get_transient('wp_agent_updater_last_update_report');
        delete_transient('wp_agent_updater_last_updated_plugins');
        delete_transient('wp_agent_updater_last_updated_translations');
        delete_transient('wp_agent_updater_last_update_report');
        $summary = [
            'updated' => [
                'plugins' => (int)($stats_plugins['count'] ?? 0),
                'translations' => (int)($stats_translations['count'] ?? 0),
            ],
            'report' => is_array($stats_report) ? $stats_report : null,
        ];
        $jobs = get_option('wp_agent_updater_jobs', []);
        if (is_wp_error($result)) {
            $jobs[$job_id]['status'] = 'error';
            $jobs[$job_id]['error'] = $result->get_error_message();
        } else {
            $jobs[$job_id]['status'] = 'completed';
            $jobs[$job_id]['error'] = null;
        }
        $jobs[$job_id]['result'] = $summary;
        update_option('wp_agent_updater_jobs', $jobs);
    }
}

add_action('wp_agent_updater_run_update_job', ['WP_Agent_Updater_Core', 'run_update_job_callback'], 10, 1);
