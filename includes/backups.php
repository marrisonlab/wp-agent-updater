<?php

class WP_Agent_Updater_Backups {

    private static $instance = null;
    private $backup_dir;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        if (self::$instance) return; // Prevent double instantiation

        $upload_dir = wp_upload_dir();
        $this->backup_dir = trailingslashit($upload_dir['basedir']) . 'wp-agent-updater-backups/';
        
        if (!file_exists($this->backup_dir)) {
            wp_mkdir_p($this->backup_dir);
            // Secure the directory
            file_put_contents($this->backup_dir . 'index.php', '<?php // Silence is golden');
            file_put_contents($this->backup_dir . '.htaccess', 'deny from all');
        }

        add_filter('upgrader_pre_install', [$this, 'create_backup'], 10, 2);
        
        // Remove backup when a plugin is deleted
        add_action('deleted_plugin', [$this, 'handle_plugin_deletion'], 10, 2);
    }

    private function log($message) {
        $log_file = WP_CONTENT_DIR . '/wp-agent-updater-debug.log';
        $timestamp = date('Y-m-d H:i:s');
        error_log("[$timestamp] [WP Agent Updater Backups] $message\n", 3, $log_file);
    }

    public function handle_plugin_deletion($plugin_file, $deleted) {
        if (!$deleted) return;

        $slug = dirname($plugin_file);
        if ($slug === '.') $slug = basename($plugin_file, '.php');

        $this->log("Plugin deletion detected: $slug. Checking for backups...");
        
        $backup_file = $slug . '.zip';
        if ($this->delete_backup($backup_file)) {
            $this->log("Backup deleted for removed plugin: $slug");
        } else {
            $this->log("No backup found/deleted for removed plugin: $slug");
        }
    }

    public function create_backup($return, $hook_extra) {
        if (is_wp_error($return)) return $return;

        // Increase resources for backup operation
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $source = '';
        $slug = '';
        $type = '';

        if (isset($hook_extra['plugin'])) {
            $type = 'plugin';
            $plugin_file = $hook_extra['plugin'];
            $slug = dirname($plugin_file);
            if ($slug === '.') $slug = basename($plugin_file, '.php');
            $source = WP_PLUGIN_DIR . '/' . $slug;
        } elseif (isset($hook_extra['theme'])) {
            $type = 'theme';
            $slug = $hook_extra['theme'];
            $source = get_theme_root() . '/' . $slug;
        } else {
            return $return;
        }

        if (!file_exists($source)) {
            $this->log("Source not found for backup: $source");
            return $return;
        }

        $this->log("Starting backup for $type: $slug");

        // Prepare zip file path
        $zip_filename = $slug . '.zip';
        $zip_path = $this->backup_dir . $zip_filename;

        // Remove existing backup if exists (overwrite policy)
        if (file_exists($zip_path)) {
            unlink($zip_path);
            $this->log("Removed existing backup: $zip_path");
        }

        // Create Zip
        if ($this->zip_directory($source, $zip_path)) {
            $this->log("Backup created successfully: $zip_path");
        } else {
            $this->log("Failed to create backup: $zip_path");
        }

        return $return;
    }

    public function get_backups() {
        if (!file_exists($this->backup_dir)) return [];

        $backups = [];
        $files = scandir($this->backup_dir);

        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || $file === 'index.php' || $file === '.htaccess') continue;
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'zip') continue;

            $path = $this->backup_dir . $file;
            $slug = pathinfo($file, PATHINFO_FILENAME);
            
            // Try to determine if it's a plugin or theme by checking if it exists in wp-content/plugins or themes
            // This is a guess, but useful for display
            $type = 'unknown';
            if (file_exists(WP_PLUGIN_DIR . '/' . $slug)) $type = 'Plugin';
            elseif (file_exists(get_theme_root() . '/' . $slug)) $type = 'Theme';
            elseif (file_exists(WP_PLUGIN_DIR . '/' . $slug . '.php')) $type = 'Plugin'; // Single file plugin

            $backups[] = [
                'filename' => $file,
                'slug' => $slug,
                'path' => $path,
                'size' => size_format(filesize($path)),
                'date' => date('Y-m-d H:i:s', filemtime($path)),
                'type' => $type
            ];
        }

        return $backups;
    }

    public function restore_backup($filename) {
        $zip_path = $this->backup_dir . $filename;
        if (!file_exists($zip_path)) {
            return new WP_Error('no_file', 'Backup file not found');
        }

        $slug = pathinfo($filename, PATHINFO_FILENAME);
        
        // Determine destination type
        $dest_type = '';
        $destination = '';
        
        // Try to guess type from existing installation or zip content
        if (file_exists(WP_PLUGIN_DIR . '/' . $slug) || file_exists(WP_PLUGIN_DIR . '/' . $slug . '.php')) {
            $dest_type = 'plugin';
            $destination = WP_PLUGIN_DIR . '/' . $slug;
        } elseif (file_exists(get_theme_root() . '/' . $slug)) {
            $dest_type = 'theme';
            $destination = get_theme_root() . '/' . $slug;
        } else {
             // Fallback: Peek inside zip to guess type
            $zip = new ZipArchive();
            if ($zip->open($zip_path) === TRUE) {
                if ($zip->getFromName('style.css') !== false || $zip->getFromName($slug . '/style.css') !== false) {
                     $dest_type = 'theme';
                     $destination = get_theme_root() . '/' . $slug;
                } else {
                     $dest_type = 'plugin';
                     $destination = WP_PLUGIN_DIR . '/' . $slug;
                }
                $zip->close();
            } else {
                return new WP_Error('zip_error', 'Cannot open zip file');
            }
        }

        // PRE-RESTORE: Check active status
        // We must check this BEFORE deleting the files
        $was_active = false;
        $plugin_slug_active = '';
        
        if ($dest_type === 'plugin') {
            if (!function_exists('is_plugin_active')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
            
            // Check if any active plugin matches this slug path
            $all_active = get_option('active_plugins');
            foreach ($all_active as $act) {
                // $act is like 'my-plugin/my-plugin.php' or 'hello.php'
                if (strpos($act, $slug . '/') === 0 || $act === $slug . '.php') {
                    $was_active = true;
                    $plugin_slug_active = $act;
                    break;
                }
            }
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        global $wp_filesystem;

        // 1. Unzip to Temporary Directory first
        // This prevents "plugin does not exist" errors during unzip and ensures we have a valid backup before deleting live site data.
        $temp_dir = $this->backup_dir . 'temp_restore_' . uniqid() . '/';
        if (!$wp_filesystem->mkdir($temp_dir)) {
             return new WP_Error('fs_error', 'Could not create temp directory');
        }
        
        $result = unzip_file($zip_path, $temp_dir);
        if (is_wp_error($result)) {
            $wp_filesystem->delete($temp_dir, true);
            return $result;
        }

        // 2. Analyze Temp Structure to handle Flat vs Nested zips
        // Some zips might contain 'slug/file.php', others just 'file.php'.
        // Also handles the case where zips might have absolute paths (bad creation).
        $items = scandir($temp_dir);
        $items = array_diff($items, ['.', '..']);
        $items = array_values($items);
        
        $source_for_move = $temp_dir;
        
        // If the zip contains a single folder that matches the slug (or even if it doesn't match exactly but is the only folder),
        // we likely want the CONTENT of that folder.
        if (count($items) === 1 && is_dir($temp_dir . $items[0])) {
             $source_for_move = $temp_dir . $items[0] . '/';
        }

        // 3. Delete Old Plugin/Theme
        if ($wp_filesystem->exists($destination)) {
            $wp_filesystem->delete($destination, true);
        }
        
        // 4. Move Files using Rename (Atomic & Fast) instead of Copy
        // If source is a folder, we can just rename it to destination
        // Note: WP_Filesystem->move() is basically rename()
        
        // Ensure parent directory exists for destination (e.g. plugins dir)
        $parent_dest = dirname($destination);
        if (!$wp_filesystem->exists($parent_dest)) {
             $wp_filesystem->mkdir($parent_dest);
        }

        // Move the source folder to destination path
        // This is much faster than copy_dir and prevents timeouts/incomplete copies
        $move_result = $wp_filesystem->move($source_for_move, $destination, true);
        
        if (!$move_result) {
             // Fallback to copy if move fails (e.g. across filesystems, though rare for temp/plugins)
             if (!$wp_filesystem->exists($destination)) {
                  $wp_filesystem->mkdir($destination);
             }
             $copy_result = copy_dir($source_for_move, $destination);
             if (is_wp_error($copy_result)) {
                  // Cleanup Temp
                  $wp_filesystem->delete($temp_dir, true);
                  return $copy_result;
             }
        }
        
        // Cleanup Temp (parent dir if we moved a subdir, or the whole temp dir if we copied)
        // If we moved the subdir, the parent temp dir is now empty or contains garbage
        $wp_filesystem->delete($temp_dir, true);

        $this->log("Restored backup: $filename to $destination");

        // 5. Reactivate plugin if it was active
        if ($dest_type === 'plugin' && $was_active) {
            $this->log("Attempting to reactivate plugin...");
            
            // Capture any output during activation to prevent breaking JSON response
            ob_start();
            try {
                // Try to reactivate the exact same slug if file exists
                if (file_exists(WP_PLUGIN_DIR . '/' . $plugin_slug_active)) {
                    $result_act = activate_plugin($plugin_slug_active);
                    if (is_wp_error($result_act)) {
                        $this->log("Error reactivating plugin (old slug): " . $result_act->get_error_message());
                    } else {
                        $this->log("Reactivated plugin: $plugin_slug_active");
                    }
                } else {
                    // If main file changed name, scan for new one
                    $plugin_files = scandir($destination);
                    foreach ($plugin_files as $p_file) {
                        if (substr($p_file, -4) === '.php') {
                            $full_path = $destination . '/' . $p_file;
                            $data = get_plugin_data($full_path, false, false);
                            if (!empty($data['Name'])) {
                                $new_slug = $slug . '/' . $p_file;
                                activate_plugin($new_slug);
                                $this->log("Reactivated plugin (new scan): $new_slug");
                                break;
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                 $this->log("CRITICAL EXCEPTION during plugin activation: " . $e->getMessage());
            } catch (Exception $e) {
                 $this->log("EXCEPTION during plugin activation: " . $e->getMessage());
            }
            
            // Discard any output
            $output = ob_get_clean();
            if (!empty($output)) {
                $this->log("Unexpected output during activation (suppressed): " . substr($output, 0, 200) . "...");
            }
        }

        return true;
    }

    private function zip_directory($source, $destination) {
        if (!extension_loaded('zip') || !file_exists($source)) {
            return false;
        }

        $zip = new ZipArchive();
        if (!$zip->open($destination, ZipArchive::CREATE)) {
            return false;
        }

        $source = str_replace('\\', '/', realpath($source));

        if (is_dir($source) === true) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source), RecursiveIteratorIterator::SELF_FIRST);

            foreach ($files as $file) {
                $file = str_replace('\\', '/', $file);

                // Ignore "." and ".." folders
                if (in_array(substr($file, strrpos($file, '/') + 1), array('.', '..'))) continue;

                $file = realpath($file);
                $file = str_replace('\\', '/', $file);
                
                // Calculate relative path robustly
                if (strpos($file, $source) === 0) {
                    $relativePath = substr($file, strlen($source) + 1);
                } else {
                    continue;
                }

                if (is_dir($file) === true) {
                    $zip->addEmptyDir($relativePath . '/');
                } else if (is_file($file) === true) {
                    $zip->addFromString($relativePath, file_get_contents($file));
                }
            }
        } else if (is_file($source) === true) {
            $zip->addFromString(basename($source), file_get_contents($source));
        }

        return $zip->close();
    }

    public function delete_backup($filename) {
        $path = $this->backup_dir . $filename;
        if (file_exists($path)) {
            unlink($path);
            return true;
        }
        return false;
    }
}
