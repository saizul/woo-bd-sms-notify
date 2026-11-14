<?php
declare(strict_types=1);

namespace Saizul\BdSms\Tests\Unit\Log;

use PHPUnit\Framework\TestCase;
use Saizul\BdSms\Log\InMemoryLogRepository;
use Saizul\BdSms\Log\LogEntry;
use Saizul\BdSms\Log\LogQuery;

final class LogTest extends TestCase {

	private function entry( array $values ): LogEntry {
		return LogEntry::from_array( $values + array( 'created_at' => 1000 ) );
	}

	public function test_from_array_casts_types(): void {
		$entry = LogEntry::from_array(
			array(
				'id'         => '5',
				'order_id'   => '1024',
				'status'     => 'sent',
				'attempts'   => '2',
				'unknown'    => 'ignored',
				'error_code' => 202,
			)
		);

		self::assertSame( 5, $entry->id );
		self::assertSame( 1024, $entry->order_id );
		self::assertSame( 2, $entry->attempts );
		self::assertSame( '202', $entry->error_code );
		self::assertArrayNotHasKey( 'unknown', $entry->to_array() );
		self::assertTrue( $entry->is_final() );
	}

	public function test_query_from_array_validates(): void {
		$query = LogQuery::from_array(
			array(
				'status'   => 'DROP TABLE',
				'type'     => 'otp',
				'order_id' => '-4',
				'phone'    => '+8801711',
				'limit'    => '9999',
				'offset'   => '-1',
			)
		);

		self::assertSame( '', $query->status );
		self::assertSame( 'otp', $query->type );
		self::assertSame( 0, $query->order_id );
		self::assertSame( '', $query->phone );
		self::assertSame( 500, $query->limit );
		self::assertSame( 0, $query->offset );
	}

	public function test_in_memory_repository(): void {
		$log = new InMemoryLogRepository();
		$log->insert( $this->entry( array( 'status' => 'sent', 'order_id' => 1, 'created_at' => 100 ) ) );
		$log->insert( $this->entry( array( 'status' => 'failed', 'order_id' => 1, 'created_at' => 200, 'phone' => '01711234567' ) ) );
		$id = $log->insert( $this->entry( array( 'status' => 'sent', 'order_id' => 2, 'created_at' => 300 ) ) );

		self::assertSame( 3, $id );
		self::assertSame( array( 3, 2, 1 ), array_map( static function ( LogEntry $e ) { return $e->id; }, $log->search( new LogQuery() ) ) );
		self::assertSame( 2, $log->count( LogQuery::from_array( array( 'status' => 'sent' ) ) ) );
		self::assertSame( 1, $log->count( LogQuery::from_array( array( 'phone' => '01711234567' ) ) ) );
		self::assertSame( 2, $log->count( LogQuery::from_array( array( 'order_id' => 1 ) ) ) );
		self::assertCount( 1, $log->search( LogQuery::from_array( array( 'limit' => 1, 'offset' => 2 ) ) ) );

		$entry         = $log->find( 2 );
		$entry->status = 'sent';
		$log->update( $entry );
		self::assertSame( 'sent', $log->find( 2 )->status );

		// Returned entries are copies.
		$log->find( 2 )->status = 'changed';
		self::assertSame( 'sent', $log->find( 2 )->status );

		self::assertSame( 2, $log->purge( 300 ) );
		self::assertNull( $log->find( 1 ) );
		self::assertNotNull( $log->find( 3 ) );
	}
}
