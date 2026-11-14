<?php
/**
 * Custom table log storage.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\WP\Db;

use Saizul\BdSms\Log\LogEntry;
use Saizul\BdSms\Log\LogQuery;
use Saizul\BdSms\Log\LogRepository;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The log lives in a custom table; rows change on every send.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only the table name (from the prefix) and the built WHERE clause of placeholders are interpolated.

/**
 * Stores log entries in `{prefix}woo_bd_sms_log`.
 */
final class DbLogRepository implements LogRepository {

	/**
	 * Column formats for $wpdb.
	 *
	 * @var array<string,string>
	 */
	private const FORMATS = array(
		'created_at'      => '%d',
		'updated_at'      => '%d',
		'order_id'        => '%d',
		'type'            => '%s',
		'event'           => '%s',
		'phone'           => '%s',
		'message'         => '%s',
		'gateway'         => '%s',
		'status'          => '%s',
		'attempts'        => '%d',
		'segments'        => '%d',
		'encoding'        => '%s',
		'provider_id'     => '%s',
		'error_code'      => '%s',
		'error_message'   => '%s',
		'response'        => '%s',
		'next_attempt_at' => '%d',
	);

	/**
	 * Insert.
	 *
	 * @param LogEntry $entry Entry.
	 */
	public function insert( LogEntry $entry ): int {
		global $wpdb;
		$row = $this->row( $entry );
		$wpdb->insert( Installer::table(), $row, $this->formats( $row ) );
		$entry->id = (int) $wpdb->insert_id;

		return $entry->id;
	}

	/**
	 * Update.
	 *
	 * @param LogEntry $entry Entry.
	 */
	public function update( LogEntry $entry ): void {
		global $wpdb;
		if ( $entry->id <= 0 ) {
			return;
		}
		$row = $this->row( $entry );
		$wpdb->update( Installer::table(), $row, array( 'id' => $entry->id ), $this->formats( $row ), array( '%d' ) );
	}

	/**
	 * Find.
	 *
	 * @param int $id Id.
	 */
	public function find( int $id ): ?LogEntry {
		global $wpdb;
		$table = Installer::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? LogEntry::from_array( $row ) : null;
	}

	/**
	 * Search, newest first.
	 *
	 * @param LogQuery $query Query.
	 * @return LogEntry[]
	 */
	public function search( LogQuery $query ): array {
		global $wpdb;
		$table                = Installer::table();
		list( $where, $args ) = $this->where( $query );
		$args[]               = $query->limit;
		$args[]               = $query->offset;

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $args ), // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The WHERE placeholders and LIMIT/OFFSET are all in $args.
			ARRAY_A
		);

		return array_map( array( LogEntry::class, 'from_array' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Count.
	 *
	 * @param LogQuery $query Query.
	 */
	public function count( LogQuery $query ): int {
		global $wpdb;
		$table                = Installer::table();
		list( $where, $args ) = $this->where( $query );
		$sql                  = "SELECT COUNT(*) FROM {$table} WHERE {$where}";

		return (int) $wpdb->get_var( array() === $args ? $sql : $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders are in the WHERE clause.
	}

	/**
	 * Purge.
	 *
	 * @param int $before Timestamp.
	 */
	public function purge( int $before ): int {
		global $wpdb;
		$table = Installer::table();

		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %d", $before ) );
	}

	/**
	 * WHERE clause with placeholders.
	 *
	 * @param LogQuery $query Query.
	 * @return array{0:string,1:array<int,int|string>}
	 */
	private function where( LogQuery $query ): array {
		$clauses = array( '1=1' );
		$args    = array();
		if ( '' !== $query->status ) {
			$clauses[] = 'status = %s';
			$args[]    = $query->status;
		}
		if ( '' !== $query->type ) {
			$clauses[] = 'type = %s';
			$args[]    = $query->type;
		}
		if ( $query->order_id > 0 ) {
			$clauses[] = 'order_id = %d';
			$args[]    = $query->order_id;
		}
		if ( '' !== $query->phone ) {
			$clauses[] = 'phone = %s';
			$args[]    = $query->phone;
		}

		return array( implode( ' AND ', $clauses ), $args );
	}

	/**
	 * Formats in the order of the row columns.
	 *
	 * @param array<string,int|string> $row Row.
	 * @return string[]
	 */
	private function formats( array $row ): array {
		$formats = array();
		foreach ( array_keys( $row ) as $column ) {
			$formats[] = self::FORMATS[ $column ];
		}

		return $formats;
	}

	/**
	 * Row values without the id.
	 *
	 * @param LogEntry $entry Entry.
	 * @return array<string,int|string>
	 */
	private function row( LogEntry $entry ): array {
		$row                  = array_intersect_key( $entry->to_array(), self::FORMATS );
		$row['error_message'] = function_exists( 'mb_substr' ) ? mb_substr( (string) $row['error_message'], 0, 255 ) : substr( (string) $row['error_message'], 0, 255 );

		return $row;
	}
}
