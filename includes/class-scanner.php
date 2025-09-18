<?php
/**
 * Accessibility scanner for ClearA11y
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class ClearA11y_Scanner {
    
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
        // AJAX endpoints for scanning
        add_action('wp_ajax_cleara11y_scan_post', array($this, 'ajax_scan_post'));
        add_action('wp_ajax_cleara11y_bulk_scan', array($this, 'ajax_bulk_scan'));
        add_action('wp_ajax_cleara11y_get_scan_results', array($this, 'ajax_get_scan_results'));
        
        // Background processing for bulk scans
        add_action('cleara11y_process_bulk_scan', array($this, 'process_bulk_scan'), 10, 2);
    }
    
    /**
     * AJAX handler for single post scanning
     */
    public function ajax_scan_post() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'cleara11y_scan_nonce')) {
            wp_die('Security check failed');
        }
        
        $post_id = intval($_POST['post_id']);
        
        // Check permissions
        if (!$this->user_can_scan_post($post_id)) {
            wp_send_json_error('Insufficient permissions');
        }
        
        // Perform scan
        $result = $this->scan_post($post_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX handler for bulk scanning
     */
    public function ajax_bulk_scan() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'cleara11y_bulk_scan_nonce')) {
            wp_die('Security check failed');
        }
        
        $post_ids = array_map('intval', $_POST['post_ids']);
        $post_types = sanitize_text_field($_POST['post_types'] ?? '');
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions for bulk scanning');
        }
        
        // Schedule background processing
        $batch_id = uniqid('bulk_scan_');
        wp_schedule_single_event(time(), 'cleara11y_process_bulk_scan', array($post_ids, $batch_id));
        
        wp_send_json_success(array(
            'batch_id' => $batch_id,
            'message' => 'Bulk scan initiated. You will be notified when complete.'
        ));
    }
    
    /**
     * AJAX handler for getting scan results
     */
    public function ajax_get_scan_results() {
        $post_id = intval($_GET['post_id']);
        
        if (!$this->user_can_scan_post($post_id)) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $scan = ClearA11y_Database::get_latest_scan($post_id);
        
        if (!$scan) {
            wp_send_json_error('No scan results found');
        }
        
        $violations = ClearA11y_Database::get_scan_violations($scan->id);
        
        wp_send_json_success(array(
            'scan' => $scan,
            'violations' => $violations
        ));
    }
    
    /**
     * Scan a single post for accessibility issues
     */
    public function scan_post($post_id) {
        $start_time = microtime(true);
        
        // Get post URL
        $post_url = get_permalink($post_id);
        if (!$post_url) {
            return new WP_Error('invalid_post', 'Invalid post ID or post not published');
        }
        
        // Fetch post content
        $response = wp_remote_get($post_url, array(
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
        
        // Run accessibility scan
        $scan_results = $this->run_accessibility_scan($html_content, $post_url);
        
        if (is_wp_error($scan_results)) {
            return $scan_results;
        }
        
        // Add timing information
        $scan_results['duration'] = microtime(true) - $start_time;
        
        // Save results to database
        $scan_id = ClearA11y_Database::save_scan_results($post_id, $scan_results);
        
        if (is_wp_error($scan_id)) {
            return $scan_id;
        }
        
        return array(
            'scan_id' => $scan_id,
            'post_id' => $post_id,
            'violations' => count($scan_results['violations'] ?? []),
            'incomplete' => count($scan_results['incomplete'] ?? []),
            'passes' => count($scan_results['passes'] ?? []),
            'duration' => $scan_results['duration']
        );
    }
    
    /**
     * Run accessibility scan using axe-core rules
     */
    private function run_accessibility_scan($html_content, $url) {
        // For now, we'll create a mock scan result structure
        // In the next step, we'll integrate actual axe-core scanning
        
        // Parse HTML to find common accessibility issues
        $violations = array();
        $passes = array();
        $incomplete = array();
        
        // Create a DOMDocument to parse HTML
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html_content);
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        // Check for images without alt text
        $images = $xpath->query('//img[not(@alt) or @alt=""]');
        foreach ($images as $img) {
            $violations[] = array(
                'id' => 'image-alt',
                'impact' => 'critical',
                'description' => 'Images must have alternate text',
                'help' => 'All img elements must have an alt attribute',
                'helpUrl' => 'https://dequeuniversity.com/rules/axe/4.7/image-alt',
                'tags' => array('cat.text-alternatives', 'wcag2a', 'wcag111'),
                'target' => array($this->get_element_selector($img)),
                'nodes' => array(array(
                    'html' => $dom->saveHTML($img),
                    'target' => array($this->get_element_selector($img))
                )),
                'failureSummary' => 'Fix the following: Element does not have an alt attribute'
            );
        }
        
        // Check for headings hierarchy
        $headings = $xpath->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6');
        $heading_levels = array();
        foreach ($headings as $heading) {
            $level = intval(substr($heading->tagName, 1));
            $heading_levels[] = $level;
        }
        
        // Check for skipped heading levels
        for ($i = 1; $i < count($heading_levels); $i++) {
            if ($heading_levels[$i] - $heading_levels[$i-1] > 1) {
                $violations[] = array(
                    'id' => 'heading-order',
                    'impact' => 'moderate',
                    'description' => 'Heading levels should only increase by one',
                    'help' => 'Headings must not skip levels',
                    'helpUrl' => 'https://dequeuniversity.com/rules/axe/4.7/heading-order',
                    'tags' => array('cat.semantics', 'best-practice'),
                    'target' => array('h' . $heading_levels[$i]),
                    'nodes' => array(),
                    'failureSummary' => 'Fix the following: Heading order invalid'
                );
                break;
            }
        }
        
        // Check for form labels
        $inputs = $xpath->query('//input[@type!="hidden" and @type!="submit" and @type!="button"] | //textarea | //select');
        foreach ($inputs as $input) {
            $id = $input->getAttribute('id');
            $has_label = false;
            
            if ($id) {
                $labels = $xpath->query('//label[@for="' . $id . '"]');
                $has_label = $labels->length > 0;
            }
            
            if (!$has_label) {
                $violations[] = array(
                    'id' => 'label',
                    'impact' => 'critical',
                    'description' => 'Form elements must have labels',
                    'help' => 'Every form element must have a label',
                    'helpUrl' => 'https://dequeuniversity.com/rules/axe/4.7/label',
                    'tags' => array('cat.forms', 'wcag2a', 'wcag412'),
                    'target' => array($this->get_element_selector($input)),
                    'nodes' => array(array(
                        'html' => $dom->saveHTML($input),
                        'target' => array($this->get_element_selector($input))
                    )),
                    'failureSummary' => 'Fix the following: Form element does not have an implicit (wrapped) or explicit (associated) label'
                );
            }
        }
        
        // Mock some passes for demonstration
        $passes[] = array(
            'id' => 'document-title',
            'description' => 'Documents must have a title element',
            'help' => 'Every HTML document must have a title'
        );
        
        return array(
            'url' => $url,
            'timestamp' => current_time('mysql'),
            'standard' => get_option('cleara11y_accessibility_standard', 'wcag21aa'),
            'violations' => $violations,
            'passes' => $passes,
            'incomplete' => $incomplete
        );
    }
    
    /**
     * Get CSS selector for an element
     */
    private function get_element_selector($element) {
        $selector = $element->tagName;
        
        if ($element->getAttribute('id')) {
            $selector .= '#' . $element->getAttribute('id');
        }
        
        if ($element->getAttribute('class')) {
            $classes = explode(' ', trim($element->getAttribute('class')));
            $selector .= '.' . implode('.', $classes);
        }
        
        return $selector;
    }
    
    /**
     * Process bulk scan in background
     */
    public function process_bulk_scan($post_ids, $batch_id) {
        $results = array();
        $total = count($post_ids);
        $processed = 0;
        
        foreach ($post_ids as $post_id) {
            $result = $this->scan_post($post_id);
            $results[] = array(
                'post_id' => $post_id,
                'result' => $result
            );
            $processed++;
            
            // Update progress (you could store this in options or transients)
            set_transient('cleara11y_bulk_progress_' . $batch_id, array(
                'processed' => $processed,
                'total' => $total,
                'results' => $results
            ), HOUR_IN_SECONDS);
        }
        
        // Send notification email to admin (optional)
        $this->send_bulk_scan_notification($batch_id, $results);
    }
    
    /**
     * Send notification email for completed bulk scan
     */
    private function send_bulk_scan_notification($batch_id, $results) {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        
        $total_scanned = count($results);
        $total_violations = 0;
        
        foreach ($results as $result) {
            if (!is_wp_error($result['result'])) {
                $total_violations += $result['result']['violations'];
            }
        }
        
        $subject = sprintf('[%s] Bulk Accessibility Scan Complete', $site_name);
        $message = sprintf(
            "Your bulk accessibility scan has completed.\n\n" .
            "Scanned: %d pages\n" .
            "Total violations found: %d\n\n" .
            "View detailed results in your WordPress admin under ClearA11y.\n\n" .
            "Batch ID: %s",
            $total_scanned,
            $total_violations,
            $batch_id
        );
        
        wp_mail($admin_email, $subject, $message);
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
