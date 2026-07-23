<?php
/**
 * Issue Query Service
 *
 * Shared query logic for consistent issue counting and filtering across all views.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Services
 */

namespace ClearA11y\Services;

use ClearA11y\Database\Schema;

/**
 * Issue Query Service Class
 */
class Issue_Query_Service {

	/**
	 * Get the active ignore detection join pattern for reuse across queries.
	 *
	 * @return string SQL JOIN clause for detecting active exceptions
	 */
	public static function get_active_ignore_join(): string {
		global $wpdb;
		$matches_table = Schema::get_table_name('violation_ignore_matches');
		$rules_table = Schema::get_table_name('ignore_rules');

		return "
			LEFT JOIN (
				SELECT DISTINCT vm.violation_id
				FROM {$matches_table} vm
				INNER JOIN {$rules_table} ir ON vm.ignore_rule_id = ir.id
				WHERE ir.status = 'active'
					AND (ir.expires_at IS NULL OR ir.expires_at > NOW())
			) active_exceptions ON i.id = active_exceptions.violation_id
		";
	}

	/**
	 * Build WHERE clause for various filters.
	 *
	 * @param array $filters Filter parameters
	 * @return array Array with WHERE clause and params array
	 */
	public static function build_where_clause(array $filters = []): array {
		$where = ["1=1"];
		$params = [];

		if (!empty($filters['scan_id'])) {
			$where[] = "i.scan_id = %d";
			$params[] = (int) $filters['scan_id'];
		}

		if (!empty($filters['severity'])) {
			$where[] = "i.severity = %s";
			$params[] = $filters['severity'];
		}

		if (!empty($filters['post_type'])) {
			$where[] = "si.post_type = %s";
			$params[] = $filters['post_type'];
		}

		if (!empty($filters['search'])) {
			$where[] = "(i.rule_id LIKE %s OR i.message LIKE %s OR si.post_title LIKE %s OR si.post_url LIKE %s)";
			$search_term = "%{$filters['search']}%";
			$params[] = $search_term;
			$params[] = $search_term;
			$params[] = $search_term;
			$params[] = $search_term;
		}

		if (!empty($filters['rule_id'])) {
			$where[] = "i.rule_id = %s";
			$params[] = $filters['rule_id'];
		}

		if (!empty($filters['post_id'])) {
			$where[] = "i.post_id = %d";
			$params[] = (int) $filters['post_id'];
		}

		if (!empty($filters['wcag'])) {
			$where[] = "i.wcag_criterion LIKE %s";
			$params[] = "%{$filters['wcag']}%";
		}

		return [
			'where' => implode(' AND ', $where),
			'params' => $params
		];
	}

	/**
	 * Get active issue counts (excluding exceptions).
	 *
	 * @param array $filters Optional filters
	 * @return array Active counts by severity and total
	 */
	public static function get_active_counts(array $filters = []): array {
		global $wpdb;

		$issues_table = Schema::get_table_name('issues');
		$where_data = self::build_where_clause($filters);

		$active_ignore_join = self::get_active_ignore_join();

		$sql = "
			SELECT
				SUM(CASE WHEN active_exceptions.violation_id IS NULL THEN 1 ELSE 0 END) as total,
				SUM(CASE WHEN i.severity = 'critical' AND active_exceptions.violation_id IS NULL THEN 1 ELSE 0 END) as critical,
				SUM(CASE WHEN i.severity = 'moderate' AND active_exceptions.violation_id IS NULL THEN 1 ELSE 0 END) as moderate,
				SUM(CASE WHEN i.severity = 'minor' AND active_exceptions.violation_id IS NULL THEN 1 ELSE 0 END) as minor
			FROM {$issues_table} i
			{$active_ignore_join}
			WHERE {$where_data['where']}
		";

		$result = $wpdb->get_row($wpdb->prepare($sql, ...$where_data['params']));

		return [
			'total' => (int) ($result->total ?? 0),
			'critical' => (int) ($result->critical ?? 0),
			'moderate' => (int) ($result->moderate ?? 0),
			'minor' => (int) ($result->minor ?? 0),
		];
	}

