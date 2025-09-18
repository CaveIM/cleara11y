<?php
/**
 * Database operations for ClearA11y
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class ClearA11y_Database {
    
    /**
     * Create plugin database tables
     */
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Scan results table
        $table_name = $wpdb->prefix . 'cleara11y_scans';
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            post_revision_id bigint(20) unsigned DEFAULT NULL,
            scan_date datetime DEFAULT CURRENT_TIMESTAMP,
            accessibility_standard varchar(50) NOT NULL DEFAULT 'wcag21aa',
            scan_url varchar(500) NOT NULL,
            total_violations int(11) NOT NULL DEFAULT 0,
            total_incomplete int(11) NOT NULL DEFAULT 0,
            total_passes int(11) NOT NULL DEFAULT 0,
            scan_status varchar(20) NOT NULL DEFAULT 'pending',
            scan_results longtext,
            scan_metadata longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY post_revision_id (post_revision_id),
            KEY scan_date (scan_date),
            KEY scan_status (scan_status)
        ) $charset_collate;";
        
        // Scan violations table for detailed issue tracking
        $violations_table = $wpdb->prefix . 'cleara11y_violations';
        
        $violations_sql = "CREATE TABLE $violations_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            scan_id bigint(20) unsigned NOT NULL,
            violation_id varchar(100) NOT NULL,
            impact varchar(20) NOT NULL,
            description text NOT NULL,
            help text NOT NULL,
            help_url varchar(500),
            tags text,
            target_selector text,
            html_snippet text,
            failure_summary text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY scan_id (scan_id),
            KEY violation_id (violation_id),
            KEY impact (impact)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        dbDelta($violations_sql);
        
        // Schedule cleanup job
        if (!wp_next_scheduled('cleara11y_cleanup_old_scans')) {
            wp_schedule_event(time(), 'daily', 'cleara11y_cleanup_old_scans');
        }
        
        add_action('cleara11y_cleanup_old_scans', array(__CLASS__, 'cleanup_old_scans'));
    }
    
    /**
     * Save scan results
     */
    public static function save_scan_results($post_id, $scan_data) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'cleara11y_scans';
        $violations_table = $wpdb->prefix . 'cleara11y_violations';
        
        // Get current post revision
        $post_revision_id = wp_get_post_revisions($post_id, array('numberposts' => 1));
        $post_revision_id = !empty($post_revision_id) ? array_keys($post_revision_id)[0] : null;
        
        // Prepare scan data
        $scan_insert_data = array(
            'post_id' => $post_id,
            'post_revision_id' => $post_revision_id,
            'accessibility_standard' => $scan_data['standard'] ?? get_option('cleara11y_accessibility_standard', 'wcag21aa'),
            'scan_url' => $scan_data['url'],
            'total_violations' => count($scan_data['violations'] ?? []),
            'total_incomplete' => count($scan_data['incomplete'] ?? []),
            'total_passes' => count($scan_data['passes'] ?? []),
            'scan_status' => 'completed',
            'scan_results' => json_encode($scan_data),
            'scan_metadata' => json_encode(array(
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'wp_version' => get_bloginfo('version'),
                'plugin_version' => CLEARA11Y_VERSION,
                'scan_duration' => $scan_data['duration'] ?? null,
            ))
        );
        
        // Insert scan record
        $result = $wpdb->insert($table_name, $scan_insert_data);
        
        if ($result === false) {
            return new WP_Error('db_insert_error', 'Failed to save scan results');
        }
        
        $scan_id = $wpdb->insert_id;
        
        // Save detailed violations
        if (!empty($scan_data['violations'])) {
            foreach ($scan_data['violations'] as $violation) {
                $violation_data = array(
                    'scan_id' => $scan_id,
                    'violation_id' => $violation['id'],
                    'impact' => $violation['impact'],
                    'description' => $violation['description'],
                    'help' => $violation['help'],
                    'help_url' => $violation['helpUrl'] ?? '',
                    'tags' => json_encode($violation['tags'] ?? []),
                    'target_selector' => json_encode($violation['target'] ?? []),
                    'html_snippet' => json_encode($violation['nodes'] ?? []),
                    'failure_summary' => $violation['failureSummary'] ?? '',
                );
                
                $wpdb->insert($violations_table, $violation_data);
            }
        }
        
        return $scan_id;
    }
    
    /**
     * Get scan results for a post
     */
    public static function get_post_scans($post_id, $limit = 10) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'cleara11y_scans';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name 
             WHERE post_id = %d 
             ORDER BY scan_date DESC 
             LIMIT %d",
            $post_id,
            $limit
        ));
        
        return $results;
    }
    
    /**
     * Get latest scan for a post
     */
    public static function get_latest_scan($post_id) {
        $scans = self::get_post_scans($post_id, 1);
        return !empty($scans) ? $scans[0] : null;
    }
    
    /**
     * Get scan violations
     */
    public static function get_scan_violations($scan_id) {
        global $wpdb;
        
        $violations_table = $wpdb->prefix . 'cleara11y_violations';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $violations_table WHERE scan_id = %d ORDER BY impact DESC, id ASC",
            $scan_id
        ));
    }
    
    /**
     * Delete old scan results based on retention settings
     */
    public static function cleanup_old_scans() {
        global $wpdb;
        
        $retention_days = get_option('cleara11y_results_retention_days', 30);
        $table_name = $wpdb->prefix . 'cleara11y_scans';
        
        // Keep the latest scan for each post, regardless of age
        $wpdb->query($wpdb->prepare(
            "DELETE s1 FROM $table_name s1
             INNER JOIN $table_name s2 
             WHERE s1.post_id = s2.post_id 
             AND s1.scan_date < s2.scan_date 
             AND s1.scan_date < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $retention_days
        ));
    }
    
    /**
     * Get scan statistics
     */
    public static function get_scan_stats($post_id = null) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'cleara11y_scans';
        $where_clause = $post_id ? $wpdb->prepare("WHERE post_id = %d", $post_id) : "";
        
        $stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total_scans,
                AVG(total_violations) as avg_violations,
                MAX(scan_date) as last_scan_date,
                SUM(CASE WHEN total_violations = 0 THEN 1 ELSE 0 END) as clean_scans
             FROM $table_name $where_clause"
        );
        
        return $stats;
    }
}
