<?php
/**
 * Scoring Service
 *
 * Calculates rule-based pass/fail percentages for accessibility scans.
 * Tracks which rules were checked, passed, and failed during scanning.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Services
 */

namespace ClearA11y\Services;

// Force OPcache to reload this file
if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__FILE__, true);
}

/**
 * Scoring Service Class
 */
class Scoring_Service {

    /**
     * Calculate pass/fail scoring from axe-core results.
     *
     * @param array $axe_results Axe-core scan results.
     * @return array {
     *     Scoring data.
     *
     *     @type array   $rules_checked     All rules that were checked.
     *     @type array   $rules_passed      Rules with no violations.
     *     @type array   $rules_failed      Rules with violations.
     *     @type int     $total_rules       Total number of rules checked.
     *     @type int     $passed_count      Number of rules that passed.
     *     @type int     $failed_count      Number of rules that failed.
     *     @type float   $pass_percentage   Pass percentage (0-100).
     *     @type float   $fail_percentage   Fail percentage (0-100).
     *     @type string  $grade             Letter grade (A-F).
     * }
     */
    public static function calculate_score(array $axe_results): array {
        // Get all rule IDs from the scan results
        $violations = $axe_results['violations'] ?? [];
        $passes = $axe_results['passes'] ?? [];
        $incomplete = $axe_results['incomplete'] ?? [];
        $inapplicable = $axe_results['inapplicable'] ?? [];

        // Extract rule IDs from each category
        $violated_rules = array_column($violations, 'id');
        $passed_rules = array_column($passes, 'id');
        $incomplete_rules = array_column($incomplete, 'id');
        $inapplicable_rules = array_column($inapplicable, 'id');

        // Rules checked = all rules that returned a result (passed, failed, incomplete, or inapplicable)
        $rules_checked = array_unique(array_merge(
            $violated_rules,
            $passed_rules,
            $incomplete_rules,
            $inapplicable_rules
        ));
        sort($rules_checked);

        // Rules passed = rules with no violations (excluding incomplete)
        // Incomplete results are treated as neither pass nor fail
        $rules_passed = array_unique(array_merge(
            $passed_rules,
            $inapplicable_rules
        ));
        sort($rules_passed);

        // Rules failed = rules with violations
        $rules_failed = array_unique($violated_rules);
        sort($rules_failed);

        // Calculate totals
        $total_rules = count($rules_checked);
        $passed_count = count($rules_passed);
        $failed_count = count($rules_failed);
        $incomplete_count = count($incomplete_rules);

        // Calculate percentage (only count completed rules)
        // Excluding incomplete from denominator gives fairer score
        $completed_rules = $total_rules - $incomplete_count;
        $pass_percentage = $completed_rules > 0 ? round(($passed_count / $completed_rules) * 100, 2) : 0;
        $fail_percentage = $completed_rules > 0 ? round(($failed_count / $completed_rules) * 100, 2) : 0;

        // Calculate letter grade
        $grade = self::calculate_grade($pass_percentage);

        /**
         * Filter scoring result.
         *
         * Allows plugins/themes to modify scoring calculation.
         *
         * @param array $scoring_data Calculated scoring data.
         * @param array $axe_results  Original axe-core results.
         */
        return apply_filters('cleara11y_scoring_result', [
            'rules_checked' => $rules_checked,
            'rules_passed' => $rules_passed,
            'rules_failed' => $rules_failed,
            'rules_incomplete' => $incomplete_rules,
            'total_rules' => $total_rules,
            'passed_count' => $passed_count,
            'failed_count' => $failed_count,
            'incomplete_count' => $incomplete_count,
            'pass_percentage' => $pass_percentage,
            'fail_percentage' => $fail_percentage,
            'grade' => $grade,
        ], $axe_results);
    }

    /**
     * Calculate letter grade from pass percentage.
     *
     * @param float $pass_percentage Pass percentage (0-100).
     * @return string Letter grade (A-F).
     */
    public static function calculate_grade(float $pass_percentage): string {
        if ($pass_percentage >= 95) {
            return 'A';
        } elseif ($pass_percentage >= 85) {
            return 'B';
        } elseif ($pass_percentage >= 70) {
            return 'C';
        } elseif ($pass_percentage >= 50) {
            return 'D';
        } else {
            return 'F';
        }
    }

