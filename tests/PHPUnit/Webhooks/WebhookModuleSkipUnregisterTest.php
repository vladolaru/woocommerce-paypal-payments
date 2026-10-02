<?php
declare(strict_types=1);

namespace WooCommerce\PayPalCommerce\Webhooks;

use Mockery;
use WooCommerce\PayPalCommerce\ModularTestCase;
use function Brain\Monkey\Actions\expectAdded;
use function Brain\Monkey\Filters\expectApplied;

/**
 * The deactivation listener must leave the PayPal webhook subscription in
 * place when a host says so through the skip filter (a host that keeps
 * serving the wallet after this plugin deactivates, for example).
 *
 * Brain Monkey's do_action() does not run callbacks, so the test captures
 * the listener WebhookModule registers and invokes it directly.
 */
class WebhookModuleSkipUnregisterTest extends ModularTestCase
{
    /**
     * Boots the plugin with a mocked registrar and returns the deactivation listener.
     */
    private function deactivationListener(WebhookRegistrar $registrar): callable
    {
        $listener = null;
        expectAdded('woocommerce_paypal_payments_gateway_deactivate')
            ->once()
            ->whenHappen(static function (callable $callback) use (&$listener): void {
                $listener = $callback;
            });
        $this->bootstrapModule([
            'webhook.registrar' => static fn() => $registrar,
        ]);
        $this->assertIsCallable($listener, 'WebhookModule must register the deactivation listener');
        return $listener;
    }

    public function testUnregisterIsSkippedWhenTheFilterSaysSo(): void
    {
        $registrar = Mockery::mock(WebhookRegistrar::class);
        $registrar->shouldReceive('unregister')->never();
        $listener = $this->deactivationListener($registrar);
        expectApplied('woocommerce_paypal_payments_skip_webhook_unregister_on_deactivate')
            ->once()
            ->with(false)
            ->andReturn(true);

        $listener();
    }

    public function testUnregisterRunsByDefault(): void
    {
        $registrar = Mockery::mock(WebhookRegistrar::class);
        $registrar->shouldReceive('unregister')->once();
        $listener = $this->deactivationListener($registrar);

        $listener();
    }
}