	/**
	 * Get exception counts.
	 *
	 * @param array $filters Optional filters
	 * @return array Exception count
	 */
	public static function get_exception_counts(array $filters = []): array {
		global $wpdb;

		$issues_table = Schema::get_table_name('issues');
		$matches_table = Schema::get_table_name('violation_ignore_matches');
		$rules_table = Schema::get_table_name('ignore_rules');

		$where = ["ir.status = 'active'", "(ir.expires_at IS NULL OR ir.expires_at > NOW())"];
		$params = [];

		if (!empty($filters['scan_id'])) {
			$where[] = "i.scan_id = %d";
			$params[] = (int) $filters['scan_id'];
		}

		if (!empty($filters['severity'])) {
			$where[] = "i.severity = %s";
			$params[] = $filters['severity'];
		}

		$where_clause = implode(' AND ', $where);

		$sql = "
			SELECT COUNT(DISTINCT i.id) as total
			FROM {$issues_table} i
			INNER JOIN {$matches_table} vm ON i.id = vm.violation_id
			INNER JOIN {$rules_table} ir ON vm.ignore_rule_id = ir.id
			WHERE {$where_clause}
		";

		$total = (int) $wpdb->get_var($wpdb->prepare($sql, ...$params));

		return ['total' => $total];
	}

	/**
	 * Get complete stats with active/exception separation.
	 *
	 * @param array $filters Optional filters
	 * @return array Complete statistics
	 */
	public static function get_complete_stats(array $filters = []): array {
		return [
			'active' => self::get_active_counts($filters),
			'exceptions' => self::get_exception_counts($filters),
		];
	}

	/**
	 * Get issue type counts grouped by rule.
	 *
	 * @param array $filters Optional filters
	 * @return array Issue type counts
	 */
	public static function get_issue_type_counts(array $filters = []): array {
		global $wpdb;

		$issues_table = Schema::get_table_name('issues');
		$where_data = self::build_where_clause($filters);
		$active_ignore_join = self::get_active_ignore_join();

		$sql = "
			SELECT i.rule_id, i.severity,
			       COUNT(DISTINCT i.id) as total_issues,
			       SUM(CASE WHEN active_exceptions.violation_id IS NULL THEN 1 ELSE 0 END) as active_issues,
			       SUM(CASE WHEN active_exceptions.violation_id IS NOT NULL THEN 1 ELSE 0 END) as exception_issues,
			       COUNT(DISTINCT CASE WHEN active_exceptions.violation_id IS NULL THEN i.post_id END) as affected_pages
			FROM {$issues_table} i
			{$active_ignore_join}
			WHERE {$where_data['where']}
			GROUP BY i.rule_id, i.severity
			ORDER BY FIELD(i.severity, 'critical', 'moderate', 'minor') DESC, active_issues DESC
		";

		$results = $wpdb->get_results($wpdb->prepare($sql, ...$where_data['params']));

		return array_map(function($row) {
			return [
				'rule_id' => $row->rule_id,
				'severity' => $row->severity,
				'total_issues' => (int) $row->total_issues,
				'active_issues' => (int) $row->active_issues,
				'exception_issues' => (int) $row->exception_issues,
				'affected_pages' => (int) $row->affected_pages,
			];
		}, $results);
	}