    /**
     * Get grade label.
     *
     * @param string $grade Letter grade.
     * @return string Human-readable grade label.
     */
    public static function get_grade_label(string $grade): string {
        $labels = [
            'A' => __('Excellent', 'cleara11y'),
            'B' => __('Good', 'cleara11y'),
            'C' => __('Fair', 'cleara11y'),
            'D' => __('Poor', 'cleara11y'),
            'F' => __('Fail', 'cleara11y'),
        ];

        return $labels[$grade] ?? __('Unknown', 'cleara11y');
    }

    /**
     * Get grade CSS class.
     *
     * @param string $grade Letter grade.
     * @return string CSS class for the grade.
     */
    public static function get_grade_class(string $grade): string {
        $classes = [
            'A' => 'cleara11y-grade-a',
            'B' => 'cleara11y-grade-b',
            'C' => 'cleara11y-grade-c',
            'D' => 'cleara11y-grade-d',
            'F' => 'cleara11y-grade-f',
        ];

        return $classes[$grade] ?? 'cleara11y-grade-unknown';
    }

    /**
     * Format scoring data for display.
     *
     * @param array $scoring_data Scoring data from calculate_score().
     * @return array Formatted scoring data.
     */
    public static function format_for_display(array $scoring_data): array {
        return [
            'pass_percentage' => $scoring_data['pass_percentage'],
            'fail_percentage' => $scoring_data['fail_percentage'],
            'grade' => $scoring_data['grade'],
            'grade_label' => self::get_grade_label($scoring_data['grade']),
            'grade_class' => self::get_grade_class($scoring_data['grade']),
            'total_rules' => $scoring_data['total_rules'],
            'passed_count' => $scoring_data['passed_count'],
            'failed_count' => $scoring_data['failed_count'],
            'incomplete_count' => $scoring_data['incomplete_count'],
            'rules_checked' => $scoring_data['rules_checked'],
            'rules_passed' => $scoring_data['rules_passed'],
            'rules_failed' => $scoring_data['rules_failed'],
            'rules_incomplete' => $scoring_data['rules_incomplete'],
        ];
    }

    /**
     * Check if a score passes the threshold.
     *
     * @param float $pass_percentage Pass percentage to check.
     * @param float $threshold       Pass threshold (default: 70).
     * @return bool True if score passes threshold.
     */
    public static function passes_threshold(float $pass_percentage, float $threshold = 70.0): bool {
        return $pass_percentage >= $threshold;
    }

    /**
     * Get severity-weighted score.
     *
     * Calculates a score where failed critical rules count more heavily.
     *
     * @param array $scoring_data  Scoring data from calculate_score().
     * @param array $failed_issues Failed issues grouped by severity.
     * @return float Weighted score (0-100).
     */
    public static function get_weighted_score(array $scoring_data, array $failed_issues): float {
        $total_rules = $scoring_data['total_rules'];
        if ($total_rules === 0) {
            return 0.0;
        }

        // Weight multipliers for severity
        $weights = [
            'critical' => 3.0,
            'moderate' => 1.5,
            'minor' => 1.0,
        ];

        // Calculate weighted failures
        $weighted_failures = 0;
        foreach ($failed_issues as $severity => $count) {
            $weight = $weights[$severity] ?? 1.0;
            $weighted_failures += ($count * $weight);
        }

        // Calculate unweighted passed count
        $passed_weight = $scoring_data['passed_count'] * 1.0;

        // Calculate weighted total
        $weighted_total = $passed_weight + $weighted_failures;

        if ($weighted_total === 0) {
            return 100.0;
        }

        return round(($passed_weight / $weighted_total) * 100, 2);
    }

    /**
     * Get default pass threshold.
     *
     * @return float Default pass threshold percentage.
     */
    public static function get_default_threshold(): float {
        return (float) apply_filters('cleara11y_default_pass_threshold', 70.0);
    }
}
