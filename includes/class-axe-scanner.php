<?php
/**
 * Axe-core based accessibility scanner for ClearA11y
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class ClearA11y_Axe_Scanner {
    
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
        // AJAX endpoints for axe-core scanning
        add_action('wp_ajax_cleara11y_axe_scan_post', array($this, 'ajax_axe_scan_post'));
        add_action('wp_ajax_cleara11y_axe_scan_content', array($this, 'ajax_axe_scan_content'));
        add_action('wp_ajax_cleara11y_get_post_url', array($this, 'ajax_get_post_url'));
    }
    
    /**
     * AJAX handler for axe-core post scanning
     */
    public function ajax_axe_scan_post() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'cleara11y_scan_nonce')) {
            wp_die('Security check failed');
        }
        
        $post_id = intval($_POST['post_id']);
        
        // Check permissions
        if (!$this->user_can_scan_post($post_id)) {
            wp_send_json_error('Insufficient permissions');
        }
        
        // Get post content for scanning
        $post_content = $this->get_post_content_for_scanning($post_id);
        
        if (is_wp_error($post_content)) {
            wp_send_json_error($post_content->get_error_message());
        }
        
        wp_send_json_success(array(
            'post_id' => $post_id,
            'content' => $post_content,
            'scan_url' => get_permalink($post_id)
        ));
    }
    
    /**
     * AJAX handler for getting post URL
     */
    public function ajax_get_post_url() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'cleara11y_scan_nonce')) {
            wp_die('Security check failed');
        }
        
        $post_id = intval($_POST['post_id']);
        
        // Check permissions
        if (!$this->user_can_scan_post($post_id)) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $post_url = get_permalink($post_id);
        
        if (!$post_url) {
            wp_send_json_error('Invalid post ID or post not published');
        }
        
        wp_send_json_success(array(
            'url' => $post_url,
            'post_id' => $post_id
        ));
    }
    
    /**
     * AJAX handler for processing axe-core scan results
     */
    public function ajax_axe_scan_content() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'cleara11y_scan_nonce')) {
            wp_die('Security check failed');
        }
        
        $post_id = intval($_POST['post_id']);
        $axe_results = json_decode(stripslashes($_POST['axe_results']), true);
        
        // Check permissions
        if (!$this->user_can_scan_post($post_id)) {
            wp_send_json_error('Insufficient permissions');
        }
        
        // Process and save axe results
        $result = $this->process_axe_results($post_id, $axe_results);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * Get post content formatted for accessibility scanning
     */
    private function get_post_content_for_scanning($post_id) {
        $post = get_post($post_id);
        
        if (!$post || $post->post_status !== 'publish') {
            return new WP_Error('invalid_post', 'Post not found or not published');
        }
        
        // Get the full rendered HTML content
        $permalink = get_permalink($post_id);
        
        // Fetch the actual rendered page
        $response = wp_remote_get($permalink, array(
            'timeout' => 30,
            'user-agent' => 'ClearA11y/' . CLEARA11Y_VERSION . ' (WordPress Accessibility Scanner)',
            'headers' => array(
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            )
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('fetch_error', 'Failed to fetch post content: ' . $response->get_error_message());
        }
        
        $html_content = wp_remote_retrieve_body($response);
        
        if (empty($html_content)) {
            return new WP_Error('empty_content', 'No content retrieved from post URL');
        }
        
        // Clean up the HTML for scanning
        $html_content = $this->prepare_html_for_scanning($html_content);
        
        return $html_content;
    }
    
    /**
     * Prepare HTML content for axe-core scanning
     */
    private function prepare_html_for_scanning($html) {
        // Remove WordPress admin bar and other admin-only elements
        $html = preg_replace('/<div[^>]*id="wpadminbar"[^>]*>.*?<\/div>/s', '', $html);
        
        // Remove script tags that might interfere with scanning
        $html = preg_replace('/<script[^>]*src="[^"]*wp-admin[^"]*"[^>]*><\/script>/', '', $html);
        
        // Ensure we have a proper HTML document structure
        if (strpos($html, '<!DOCTYPE') === false) {
            $html = '<!DOCTYPE html><html><head><title>Accessibility Scan</title></head><body>' . $html . '</body></html>';
        }
        
        return $html;
    }
    
    /**
     * Process axe-core scan results and save to database
     */
    private function process_axe_results($post_id, $axe_results) {
        if (!$axe_results || !isset($axe_results['violations'])) {
            return new WP_Error('invalid_results', 'Invalid axe-core results');
        }
        
        $start_time = microtime(true);
        
        // Transform axe results to our format
        $scan_data = array(
            'url' => get_permalink($post_id),
            'timestamp' => current_time('mysql'),
            'standard' => get_option('cleara11y_accessibility_standard', 'wcag21aa'),
            'violations' => $this->transform_axe_violations($axe_results['violations']),
            'passes' => $this->transform_axe_passes($axe_results['passes'] ?? []),
            'incomplete' => $this->transform_axe_incomplete($axe_results['incomplete'] ?? []),
            'duration' => microtime(true) - $start_time,
            'axe_version' => $axe_results['testEngine']['version'] ?? 'unknown',
            'axe_environment' => $axe_results['testEnvironment'] ?? array()
        );
        
        // Save results to database
        $scan_id = ClearA11y_Database::save_scan_results($post_id, $scan_data);
        
        if (is_wp_error($scan_id)) {
            return $scan_id;
        }
        
        return array(
            'scan_id' => $scan_id,
            'post_id' => $post_id,
            'violations' => count($scan_data['violations']),
            'incomplete' => count($scan_data['incomplete']),
            'passes' => count($scan_data['passes']),
            'duration' => $scan_data['duration']
        );
    }
    
    /**
     * Transform axe violations to our database format
     */
    private function transform_axe_violations($violations) {
        $transformed = array();
        
        foreach ($violations as $violation) {
            $transformed[] = array(
                'id' => $violation['id'],
                'impact' => $violation['impact'],
                'description' => $violation['description'],
                'help' => $violation['help'],
                'helpUrl' => $violation['helpUrl'] ?? '',
                'tags' => $violation['tags'] ?? array(),
                'target' => $this->extract_targets_from_nodes($violation['nodes']),
                'nodes' => $this->transform_axe_nodes($violation['nodes']),
                'failureSummary' => $this->generate_failure_summary($violation['nodes'])
            );
        }
        
        return $transformed;
    }
    
    /**
     * Transform axe passes to our database format
     */
    private function transform_axe_passes($passes) {
        $transformed = array();
        
        foreach ($passes as $pass) {
            $transformed[] = array(
                'id' => $pass['id'],
                'description' => $pass['description'],
                'help' => $pass['help'],
                'helpUrl' => $pass['helpUrl'] ?? '',
                'tags' => $pass['tags'] ?? array()
            );
        }
        
        return $transformed;
    }
    
    /**
     * Transform axe incomplete results to our database format
     */
    private function transform_axe_incomplete($incomplete) {
        $transformed = array();
        
        foreach ($incomplete as $item) {
            $transformed[] = array(
                'id' => $item['id'],
                'impact' => $item['impact'] ?? 'unknown',
                'description' => $item['description'],
                'help' => $item['help'],
                'helpUrl' => $item['helpUrl'] ?? '',
                'tags' => $item['tags'] ?? array(),
                'target' => $this->extract_targets_from_nodes($item['nodes']),
                'nodes' => $this->transform_axe_nodes($item['nodes'])
            );
        }
        
        return $transformed;
    }
    
    /**
     * Transform axe node data
     */
    private function transform_axe_nodes($nodes) {
        $transformed = array();
        
        foreach ($nodes as $node) {
            $transformed[] = array(
                'html' => $node['html'] ?? '',
                'target' => $node['target'] ?? array(),
                'failureSummary' => $node['failureSummary'] ?? '',
                'any' => $node['any'] ?? array(),
                'all' => $node['all'] ?? array(),
                'none' => $node['none'] ?? array()
            );
        }
        
        return $transformed;
    }
    
    /**
     * Extract CSS selectors from axe nodes
     */
    private function extract_targets_from_nodes($nodes) {
        $targets = array();
        
        foreach ($nodes as $node) {
            if (isset($node['target']) && is_array($node['target'])) {
                foreach ($node['target'] as $target) {
                    if (is_string($target)) {
                        $targets[] = $target;
                    }
                }
            }
        }
        
        return array_unique($targets);
    }
    
    /**
     * Generate failure summary from axe nodes
     */
    private function generate_failure_summary($nodes) {
        $summaries = array();
        
        foreach ($nodes as $node) {
            if (isset($node['failureSummary']) && !empty($node['failureSummary'])) {
                $summaries[] = $node['failureSummary'];
            }
        }
        
        return implode('; ', array_unique($summaries));
    }
    
    /**
     * Get axe-core configuration based on accessibility standard
     */
    public function get_axe_config() {
        $standard = get_option('cleara11y_accessibility_standard', 'wcag21aa');
        
        $config = array(
            'rules' => array(),
            'tags' => array(),
            'locale' => get_locale()
        );
        
        // Configure rules based on accessibility standard
        switch ($standard) {
            case 'wcag21aa':
                $config['tags'] = array('wcag2a', 'wcag2aa', 'wcag21aa');
                break;
            case 'wcag21aaa':
                $config['tags'] = array('wcag2a', 'wcag2aa', 'wcag2aaa', 'wcag21aa', 'wcag21aaa');
                break;
            case 'wcag22aa':
                $config['tags'] = array('wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa');
                break;
            case 'wcag22aaa':
                $config['tags'] = array('wcag2a', 'wcag2aa', 'wcag2aaa', 'wcag21aa', 'wcag21aaa', 'wcag22aa', 'wcag22aaa');
                break;
            default:
                $config['tags'] = array('wcag2a', 'wcag2aa');
        }
        
        // Add best practice rules
        $config['tags'][] = 'best-practice';
        
        return $config;
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