	/**
	 * Get pages with their issue counts and scores.
	 *
	 * @param array $filters Optional filters
	 * @param string $orderby Order by field
	 * @param string $order Order direction
	 * @param int $page Page number
	 * @param int $per_page Items per page
	 * @return array Pages with counts
	 */
	public static function get_pages_with_counts(array $filters = [], string $orderby = 'issues', string $order = 'desc', int $page = 1, int $per_page = 20): array {
		global $wpdb;

		$scan_items_table = Schema::get_table_name('scan_items');
		$issues_table = Schema::get_table_name('issues');
		$where_data = self::build_where_clause($filters);
		$active_ignore_join = self::get_active_ignore_join();

		// Build ORDER BY clause
		$orderby_clause = match ($orderby) {
			'title' => 'si.post_title ' . strtoupper($order),
			'score' => 'si.pass_percentage ' . strtoupper($order),
			'scanned_date' => 'si.scanned_at ' . strtoupper($order),
			default => 'total_active ' . strtoupper($order),
		};

		$offset = ($page - 1) * $per_page;

		$sql = "
			SELECT si.id, si.post_id, si.post_title, si.post_url, si.post_type, si.template,
			       si.scan_id, si.scanned_at, si.score_grade, si.pass_percentage,
			       SUM(CASE WHEN i.severity = 'critical' AND active_exceptions.violation_id IS NULL THEN 1 ELSE 0 END) as critical_active,
			       SUM(CASE WHEN i.severity = 'moderate' AND active_exceptions.violation_id IS NULL THEN 1 ELSE 0 END) as moderate_active,
			       SUM(CASE WHEN i.severity = 'minor' AND active_exceptions.violation_id IS NULL THEN 1 ELSE 0 END) as minor_active,
			       SUM(CASE WHEN active_exceptions.violation_id IS NULL THEN 1 ELSE 0 END) as total_active,
			       SUM(CASE WHEN active_exceptions.violation_id IS NOT NULL THEN 1 ELSE 0 END) as total_exceptions,
			       100 - SUM(CASE WHEN active_exceptions.violation_id IS NULL
			           THEN (CASE WHEN i.severity = 'critical' THEN 10 WHEN i.severity = 'moderate' THEN 5 ELSE 2 END)
			           ELSE 0 END) as calculated_score
			FROM {$scan_items_table} si
			LEFT JOIN {$issues_table} i ON i.scan_item_id = si.id
			LEFT JOIN {$active_ignore_join}
			WHERE si.status = 'completed' AND {$where_data['where']}
			GROUP BY si.id
			ORDER BY {$orderby_clause}
			LIMIT %d OFFSET %d
		";

		$where_data['params'][] = $per_page;
		$where_data['params'][] = $offset;

		$results = $wpdb->get_results($wpdb->prepare($sql, ...$where_data['params']));

		return array_map(function($row) {
			return [
				'scan_item_id' => (int) $row->id,
				'post_id' => (int) $row->post_id,
				'post_title' => $row->post_title,
				'post_url' => $row->post_url,
				'post_type' => $row->post_type,
				'template' => $row->template,
				'scan_id' => (int) $row->scan_id,
				'scanned_at' => $row->scanned_at,
				'score_grade' => $row->score_grade,
				'pass_percentage' => (int) $row->pass_percentage,
				'score' => (int) $row->calculated_score,
				'active' => [
					'total' => (int) $row->total_active,
					'critical' => (int) $row->critical_active,
					'moderate' => (int) $row->moderate_active,
					'minor' => (int) $row->minor_active,
				],
				'exceptions' => [
					'total' => (int) $row->total_exceptions,
				],
			];
		}, $results);
	}

	/**
	 * Get severity buckets with top rules.
	 *
	 * @param array $filters Optional filters
	 * @return array Severity buckets
	 */
	public static function get_severity_buckets(array $filters = []): array {
		global $wpdb;

		$issues_table = Schema::get_table_name('issues');
		$where_data = self::build_where_clause($filters);
		$active_ignore_join = self::get_active_ignore_join();

		$sql = "
			SELECT i.severity,
			       SUM(CASE WHEN active_exceptions.violation_id IS NULL THEN 1 ELSE 0 END) as active_total,
			       COUNT(DISTINCT CASE WHEN active_exceptions.violation_id IS NULL THEN i.post_id END) as active_unique_pages,
			       SUM(CASE WHEN active_exceptions.violation_id IS NOT NULL THEN 1 ELSE 0 END) as exception_total
			FROM {$issues_table} i
			LEFT JOIN {$active_ignore_join}
			WHERE {$where_data['where']}
			GROUP BY i.severity
			ORDER BY FIELD(i.severity, 'critical', 'moderate', 'minor')
		";

		$results = $wpdb->get_results($wpdb->prepare($sql, ...$where_data['params']));

		$buckets = [];
		foreach ($results as $row) {
			// Get top rules for this severity
			$where_rules = $where_data['where'];
			$params_rules = $where_data['params'];
			$where_rules[] = "i.severity = %s";
			$params_rules[] = $row->severity;

			$where_clause_rules = implode(' AND ', $where_rules);

			$top_rules_sql = "
				SELECT i.rule_id,
				       COUNT(*) as issue_count,
				       COUNT(DISTINCT i.post_id) as page_count
				FROM {$issues_table} i
				WHERE {$where_clause_rules}
				GROUP BY i.rule_id
				ORDER BY issue_count DESC
				LIMIT 5
			";

			$top_rules = $wpdb->get_results($wpdb->prepare($top_rules_sql, ...$params_rules));

			$top_rules_data = [];
			foreach ($top_rules as $rule) {
				$top_rules_data[] = [
					'rule_id' => $rule->rule_id,
					'issue_count' => (int) $rule->issue_count,
					'page_count' => (int) $rule->page_count,
				];
			}

			$buckets[] = [
				'severity' => $row->severity,
				'active' => [
					'total' => (int) $row->active_total,
					'unique_pages' => (int) $row->active_unique_pages,
				],
				'exceptions' => [
					'total' => (int) $row->exception_total,
				],
				'top_rules' => $top_rules_data,
			];
		}

		return $buckets;
	}

