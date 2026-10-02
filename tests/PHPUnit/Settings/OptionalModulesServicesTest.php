<?php
declare(strict_types=1);

namespace WooCommerce\PayPalCommerce\Settings;

use Mockery;
use WooCommerce\PayPalCommerce\Button\Helper\MessagesApply;
use WooCommerce\PayPalCommerce\ModuleAvailability;
use WooCommerce\PayPalCommerce\Settings\Data\GeneralSettings;
use WooCommerce\PayPalCommerce\Settings\Data\PaymentSettings;
use WooCommerce\PayPalCommerce\Settings\Data\Definition\FeaturesDefinition;
use WooCommerce\PayPalCommerce\Settings\Data\Definition\PaymentMethodsDefinition;
use WooCommerce\PayPalCommerce\Settings\Service\FeaturesEligibilityService;
use WooCommerce\PayPalCommerce\Settings\Service\PaymentMethodsEligibilityService;
use WooCommerce\PayPalCommerce\TestCase;
use WooCommerce\PayPalCommerce\Vendor\Psr\Container\ContainerInterface;
use WooCommerce\PayPalCommerce\WcGateway\Helper\DCCProductStatus;
use function Brain\Monkey\Functions\when;

/**
 * The settings and gateway wiring must resolve when the optional modules
 * (Apple Pay, Google Pay, Fastlane, card fields, local APMs, order tracking,
 * PayPal Subscriptions) are not loaded: no service-not-found, and every
 * optional feature reads as not eligible.
 */
class OptionalModulesServicesTest extends TestCase
{
    /**
     * A container that serves only what the wallet modules register, plus
     * 'ppcp.module-availability' built from the same container.
     *
     * @param array<string, mixed> $services
     */
    private function walletOnlyContainer(array $services): ContainerInterface
    {
        $container = Mockery::mock(ContainerInterface::class);
        $services['ppcp.module-availability'] = new ModuleAvailability($container);
        $container->shouldReceive('has')->andReturnUsing(
            static function (string $id) use ($services): bool {
                return array_key_exists($id, $services);
            }
        );
        $container->shouldReceive('get')->andReturnUsing(
            static function (string $id) use ($services) {
                if (!array_key_exists($id, $services)) {
                    throw new \RuntimeException("Service $id not found");
                }
                return $services[$id];
            }
        );
        return $container;
    }

    public function setUp(): void
    {
        parent::setUp();
        when('get_option')->justReturn([]);
    }

    public function testFeaturesEligibilityResolvesWithoutOptionalModules(): void
    {
        $messages_apply = Mockery::mock(MessagesApply::class);
        $messages_apply->shouldReceive('for_country')->andReturn(true);
        $container = $this->walletOnlyContainer([
            'button.helper.messages-apply' => $messages_apply,
            'save-payment-methods.eligible' => true,
            'api.merchant.country' => 'US',
        ]);

        $services = require ROOT_DIR . '/modules/ppcp-settings/services.php';
        $service = $services['settings.service.features_eligibilities']($container);

        $this->assertInstanceOf(FeaturesEligibilityService::class, $service);
        $checks = $service->get_eligibility_checks();
        $this->assertFalse($checks[FeaturesDefinition::FEATURE_ADVANCED_CREDIT_AND_DEBIT_CARDS]());
        $this->assertFalse($checks[FeaturesDefinition::FEATURE_GOOGLE_PAY]());
        $this->assertFalse($checks[FeaturesDefinition::FEATURE_APPLE_PAY]());
        $this->assertFalse($checks[FeaturesDefinition::FEATURE_ALTERNATIVE_PAYMENT_METHODS]());
        $this->assertTrue($checks[FeaturesDefinition::FEATURE_SAVE_PAYPAL_AND_VENMO]());
    }

    public function testPaymentMethodsEligibilityResolvesWithoutOptionalModules(): void
    {
        $dcc_status = Mockery::mock(DCCProductStatus::class);
        $container = $this->walletOnlyContainer([
            'api.merchant.country' => 'US',
            'settings.service.merchant_capabilities' => [],
            'wcgateway.helper.dcc-product-status' => $dcc_status,
        ]);

        $services = require ROOT_DIR . '/modules/ppcp-settings/services.php';
        $service = $services['settings.service.payment_methods_eligibilities']($container);

        $this->assertInstanceOf(PaymentMethodsEligibilityService::class, $service);
    }

    public function testMethodsDefinitionResolvesWithoutFastlane(): void
    {
        $container = $this->walletOnlyContainer([
            'settings.data.payment' => Mockery::mock(PaymentSettings::class),
            'settings.data.general' => Mockery::mock(GeneralSettings::class),
        ]);

        $services = require ROOT_DIR . '/modules/ppcp-settings/services.php';
        $definition = $services['settings.data.definition.methods']($container);

        $this->assertInstanceOf(PaymentMethodsDefinition::class, $definition);
    }

    public function testGatewayFeatureEligibilityListResolvesWithoutOptionalModules(): void
    {
        $container = $this->walletOnlyContainer([
            'save-payment-methods.eligibility.check' => static fn(): bool => true,
            'wcgateway.contact-module.eligibility.check' => static fn(): bool => false,
        ]);

        $services = require ROOT_DIR . '/modules/ppcp-wc-gateway/services.php';
        $list = $services['wcgateway.feature-eligibility.list']($container);

        $this->assertIsArray($list);
        foreach ($list as $feature => $check) {
            $this->assertIsCallable($check, "$feature must be a callable");
        }
        $this->assertFalse($list[FeaturesDefinition::FEATURE_APPLE_PAY]());
    }

    public function testWebhookHandlersOmitSaleCompletedWithoutPayPalSubscriptions(): void
    {
        $container = $this->walletOnlyContainer([
            'woocommerce.logger.woocommerce' => Mockery::mock(\Psr\Log\LoggerInterface::class),
            'api.prefix' => 'WC-',
            'api.endpoint.order' => Mockery::mock(\WooCommerce\PayPalCommerce\ApiClient\Endpoint\OrderEndpoint::class),
            'session.handler' => Mockery::mock(\WooCommerce\PayPalCommerce\Session\SessionHandler::class),
            'wcgateway.funding-source.renderer' => Mockery::mock(\WooCommerce\PayPalCommerce\WcGateway\FundingSource\FundingSourceRenderer::class),
            'wcgateway.helper.refund-fees-updater' => Mockery::mock(\WooCommerce\PayPalCommerce\WcGateway\Helper\RefundFeesUpdater::class),
            'wcgateway.order-processor' => Mockery::mock(\WooCommerce\PayPalCommerce\WcGateway\Processor\OrderProcessor::class),
            'wcgateway.processor.authorized-payments' => Mockery::mock(\WooCommerce\PayPalCommerce\WcGateway\Processor\AuthorizedPaymentsProcessor::class),
        ]);

        $services = require ROOT_DIR . '/modules/ppcp-webhooks/services.php';
        $handlers = $services['webhook.endpoint.handler']($container);

        $this->assertNotEmpty($handlers);
        foreach ($handlers as $handler) {
            $this->assertNotInstanceOf(\WooCommerce\PayPalCommerce\Webhooks\Handler\PaymentSaleCompleted::class, $handler);
        }
    }
}
