<?php
declare( strict_types = 1 );

namespace WooCommerce\PayPalCommerce\WcGateway\Helper;

use Mockery;
use ReflectionMethod;
use WooCommerce\PayPalCommerce\ApiClient\Endpoint\PartnersEndpoint;
use WooCommerce\PayPalCommerce\ApiClient\Entity\SellerStatus;
use WooCommerce\PayPalCommerce\ApiClient\Entity\SellerStatusCapability;
use WooCommerce\PayPalCommerce\ApiClient\Helper\FailureRegistry;
use WooCommerce\PayPalCommerce\ApiClient\Helper\ProductStatusResultCache;
use WooCommerce\PayPalCommerce\TestCase;

/**
 * @covers \WooCommerce\PayPalCommerce\WcGateway\Helper\ApmCapabilityStatus
 */
class ApmCapabilityStatusTest extends TestCase {

	private function check( array $capabilities ): bool {
		$testee = new ApmCapabilityStatus(
			true,
			Mockery::mock( PartnersEndpoint::class ),
			Mockery::mock( FailureRegistry::class ),
			Mockery::mock( ProductStatusResultCache::class )
		);

		$method = new ReflectionMethod( $testee, 'check_api_response' );
		$method->setAccessible( true );

		return $method->invoke( $testee, new SellerStatus( array(), $capabilities ) );
	}

	public function test_active_apm_capability_is_active(): void {
		$this->assertTrue(
			$this->check(
				array(
					new SellerStatusCapability( 'OTHER', SellerStatusCapability::STATUS_ACTIVE ),
					new SellerStatusCapability( 'PAYPAL_CHECKOUT_ALTERNATIVE_PAYMENT_METHODS', SellerStatusCapability::STATUS_ACTIVE ),
				)
			)
		);
	}

	public function test_inactive_apm_capability_is_not_active(): void {
		$this->assertFalse(
			$this->check( array( new SellerStatusCapability( 'PAYPAL_CHECKOUT_ALTERNATIVE_PAYMENT_METHODS', 'DENIED' ) ) )
		);
	}

	public function test_other_active_capability_is_not_active(): void {
		$this->assertFalse(
			$this->check( array( new SellerStatusCapability( 'CRYPTO_PYMTS', SellerStatusCapability::STATUS_ACTIVE ) ) )
		);
	}

	public function test_no_capabilities_is_not_active(): void {
		$this->assertFalse( $this->check( array() ) );
	}

	/**
	 * The result-cache key is shared with the local APM status on purpose, so that
	 * both read the same cached answer and cannot disagree.
	 */
	public function test_shares_the_result_cache_key_with_the_local_apm_status(): void {
		$this->assertSame( 'products_local_apms_enabled', ApmCapabilityStatus::KEY );
	}
}
