<?php
declare(strict_types=1);

namespace WooCommerce\PayPalCommerce;

/**
 * Every constant the main file defines must be guarded with ! defined(), so a
 * host that already defined it (or a second copy of the plugin loaded in the
 * same request) does not trigger "Constant already defined" warnings.
 */
class MainFileConstantsGuardedTest extends TestCase
{
    public function testEveryDefineIsGuarded(): void
    {
        $source = (string) file_get_contents(ROOT_DIR . '/woocommerce-paypal-payments.php');
        preg_match_all("/^(.*)define\\( '([A-Z_]+)'/m", $source, $matches, PREG_SET_ORDER);

        $this->assertNotEmpty($matches, 'The main file must define constants');
        foreach ($matches as $match) {
            $this->assertStringContainsString(
                "! defined( '{$match[2]}' ) && ",
                $match[1] . "define( '{$match[2]}'",
                "{$match[2]} must be guarded with ! defined()"
            );
        }
    }
}
