<?php

/**
 * WP Agent Updater API - Complete Rewrite
 * Simple, reliable REST endpoints
 */

class WP_Agent_Updater_API {
    
    private $core;
    
    public function __construct() {
        $this->core = new WP_Agent_Updater_Core();
        add_action('rest_api_init', [$this, 'register_routes']);
    }
    
    public function register_routes() {
        // Status endpoint
        register_rest_route('marrison-agent/v1', '/status', [
            'methods' => 'GET',
            'callback' => [$this, 'handle_status'],
            'permission_callback' => '__return_true'
        ]);
        
        // Update endpoint
        register_rest_route('marrison-agent/v1', '/update', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_update'],
            'permission_callback' => '__return_true'
        ]);
        
        // Sync endpoint
        register_rest_route('marrison-agent/v1', '/sync', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_sync'],
            'permission_callback' => '__return_true'
        ]);
        
        // Config endpoint
        register_rest_route('marrison-agent/v1', '/config', [
            'methods' => ['GET', 'POST'],
            'callback' => [$this, 'handle_config'],
            'permission_callback' => '__return_true'
        ]);
        
        // Activate/Deactivate endpoint
        register_rest_route('marrison-agent/v1', '/status/(?P<action>activate|deactivate)', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_status_change'],
            'permission_callback' => '__return_true'
        ]);
        
        // Test endpoint
        register_rest_route('marrison-agent/v1', '/test', [
            'methods' => 'GET',
            'callback' => [$this, 'handle_test'],
            'permission_callback' => '__return_true'
        ]);
    }
    
    /**
     * Handle status request
     */
    public function handle_status($request) {
        try {
            $status = $this->core->get_status_data();
            $config = $this->core->get_config();
            
            return rest_ensure_response([
                'success' => true,
                'status' => $status,
                'config' => $config
            ]);
            
        } catch (Exception $e) {
            return new WP_Error('status_error', $e->getMessage(), ['status' => 500]);
        }
    }
    
    /**
     * Handle update request
     */
    public function handle_update($request) {
        $options = $request->get_json_params() ?? [];
        
        $result = $this->core->perform_update($options);
        
        if (!$result['success']) {
            return new WP_Error('update_failed', $result['message'], ['status' => 500]);
        }
        
        return rest_ensure_response($result);
    }
    
    /**
     * Handle sync request
     */
    public function handle_sync($request) {
        $result = $this->core->sync_with_master();
        
        if (!$result['success']) {
            return new WP_Error('sync_failed', $result['message'], ['status' => 500]);
        }
        
        return rest_ensure_response($result);
    }
    
    /**
     * Handle config request
     */
    public function handle_config($request) {
        $method = $request->get_method();
        
        if ($method === 'GET') {
            $config = $this->core->get_config();
            return rest_ensure_response([
                'success' => true,
                'config' => $config
            ]);
        }
        
        if ($method === 'POST') {
            $config = $request->get_json_params() ?? [];
            
            // Validate config
            $allowed_fields = [
                'master_url',
                'plugins_repo',
                'themes_repo',
                'enable_private_plugins',
                'enable_private_themes',
                'auto_sync',
                'sync_interval'
            ];
            
            $filtered_config = array_intersect_key($config, array_flip($allowed_fields));
            
            // Validate URLs
            if (isset($filtered_config['master_url'])) {
                if (!empty($filtered_config['master_url']) && !filter_var($filtered_config['master_url'], FILTER_VALIDATE_URL)) {
                    return new WP_Error('invalid_url', 'Invalid master URL', ['status' => 400]);
                }
            }
            
            if (isset($filtered_config['plugins_repo'])) {
                if (!empty($filtered_config['plugins_repo']) && !filter_var($filtered_config['plugins_repo'], FILTER_VALIDATE_URL)) {
                    return new WP_Error('invalid_url', 'Invalid plugins repository URL', ['status' => 400]);
                }
            }
            
            if (isset($filtered_config['themes_repo'])) {
                if (!empty($filtered_config['themes_repo']) && !filter_var($filtered_config['themes_repo'], FILTER_VALIDATE_URL)) {
                    return new WP_Error('invalid_url', 'Invalid themes repository URL', ['status' => 400]);
                }
            }
            
            $success = $this->core->update_config($filtered_config);
            
            if (!$success) {
                return new WP_Error('config_failed', 'Failed to update config', ['status' => 500]);
            }
            
            return rest_ensure_response([
                'success' => true,
                'message' => 'Configuration updated',
                'config' => $this->core->get_config()
            ]);
        }
    }
    
    /**
     * Handle status change (activate/deactivate)
     */
    public function handle_status_change($request) {
        $action = $request->get_param('action');
        
        if ($action === 'activate') {
            $this->core->set_status('active');
            $message = 'Agent activated';
        } else {
            $this->core->set_status('inactive');
            $message = 'Agent deactivated';
        }
        
        return rest_ensure_response([
            'success' => true,
            'message' => $message,
            'status' => $this->core->get_status()
        ]);
    }
    
    /**
     * Handle test request
     */
    public function handle_test($request) {
        $tests = [];
        
        // Test WordPress functionality
        $tests['wordpress'] = [
            'version' => get_bloginfo('version'),
            'multisite' => is_multisite(),
            'debug_mode' => WP_DEBUG,
            'memory_limit' => WP_MEMORY_LIMIT
        ];
        
        // Test file system
        $upload_dir = wp_upload_dir();
        $tests['filesystem'] = [
            'writable' => wp_is_writable($upload_dir['basedir']),
            'upload_dir' => $upload_dir['basedir']
        ];
        
        // Test HTTP requests
        $response = wp_remote_get('https://wordpress.org', ['timeout' => 5]);
        $tests['http'] = [
            'working' => !is_wp_error($response),
            'status_code' => is_wp_error($response) ? null : wp_remote_retrieve_response_code($response)
        ];
        
        // Test agent status
        $tests['agent'] = [
            'active' => $this->core->is_active(),
            'config' => $this->core->get_config(),
            'log_file' => file_exists(WP_CONTENT_DIR . '/marrison-agent-updater.log')
        ];
        
        return rest_ensure_response([
            'success' => true,
            'tests' => $tests,
            'timestamp' => current_time('mysql')
        ]);
    }
}

// Initialize API
new WP_Agent_Updater_API();
