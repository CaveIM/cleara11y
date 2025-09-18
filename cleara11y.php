<?php
/**
 * Plugin Name: ClearA11y
 * Plugin URI: https://cleara11y.com
 * Description: Local accessibility checker for WordPress with optional remote SaaS integration. Scan pages, posts, and custom post types for accessibility issues with detailed remediation guidance.
 * Version: 1.0.0
 * Author: ClearA11y Team
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cleara11y
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CLEARA11Y_VERSION', '1.0.0');
define('CLEARA11Y_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CLEARA11Y_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CLEARA11Y_PLUGIN_FILE', __FILE__);
define('CLEARA11Y_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main plugin class
 */
final class ClearA11y {
    
    /**
     * Plugin instance
     */
    private static $instance = null;
    
    /**
     * Get plugin instance
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('init', array($this, 'init'), 0);
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        
        // Activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Load required files
        $this->load_dependencies();
        
        // Initialize components
        if (is_admin()) {
            new ClearA11y_Admin();
        }
        
        new ClearA11y_Frontend();
        new ClearA11y_Scanner();
        // Temporarily disabled: new ClearA11y_Axe_Scanner();
    }
    
    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        require_once CLEARA11Y_PLUGIN_DIR . 'includes/class-database.php';
        require_once CLEARA11Y_PLUGIN_DIR . 'includes/class-scanner.php';
        // Temporarily disabled: require_once CLEARA11Y_PLUGIN_DIR . 'includes/class-axe-scanner.php';
        require_once CLEARA11Y_PLUGIN_DIR . 'includes/class-admin.php';
        require_once CLEARA11Y_PLUGIN_DIR . 'includes/class-frontend.php';
    }
    
    /**
     * Load text domain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain('cleara11y', false, dirname(CLEARA11Y_PLUGIN_BASENAME) . '/languages');
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Load dependencies first
        $this->load_dependencies();
        
        // Create database tables
        ClearA11y_Database::create_tables();
        
        // Set default options
        $this->set_default_options();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clean up scheduled events
        wp_clear_scheduled_hook('cleara11y_cleanup_old_scans');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Set default plugin options
     */
    private function set_default_options() {
        $defaults = array(
            'accessibility_standard' => 'wcag21aa',
            'scan_post_types' => array('page', 'post'),
            'results_retention_days' => 30,
            'enable_frontend_highlighting' => true,
            'scan_permission' => 'edit_posts',
        );
        
        foreach ($defaults as $option => $value) {
            if (false === get_option('cleara11y_' . $option)) {
                add_option('cleara11y_' . $option, $value);
            }
        }
    }
}

/**
 * Initialize the plugin
 */
function cleara11y() {
    return ClearA11y::instance();
}

// Start the plugin
cleara11y();
