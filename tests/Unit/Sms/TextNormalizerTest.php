<?php
declare(strict_types=1);

namespace Saizul\BdSms\Tests\Unit\Sms;

use PHPUnit\Framework\TestCase;
use Saizul\BdSms\Sms\Encoding;
use Saizul\BdSms\Sms\SegmentCounter;
use Saizul\BdSms\Sms\TextNormalizer;

final class TextNormalizerTest extends TestCase {

	public function test_typographic_punctuation_becomes_gsm(): void {
		$normalizer = new TextNormalizer();
		$text       = $normalizer->normalize( "Your order’s “ready” – pick it up… now" );

		self::assertSame( 'Your order\'s "ready" - pick it up... now', $text );
		self::assertSame( Encoding::GSM7, ( new SegmentCounter() )->encoding_of( $text ) );
	}

	public function test_punctuation_can_be_kept(): void {
		self::assertSame( 'It’s here', ( new TextNormalizer() )->normalize( 'It’s here', false ) );
	}

	public function test_clean_strips_markup_and_spacing(): void {
		$text = ( new TextNormalizer() )->clean( "  <b>Hello</b>&nbsp;&amp;   welcome \r\n\r\n\r\n\r\n  to   the store \u{200B} " );

		self::assertSame( "Hello\u{00A0}& welcome\n\nto the store", $text );
	}

	public function test_bangla_is_untouched_including_joiners(): void {
		$bangla = "র\u{200C}্যাব আপনার অর্ডার";

		self::assertSame( $bangla, ( new TextNormalizer() )->normalize( $bangla ) );
	}
}
