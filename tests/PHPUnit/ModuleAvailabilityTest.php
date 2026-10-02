<?php
declare(strict_types=1);

namespace WooCommerce\PayPalCommerce;

use Mockery;
use WooCommerce\PayPalCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * @covers \WooCommerce\PayPalCommerce\ModuleAvailability
 */
class ModuleAvailabilityTest extends TestCase
{
    /**
     * @param array<string, mixed> $services Services the stub container holds.
     */
    private function sut(array $services): ModuleAvailability
    {
        $container = Mockery::mock(ContainerInterface::class);
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
        return new ModuleAvailability($container);
    }

    public function testAbsentModuleIsNotLoadedNotEligibleNotAvailable(): void
    {
        $sut = $this->sut([]);

        $this->assertFalse($sut->is_loaded('applepay'));
        $this->assertFalse(($sut->eligibility_check('applepay'))());
        $this->assertFalse(($sut->availability_check('applepay'))());
        $this->assertFalse($sut->is_eligible('applepay'));
        $this->assertFalse($sut->is_available('applepay'));
    }

    public function testLoadedModuleWithCallableCheckAndAvailableFlag(): void
    {
        $sut = $this->sut([
            'applepay.available' => true,
            'applepay.eligibility.check' => static fn(): bool => true,
        ]);

        $this->assertTrue($sut->is_loaded('applepay'));
        $this->assertTrue($sut->is_eligible('applepay'));
        $this->assertTrue($sut->is_available('applepay'));
    }

    public function testEligibleButNotAvailable(): void
    {
        $sut = $this->sut([
            'applepay.available' => false,
            'applepay.eligibility.check' => static fn(): bool => true,
        ]);

        $this->assertTrue($sut->is_eligible('applepay'));
        $this->assertFalse($sut->is_available('applepay'));
    }

    public function testBoolCheckIsWrappedInACallable(): void
    {
        $sut = $this->sut([
            'ppcp-local-apms.available' => true,
            'ppcp-local-apms.eligibility.check' => true,
            'ppcp-local-apms.pwc.eligibility.check' => false,
        ]);

        $this->assertIsCallable($sut->eligibility_check('ppcp-local-apms'));
        $this->assertTrue(($sut->eligibility_check('ppcp-local-apms'))());
        $this->assertFalse(($sut->eligibility_check('ppcp-local-apms.pwc'))());
    }

    public function testLoadedModuleWithoutCheckIsEligibleWhenAvailable(): void
    {
        $sut = $this->sut(['order-tracking.available' => true]);

        $this->assertTrue($sut->is_loaded('order-tracking'));
        $this->assertTrue($sut->is_eligible('order-tracking'));
        $this->assertTrue($sut->is_available('order-tracking'));
    }

    public function testAvailabilityCheckIsLazy(): void
    {
        $calls = 0;
        $sut = $this->sut([
            'googlepay.available' => true,
            'googlepay.eligibility.check' => static function () use (&$calls): bool {
                $calls++;
                return true;
            },
        ]);

        $check = $sut->availability_check('googlepay');
        $this->assertSame(0, $calls, 'Building the check must not run it');
        $this->assertTrue($check());
        $this->assertSame(1, $calls);
    }

    public function testEligibilityIsCheckedBeforeAvailability(): void
    {
        // availability_check must not read '.available' when the eligibility check fails:
        // the Apple Pay and Google Pay '.available' services call PayPal for the product status.
        $container = Mockery::mock(ContainerInterface::class);
        $container->shouldReceive('has')->with('googlepay.available')->andReturn(true);
        $container->shouldReceive('has')->with('googlepay.eligibility.check')->andReturn(true);
        $container->shouldReceive('get')->with('googlepay.eligibility.check')->andReturn(static fn(): bool => false);
        $container->shouldReceive('get')->with('googlepay.available')->never();

        $sut = new ModuleAvailability($container);

        $this->assertFalse(($sut->availability_check('googlepay'))());
    }
}
