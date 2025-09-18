<?php
/**
 * Admin interface for ClearA11y
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class ClearA11y_Admin {
    
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
        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Post editor integration
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        
        // Admin bar integration
        add_action('admin_bar_menu', array($this, 'add_admin_bar_menu'), 100);
        
        // Post list columns
        add_filter('manage_posts_columns', array($this, 'add_posts_column'));
        add_filter('manage_pages_columns', array($this, 'add_posts_column'));
        add_action('manage_posts_custom_column', array($this, 'display_posts_column'), 10, 2);
        add_action('manage_pages_custom_column', array($this, 'display_posts_column'), 10, 2);
        
        // Settings
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('ClearA11y', 'cleara11y'),
            __('ClearA11y', 'cleara11y'),
            'manage_options',
            'cleara11y',
            array($this, 'admin_page'),
            'dashicons-universal-access-alt',
            30
        );
        
        add_submenu_page(
            'cleara11y',
            __('Scan Results', 'cleara11y'),
            __('Scan Results', 'cleara11y'),
            'edit_posts',
            'cleara11y-results',
            array($this, 'results_page')
        );
        
        add_submenu_page(
            'cleara11y',
            __('Bulk Scanner', 'cleara11y'),
            __('Bulk Scanner', 'cleara11y'),
            'manage_options',
            'cleara11y-bulk',
            array($this, 'bulk_scanner_page')
        );
        
        add_submenu_page(
            'cleara11y',
            __('Settings', 'cleara11y'),
            __('Settings', 'cleara11y'),
            'manage_options',
            'cleara11y-settings',
            array($this, 'settings_page')
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our admin pages and post editor
        $our_pages = array('toplevel_page_cleara11y', 'cleara11y_page_cleara11y-results', 'cleara11y_page_cleara11y-bulk', 'cleara11y_page_cleara11y-settings');
        $editor_pages = array('post.php', 'post-new.php');
        
        if (!in_array($hook, array_merge($our_pages, $editor_pages))) {
            return;
        }
        
        wp_enqueue_style(
            'cleara11y-admin',
            CLEARA11Y_PLUGIN_URL . 'admin/css/admin.css',
            array(),
            CLEARA11Y_VERSION
        );
        
        wp_enqueue_script(
            'cleara11y-admin',
            CLEARA11Y_PLUGIN_URL . 'admin/js/admin.js',
            array('jquery'),
            CLEARA11Y_VERSION,
            true
        );
        
        // Enqueue axe-core scanner
        wp_enqueue_script(
            'cleara11y-axe-scanner',
            CLEARA11Y_PLUGIN_URL . 'admin/js/axe-scanner.js',
            array('jquery', 'cleara11y-admin'),
            CLEARA11Y_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script('cleara11y-admin', 'cleara11y_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'plugin_url' => CLEARA11Y_PLUGIN_URL,
            'scan_nonce' => wp_create_nonce('cleara11y_scan_nonce'),
            'bulk_scan_nonce' => wp_create_nonce('cleara11y_bulk_scan_nonce'),
            'accessibility_standard' => get_option('cleara11y_accessibility_standard', 'wcag21aa'),
            'locale' => get_locale(),
            'strings' => array(
                'scanning' => __('Scanning...', 'cleara11y'),
                'scan_complete' => __('Scan complete', 'cleara11y'),
                'scan_error' => __('Scan failed', 'cleara11y'),
                'no_violations' => __('No accessibility violations found!', 'cleara11y'),
                'violations_found' => __('violations found', 'cleara11y'),
            )
        ));
    }
    
    /**
     * Add meta boxes to post editor
     */
    public function add_meta_boxes() {
        $enabled_post_types = get_option('cleara11y_scan_post_types', array('page', 'post'));
        
        foreach ($enabled_post_types as $post_type) {
            add_meta_box(
                'cleara11y-scanner',
                __('Accessibility Scanner', 'cleara11y'),
                array($this, 'meta_box_scanner'),
                $post_type,
                'normal',  // Changed from 'side' to 'normal' for main content area
                'high'     // Changed from 'default' to 'high' for better visibility
            );
        }
    }
    
    /**
     * Display scanner meta box
     */
    public function meta_box_scanner($post) {
        if ($post->post_status !== 'publish') {
            echo '<p>' . __('Post must be published to scan for accessibility issues.', 'cleara11y') . '</p>';
            return;
        }
        
        $latest_scan = ClearA11y_Database::get_latest_scan($post->ID);
        
        echo '<div id="cleara11y-meta-box" class="cleara11y-widget">';
        
        // Tab navigation
        echo '<div class="cleara11y-tabs">';
        echo '<button type="button" class="cleara11y-tab-btn active" data-tab="scan">' . __('Scan', 'cleara11y') . '</button>';
        echo '<button type="button" class="cleara11y-tab-btn" data-tab="violations">' . __('Violations', 'cleara11y') . '</button>';
        echo '<button type="button" class="cleara11y-tab-btn" data-tab="history">' . __('History', 'cleara11y') . '</button>';
        echo '</div>';
        
        // Tab content
        echo '<div class="cleara11y-tab-content">';
        
        // Scan tab
        echo '<div class="cleara11y-tab-pane active" id="cleara11y-tab-scan">';
        echo '<div class="cleara11y-scan-buttons">';
        echo '<button type="button" class="button button-primary cleara11y-comprehensive-scan-btn" data-post-id="' . $post->ID . '">';
        echo __('Scan for Accessibility Issues', 'cleara11y');
        echo '</button>';
        echo '</div>';
        echo '<div id="cleara11y-scan-results"></div>';
        echo '</div>';
        
        // Violations tab
        echo '<div class="cleara11y-tab-pane" id="cleara11y-tab-violations">';
        echo '<div id="cleara11y-violations-list">';
        if ($latest_scan && $latest_scan->total_violations > 0) {
            echo '<p>' . __('Loading violations...', 'cleara11y') . '</p>';
        } else {
            echo '<p>' . __('No violations found. Run a scan to check for accessibility issues.', 'cleara11y') . '</p>';
        }
        echo '</div>';
        echo '</div>';
        
        // History tab
        echo '<div class="cleara11y-tab-pane" id="cleara11y-tab-history">';
        echo '<div id="cleara11y-scan-history">';
        echo '<p>' . __('Loading scan history...', 'cleara11y') . '</p>';
        echo '</div>';
        echo '</div>';
        
        echo '</div>'; // End tab content
        
        if ($latest_scan) {
            $scan_data = json_decode($latest_scan->scan_results, true);
            $violations_count = $latest_scan->total_violations;
            
            echo '<div class="cleara11y-last-scan">';
            echo '<h4>' . __('Last Scan Results', 'cleara11y') . '</h4>';
            echo '<p><strong>' . __('Scanned:', 'cleara11y') . '</strong> ' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($latest_scan->scan_date)) . '</p>';
            
            if ($violations_count > 0) {
                echo '<p class="cleara11y-violations"><strong>' . $violations_count . '</strong> ' . __('violations found', 'cleara11y') . '</p>';
                echo '<button type="button" class="button cleara11y-view-results" data-post-id="' . $post->ID . '">' . __('View Details', 'cleara11y') . '</button>';
            } else {
                echo '<p class="cleara11y-success">' . __('No violations found!', 'cleara11y') . '</p>';
            }
            echo '</div>';
        }
        
        echo '<div id="cleara11y-scan-results"></div>';
        echo '</div>';
    }
    
    /**
     * Add admin bar menu
     */
    public function add_admin_bar_menu($wp_admin_bar) {
        if (!is_singular() || !is_user_logged_in()) {
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
        
        $wp_admin_bar->add_node(array(
            'id' => 'cleara11y-scan',
            'title' => __('Scan Accessibility', 'cleara11y'),
            'href' => '#',
            'meta' => array(
                'class' => 'cleara11y-admin-bar-scan',
                'onclick' => 'return false;'
            )
        ));
    }
    
    /**
     * Add posts column
     */
    public function add_posts_column($columns) {
        $enabled_post_types = get_option('cleara11y_scan_post_types', array('page', 'post'));
        $current_screen = get_current_screen();
        
        if ($current_screen && in_array($current_screen->post_type, $enabled_post_types)) {
            $columns['cleara11y_status'] = __('A11y Status', 'cleara11y');
        }
        
        return $columns;
    }
    
    /**
     * Display posts column content
     */
    public function display_posts_column($column, $post_id) {
        if ($column !== 'cleara11y_status') {
            return;
        }
        
        $latest_scan = ClearA11y_Database::get_latest_scan($post_id);
        
        if (!$latest_scan) {
            echo '<span class="cleara11y-status-none">' . __('Not scanned', 'cleara11y') . '</span>';
            return;
        }
        
        $violations_count = $latest_scan->total_violations;
        
        if ($violations_count === 0) {
            echo '<span class="cleara11y-status-good">✓ ' . __('Clean', 'cleara11y') . '</span>';
        } else {
            echo '<span class="cleara11y-status-issues">⚠ ' . $violations_count . ' ' . __('issues', 'cleara11y') . '</span>';
        }
        
        echo '<br><small>' . human_time_diff(strtotime($latest_scan->scan_date)) . ' ' . __('ago', 'cleara11y') . '</small>';
    }
    
    /**
     * Main admin page
     */
    public function admin_page() {
        $stats = ClearA11y_Database::get_scan_stats();
        
        echo '<div class="wrap">';
        echo '<h1>' . __('ClearA11y - Accessibility Scanner', 'cleara11y') . '</h1>';
        
        echo '<div class="cleara11y-dashboard">';
        echo '<div class="cleara11y-stats">';
        echo '<h2>' . __('Scan Statistics', 'cleara11y') . '</h2>';
        
        if ($stats && $stats->total_scans > 0) {
            echo '<div class="cleara11y-stat-boxes">';
            echo '<div class="stat-box">';
            echo '<h3>' . number_format($stats->total_scans) . '</h3>';
            echo '<p>' . __('Total Scans', 'cleara11y') . '</p>';
            echo '</div>';
            
            echo '<div class="stat-box">';
            echo '<h3>' . number_format($stats->clean_scans) . '</h3>';
            echo '<p>' . __('Clean Pages', 'cleara11y') . '</p>';
            echo '</div>';
            
            echo '<div class="stat-box">';
            echo '<h3>' . number_format($stats->avg_violations, 1) . '</h3>';
            echo '<p>' . __('Avg. Violations', 'cleara11y') . '</p>';
            echo '</div>';
            
            echo '<div class="stat-box">';
            echo '<h3>' . human_time_diff(strtotime($stats->last_scan_date)) . '</h3>';
            echo '<p>' . __('Last Scan', 'cleara11y') . '</p>';
            echo '</div>';
            echo '</div>';
        } else {
            echo '<p>' . __('No scans performed yet. Start by scanning individual pages or running a bulk scan.', 'cleara11y') . '</p>';
        }
        
        echo '</div>';
        echo '</div>';
        
        echo '</div>';
    }
    
    /**
     * Results page
     */
    public function results_page() {
        echo '<div class="wrap">';
        echo '<h1>' . __('Scan Results', 'cleara11y') . '</h1>';
        echo '<p>' . __('View detailed accessibility scan results for your content.', 'cleara11y') . '</p>';
        echo '</div>';
    }
    
    /**
     * Bulk scanner page
     */
    public function bulk_scanner_page() {
        echo '<div class="wrap">';
        echo '<h1>' . __('Bulk Scanner', 'cleara11y') . '</h1>';
        
        echo '<form id="cleara11y-bulk-scan-form">';
        echo '<table class="form-table">';
        
        echo '<tr>';
        echo '<th scope="row">' . __('Post Types to Scan', 'cleara11y') . '</th>';
        echo '<td>';
        $enabled_post_types = get_option('cleara11y_scan_post_types', array('page', 'post'));
        foreach ($enabled_post_types as $post_type) {
            $post_type_obj = get_post_type_object($post_type);
            if ($post_type_obj) {
                echo '<label><input type="checkbox" name="post_types[]" value="' . $post_type . '" checked> ' . $post_type_obj->labels->name . '</label><br>';
            }
        }
        echo '</td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th scope="row">' . __('Post Status', 'cleara11y') . '</th>';
        echo '<td>';
        echo '<label><input type="radio" name="post_status" value="publish" checked> ' . __('Published only', 'cleara11y') . '</label><br>';
        echo '<label><input type="radio" name="post_status" value="any"> ' . __('All statuses', 'cleara11y') . '</label>';
        echo '</td>';
        echo '</tr>';
        
        echo '</table>';
        
        echo '<p class="submit">';
        echo '<button type="submit" class="button button-primary">' . __('Start Bulk Scan', 'cleara11y') . '</button>';
        echo '</p>';
        
        echo '</form>';
        
        echo '<div id="cleara11y-bulk-progress" style="display: none;">';
        echo '<h3>' . __('Scan Progress', 'cleara11y') . '</h3>';
        echo '<div class="progress-bar"><div class="progress-fill"></div></div>';
        echo '<p class="progress-text"></p>';
        echo '</div>';
        
        echo '</div>';
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        echo '<div class="wrap">';
        echo '<h1>' . __('ClearA11y Settings', 'cleara11y') . '</h1>';
        
        echo '<form method="post" action="options.php">';
        settings_fields('cleara11y_settings');
        do_settings_sections('cleara11y_settings');
        submit_button();
        echo '</form>';
        
        echo '</div>';
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('cleara11y_settings', 'cleara11y_accessibility_standard');
        register_setting('cleara11y_settings', 'cleara11y_scan_post_types');
        register_setting('cleara11y_settings', 'cleara11y_results_retention_days');
        register_setting('cleara11y_settings', 'cleara11y_enable_frontend_highlighting');
        register_setting('cleara11y_settings', 'cleara11y_scan_permission');
        
        add_settings_section(
            'cleara11y_general',
            __('General Settings', 'cleara11y'),
            null,
            'cleara11y_settings'
        );
        
        add_settings_field(
            'accessibility_standard',
            __('Accessibility Standard', 'cleara11y'),
            array($this, 'setting_accessibility_standard'),
            'cleara11y_settings',
            'cleara11y_general'
        );
        
        add_settings_field(
            'scan_post_types',
            __('Post Types to Scan', 'cleara11y'),
            array($this, 'setting_scan_post_types'),
            'cleara11y_settings',
            'cleara11y_general'
        );
        
        add_settings_field(
            'results_retention_days',
            __('Results Retention (days)', 'cleara11y'),
            array($this, 'setting_results_retention'),
            'cleara11y_settings',
            'cleara11y_general'
        );
        
        add_settings_field(
            'enable_frontend_highlighting',
            __('Frontend Issue Highlighting', 'cleara11y'),
            array($this, 'setting_frontend_highlighting'),
            'cleara11y_settings',
            'cleara11y_general'
        );
    }
    
    /**
     * Accessibility standard setting
     */
    public function setting_accessibility_standard() {
        $value = get_option('cleara11y_accessibility_standard', 'wcag21aa');
        
        echo '<select name="cleara11y_accessibility_standard">';
        echo '<option value="wcag21aa"' . selected($value, 'wcag21aa', false) . '>WCAG 2.1 AA</option>';
        echo '<option value="wcag21aaa"' . selected($value, 'wcag21aaa', false) . '>WCAG 2.1 AAA</option>';
        echo '<option value="wcag22aa"' . selected($value, 'wcag22aa', false) . '>WCAG 2.2 AA</option>';
        echo '<option value="wcag22aaa"' . selected($value, 'wcag22aaa', false) . '>WCAG 2.2 AAA</option>';
        echo '</select>';
        echo '<p class="description">' . __('Choose which accessibility standard to check against.', 'cleara11y') . '</p>';
    }
    
    /**
     * Scan post types setting
     */
    public function setting_scan_post_types() {
        $enabled_types = get_option('cleara11y_scan_post_types', array('page', 'post'));
        $post_types = get_post_types(array('public' => true), 'objects');
        
        foreach ($post_types as $post_type) {
            $checked = in_array($post_type->name, $enabled_types) ? 'checked' : '';
            echo '<label><input type="checkbox" name="cleara11y_scan_post_types[]" value="' . $post_type->name . '" ' . $checked . '> ' . $post_type->labels->name . '</label><br>';
        }
        echo '<p class="description">' . __('Select which post types should be available for accessibility scanning.', 'cleara11y') . '</p>';
    }
    
    /**
     * Results retention setting
     */
    public function setting_results_retention() {
        $value = get_option('cleara11y_results_retention_days', 30);
        
        echo '<input type="number" name="cleara11y_results_retention_days" value="' . $value . '" min="1" max="365" />';
        echo '<p class="description">' . __('How many days to keep old scan results. The latest scan for each post is always kept.', 'cleara11y') . '</p>';
    }
    
    /**
     * Frontend highlighting setting
     */
    public function setting_frontend_highlighting() {
        $value = get_option('cleara11y_enable_frontend_highlighting', true);
        
        echo '<label><input type="checkbox" name="cleara11y_enable_frontend_highlighting" value="1"' . checked($value, true, false) . '> ' . __('Enable visual highlighting of accessibility issues on frontend', 'cleara11y') . '</label>';
        echo '<p class="description">' . __('When enabled, users with edit permissions can see accessibility issues highlighted on the frontend.', 'cleara11y') . '</p>';
    }
}
