<?php
/**
 * Clear stale ClearA11y cron events
 *
 * Run from WordPress root: php wp-content/plugins/clearA11y/clear-stale-cron.php
 */

// Load WordPress
$wp_load_paths = [
    __DIR__ . '/../../../wp-load.php',
    dirname(__DIR__, 3) . '/wp-load.php',
];

$loaded = false;
foreach ($wp_load_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    die("ERROR: Could not find wp-load.php\n");
}

echo "Clearing stale ClearA11y cron events...\n\n";

// Clear the stale cleara11y_process_scheduled_scans event
$count = wp_clear_scheduled_hook('cleara11y_process_scheduled_scans');

if ($count > 0) {
    echo "✅ Cleared {$count} instance(s) of 'cleara11y_process_scheduled_scans'\n";
} else {
    echo "ℹ️  No 'cleara11y_process_scheduled_scans' events found (already clean)\n";
}

// List current ClearA11y events
echo "\nCurrent ClearA11y cron events:\n";
echo str_repeat('─', 60) . "\n";

$cron = _get_cron_array();
if ($cron) {
    foreach ($cron as $timestamp => $hooks) {
        foreach ($hooks as $hook => $details) {
            if (strpos($hook, 'cleara11y') === 0) {
                $schedule = wp_get_schedule($hook);
                $next = wp_next_scheduled($hook);
                echo "Hook: {$hook}\n";
                echo "  Next run: " . ($next ? date('Y-m-d H:i:s', $next) : 'N/A') . "\n";
                echo "  Schedule: {$schedule}\n";

                // Check if callback is registered
                global $wp_filter;
                if (isset($wp_filter[$hook]) && !empty($wp_filter[$hook]->callbacks)) {
                    echo "  Callback: ✅ Registered\n";
                } else {
                    echo "  Callback: ❌ MISSING!\n";
                }
                echo "\n";
            }
        }
    }
} else {
    echo "No cron events found.\n";
}

echo "\nDone!\n";
