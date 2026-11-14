<?php
/**
 * Array-backed log.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Log;

/**
 * {@see LogRepository} kept in memory (tests and dry runs).
 */
final class InMemoryLogRepository implements LogRepository {

	/**
	 * Entries by id.
	 *
	 * @var array<int,LogEntry>
	 */
	private $entries = array();

	/**
	 * Next id.
	 *
	 * @var int
	 */
	private $next_id = 1;

	/**
	 * Insert.
	 *
	 * @param LogEntry $entry Entry.
	 */
	public function insert( LogEntry $entry ): int {
		$entry->id                   = $this->next_id++;
		$this->entries[ $entry->id ] = clone $entry;

		return $entry->id;
	}

	/**
	 * Update.
	 *
	 * @param LogEntry $entry Entry.
	 */
	public function update( LogEntry $entry ): void {
		if ( isset( $this->entries[ $entry->id ] ) ) {
			$this->entries[ $entry->id ] = clone $entry;
		}
	}

	/**
	 * Find.
	 *
	 * @param int $id Id.
	 */
	public function find( int $id ): ?LogEntry {
		return isset( $this->entries[ $id ] ) ? clone $this->entries[ $id ] : null;
	}

	/**
	 * Search, newest first.
	 *
	 * @param LogQuery $query Query.
	 * @return LogEntry[]
	 */
	public function search( LogQuery $query ): array {
		$matches = array_values( array_filter( $this->entries, array( $query, 'matches' ) ) );
		usort(
			$matches,
			static function ( LogEntry $a, LogEntry $b ): int {
				return array( $b->created_at, $b->id ) <=> array( $a->created_at, $a->id );
			}
		);

		return array_map(
			static function ( LogEntry $entry ): LogEntry {
				return clone $entry;
			},
			array_slice( $matches, $query->offset, $query->limit )
		);
	}

	/**
	 * Count.
	 *
	 * @param LogQuery $query Query.
	 */
	public function count( LogQuery $query ): int {
		return count( array_filter( $this->entries, array( $query, 'matches' ) ) );
	}

	/**
	 * Purge.
	 *
	 * @param int $before Timestamp.
	 */
	public function purge( int $before ): int {
		$count = 0;
		foreach ( $this->entries as $id => $entry ) {
			if ( $entry->created_at < $before ) {
				unset( $this->entries[ $id ] );
				++$count;
			}
		}

		return $count;
	}
}
