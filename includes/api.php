<?php

class WP_Agent_Updater_API {

    private $core;
    private $response_sent = false;

    public function __construct() {
        $this->core = new WP_Agent_Updater_Core();
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        register_rest_route('wp-agent-updater/v1', '/update', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_update_request'],
            'permission_callback' => '__return_true'
        ]);
        
        register_rest_route('wp-agent-updater/v1', '/poll-now', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_poll_now'],
            'permission_callback' => '__return_true'
        ]);
        
        register_rest_route('wp-agent-updater/v1', '/status', [
            'methods' => 'GET',
            'callback' => [$this, 'handle_status_request'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('wp-agent-updater/v1', '/backups', [
            'methods' => 'GET',
            'callback' => [$this, 'handle_get_backups'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('wp-agent-updater/v1', '/backups/restore', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_restore_backup'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('wp-agent-updater/v1', '/clear-repo-cache', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_clear_repo_cache'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('wp-agent-updater/v1', '/test-endpoints', [
            'methods' => 'GET',
            'callback' => [$this, 'test_endpoints'],
            'permission_callback' => '__return_true'
        ]);
    }

    private function is_authorized($request) {
        $token = get_option('wp_agent_updater_master_token');
        if (empty($token)) {
            return true;
        }
        $ts = $request->get_header('x-marrison-timestamp');
        $sig = $request->get_header('x-marrison-signature');
        if ($ts && $sig) {
            $now = time();
            if (abs($now - (int)$ts) > 600) {
                return false;
            }
            $message = $request->get_method() === 'POST' ? (string)$request->get_body() : (string)$request->get_param('site_url');
            $expected = hash_hmac('sha256', $message . '|' . $ts, $token);
            return hash_equals($expected, $sig);
        }
        $provided = $request->get_header('x-marrison-token');
        return is_string($provided) && hash_equals($token, $provided);
    }

    private function start_guard($context) {
        $this->response_sent = false;
        ob_start();
        set_error_handler(function ($severity, $message, $file, $line) {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        register_shutdown_function(function () use ($context) {
            if ($this->response_sent) {
                return;
            }
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                while (ob_get_level()) {
                    ob_end_clean();
                }
                http_response_code(200);
                header('Content-Type: application/json; charset=' . get_option('blog_charset'));
                echo wp_json_encode([
                    'success' => false,
                    'message' => $context . ': ' . $error['message']
                ]);
                exit;
            }
        });
    }

    private function finish_guard($log_prefix) {
        $output = ob_get_clean();
        restore_error_handler();
        $this->response_sent = true;

        if (!empty($output)) {
            error_log($log_prefix . substr($output, 0, 500));
        }
    }

    public function handle_get_backups($request) {
        if (!$this->is_authorized($request)) {
            return new WP_Error('unauthorized', 'Unauthorized', ['status' => 403]);
        }
        if (!$this->core->is_active()) {
            return new WP_Error('disabled', 'Agent service disabled', ['status' => 403]);
        }
        
        // Ensure Backups class is loaded
        if (!class_exists('WP_Agent_Updater_Backups')) {
            return new WP_Error('backups_unavailable', 'Backup module not loaded', ['status' => 500]);
        }

        $backups = WP_Agent_Updater_Backups::get_instance()->get_backups();
        return rest_ensure_response($backups);
    }

    public function handle_restore_backup($request) {
        if (!$this->is_authorized($request)) {
            return new WP_Error('unauthorized', 'Unauthorized', ['status' => 403]);
        }
        if (!$this->core->is_active()) {
            return new WP_Error('disabled', 'Agent service disabled', ['status' => 403]);
        }

        $filename = $request->get_param('filename');
        if (empty($filename)) {
            return new WP_Error('missing_param', 'Filename is required', ['status' => 400]);
        }

        if (!class_exists('WP_Agent_Updater_Backups')) {
             return new WP_Error('backups_unavailable', 'Backup module not loaded', ['status' => 500]);
        }

        @set_time_limit(600);
        @ini_set('memory_limit', '512M');
        $this->start_guard('Restore failed');

        try {
            $result = WP_Agent_Updater_Backups::get_instance()->restore_backup($filename);
        } catch (Throwable $e) {
            $result = new WP_Error('restore_exception', $e->getMessage());
        } catch (Exception $e) {
            $result = new WP_Error('restore_exception', $e->getMessage());
        }

        $this->finish_guard('[WP Agent Updater Restore Output] ');

        if (is_wp_error($result)) {
            return rest_ensure_response([
                'success' => false,
                'message' => $result->get_error_message()
            ]);
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'Backup restored successfully'
        ]);
    }

    public function handle_status_request($request) {
        if (!$this->is_authorized($request)) {
            return new WP_Error('unauthorized', 'Unauthorized', ['status' => 403]);
        }
        $cached = get_option('wp_agent_updater_cached_status');
        if (is_array($cached) && isset($cached['data'])) {
            $data = $cached['data'];
            $data['cached_at'] = $cached['timestamp'] ?? null;
        } else {
            $data = $this->core->gather_site_data();
        }
        $data['service_active'] = $this->core->is_active();
        return rest_ensure_response($data);
    }

    public function handle_clear_repo_cache($request) {
        if (!$this->is_authorized($request)) {
            return new WP_Error('unauthorized', 'Unauthorized', ['status' => 403]);
        }
        return $this->core->handle_clear_repo_cache();
    }

    public function test_endpoints($request) {
        $results = [];
        
        // Test 1: Clear cache
        $clear_result = $this->core->handle_clear_repo_cache();
        $results['clear_cache'] = $clear_result->get_data();
        
        // Test 2: Status
        $results['status'] = $this->core->gather_site_data();
        
        // Test 3: Check available translations
        if (!function_exists('wp_get_translation_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }
        $translation_updates = wp_get_translation_updates();
        $results['translations_available'] = count($translation_updates);
        
        return rest_ensure_response($results);
    }

    public function handle_update_request($request) {
        if (!$this->is_authorized($request)) {
            return new WP_Error('unauthorized', 'Unauthorized', ['status' => 403]);
        }
        if (!$this->core->is_active()) {
            return new WP_Error('disabled', 'Agent service disabled', ['status' => 403]);
        }

        $clear_cache = $request->get_param('clear_cache');
        $update_translations = $request->get_param('update_translations');

        // Trigger the update sequence
        // Increase limits for heavy operations
        @set_time_limit(600); 
        @ini_set('memory_limit', '512M');
        $this->start_guard('Update failed');
        
        try {
            $result = $this->core->perform_full_update_routine($clear_cache, $update_translations);
        } catch (Throwable $e) {
            $result = new WP_Error('update_exception', $e->getMessage());
        } catch (Exception $e) {
            $result = new WP_Error('update_exception', $e->getMessage());
        }

        $this->finish_guard('[WP Agent Updater Update Output] ');

        if (is_wp_error($result)) {
            return rest_ensure_response([
                'success' => false,
                'message' => $result->get_error_message()
            ]);
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'Update routine completed',
            'sync_result' => $result
        ]);
    }
    
    public function handle_poll_now($request) {
        if (!$this->is_authorized($request)) {
            return new WP_Error('unauthorized', 'Unauthorized', ['status' => 403]);
        }
        if (!$this->core->is_active()) {
            return new WP_Error('disabled', 'Agent service disabled', ['status' => 403]);
        }
        
        $master = $this->core->get_master_url();
        if (empty($master)) {
            return new WP_Error('no_master', 'Master URL not configured', ['status' => 400]);
        }
        
        $site = get_site_url();
        $poll = untrailingslashit($master) . '/wp-json/wp-master-updater/v1/poll';
        $poll = add_query_arg('site_url', $site, $poll);
        $headers = [];
        $token = get_option('wp_agent_updater_master_token');
        $ts = time();
        if (!empty($token)) {
            $headers['X-Marrison-Token'] = $token;
            $headers['X-Marrison-Timestamp'] = (string)$ts;
            $headers['X-Marrison-Signature'] = hash_hmac('sha256', $site . '|' . $ts, $token);
        }
        
        $resp = wp_remote_get($poll, ['timeout' => 10, 'sslverify' => true, 'headers' => $headers]);
        if (is_wp_error($resp)) {
            return new WP_Error('poll_error', $resp->get_error_message(), ['status' => 500]);
        }
        $info = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($info)) {
            return new WP_Error('poll_invalid', 'Invalid poll response', ['status' => 500]);
        }
        
        $actions_started = [];
        
        if (!empty($info['push_requested'])) {
            $this->core->run_scheduled_scan();
            $actions_started[] = 'scan';
        }
        
        if (!empty($info['update_requested'])) {
            $opts = is_array($info['update_options'] ?? null) ? $info['update_options'] : [];
            $clear = !isset($opts['clear_cache']) ? true : (bool)$opts['clear_cache'];
            $trans = !isset($opts['update_translations']) ? true : (bool)$opts['update_translations'];
            $this->core->perform_full_update_routine($clear, $trans);
            $actions_started[] = 'update';
        }
        
        if (!empty($info['restore_requested']) && !empty($info['restore_data']['filename'])) {
            @set_time_limit(600);
            @ini_set('memory_limit', '512M');
            $backups = WP_Agent_Updater_Backups::get_instance();
            $result = $backups->restore_backup($info['restore_data']['filename']);
            if (!is_wp_error($result)) {
                $this->core->run_scheduled_scan();
                $actions_started[] = 'restore';
            }
        }
        
        return rest_ensure_response([
            'success' => true,
            'actions' => $actions_started
        ]);
    }
}

new WP_Agent_Updater_API();
