<?php
/**
 * Schedule Repository
 *
 * Handles database operations for Schedule records.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Database
 */

namespace ClearA11y\Database;

use ClearA11y\Models\Schedule;

/**
 * Schedule Repository Class
 */
class Schedule_Repository {

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	private static function get_table(): string {
		return Schema::get_table_name('schedules');
	}

	/**
	 * Insert a new schedule.
	 *
	 * @param Schedule $schedule Schedule object.
	 * @return int|false Inserted ID or false on failure.
	 */
	public static function insert(Schedule $schedule): int|false {
		global $wpdb;

		$data = [
			'schedule_name' => $schedule->schedule_name,
			'frequency' => $schedule->frequency,
			'schedule_config' => wp_json_encode($schedule->schedule_config),
			'enabled' => $schedule->enabled ? 1 : 0,
			'last_scan_id' => $schedule->last_scan_id,
			'last_run' => $schedule->last_run,
			'next_run' => $schedule->next_run,
			'created_at' => $schedule->created_at ?? current_time('mysql'),
		];

		$result = $wpdb->insert(
			self::get_table(),
			$data,
			['%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s']
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Update an existing schedule.
	 *
	 * @param Schedule $schedule Schedule object.
	 * @return bool True on success, false on failure.
	 */
	public static function update(Schedule $schedule): bool {
		global $wpdb;

		$data = [
			'schedule_name' => $schedule->schedule_name,
			'frequency' => $schedule->frequency,
			'schedule_config' => wp_json_encode($schedule->schedule_config),
			'enabled' => $schedule->enabled ? 1 : 0,
			'last_scan_id' => $schedule->last_scan_id,
			'last_run' => $schedule->last_run,
			'next_run' => $schedule->next_run,
		];

		$result = $wpdb->update(
			self::get_table(),
			$data,
			['id' => $schedule->id],
			['%s', '%s', '%s', '%d', '%d', '%s', '%s'],
			['%d']
		);

		return $result !== false;
	}

	/**
	 * Get schedule by ID.
	 *
	 * @param int $schedule_id Schedule ID.
	 * @return Schedule|null
	 */
	public static function get_by_id(int $schedule_id): ?Schedule {
		global $wpdb;

		$table = self::get_table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE id = %d",
				$schedule_id
			)
		);

		return $row ? Schedule::from_row($row) : null;
	}

	/**
	 * Get schedule by name.
	 *
	 * @param string $schedule_name Schedule name.
	 * @return Schedule|null
	 */
	public static function get_by_name(string $schedule_name): ?Schedule {
		global $wpdb;

		$table = self::get_table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE schedule_name = %s",
				$schedule_name
			)
		);

		return $row ? Schedule::from_row($row) : null;
	}

	/**
	 * Get all schedules.
	 *
	 * @param array $args Optional query arguments.
	 * @return Schedule[]
	 */
	public static function get_all(array $args = []): array {
		global $wpdb;

		$defaults = [
			'enabled' => null,
			'orderby' => 'created_at',
			'order' => 'DESC',
		];

		$args = wp_parse_args($args, $defaults);

		$where = ['1=1'];
		$where_params = [];

		if (null !== $args['enabled']) {
			$where[] = 'enabled = %d';
			$where_params[] = $args['enabled'] ? 1 : 0;
		}

		$where_clause = implode(' AND ', $where);
		$orderby = sanitize_sql_orderby("{$args['orderby']} {$args['order']}");

		$table = self::get_table();
		$query = "SELECT * FROM `{$table}` WHERE {$where_clause} ORDER BY {$orderby}";

		// @phpstan-ignore-next-line
		$query = $wpdb->prepare($query, ...$where_params);

		$rows = $wpdb->get_results($query);

		return array_map(fn($row) => Schedule::from_row($row), $rows ?: []);
	}

	/**
	 * Get schedules that are due to run.
	 *
	 * @return Schedule[]
	 */
	public static function get_due(): array {
		global $wpdb;

		$current_time = current_time('mysql');
		$table = self::get_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}`
				WHERE enabled = 1
				AND (next_run IS NULL OR next_run <= %s)
				ORDER BY next_run ASC",
				$current_time
			)
		);

		return array_map(fn($row) => Schedule::from_row($row), $rows ?: []);
	}

	/**
	 * Delete schedule by ID.
	 *
	 * @param int $schedule_id Schedule ID.
	 * @return bool True on success, false on failure.
	 */
	public static function delete(int $schedule_id): bool {
		global $wpdb;

		$result = $wpdb->delete(
			self::get_table(),
			['id' => $schedule_id],
			['%d']
		);

		return $result !== false;
	}

	/**
	 * Update schedule next run time.
	 *
	 * @param int $schedule_id Schedule ID.
	 * @param string $next_run Next run time.
	 * @return bool True on success, false on failure.
	 */
	public static function update_next_run(int $schedule_id, string $next_run): bool {
		global $wpdb;

		$result = $wpdb->update(
			self::get_table(),
			['next_run' => $next_run],
			['id' => $schedule_id],
			['%s'],
			['%d']
		);

		return $result !== false;
	}

	/**
	 * Update schedule after running a scan.
	 *
	 * @param int $schedule_id Schedule ID.
	 * @param int $scan_id     Scan ID that was run.
	 * @return bool True on success, false on failure.
	 */
	public static function update_after_run(int $schedule_id, int $scan_id): bool {
		global $wpdb;

		$schedule = self::get_by_id($schedule_id);
		if (!$schedule) {
			return false;
		}

		$next_run = $schedule->calculate_next_run();

		$result = $wpdb->update(
			self::get_table(),
			[
				'last_scan_id' => $scan_id,
				'last_run' => current_time('mysql'),
				'next_run' => $next_run,
			],
			['id' => $schedule_id],
			['%d', '%s', '%s'],
			['%d']
		);

		return $result !== false;
	}

	/**
	 * Toggle schedule enabled status.
	 *
	 * @param int  $schedule_id Schedule ID.
	 * @param bool $enabled     Enabled status.
	 * @return bool True on success, false on failure.
	 */
	public static function toggle_enabled(int $schedule_id, bool $enabled): bool {
		global $wpdb;

		$result = $wpdb->update(
			self::get_table(),
			['enabled' => $enabled ? 1 : 0],
			['id' => $schedule_id],
			['%d'],
			['%d']
		);

		return $result !== false;
	}

	/**
	 * Get count of schedules.
	 *
	 * @param bool|null $enabled Filter by enabled status, or null for all.
	 * @return int
	 */
	public static function get_count(?bool $enabled = null): int {
		global $wpdb;

		$where = '';
		if (null !== $enabled) {
			$where = $wpdb->prepare('WHERE enabled = %d', $enabled ? 1 : 0);
		}

		$count = $wpdb->get_var(
			"SELECT COUNT(*) FROM `" . self::get_table() . "` $where"
		);

		return (int) $count;
	}

	/**
	 * Create or update a schedule by name.
	 *
	 * @param Schedule $schedule Schedule object.
	 * @return int Schedule ID.
	 */
	public static function save(Schedule $schedule): int {
		$existing = self::get_by_name($schedule->schedule_name);

		if ($existing) {
			$schedule->id = $existing->id;
			self::update($schedule);
			return $schedule->id;
		}

		$id = self::insert($schedule);
		return $id ?: 0;
	}
}
