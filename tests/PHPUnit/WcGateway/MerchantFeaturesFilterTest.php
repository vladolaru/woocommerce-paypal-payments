<?php
declare( strict_types = 1 );

namespace WooCommerce\PayPalCommerce\WcGateway;

use Closure;
use Mockery;
use ReflectionFunction;
use WooCommerce\PayPalCommerce\ApiClient\Helper\DccApplies;
use WooCommerce\PayPalCommerce\ApiClient\Helper\ReferenceTransactionStatus;
use WooCommerce\PayPalCommerce\LocalAlternativePaymentMethods\LocalApmProductStatus;
use WooCommerce\PayPalCommerce\LocalAlternativePaymentMethods\PayUponInvoice\PayUponInvoiceProductStatus;
use WooCommerce\PayPalCommerce\ModularTestCase;
use WooCommerce\PayPalCommerce\ModuleAvailability;
use WooCommerce\PayPalCommerce\Vendor\Psr\Container\ContainerInterface;
use WooCommerce\PayPalCommerce\Settings\Data\Definition\FeaturesDefinition;
use WooCommerce\PayPalCommerce\WcGateway\Helper\ApmCapabilityStatus;
use WooCommerce\PayPalCommerce\WcGateway\Helper\DCCProductStatus;
use WooCommerce\PayPalCommerce\WcGateway\Helper\InstallmentsProductStatus;
use WooCommerce\PayPalCommerce\WcGateway\Helper\PWCProductStatus;
use function Brain\Monkey\Filters\expectAdded;
use function Brain\Monkey\Functions\when;

/**
 * Pay Later messaging reads the APM seller-status capability as a proxy, so it
 * must follow that capability whether or not the local APM module is loaded.
 * The alternative payment methods feature still needs the module.
 *
 * Brain Monkey's apply_filters() does not run callbacks, so the test captures
 * the callback WCGatewayModule registers and invokes it directly.
 */
class MerchantFeaturesFilterTest extends ModularTestCase {

	public function setUp(): void {
		parent::setUp();
		when( 'get_option' )->justReturn( array() );
	}

	/**
	 * Boots the plugin and returns the merchant features callback.
	 *
	 * @param bool $apms_loaded     Whether the local APM module counts as loaded.
	 * @param bool $capability      What the APM capability status reports.
	 * @param bool $local_apm_state What the local APM product status reports, when loaded.
	 */
	private function features( bool $apms_loaded, bool $capability, bool $local_apm_state ): array {
		$callback = null;
		expectAdded( 'woocommerce_paypal_payments_rest_common_merchant_features' )
			->atLeast()
			->once()
			->whenHappen(
				static function ( callable $cb ) use ( &$callback ): void {
					// Several modules extend this filter; keep the one WCGatewayModule registers.
					$scope = ( new ReflectionFunction( Closure::fromCallable( $cb ) ) )->getClosureScopeClass();
					if ( null !== $scope && WCGatewayModule::class === $scope->getName() ) {
						$callback = $cb;
					}
				}
			);

		$apm_capability = Mockery::mock( ApmCapabilityStatus::class );
		$apm_capability->shouldReceive( 'is_active' )->andReturn( $capability );

		$local_apm = Mockery::mock( LocalApmProductStatus::class );
		if ( $apms_loaded ) {
			$local_apm->shouldReceive( 'is_active' )->andReturn( $local_apm_state );
		} else {
			$local_apm->shouldNotReceive( 'is_active' );
		}

		$reference = Mockery::mock( ReferenceTransactionStatus::class );
		$reference->shouldReceive( 'reference_transaction_enabled' )->andReturn( false );
		$dcc_status = Mockery::mock( DCCProductStatus::class );
		$dcc_status->shouldReceive( 'is_active' )->andReturn( false );
		$dcc_applies = Mockery::mock( DccApplies::class );
		$dcc_applies->shouldReceive( 'for_country_currency' )->andReturn( false );
		$installments = Mockery::mock( InstallmentsProductStatus::class );
		$installments->shouldReceive( 'is_active' )->andReturn( false );
		$pwc = Mockery::mock( PWCProductStatus::class );
		$pwc->shouldReceive( 'is_active' )->andReturn( false );
		$pui = Mockery::mock( PayUponInvoiceProductStatus::class );
		$pui->shouldReceive( 'is_active' )->andReturn( false );

		$this->bootstrapModule(
			array(
				'settings.flag.is-connected'                => static fn() => true,
				'ppcp.module-availability'                  => static function ( ContainerInterface $c ) use ( $apms_loaded ) {
					return new class( $c, $apms_loaded ) extends ModuleAvailability {
						private bool $apms_loaded;

						public function __construct( ContainerInterface $c, bool $apms_loaded ) {
							parent::__construct( $c );
							$this->apms_loaded = $apms_loaded;
						}

						public function is_loaded( string $prefix ): bool {
							return 'ppcp-local-apms' === $prefix ? $this->apms_loaded : parent::is_loaded( $prefix );
						}
					};
				},
				'wcgateway.apm-capability-status'           => static fn() => $apm_capability,
				'ppcp-local-apms.product-status'            => static fn() => $local_apm,
				'api.reference-transaction-status'          => static fn() => $reference,
				'wcgateway.helper.dcc-product-status'       => static fn() => $dcc_status,
				'api.helpers.dccapplies'                    => static fn() => $dcc_applies,
				'wcgateway.installments-product-status'     => static fn() => $installments,
				'wcgateway.pwc-product-status'              => static fn() => $pwc,
				'wcgateway.contact-module.eligibility.check' => static fn() => static fn() => false,
				'wcgateway.pay-upon-invoice-product-status' => static fn() => $pui,
				'save-payment-methods.eligibility.check'    => static fn() => static fn() => false,
			)
		);

		$this->assertIsCallable( $callback, 'WCGatewayModule must register the merchant features callback' );

		return $callback( array() );
	}

	public function test_pay_later_messaging_follows_the_capability_when_local_apms_are_absent(): void {
		$features = $this->features( false, true, false );

		$this->assertTrue( $features[ FeaturesDefinition::FEATURE_PAY_LATER_MESSAGING ]['enabled'] );
		$this->assertFalse( $features[ FeaturesDefinition::FEATURE_ALTERNATIVE_PAYMENT_METHODS ]['enabled'] );
	}

	public function test_pay_later_messaging_is_off_without_the_capability_when_local_apms_are_absent(): void {
		$features = $this->features( false, false, true );

		$this->assertFalse( $features[ FeaturesDefinition::FEATURE_PAY_LATER_MESSAGING ]['enabled'] );
		$this->assertFalse( $features[ FeaturesDefinition::FEATURE_ALTERNATIVE_PAYMENT_METHODS ]['enabled'] );
	}

	public function test_both_features_are_on_when_local_apms_are_loaded_and_active(): void {
		$features = $this->features( true, true, true );

		$this->assertTrue( $features[ FeaturesDefinition::FEATURE_PAY_LATER_MESSAGING ]['enabled'] );
		$this->assertTrue( $features[ FeaturesDefinition::FEATURE_ALTERNATIVE_PAYMENT_METHODS ]['enabled'] );
	}
}
