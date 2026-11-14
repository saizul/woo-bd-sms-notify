<?php
/**
 * Log filters.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Log;

/**
 * Filters and paging for {@see LogRepository::search()}.
 */
final class LogQuery {

	/**
	 * Status filter.
	 *
	 * @var string
	 */
	public $status = '';

	/**
	 * Order id filter.
	 *
	 * @var int
	 */
	public $order_id = 0;

	/**
	 * Phone filter (01XXXXXXXXX).
	 *
	 * @var string
	 */
	public $phone = '';

	/**
	 * Type filter.
	 *
	 * @var string
	 */
	public $type = '';

	/**
	 * Page size (1 to 500).
	 *
	 * @var int
	 */
	public $limit = 20;

	/**
	 * Offset.
	 *
	 * @var int
	 */
	public $offset = 0;

	/**
	 * Build from request or CLI arguments; unknown values are ignored.
	 *
	 * @param array<string,mixed> $args Arguments.
	 */
	public static function from_array( array $args ): self {
		$query = new self();
		if ( isset( $args['status'] ) && in_array( $args['status'], LogEntry::STATUSES, true ) ) {
			$query->status = (string) $args['status'];
		}
		if ( isset( $args['type'] ) && preg_match( '/^[a-z_]{1,20}$/', (string) $args['type'] ) ) {
			$query->type = (string) $args['type'];
		}
		if ( isset( $args['order_id'] ) ) {
			$query->order_id = max( 0, (int) $args['order_id'] );
		}
		if ( isset( $args['phone'] ) && preg_match( '/^01\d{9}$/', (string) $args['phone'] ) ) {
			$query->phone = (string) $args['phone'];
		}
		if ( isset( $args['limit'] ) ) {
			$query->limit = max( 1, min( 500, (int) $args['limit'] ) );
		}
		if ( isset( $args['offset'] ) ) {
			$query->offset = max( 0, (int) $args['offset'] );
		}

		return $query;
	}

	/**
	 * Whether an entry matches the filters.
	 *
	 * @param LogEntry $entry Entry.
	 */
	public function matches( LogEntry $entry ): bool {
		return ( '' === $this->status || $this->status === $entry->status )
			&& ( '' === $this->type || $this->type === $entry->type )
			&& ( 0 === $this->order_id || $this->order_id === $entry->order_id )
			&& ( '' === $this->phone || $this->phone === $entry->phone );
	}
}
