<?php
declare(strict_types=1);

namespace Saizul\BdSms\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;
use Saizul\BdSms\Template\Placeholders;
use Saizul\BdSms\Template\TemplateRenderer;

final class TemplateRendererTest extends TestCase {

	/** @var TemplateRenderer */
	private $renderer;

	protected function setUp(): void {
		$this->renderer = new TemplateRenderer();
	}

	public function test_replaces_placeholders(): void {
		self::assertSame(
			'Hi Karim, order #1024 (1,250 BDT) is Processing.',
			$this->renderer->render(
				'Hi {name}, order #{order_number} ({total} {currency}) is {status}.',
				array(
					'name'         => 'Karim',
					'order_number' => '1024',
					'total'        => '1,250',
					'currency'     => 'BDT',
					'status'       => 'Processing',
				)
			)
		);
	}

	public function test_names_are_case_insensitive_and_may_have_spaces(): void {
		self::assertSame( 'ID 7', $this->renderer->render( 'ID { ORDER_ID }', array( 'order_id' => 7 ) ) );
	}

	public function test_fallbacks_for_missing_and_empty_values(): void {
		$vars = array(
			'tracking' => '',
			'courier'  => 'Steadfast',
		);

		self::assertSame( 'Tracking: not yet available', $this->renderer->render( 'Tracking: {tracking|not yet available}', $vars ) );
		self::assertSame( 'Courier: Steadfast', $this->renderer->render( 'Courier: {courier|unknown}', $vars ) );
		self::assertSame( 'Link: ', $this->renderer->render( 'Link: {tracking_url}', $vars ) );
	}

	public function test_unknown_placeholders_are_removed(): void {
		self::assertSame( 'Hello !', $this->renderer->render( 'Hello {nope}!', array() ) );
	}

	public function test_double_braces_are_literal(): void {
		self::assertSame( 'Use {name} for Karim', $this->renderer->render( 'Use {{name}} for {name}', array( 'name' => 'Karim' ) ) );
	}

	public function test_values_cannot_inject_placeholders(): void {
		self::assertSame( 'Hi {otp}', $this->renderer->render( 'Hi {name}', array( 'name' => '{otp}', 'otp' => '123456' ) ) );
	}

	public function test_bangla_template(): void {
		self::assertSame(
			'প্রিয় করিম, আপনার অর্ডার #১০২৪ পাঠানো হয়েছে।',
			$this->renderer->render( 'প্রিয় {first_name}, আপনার অর্ডার #{order_number} পাঠানো হয়েছে।', array( 'first_name' => 'করিম', 'order_number' => '১০২৪' ) )
		);
	}

	public function test_lists_placeholders(): void {
		self::assertSame( array( 'name', 'total', 'tracking' ), $this->renderer->placeholders( '{name} {total} {name} {tracking|-} {{literal}}' ) );
		self::assertSame( array( 'shiping' ), $this->renderer->unknown_placeholders( '{name} {shiping}', Placeholders::order_names() ) );
	}
}
