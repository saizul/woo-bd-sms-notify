<?php
declare(strict_types=1);

namespace Saizul\BdSms\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Saizul\BdSms\Support\ArrayStore;
use Saizul\BdSms\Support\FrozenClock;
use Saizul\BdSms\Support\JsonPath;
use Saizul\BdSms\Support\Masker;

final class SupportTest extends TestCase {

	public function test_json_path(): void {
		$data = JsonPath::decode( '{"status":"SUCCESS","smsinfo":[{"reference_id":"abc"}],"data":{"request_id":42},"ok":true,"none":null}' );

		self::assertIsArray( $data );
		self::assertSame( 'SUCCESS', JsonPath::get( $data, 'status' ) );
		self::assertSame( 'abc', JsonPath::get( $data, 'smsinfo.0.reference_id' ) );
		self::assertSame( 42, JsonPath::get( $data, 'data.request_id' ) );
		self::assertNull( JsonPath::get( $data, 'data.missing.deeper' ) );
		self::assertSame( $data, JsonPath::get( $data, '' ) );
		self::assertSame( 'true', JsonPath::stringify( JsonPath::get( $data, 'ok' ) ) );
		self::assertSame( 'null', JsonPath::stringify( JsonPath::get( $data, 'none' ) ) );
		self::assertSame( '{"request_id":42}', JsonPath::stringify( JsonPath::get( $data, 'data' ) ) );
	}

	public function test_json_decode_rejects_non_json(): void {
		self::assertNull( JsonPath::decode( '202' ) );
		self::assertNull( JsonPath::decode( '' ) );
		self::assertNull( JsonPath::decode( '{broken' ) );
		self::assertSame( array( 1, 2 ), JsonPath::decode( ' [1,2] ' ) );
	}

	public function test_masker(): void {
		self::assertSame( '0171****567', Masker::phone( '01711234567' ) );
		self::assertSame( '****', Masker::phone( '1234' ) );
		self::assertSame( '********cdef', Masker::secret( 'xxxxxxxxxxxxabcdef' ) );
		self::assertSame( '******', Masker::secret( 'short1' ) );
		self::assertSame( '', Masker::secret( '' ) );
		self::assertSame(
			'api_key=[redacted]&k2=[redacted]&msg=hi',
			Masker::redact( 'api_key=s3cr3t/key+x&k2=' . rawurlencode( 's3cr3t/key+x' ) . '&msg=hi', array( 's3cr3t/key+x', 'ab' ) )
		);
	}

	public function test_array_store_expires(): void {
		$clock = new FrozenClock( 1000 );
		$store = new ArrayStore( $clock );
		$store->set( 'a', array( 'x' => 1 ), 10 );
		$store->set( 'b', 5, 100 );

		self::assertSame( array( 'x' => 1 ), $store->get( 'a' ) );
		self::assertSame( 2, $store->count() );
		$clock->advance( 10 );
		self::assertNull( $store->get( 'a' ) );
		self::assertSame( 5, $store->get( 'b' ) );
		$store->delete( 'b' );
		self::assertNull( $store->get( 'b' ) );
		self::assertSame( 0, $store->count() );
	}
}
