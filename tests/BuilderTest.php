<?php declare(strict_types=1);

namespace WeChatPay\Tests;

use function class_implements;
use function class_uses;
use function is_array;
use function array_map;
use function iterator_to_array;
use function openssl_pkey_get_private;
use function openssl_pkey_get_public;
use function sprintf;
use function method_exists;

use ArrayAccess;
use WeChatPay\Builder;
use WeChatPay\BuilderChainable;
use WeChatPay\Formatter;
use PHPUnit\Framework\TestCase;

class BuilderTest extends TestCase
{
    const FIXTURES = __DIR__ . '/fixtures/mock.%s.%s';

    public function testConstractor()
    {
        if (method_exists($this, 'expectError')) {
            $this->expectError();
        }
        // for PHPUnit8+
        if (method_exists($this, 'expectExceptionMessageMatches')) {
            $this->expectExceptionMessageMatches('#^Call to private#');
        }
        // for PHPUnit7
        elseif (method_exists($this, 'expectExceptionMessageRegExp')) {
            $this->expectExceptionMessageRegExp('#^Call to private#');
        }
        new Builder(); /** @phpstan-ignore-line */
    }

    /**
     * @return array<string,array{string,\OpenSSLAsymmetricKey|resource|string|mixed,\OpenSSLAsymmetricKey|\OpenSSLCertificate|resource|string|mixed,string,string}>
     */
    public function configurationDataProvider(): array
    {
        $privateKey = openssl_pkey_get_private('file://' . sprintf(self::FIXTURES, 'pkcs8', 'key'));
        $publicKey  = openssl_pkey_get_public('file://' . sprintf(self::FIXTURES, 'spki', 'pem'));

        if (false === $privateKey || false === $publicKey) {
            throw new \Exception('Loading the pkey failed.');
        }

        return [
            'standard' => ['1230000109', $privateKey, $publicKey, Formatter::nonce(40), Formatter::nonce(40)],
        ];
    }

    /**
     * @dataProvider configurationDataProvider
     *
     * @param string $mchid
     * @param resource|mixed $privateKey
     * @param string|resource|mixed $publicKey
     * @param string $mchSerial
     * @param string $platSerial
     */
    public function testFactory(string $mchid, $privateKey, $publicKey, string $mchSerial, string $platSerial)
    {
        $instance = Builder::factory([
            'mchid' => $mchid,
            'serial' => $mchSerial,
            'privateKey' => $privateKey,
            'certs' => [$platSerial => $publicKey],
        ]);

        $map = class_implements($instance);

        self::assertIsArray($map);
        self::assertNotEmpty($map);

        self::assertArrayHasKey(BuilderChainable::class, $map);
        if (method_exists($this, 'assertContainsEquals')) {
            $this->assertContainsEquals(BuilderChainable::class, $map);
        }

        self::assertInstanceOf(ArrayAccess::class, $instance);
        self::assertInstanceOf(BuilderChainable::class, $instance);

        $traits = class_uses($instance);

        self::assertIsArray($traits);
        self::assertNotEmpty($traits);
        self::assertContains(\WeChatPay\BuilderTrait::class, $traits);

        self::assertInstanceOf(BuilderChainable::class, $instance->v3);
        /** @phpstan-ignore-next-line */
        self::assertInstanceOf(BuilderChainable::class, $instance->v3->pay->transcations->native);
        /** @phpstan-ignore-next-line */
        self::assertInstanceOf(BuilderChainable::class, $instance->v3->combineTransactions->{'{combine_out_trade_no}'});
        /** @phpstan-ignore-next-line */
        self::assertInstanceOf(BuilderChainable::class, $instance->v3->marketing->busifavor->users['{openid}/coupons/{coupon_code}']->appids['{appid}']);

        self::assertInstanceOf(BuilderChainable::class, $instance['v2/pay/micropay']);
        self::assertInstanceOf(BuilderChainable::class, $instance['v2/pay/refundquery']);

        self::assertInstanceOf(BuilderChainable::class, $instance->chain('what_ever_endpoints/with-anyDepths_segments/also/contains/{uri_template}/{blah}/blah/'));

        /** @phpstan-ignore-next-line */
        $copy = iterator_to_array($instance->v3->combineTransactions->{'{combine_out_trade_no}'});
        self::assertIsArray($copy);
        self::assertNotEmpty($copy);
        self::assertNotContains('combineTransactions', $copy);
        self::assertContains('combine-transactions', $copy);

        /** @phpstan-ignore-next-line */
        $copy = iterator_to_array($instance->v3->marketing->busifavor->users['{openid}']->coupons->{'{coupon_code}'}->appids->_appid_);
        self::assertIsArray($copy);
        self::assertNotEmpty($copy);
        self::assertNotContains('V3', $copy);
        self::assertContains('v3', $copy);
        self::assertNotContains('_appid_', $copy);
        self::assertContains('{appid}', $copy);

        $context = $this;
        array_map(static function($item) use($context) {
            static::assertIsString($item);
            if (method_exists($context, 'assertMatchesRegularExpression')) {
                $context->assertMatchesRegularExpression('#[^A-Z]#', $item);
            } else {
                static::assertRegExp('#[^A-Z]#', $item);
            }
        }, $copy);
    }
}
