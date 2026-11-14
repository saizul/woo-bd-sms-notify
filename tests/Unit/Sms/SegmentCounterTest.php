<?php
declare(strict_types=1);

namespace Saizul\BdSms\Tests\Unit\Sms;

use PHPUnit\Framework\TestCase;
use Saizul\BdSms\Sms\Encoding;
use Saizul\BdSms\Sms\SegmentCounter;

final class SegmentCounterTest extends TestCase {

	/** @var SegmentCounter */
	private $counter;

	protected function setUp(): void {
		$this->counter = new SegmentCounter();
	}

	/**
	 * @return array<string,array{0:string,1:string,2:int,3:int,4:int}>
	 */
	public function provide_lengths(): array {
		return array(
			'empty'                    => array( '', Encoding::GSM7, 0, 0, 160 ),
			'one GSM char'             => array( 'a', Encoding::GSM7, 1, 1, 160 ),
			'160 GSM chars'            => array( str_repeat( 'a', 160 ), Encoding::GSM7, 160, 1, 160 ),
			'161 GSM chars'            => array( str_repeat( 'a', 161 ), Encoding::GSM7, 161, 2, 153 ),
			'306 GSM chars'            => array( str_repeat( 'a', 306 ), Encoding::GSM7, 306, 2, 153 ),
			'307 GSM chars'            => array( str_repeat( 'a', 307 ), Encoding::GSM7, 307, 3, 153 ),
			'459 GSM chars'            => array( str_repeat( 'a', 459 ), Encoding::GSM7, 459, 3, 153 ),
			'80 euro signs'            => array( str_repeat( '€', 80 ), Encoding::GSM7, 160, 1, 160 ),
			'81 euro signs'            => array( str_repeat( '€', 81 ), Encoding::GSM7, 162, 2, 153 ),
			'GSM accents'              => array( 'Ça va? Ünïcode-free: ÄÖÑÜ§¿äöñüà', Encoding::UCS2, 32, 1, 70 ),
			'70 Bangla chars'          => array( str_repeat( 'ক', 70 ), Encoding::UCS2, 70, 1, 70 ),
			'71 Bangla chars'          => array( str_repeat( 'ক', 71 ), Encoding::UCS2, 71, 2, 67 ),
			'134 Bangla chars'         => array( str_repeat( 'ক', 134 ), Encoding::UCS2, 134, 2, 67 ),
			'135 Bangla chars'         => array( str_repeat( 'ক', 135 ), Encoding::UCS2, 135, 3, 67 ),
			'Bangla sentence'          => array( 'আপনার অর্ডার পাঠানো হয়েছে', Encoding::UCS2, 26, 1, 70 ),
			'one curly quote'          => array( 'Your order’s on the way', Encoding::UCS2, 23, 1, 70 ),
			'35 emoji (surrogates)'    => array( str_repeat( '😀', 35 ), Encoding::UCS2, 70, 1, 70 ),
			'36 emoji (surrogates)'    => array( str_repeat( '😀', 36 ), Encoding::UCS2, 72, 2, 67 ),
			'line breaks count as one' => array( "Line 1\nLine 2", Encoding::GSM7, 13, 1, 160 ),
		);
	}

	/**
	 * @dataProvider provide_lengths
	 */
	public function test_counts( string $text, string $encoding, int $units, int $segments, int $per_segment ): void {
		$info = $this->counter->count( $text );

		self::assertSame( $encoding, $info->encoding() );
		self::assertSame( $units, $info->units() );
		self::assertSame( $segments, $info->segments() );
		self::assertSame( $per_segment, $info->per_segment() );
		self::assertSame( $text, implode( '', $info->parts() ), 'Parts join back to the text.' );
	}

	public function test_lowercase_c_cedilla_is_not_gsm(): void {
		// The GSM 03.38 table only has the capital Ç.
		self::assertSame( Encoding::GSM7, $this->counter->encoding_of( 'Ç' ) );
		self::assertSame( Encoding::UCS2, $this->counter->encoding_of( 'ç' ) );
	}

	public function test_extension_characters_take_two_septets(): void {
		self::assertSame( 22, $this->counter->count( 'Price: 100€ [promo]' )->units() );
		foreach ( array( '^', '{', '}', '\\', '[', '~', ']', '|', '€', "\f" ) as $char ) {
			self::assertSame( 2, Encoding::gsm7_cost( $char ), $char );
		}
	}

	public function test_escape_pairs_are_not_split_across_parts(): void {
		$info  = $this->counter->count( str_repeat( '€', 81 ) );
		$parts = $info->parts();

		// 76 x 2 = 152 septets; a 77th would need 154 > 153.
		self::assertSame( 76, mb_strlen( $parts[0] ) );
		self::assertSame( 5, mb_strlen( $parts[1] ) );
	}

	public function test_surrogate_pairs_are_not_split_across_parts(): void {
		$parts = $this->counter->count( str_repeat( '😀', 36 ) )->parts();

		self::assertSame( 33, mb_strlen( $parts[0] ) );
		self::assertSame( 3, mb_strlen( $parts[1] ) );
	}

	public function test_long_bangla_message_is_split_at_67(): void {
		$text  = 'প্রিয় গ্রাহক, আপনার অর্ডার #১০২৪ সফলভাবে গ্রহণ করা হয়েছে। মোট ১২৫০ টাকা। ধন্যবাদ, ঢাকা শপ।';
		$info  = $this->counter->count( $text );
		$parts = $info->parts();

		self::assertSame( 92, $info->characters() );
		self::assertSame( 2, $info->segments() );
		self::assertSame( 67, mb_strlen( $parts[0] ) );
		self::assertSame( 25, mb_strlen( $parts[1] ) );
		self::assertSame( 42, $info->remaining() );
	}

	public function test_remaining_and_array_form(): void {
		self::assertSame( 155, $this->counter->count( 'hello' )->remaining() );
		self::assertSame( 145, $this->counter->count( str_repeat( 'a', 161 ) )->remaining() );
		self::assertSame( 0, $this->counter->count( str_repeat( 'a', 160 ) )->remaining() );

		self::assertSame(
			array(
				'encoding'    => 'UCS-2',
				'characters'  => 26,
				'units'       => 26,
				'segments'    => 1,
				'per_segment' => 70,
				'remaining'   => 44,
			),
			$this->counter->count( 'আপনার অর্ডার পাঠানো হয়েছে' )->to_array()
		);
	}

	public function test_bangla_detection(): void {
		self::assertTrue( Encoding::has_bangla( 'Order আপনার' ) );
		self::assertFalse( Encoding::has_bangla( 'Order 1024' ) );
		self::assertTrue( $this->counter->count( 'ধন্যবাদ' )->is_unicode() );
	}

	public function test_invalid_utf8_still_counts(): void {
		$info = $this->counter->count( "abc\xff" );

		self::assertSame( Encoding::UCS2, $info->encoding() );
		self::assertSame( 1, $info->segments() );
	}
}
