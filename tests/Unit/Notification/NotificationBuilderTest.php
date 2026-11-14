<?php
declare(strict_types=1);

namespace Saizul\BdSms\Tests\Unit\Notification;

use PHPUnit\Framework\TestCase;
use Saizul\BdSms\Log\LogEntry;
use Saizul\BdSms\Notification\NotificationBuilder;
use Saizul\BdSms\Notification\StatusTemplates;
use Saizul\BdSms\Order\OrderData;

final class NotificationBuilderTest extends TestCase {

	private function order( string $phone = '01711234567' ): OrderData {
		return new OrderData(
			1024,
			$phone,
			array(
				'first_name' => 'Karim',
				'total'      => '1,250',
				'currency'   => 'BDT',
				'tracking'   => '',
			)
		);
	}

	public function test_customer_and_admin_messages(): void {
		$templates = StatusTemplates::from_array(
			array(
				'customer_enabled' => '1',
				'customer'         => 'Hi {first_name}, order #{order_number} ({total} {currency}). Tracking: {tracking|soon}',
				'admin_enabled'    => '1',
				'admin'            => 'New order #{order_id} {total}',
			)
		);
		$messages  = ( new NotificationBuilder() )->build( $this->order(), 'processing', $templates, array( '01811111112', '01911111113', '01811111112' ) );

		self::assertCount( 3, $messages );
		self::assertSame( 'Hi Karim, order #1024 (1,250 BDT). Tracking: soon', $messages[0]->text );
		self::assertSame( LogEntry::TYPE_CUSTOMER, $messages[0]->type );
		self::assertSame( 'processing', $messages[0]->event );
		self::assertSame( 1024, $messages[0]->order_id );
		self::assertSame( '01711234567', $messages[0]->phone );
		self::assertSame( LogEntry::TYPE_ADMIN, $messages[1]->type );
		self::assertSame( '01811111112', $messages[1]->phone );
		self::assertSame( '01911111113', $messages[2]->phone );
		self::assertSame( 'New order #1024 1,250', $messages[2]->text );
	}

	public function test_disabled_or_empty_templates_send_nothing(): void {
		$builder = new NotificationBuilder();

		self::assertSame( array(), $builder->build( $this->order(), 'completed', new StatusTemplates( false, 'Hi', false, 'Admin' ), array( '01811111112' ) ) );
		self::assertSame( array(), $builder->build( $this->order(), 'completed', new StatusTemplates( true, '   ' ), array() ) );
		self::assertSame( array(), $builder->build( $this->order(), 'completed', new StatusTemplates( true, '{unknown}' ), array() ) );
		self::assertSame( array(), $builder->build( $this->order( '' ), 'completed', new StatusTemplates( true, 'Hi' ), array() ) );
		self::assertCount( 0, $builder->build( $this->order(), 'completed', new StatusTemplates( false, '', true, 'Admin' ), array() ) );
	}
}
