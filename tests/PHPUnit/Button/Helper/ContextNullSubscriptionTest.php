<?php
declare(strict_types=1);

namespace WooCommerce\PayPalCommerce\Button\Helper;

use Mockery;
use WooCommerce\PayPalCommerce\ApiClient\Entity\Order;
use WooCommerce\PayPalCommerce\ApiClient\Entity\OrderStatus;
use WooCommerce\PayPalCommerce\Session\SessionHandler;
use WooCommerce\PayPalCommerce\TestCase;
use function Brain\Monkey\Functions\when;

/**
 * @covers \WooCommerce\PayPalCommerce\Button\Helper\Context::is_paypal_continuation
 */
class ContextNullSubscriptionTest extends TestCase
{
    public function testNoSubscriptionStatusHelperAndASubscriptionIdIsNotAContinuation(): void
    {
        $status = Mockery::mock(OrderStatus::class);
        $status->shouldReceive('is')->andReturn(false);
        $order = Mockery::mock(Order::class);
        $order->shouldReceive('status')->andReturn($status);
        $session_handler = Mockery::mock(SessionHandler::class);
        $session_handler->shouldReceive('order')->andReturn($order);
        $session = Mockery::mock(\stdClass::class);
        $session->shouldReceive('get')->with('ppcp_subscription_id')->andReturn('I-123');
        when('WC')->justReturn((object) ['session' => $session]);

        $sut = new Context($session_handler, null);

        $this->assertFalse($sut->is_paypal_continuation());
    }
}