	/**
	 * Get content type groupings with statistics.
	 *
	 * @param array $filters Optional filters
	 * @param string $content_type Grouping type (post_type, template, etc.)
	 * @return array Content type statistics
	 */
	public static function get_content_type_counts(array $filters = [], string $content_type = 'post_type'): array {
		global $wpdb;

		$scan_items_table = Schema::get_table_name('scan_items');
		$issues_table = Schema::get_table_name('issues');
		$where_data = self::build_where_clause($filters);
		$active_ignore_join = self::get_active_ignore_join();

		// Build GROUP BY clause based on content type
		$group_field = match ($content_type) {
			'template' => 'si.template',
			'taxonomy', 'author' => 'si.post_type', // Fallback for now
			default => 'si.post_type',
		};

		$sql = "
			SELECT {$group_field} as content_type,
			       COUNT(DISTINCT si.id) as pages_count,
			       SUM(CASE WHEN i.severity = 'critical' AND active_exceptions.violation_id IS NULL THEN 1 ELSE 0 END) as active_critical,
			       SUM(CASE WHEN i.severity = 'moderate' AND active_exceptions.violation_id IS NULL THEN 1 ELSE 0 END) as active_moderate,
			       SUM(CASE WHEN i.severity = 'minor' AND active_exceptions.violation_id IS NULL THEN 1 ELSE 0 END) as active_minor,
			       SUM(CASE WHEN active_exceptions.violation_id IS NULL THEN 1 ELSE 0 END) as active_total,
			       SUM(CASE WHEN active_exceptions.violation_id IS NOT NULL THEN 1 ELSE 0 END) as exception_total,
			       AVG(si.pass_percentage) as avg_score
			FROM {$scan_items_table} si
			LEFT JOIN {$issues_table} i ON i.scan_item_id = si.id
			LEFT JOIN {$active_ignore_join}
			WHERE si.status = 'completed' AND {$where_data['where']}
			GROUP BY {$group_field}
			ORDER BY active_total DESC
		";

		$results = $wpdb->get_results($wpdb->prepare($sql, ...$where_data['params']));

		return array_map(function($row) use ($content_type) {
			// Generate a label for the content type
			$content_label = $row->content_type;
			if ($content_type === 'post_type') {
				$post_type_obj = get_post_type_object($row->content_type);
				$content_label = $post_type_obj ? $post_type_obj->labels->name : $row->content_type;
			} elseif ($content_type === 'template') {
				$content_label = $row->content_type ?: 'Default Template';
			}

			return [
				'content_type' => $row->content_type,
				'content_label' => $content_label,
				'pages_count' => (int) $row->pages_count,
				'active' => [
					'total_issues' => (int) $row->active_total,
					'critical' => (int) $row->active_critical,
					'moderate' => (int) $row->active_moderate,
					'minor' => (int) $row->active_minor,
				],
				'exceptions' => [
					'total_issues' => (int) $row->exception_total,
				],
				'avg_score' => (int) round($row->avg_score ?: 0),
			];
		}, $results);
	}
}