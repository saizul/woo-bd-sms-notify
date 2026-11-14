<?php
/**
 * Send log storage.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Log;

/**
 * Persists log entries. The plugin stores them in a custom table.
 */
interface LogRepository {

	/**
	 * Insert an entry and set its id.
	 *
	 * @param LogEntry $entry Entry.
	 */
	public function insert( LogEntry $entry ): int;

	/**
	 * Update an existing entry.
	 *
	 * @param LogEntry $entry Entry.
	 */
	public function update( LogEntry $entry ): void;

	/**
	 * Find by id.
	 *
	 * @param int $id Id.
	 */
	public function find( int $id ): ?LogEntry;

	/**
	 * Newest entries first.
	 *
	 * @param LogQuery $query Filters and paging.
	 * @return LogEntry[]
	 */
	public function search( LogQuery $query ): array;

	/**
	 * Number of entries matching the filters.
	 *
	 * @param LogQuery $query Filters.
	 */
	public function count( LogQuery $query ): int;

	/**
	 * Delete entries created before a timestamp.
	 *
	 * @param int $before Timestamp.
	 * @return int Rows deleted.
	 */
	public function purge( int $before ): int;
}
