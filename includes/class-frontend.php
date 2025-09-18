<?php
/**
 * Frontend functionality for ClearA11y
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class ClearA11y_Frontend {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Frontend scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        
        // Admin bar integration for frontend
        add_action('wp_enqueue_scripts', array($this, 'enqueue_admin_bar_scripts'));
        
        // AJAX endpoint for frontend scanning
        add_action('wp_ajax_cleara11y_frontend_scan', array($this, 'ajax_frontend_scan'));
    }
    
    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_frontend_scripts() {
        // Only load if user has edit permissions and highlighting is enabled
        if (!$this->should_load_frontend_assets()) {
            return;
        }
        
        wp_enqueue_style(
            'cleara11y-frontend',
            CLEARA11Y_PLUGIN_URL . 'public/css/frontend.css',
            array(),
            CLEARA11Y_VERSION
        );
        
        wp_enqueue_script(
            'cleara11y-frontend',
            CLEARA11Y_PLUGIN_URL . 'public/js/frontend.js',
            array('jquery'),
            CLEARA11Y_VERSION,
            true
        );
        
        // Localize script with scan data if available
        $this->localize_frontend_script();
    }
    
    /**
     * Enqueue admin bar scripts for frontend scanning
     */
    public function enqueue_admin_bar_scripts() {
        if (!is_admin_bar_showing() || !is_singular()) {
            return;
        }
        
        global $post;
        
        if (!$post) {
            return;
        }
        
        $enabled_post_types = get_option('cleara11y_scan_post_types', array('page', 'post'));
        
        if (!in_array($post->post_type, $enabled_post_types)) {
            return;
        }
        
        $post_type_obj = get_post_type_object($post->post_type);
        
        if (!current_user_can($post_type_obj->cap->edit_post, $post->ID)) {
            return;
        }
        
        wp_enqueue_script(
            'cleara11y-admin-bar',
            CLEARA11Y_PLUGIN_URL . 'public/js/admin-bar.js',
            array('jquery'),
            CLEARA11Y_VERSION,
            true
        );
        
        wp_localize_script('cleara11y-admin-bar', 'cleara11y_frontend', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cleara11y_frontend_scan'),
            'post_id' => $post->ID,
            'strings' => array(
                'scanning' => __('Scanning for accessibility issues...', 'cleara11y'),
                'scan_complete' => __('Scan complete', 'cleara11y'),
                'scan_error' => __('Scan failed', 'cleara11y'),
                'no_violations' => __('No accessibility violations found!', 'cleara11y'),
                'violations_found' => __('accessibility violations found', 'cleara11y'),
                'view_details' => __('View Details', 'cleara11y'),
            )
        ));
    }
    
    /**
     * AJAX handler for frontend scanning
     */
    public function ajax_frontend_scan() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'cleara11y_frontend_scan')) {
            wp_die('Security check failed');
        }
        
        $post_id = intval($_POST['post_id']);
        
        // Check permissions
        if (!$this->user_can_scan_post($post_id)) {
            wp_send_json_error('Insufficient permissions');
        }
        
        // Use the scanner class to perform the scan
        $scanner = new ClearA11y_Scanner();
        $result = $scanner->scan_post($post_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * Localize frontend script with current page scan data
     */
    private function localize_frontend_script() {
        if (!is_singular()) {
            return;
        }
        
        global $post;
        
        $latest_scan = ClearA11y_Database::get_latest_scan($post->ID);
        $scan_data = array();
        
        if ($latest_scan) {
            $violations = ClearA11y_Database::get_scan_violations($latest_scan->id);
            
            // Prepare violation data for frontend highlighting
            $frontend_violations = array();
            
            foreach ($violations as $violation) {
                $target_selectors = json_decode($violation->target_selector, true);
                $html_snippets = json_decode($violation->html_snippet, true);
                
                $frontend_violations[] = array(
                    'id' => $violation->violation_id,
                    'impact' => $violation->impact,
                    'description' => $violation->description,
                    'help' => $violation->help,
                    'helpUrl' => $violation->help_url,
                    'targets' => $target_selectors,
                    'nodes' => $html_snippets,
                    'failureSummary' => $violation->failure_summary,
                );
            }
            
            $scan_data = array(
                'post_id' => $post->ID,
                'scan_date' => $latest_scan->scan_date,
                'total_violations' => $latest_scan->total_violations,
                'violations' => $frontend_violations,
            );
        }
        
        wp_localize_script('cleara11y-frontend', 'cleara11y_scan_data', $scan_data);
    }
    
    /**
     * Check if frontend assets should be loaded
     */
    private function should_load_frontend_assets() {
        // Only load if highlighting is enabled
        if (!get_option('cleara11y_enable_frontend_highlighting', true)) {
            return false;
        }
        
        // Only load if user is logged in and has edit permissions
        if (!is_user_logged_in()) {
            return false;
        }
        
        // Only load on singular pages
        if (!is_singular()) {
            return false;
        }
        
        global $post;
        
        if (!$post) {
            return false;
        }
        
        // Check if post type is enabled for scanning
        $enabled_post_types = get_option('cleara11y_scan_post_types', array('page', 'post'));
        
        if (!in_array($post->post_type, $enabled_post_types)) {
            return false;
        }
        
        // Check if user can edit this post
        $post_type_obj = get_post_type_object($post->post_type);
        
        return current_user_can($post_type_obj->cap->edit_post, $post->ID);
    }
    
    /**
     * Check if user can scan a specific post
     */
    private function user_can_scan_post($post_id) {
        $post = get_post($post_id);
        
        if (!$post) {
            return false;
        }
        
        // Check if user can edit this post type
        $post_type_obj = get_post_type_object($post->post_type);
        
        if (!$post_type_obj) {
            return false;
        }
        
        // Check if post type is enabled for scanning
        $enabled_post_types = get_option('cleara11y_scan_post_types', array('page', 'post'));
        
        if (!in_array($post->post_type, $enabled_post_types)) {
            return false;
        }
        
        // Check user capability
        return current_user_can($post_type_obj->cap->edit_post, $post_id);
    }
}
