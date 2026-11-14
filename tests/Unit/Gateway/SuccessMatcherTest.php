<?php
declare(strict_types=1);

namespace Saizul\BdSms\Tests\Unit\Gateway;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Saizul\BdSms\Gateway\SuccessMatcher;
use Saizul\BdSms\Http\Response;

final class SuccessMatcherTest extends TestCase {

	/**
	 * @return array<string,array{0:string,1:string,2:int,3:string,4:bool}>
	 */
	public function provide_rules(): array {
		return array(
			'2xx ok'             => array( 'http_2xx', '', 201, '', true ),
			'2xx fails on 400'   => array( 'http_2xx', '', 400, '', false ),
			'contains'           => array( 'contains', 'success', 200, 'SMS SUCCESS', true ),
			'contains missing'   => array( 'contains', 'success', 200, 'failed', false ),
			'contains on 500'    => array( 'contains', 'success', 500, 'success', false ),
			'not contains'       => array( 'not_contains', 'error', 200, 'queued', true ),
			'not contains found' => array( 'not_contains', 'error', 200, 'ERROR 12', false ),
			'regex'              => array( 'regex', '^\d{3}\|ok', 200, '202|OK|id=1', true ),
			'regex with tilde'   => array( 'regex', 'a~b', 200, 'xa~bx', true ),
			'json equals'        => array( 'json', 'error=0', 200, '{"error":0}', true ),
			'json string number' => array( 'json', 'code = "200"', 200, '{"code":200}', true ),
			'json not equal'     => array( 'json', 'error!=1', 200, '{"error":0}', true ),
			'json not equal hit' => array( 'json', 'error!=1', 200, '{"error":1}', false ),
			'json bool'          => array( 'json', 'ok=true', 200, '{"ok":true}', true ),
			'json all'           => array( 'json', 'status=SUCCESS && smsinfo.0.sms_status=SUCCESS', 200, '{"status":"SUCCESS","smsinfo":[{"sms_status":"FAILED"}]}', false ),
			'json present'       => array( 'json', 'data.id', 200, '{"data":{"id":"x"}}', true ),
			'json empty'         => array( 'json', 'data.id', 200, '{"data":{"id":""}}', false ),
			'json missing'       => array( 'json', 'status=ok', 200, '{"other":1}', false ),
			'not json'           => array( 'json', 'status=ok', 200, 'status=ok', false ),
		);
	}

	/**
	 * @dataProvider provide_rules
	 */
	public function test_rules( string $type, string $value, int $status, string $body, bool $expected ): void {
		self::assertSame( $expected, ( new SuccessMatcher( $type, $value ) )->matches( new Response( $status, $body ) ) );
	}

	public function test_conditions_parser(): void {
		self::assertSame(
			array(
				array( 'status', '=', 'SUCCESS' ),
				array( 'code', '!=', '4003' ),
				array( 'data.id', '', '' ),
			),
			SuccessMatcher::conditions( "status = 'SUCCESS' && code!=4003\ndata.id" )
		);
	}

	public function test_rejects_bad_rules(): void {
		$this->expectException( InvalidArgumentException::class );
		new SuccessMatcher( 'magic' );
	}

	public function test_rejects_empty_value(): void {
		$this->expectException( InvalidArgumentException::class );
		new SuccessMatcher( 'contains', '  ' );
	}

	public function test_pattern_validation(): void {
		self::assertTrue( SuccessMatcher::is_valid_pattern( 'ok|accepted' ) );
		self::assertFalse( SuccessMatcher::is_valid_pattern( '(' ) );
	}
}
