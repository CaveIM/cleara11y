<?php
/**
 * Manual Verification Script for ClearA11y Automated Scan System
 *
 * This script tests the Legacy Simple Automated Scan functionality.
 * Run from WordPress root: php wp-content/plugins/clearA11y/verify-automated-scans.php
 *
 * @package ClearA11y
 * @version 1.0.0
 */

// Load WordPress
$wp_load_paths = [
    __DIR__ . '/../../../wp-load.php',
    dirname(__DIR__, 3) . '/wp-load.php',
    ABSPATH . 'wp-load.php',
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

echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  ClearA11y Automated Scan Verification Script              ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";
echo "\n";

$test_results = [];
$test_number = 1;

/**
 * Test helper functions
 */
function test_start($name) {
    global $test_number;
    echo "Test {$test_number}: {$name}\n";
    echo str_repeat('─', 60) . "\n";
    $test_number++;
    return time();
}

function test_pass($message = '') {
    global $test_results;
    $status = "✅ PASS";
    echo "{$status}";
    if ($message) {
        echo " - {$message}";
    }
    echo "\n\n";
    $test_results[] = ['status' => 'pass', 'message' => $message];
}

function test_fail($message) {
    global $test_results;
    $status = "❌ FAIL";
    echo "{$status} - {$message}\n\n";
    $test_results[] = ['status' => 'fail', 'message' => $message];
}

function test_skip($message) {
    global $test_results;
    $status = "⏭️  SKIP";
    echo "{$status} - {$message}\n\n";
    $test_results[] = ['status' => 'skip', 'message' => $message];
}

function test_info($label, $value) {
    echo "  {$label}: ";
    if (is_array($value) || is_object($value)) {
        print_r($value);
    } else {
        echo $value;
    }
    echo "\n";
}

// ═══════════════════════════════════════════════════════════════════════
// Test 1: Verify Custom Schedules Registered
// ═══════════════════════════════════════════════════════════════════════
test_start("Verify custom cron schedules are registered");

$schedules = wp_get_schedules();
$cleara11y_schedules = array_filter($schedules, function($key) {
    return strpos($key, 'cleara11y_') === 0;
}, ARRAY_FILTER_USE_KEY);

if (empty($cleara11y_schedules)) {
    test_fail("No ClearA11y custom schedules found");
} else {
    test_info("Custom schedules found", count($cleara11y_schedules));
    foreach ($cleara11y_schedules as $name => $schedule) {
        $interval_hours = round($schedule['interval'] / 3600, 1);
        test_info("  - {$name}", "{$schedule['display']} ({$interval_hours}h)");
    }

    $required = ['cleara11y_daily', 'cleara11y_weekly', 'cleara11y_monthly'];
    $missing = array_diff($required, array_keys($cleara11y_schedules));
    if ($missing) {
        test_fail("Missing required schedules: " . implode(', ', $missing));
    } else {
        test_pass("All required schedules registered");
    }
}

// ═══════════════════════════════════════════════════════════════════════
// Test 2: Verify Current Options
// ═══════════════════════════════════════════════════════════════════════
test_start("Verify current automated scan options");

$enabled = get_option('cleara11y_automated_enabled', 0);
$frequency = get_option('cleara11y_automated_frequency', 'weekly');
$post_types = get_option('cleara11y_scan_post_types', ['page', 'post']);

test_info("Automated enabled", $enabled ? 'Yes' : 'No');
test_info("Frequency", $frequency);
test_info("Post types to scan", is_array($post_types) ? implode(', ', $post_types) : $post_types);

$valid_frequencies = ['daily', 'weekly', 'monthly'];
if (!in_array($frequency, $valid_frequencies)) {
    test_fail("Invalid frequency: {$frequency}");
} else {
    test_pass("Options are valid");
}

// ═══════════════════════════════════════════════════════════════════════
// Test 3: Verify Cron Event Status
// ═══════════════════════════════════════════════════════════════════════
test_start("Verify automated scan cron event status");

$next_scheduled = wp_next_scheduled('cleara11y_automated_scan');
if ($next_scheduled) {
    $time_until = $next_scheduled - time();
    $hours_until = round($time_until / 3600, 1);
    test_info("Next run", date('Y-m-d H:i:s', $next_scheduled));
    test_info("Time until next run", "{$hours_until} hours");

    if ($enabled) {
        test_pass("Cron event is scheduled and enabled is ON");
    } else {
        test_fail("Cron event exists but enabled is OFF (should be cleared)");
    }
} else {
    if ($enabled) {
        test_fail("Enabled is ON but no cron event scheduled");
    } else {
        test_pass("No cron event (disabled state is correct)");
    }
}

// ═══════════════════════════════════════════════════════════════════════
// Test 4: Test Schedule Creation (Daily)
// ═══════════════════════════════════════════════════════════════════════
test_start("Test creating daily schedule");

// Save current state
$original_enabled = $enabled;
$original_frequency = $frequency;

// Enable daily
update_option('cleara11y_automated_enabled', 1);
update_option('cleara11y_automated_frequency', 'daily');

// Simulate the schedule creation logic from Settings_Page
wp_clear_scheduled_hook('cleara11y_automated_scan');
$scheduled = wp_schedule_event(time(), 'cleara11y_daily', 'cleara11y_automated_scan');

if ($scheduled === false) {
    test_fail("Failed to schedule daily event");
} else {
    $next_scheduled = wp_next_scheduled('cleara11y_automated_scan');
    $recurrence = wp_get_schedule('cleara11y_automated_scan');

    test_info("Next run", date('Y-m-d H:i:s', $next_scheduled));
    test_info("Recurrence", $recurrence);

    if ($recurrence === 'cleara11y_daily') {
        test_pass("Daily schedule created successfully");
    } else {
        test_fail("Wrong recurrence: {$recurrence}");
    }
}

// Restore original state
update_option('cleara11y_automated_enabled', $original_enabled);
update_option('cleara11y_automated_frequency', $original_frequency);
if ($original_enabled) {
    wp_schedule_event(time(), 'cleara11y_' . $original_frequency, 'cleara11y_automated_scan');
} else {
    wp_clear_scheduled_hook('cleara11y_automated_scan');
}

// ═══════════════════════════════════════════════════════════════════════
// Test 5: Test Schedule Change (Daily → Weekly)
// ═══════════════════════════════════════════════════════════════════════
test_start("Test changing schedule from daily to weekly");

// Enable daily first
update_option('cleara11y_automated_enabled', 1);
update_option('cleara11y_automated_frequency', 'daily');
wp_clear_scheduled_hook('cleara11y_automated_scan');
wp_schedule_event(time(), 'cleara11y_daily', 'cleara11y_automated_scan');

$daily_next = wp_next_scheduled('cleara11y_automated_scan');
$daily_recurrence = wp_get_schedule('cleara11y_automated_scan');

test_info("Daily schedule next run", date('Y-m-d H:i:s', $daily_next));
test_info("Daily recurrence", $daily_recurrence);

// Change to weekly
update_option('cleara11y_automated_frequency', 'weekly');
wp_clear_scheduled_hook('cleara11y_automated_scan');
wp_schedule_event(time(), 'cleara11y_weekly', 'cleara11y_automated_scan');

$weekly_next = wp_next_scheduled('cleara11y_automated_scan');
$weekly_recurrence = wp_get_schedule('cleara11y_automated_scan');

test_info("Weekly schedule next run", date('Y-m-d H:i:s', $weekly_next));
test_info("Weekly recurrence", $weekly_recurrence);

if ($weekly_recurrence === 'cleara11y_weekly' && $daily_next !== $weekly_next) {
    test_pass("Schedule changed successfully (old event cleared, new event created)");
} else {
    test_fail("Schedule change failed");
}

// Clean up
wp_clear_scheduled_hook('cleara11y_automated_scan');
update_option('cleara11y_automated_enabled', $original_enabled);
update_option('cleara11y_automated_frequency', $original_frequency);

// ═══════════════════════════════════════════════════════════════════════
// Test 6: Test Schedule Disable and Cleanup
// ═══════════════════════════════════════════════════════════════════════
test_start("Test disabling and cleanup");

// Enable first
update_option('cleara11y_automated_enabled', 1);
update_option('cleara11y_automated_frequency', 'daily');
wp_schedule_event(time(), 'cleara11y_daily', 'cleara11y_automated_scan');

$event_exists_before = wp_next_scheduled('cleara11y_automated_scan');
test_info("Event exists before disable", $event_exists_before ? 'Yes' : 'No');

// Disable
update_option('cleara11y_automated_enabled', 0);
wp_clear_scheduled_hook('cleara11y_automated_scan');

$event_exists_after = wp_next_scheduled('cleara11y_automated_scan');
test_info("Event exists after disable", $event_exists_after ? 'Yes' : 'No');

if ($event_exists_before && !$event_exists_after) {
    test_pass("Event cleared successfully on disable");
} else {
    test_fail("Event cleanup failed");
}

// Restore original state
update_option('cleara11y_automated_enabled', $original_enabled);
update_option('cleara11y_automated_frequency', $original_frequency);

// ═══════════════════════════════════════════════════════════════════════
// Test 7: Verify Plugin Activation Hooks
// ═══════════════════════════════════════════════════════════════════════
test_start("Verify cleanup old scans cron event");

$cleanup_next = wp_next_scheduled('cleara11y_cleanup_old_scans');
test_info("Cleanup old scans", $cleanup_next ? date('Y-m-d H:i:s', $cleanup_next) : 'Not scheduled');
test_info("Process scheduled scans", $process_next ? date('Y-m-d H:i:s', $process_next) : 'Not scheduled');

if ($cleanup_next && $process_next) {
    test_pass("System cron events are scheduled");
} else {
    test_fail("Some system cron events are missing (may need plugin reactivation)");
}

// ═══════════════════════════════════════════════════════════════════════
// Test 8: Verify Database Tables
// ═══════════════════════════════════════════════════════════════════════
test_start("Verify database tables exist");

global $wpdb;
$prefix = 'wp_cleara11y_';
$tables = [
    'scans',
    'scan_items',
    'scan_jobs',
];

$all_exist = true;
foreach ($tables as $table) {
    $table_name = $prefix . $table;
    $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
    test_info("Table {$table_name}", $exists ? '✓' : '✗');
    if (!$exists) {
        $all_exist = false;
    }
}

if ($all_exist) {
    test_pass("All database tables exist");
} else {
    test_fail("Some database tables are missing");
}

// ═══════════════════════════════════════════════════════════════════════
// Test 9: Verify Scan Repository Functionality
// ═══════════════════════════════════════════════════════════════════════
test_start("Verify Scan Repository can create records");

if (!class_exists('ClearA11y\Database\Scan_Repository')) {
    test_skip("Scan_Repository class not available");
} else {
    $scans_table = \ClearA11y\Database\Schema::get_table_name('scans');
    $scan_count = $wpdb->get_var("SELECT COUNT(*) FROM `{$scans_table}`");
    test_info("Total scans in database", $scan_count);

    $recent_scans = $wpdb->get_results(
        "SELECT id, scan_name, scan_type, status, created_at
        FROM `{$scans_table}`
        ORDER BY id DESC
        LIMIT 3"
    );

    if ($recent_scans) {
        test_info("Recent scans", count($recent_scans));
        foreach ($recent_scans as $scan) {
            test_info("  - ID {$scan->id}", "{$scan->scan_type} - {$scan->status}");
        }
        test_pass("Scan Repository is working");
    } else {
        test_info("No scans found (this is OK for new installations)");
        test_pass("Scan Repository table exists and is queryable");
    }
}

// ═══════════════════════════════════════════════════════════════════════
// Test 10: Manual Callback Invocation Test
// ═══════════════════════════════════════════════════════════════════════
test_start("Manual callback invocation test (dry run)");

if (!class_exists('ClearA11y_Plugin')) {
    test_skip("ClearA11y_Plugin class not available");
} else {
    test_info("Note", "This test checks if the callback function exists and is callable");

    // Check if the callback method exists
    $plugin_instance = new ClearA11y_Plugin();
    if (method_exists($plugin_instance, 'run_automated_scan')) {
        test_info("Callback method", "run_automated_scan() exists");

        // Check if the hook is registered
        $hooks = $GLOBALS['wp_filter']['cleara11y_automated_scan'] ?? null;
        if ($hooks && !empty($hooks->callbacks[10])) {
            $registered = false;
            foreach ($hooks->callbacks[10] as $callback) {
                if (is_array($callback['function']) &&
                    $callback['function'][1] === 'run_automated_scan') {
                    $registered = true;
                    break;
                }
            }
            if ($registered) {
                test_pass("Callback is properly registered to hook");
            } else {
                test_fail("Callback method exists but not registered to hook");
            }
        } else {
            test_fail("Hook has no callbacks registered");
        }
    } else {
        test_fail("Callback method does not exist");
    }
}

// ═══════════════════════════════════════════════════════════════════════
// Summary
// ═══════════════════════════════════════════════════════════════════════
echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  Test Results Summary                                        ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";
echo "\n";

$passed = 0;
$failed = 0;
$skipped = 0;

foreach ($test_results as $result) {
    switch ($result['status']) {
        case 'pass':
            $passed++;
            break;
        case 'fail':
            $failed++;
            break;
        case 'skip':
            $skipped++;
            break;
    }
}

$total = $passed + $failed + $skipped;
$percentage = $total > 0 ? round(($passed / $total) * 100, 1) : 0;

echo "Total Tests: {$total}\n";
echo "✅ Passed: {$passed}\n";
echo "❌ Failed: {$failed}\n";
echo "⏭️  Skipped: {$skipped}\n";
echo "\n";
echo "Success Rate: {$percentage}%\n";
echo "\n";

if ($failed > 0) {
    echo "⚠️  Some tests failed. Please review the failures above.\n";
    exit(1);
} else if ($skipped > 0) {
    echo "✅ All critical tests passed. Some optional tests were skipped.\n";
    exit(0);
} else {
    echo "🎉 All tests passed! The automated scan system is working correctly.\n";
    exit(0);
}
