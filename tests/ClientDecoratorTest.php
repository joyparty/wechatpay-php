<?php declare(strict_types=1);

namespace WeChatPay\Tests;

use function class_implements;
use function class_uses;
use function is_array;
use function strval;
use function abs;
use function json_encode;
use function explode;
use function array_reduce;
use function array_map;
use function trim;
use function openssl_pkey_get_private;
use function openssl_pkey_get_public;
use function sprintf;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use ReflectionClass;
use ReflectionMethod;

use WeChatPay\Formatter;
use WeChatPay\Crypto\Rsa;
use WeChatPay\ClientDecorator;
use WeChatPay\ClientDecoratorInterface;
use WeChatPay\Exception\InvalidArgumentException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\RequestInterface;
use PHPUnit\Framework\TestCase;

class ClientDecoratorTest extends TestCase
{
    const FIXTURES = __DIR__ . '/fixtures/mock.%s.%s';

    /** @var int - The maximum clock offset in second */
    const MAXIMUM_CLOCK_OFFSET = 300;

    public function testImplementsClientDecoratorInterface()
    {
        $map = class_implements(ClientDecorator::class);

        self::assertIsArray($map);
        self::assertNotEmpty($map);
        self::assertArrayHasKey(ClientDecoratorInterface::class, $map);
        if (method_exists($this, 'assertContainsEquals')) {
            $this->assertContainsEquals(ClientDecoratorInterface::class, $map);
        }
    }

    public function testClassUsesTraits()
    {
        $traits = class_uses(ClientDecorator::class);

        self::assertIsArray($traits);
        self::assertNotEmpty($traits);
        self::assertContains(\WeChatPay\ClientJsonTrait::class, $traits);
        self::assertContains(\WeChatPay\ClientXmlTrait::class, $traits);
    }

    public function testClassConstants()
    {
        self::assertIsString(ClientDecorator::VERSION);
        self::assertIsString(ClientDecorator::XML_BASED);
        self::assertIsString(ClientDecorator::JSON_BASED);
    }

    public function testByReflectionClass()
    {
        $ref = new ReflectionClass(ClientDecorator::class);
        self::assertInstanceOf(ReflectionClass::class, $ref);

        $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);
        self::assertIsArray($methods);

        self::assertTrue($ref->isFinal());
        self::assertTrue($ref->hasMethod('select'));
        self::assertTrue($ref->hasMethod('request'));
        self::assertTrue($ref->hasMethod('requestAsync'));

        $traits = $ref->getTraitNames();
        self::assertIsArray($traits);
        self::assertContains(\WeChatPay\ClientJsonTrait::class, $traits);
        self::assertContains(\WeChatPay\ClientXmlTrait::class, $traits);
    }

    /**
     * @return array<string,array{array<string,mixed>,string}>
     */
    public function constructorExceptionsProvider(): array
    {
        return [
            'none args passed' => [
                [],
                '#`mchid` is required#',
            ],
            'only `mchid` passed' => [
                ['mchid' => '1230000109',],
                '#`serial` is required#',
            ],
            '`mchid` and `serial` passed' => [
                ['mchid' => '1230000109', 'serial' => 'MCH123SERIAL',],
                '#`privateKey` is required#',
            ],
            '`mchid`, `serial` and `priviateKey` in' => [
                ['mchid' => '1230000109', 'serial' => 'MCH123SERIAL', 'privateKey' => '------ BEGIN PRIVATE ------',],
                '#`certs` is required#',
            ],
            '`mchid`, `serial`, `priviateKey` and bad `certs` in' => [
                ['mchid' => '1230000109', 'serial' => 'MCH123SERIAL', 'privateKey' => '------ BEGIN PRIVATE ------', 'certs' => ['MCH123SERIAL' => '']],
                '#the merchant\'s certificate serial number\(MCH123SERIAL\) which is not allowed here#',
            ],
        ];
    }

    /**
     * @dataProvider constructorExceptionsProvider
     * @param array<string,mixed> $config
     * @param string $pattern
     */
    public function testConstructorExceptions(array $config, string $pattern)
    {
        $this->expectException(InvalidArgumentException::class);
        // for PHPUnit8+
        if (method_exists($this, 'expectExceptionMessageMatches')) {
            $this->expectExceptionMessageMatches($pattern);
        }
        // for PHPUnit7
        elseif (method_exists($this, 'expectExceptionMessageRegExp')) {
            $this->expectExceptionMessageRegExp($pattern);
        }
        new ClientDecorator($config);
    }

    /** @var MockHandler $mock */
    private $mock;

    private function guzzleMockStack(): HandlerStack
    {
        $this->mock = new MockHandler();

        return HandlerStack::create($this->mock);
    }

    /**
     * @return array<string,array{array<string,mixed>}>
     */
    public function constructorSuccessProvider(): array
    {
        return [
            'default' => [
                [
                    'mchid' => '1230000109', 'serial' => 'MCH123SERIAL', 'privateKey' => '------ BEGIN PRIVATE ------', 'certs' => ['PLAT123SERIAL' => ''],
                ],
            ],
            'with base_uri' => [
                [
                    'mchid' => '1230000109', 'serial' => 'MCH123SERIAL', 'privateKey' => '------ BEGIN PRIVATE ------', 'certs' => ['PLAT123SERIAL' => ''],
                    'base_uri' => 'https://api.mch.weixin.qq.com/hk/',
                ],
            ],
            'with base_uri and handler' => [
                [
                    'mchid' => '1230000109', 'serial' => 'MCH123SERIAL', 'privateKey' => '------ BEGIN PRIVATE ------', 'certs' => ['PLAT123SERIAL' => ''],
                    'base_uri' => 'https://apius.mch.weixin.qq.com/v3/sandbox/',
                    'handler' => $this->guzzleMockStack(),
                ],
            ],
        ];
    }

    /**
     * @dataProvider constructorSuccessProvider
     *
     * @param array<string,mixed> $config
     */
    public function testSelect(array $config)
    {
        $instance = new ClientDecorator($config);

        self::assertInstanceOf(ClientDecoratorInterface::class, $instance);

        $client = $instance->select(ClientDecoratorInterface::JSON_BASED);
        self::assertInstanceOf(\GuzzleHttp\Client::class, $client);

        /** @var array<string,mixed> $settings */
        $settings = $client->getConfig(); // TODO: refactor while Guzzle8 dropped this API
        self::assertIsArray($settings);
        self::assertArrayHasKey('handler', $settings);
        /** @var HandlerStack $stack */
        ['handler' => $stack] = $settings;
        self::assertInstanceOf(HandlerStack::class, $stack);

        self::assertArrayHasKey('base_uri', $settings);
        /** @var \GuzzleHttp\Psr7\Uri $baseUri */
        ['base_uri' => $baseUri] = $settings;
        self::assertEquals($config['base_uri'] ?? 'https://api.mch.weixin.qq.com/', (string)$baseUri);

        self::assertArrayHasKey('headers', $settings);
        /** @var array<string,mixed> $headers */
        ['headers' => $headers] = $settings;
        self::assertIsArray($headers);
        self::assertArrayHasKey('Content-Type', $headers);
        self::assertArrayHasKey('Accept', $headers);
        /**
         * @var string $accept
         * @var string $contentType
         */
        ['Accept' => $accept, 'Content-Type' => $contentType] = $headers;
        self::assertStringStartsWith('application/json, text/plain, application/x-gzip', $accept);
        self::assertEquals('application/json; charset=utf-8', $contentType);

        $stackDebugInfo = strval($stack);
        self::assertStringContainsString('verifier', $stackDebugInfo);
        self::assertStringContainsString('signer', $stackDebugInfo);
        self::assertStringNotContainsString('transform_request', $stackDebugInfo);
        self::assertStringNotContainsString('transform_response', $stackDebugInfo);

        $client = $instance->select(ClientDecoratorInterface::XML_BASED);
        self::assertInstanceOf(\GuzzleHttp\Client::class, $client);

        /** @var array<string,mixed> $settings */
        $settings = $client->getConfig(); // TODO: refactor while Guzzle8 dropped this API
        self::assertIsArray($settings);

        self::assertArrayHasKey('handler', $settings);
        /** @var HandlerStack $stack */
        ['handler' => $stack] = $settings;
        self::assertInstanceOf(HandlerStack::class, $stack);

        /** @var \GuzzleHttp\Psr7\Uri $baseUri */
        ['base_uri' => $baseUri] = $settings;
        self::assertEquals($config['base_uri'] ?? 'https://api.mch.weixin.qq.com/', (string)$baseUri);

        self::assertArrayHasKey('headers', $settings);
        /** @var array<string,mixed> $headers */
        ['headers' => $headers] = $settings;
        self::assertIsArray($headers);
        self::assertArrayHasKey('Content-Type', $headers);
        self::assertArrayHasKey('Accept', $headers);
        /**
         * @var string $accept
         * @var string $contentType
         */
        ['Accept' => $accept, 'Content-Type' => $contentType] = $headers;
        self::assertEquals('text/xml, text/plain, application/x-gzip', $accept);
        self::assertEquals('text/xml; charset=utf-8', $contentType);

        $stackDebugInfo = strval($stack);
        self::assertStringNotContainsString('verifier', $stackDebugInfo);
        self::assertStringNotContainsString('signer', $stackDebugInfo);
        self::assertStringContainsString('transform_request', $stackDebugInfo);
        self::assertStringContainsString('transform_response', $stackDebugInfo);
    }

    /**
     * @return array{string,\OpenSSLAsymmetricKey|resource|string|mixed,\OpenSSLAsymmetricKey|\OpenSSLCertificate|resource|string|mixed,string,string}
     */
    private function mockConfiguration(): array
    {
        $privateKey = openssl_pkey_get_private('file://' . sprintf(self::FIXTURES, 'pkcs8', 'key'));
        $publicKey  = openssl_pkey_get_public('file://' . sprintf(self::FIXTURES, 'sha256', 'crt'));

        if (false === $privateKey || false === $publicKey) {
            throw new \Exception('Loading the pkey failed.');
        }

        return ['1230000109', $privateKey, $publicKey, Formatter::nonce(40), Formatter::nonce(40)];
    }

    /**
     * @return array<string,array{string,resource|mixed,string|resource|mixed,string,string,object|mixed,string,string,string}>
     */
    public function withMockHandlerProvider(): array
    {
        [$mchid, $privateKey, $publicKey, $mchSerial, $platSerial] = $this->mockConfiguration();

        return [
            'HTTP 400 STATUS' => [
                $mchid, $privateKey, $publicKey, $mchSerial, $platSerial,
                new Response(400), ClientException::class, 'GET', '/',
            ],
            'HTTP 401 STATUS' => [
                $mchid, $privateKey, $publicKey, $mchSerial, $platSerial,
                new Response(401), ClientException::class, 'POST', '/next/aipay',
            ],
            'HTTP 403 STATUS' => [
                $mchid, $privateKey, $publicKey, $mchSerial, $platSerial,
                new Response(403), ClientException::class, 'PUT', 'app/done',
            ],
            'HTTP 404 STATUS' => [
                $mchid, $privateKey, $publicKey, $mchSerial, $platSerial,
                new Response(404), ClientException::class, 'DELETE', 'secapi/micro',
            ],
            'HTTP 429 STATUS' => [
                $mchid, $privateKey, $publicKey, $mchSerial, $platSerial,
                new Response(429), ClientException::class, 'PATCH', 'sandboxnew',
            ],
            'HTTP 500 STATUS' => [
                $mchid, $privateKey, $publicKey, $mchSerial, $platSerial,
                new Response(500), ServerException::class, 'POST', 'v3/sandbox',
            ],
            'HTTP 502 STATUS' => [
                $mchid, $privateKey, $publicKey, $mchSerial, $platSerial,
                new Response(502), ServerException::class, 'PATCH', 'facepay',
            ],
            'HTTP 503 STATUS' => [
                $mchid, $privateKey, $publicKey, $mchSerial, $platSerial,
                new Response(503), ServerException::class, 'PUT', 'frog-over-miniprogram/pay',
            ],
            'HTTP 200 STATUS without mandatory headers' => [
                $mchid, $privateKey, $publicKey, $mchSerial, $platSerial,
                new Response(200), RequestException::class, 'GET', 'v3/pay/transcations',
            ],
            'HTTP 200 STATUS with bad clock offset(in the server late)' => [
                $mchid, $privateKey, $publicKey, $mchSerial, $platSerial,
                new Response(200, [
                    'Wechatpay-Nonce' => Formatter::nonce(),
                    'Wechatpay-Serial' => $platSerial,
                    'Wechatpay-Timestamp' => strval(Formatter::timestamp() - 60 * 6),
                    'Wechatpay-Signature' => Formatter::nonce(200),
                ]), RequestException::class, 'DELETE', 'v3/pay/transcations',
            ],
            'HTTP 200 STATUS with bad clock offset(in the server ahead)' => [
                $mchid, $privateKey, $publicKey, $mchSerial, $platSerial,
                new Response(200, [
                    'Wechatpay-Nonce' => Formatter::nonce(),
                    'Wechatpay-Serial' => $platSerial,
                    'Wechatpay-Timestamp' => strval(Formatter::timestamp() + 60 * 6),
                    'Wechatpay-Signature' => Formatter::nonce(200),
                ]), RequestException::class, 'PUT', 'v3/pay/transcations',
            ],
            'HTTP 200 STATUS with unreachable platform certificate serial number' => [
                $mchid, $privateKey, $publicKey, $mchSerial, $platSerial,
                new Response(200, [
                    'Wechatpay-Nonce' => Formatter::nonce(),
                    'Wechatpay-Serial' => Formatter::nonce(40),
                    'Wechatpay-Timestamp' => strval(Formatter::timestamp()),
                    'Wechatpay-Signature' => Formatter::nonce(200),
                ]), RequestException::class, 'PATCH', 'v3/pay/transcations',
            ],
            'HTTP 200 STATUS with bad digest signature' => [
                $mchid, $privateKey, $publicKey, $mchSerial, $platSerial,
                new Response(200, [
                    'Wechatpay-Nonce' => Formatter::nonce(),
                    'Wechatpay-Serial' => $platSerial,
                    'Wechatpay-Timestamp' => strval(Formatter::timestamp()),
                    'Wechatpay-Signature' => Formatter::nonce(200),
                ]), RequestException::class, 'POST', 'v3/pay/transcations',
            ],
        ];
    }

    /**
     * @dataProvider withMockHandlerProvider
     *
     * @param string $mchid
     * @param resource|mixed $privateKey
     * @param string|resource|mixed $publicKey
     * @param string $mchSerial
     * @param string $platSerial
     * @param ResponseInterface $response
     * @param class-string<\Exception> $expectedGuzzleException
     * @param string $method
     * @param string $uri
     */
    public function testRequestsWithMockHandler(
        string $mchid, $privateKey, $publicKey, string $mchSerial, string $platSerial,
        ResponseInterface $response, string $expectedGuzzleException, string $method, string $uri)
    {
        $instance = new ClientDecorator([
            'mchid' => $mchid,
            'serial' => $mchSerial,
            'privateKey' => $privateKey,
            'certs' => [$platSerial => $publicKey],
            'handler' => $this->guzzleMockStack(),
        ]);

        $this->mock->reset();
        $this->mock->append($response);
        $this->expectException($expectedGuzzleException);

        $instance->request($method, $uri);
    }

    /**
     * @dataProvider withMockHandlerProvider
     *
     * @param string $mchid
     * @param resource|mixed $privateKey
     * @param string|resource|mixed $publicKey
     * @param string $mchSerial
     * @param string $platSerial
     * @param ResponseInterface $response
     * @param class-string<\Exception> $expectedGuzzleException
     * @param string $method
     * @param string $uri
     */
    public function testAsyncRequestsWithMockHandler(
        string $mchid, $privateKey, $publicKey, string $mchSerial, string $platSerial,
        ResponseInterface $response, string $expectedGuzzleException, string $method, string $uri)
    {
        $instance = new ClientDecorator([
            'mchid' => $mchid,
            'serial' => $mchSerial,
            'privateKey' => $privateKey,
            'certs' => [$platSerial => $publicKey],
            'handler' => $this->guzzleMockStack(),
        ]);

        $mock = $this->mock;
        $mock->reset();
        $mock->append($response);

        $instance->requestAsync($method, $uri)->otherwise(static function($actual) use ($expectedGuzzleException, $mock, $method, $uri) {
            /** @var \GuzzleHttp\Psr7\Request $req */
            $req = $mock->getLastRequest();
            static::assertInstanceOf(\GuzzleHttp\Psr7\Request::class, $req);
            static::assertEquals($method, $req->getMethod());
            if (static::stringStartsWith('/')->evaluate($uri, '', true)) {
                static::assertEquals($uri, $req->getRequestTarget());
            }
            static::assertStringEndsWith($uri, $req->getRequestTarget());
            static::assertInstanceOf($expectedGuzzleException, $actual);
        })->wait();
    }

    /**
     * @return array<string,string>
     */
    private static function parseAuthorization(string $value): array
    {
        [$type, $credentials] = explode(' ', $value, 2);

        return array_reduce(array_map(static function($item) {
            [$key, $value] = explode('=', $item, 2);
            return [$key => trim($value, '"')];
        }, explode(',', $credentials)), static function(array $carry, array $item) {
            return $carry + $item;
        }, ['type' => $type]);
    }

    /**
     * @param RequestInterface $request
     * @param string $mchid
     * @param string $mchSerial
     * @param \OpenSSLAsymmetricKey|\OpenSSLCertificate|resource|string|mixed $publicKey
     */
    private static function verification(RequestInterface $request, string $mchid, string $mchSerial, $publicKey)
    {
        static::assertTrue($request->hasHeader('Authorization'));

        [$authorization] = $request->getHeader('Authorization');

        static::assertIsString($authorization);
        $rules = self::parseAuthorization($authorization);

        static::assertIsArray($rules);
        static::assertNotEmpty($rules);
        static::assertArrayHasKey('type', $rules);
        static::assertArrayHasKey('mchid', $rules);
        static::assertArrayHasKey('nonce_str', $rules);
        static::assertArrayHasKey('timestamp', $rules);
        static::assertArrayHasKey('signature', $rules);
        static::assertArrayHasKey('serial_no', $rules);

        ['type' => $type] = $rules;
        static::assertEquals('WECHATPAY2-SHA256-RSA2048', $type);

        ['mchid' => $mchId, 'nonce_str' => $nonceStr, 'timestamp' => $timestamp, 'serial_no' => $serialNo, 'signature' => $signature] = $rules;
        static::assertEquals($mchId, $mchid);
        static::assertEquals($serialNo, $mchSerial);
        static::assertFalse(abs(Formatter::timestamp() - intval($timestamp)) > self::MAXIMUM_CLOCK_OFFSET);
        static::assertTrue(Rsa::verify(Formatter::request(
            $request->getMethod(),
            $request->getRequestTarget(),
            $timestamp,
            $nonceStr,
            (string) $request->getBody()
        ), $signature, $publicKey));
    }

    /**
     * @param int $status
     * @param string $serial
     * @param string $body
     * @param \OpenSSLAsymmetricKey|resource|string|mixed $privateKey
     */
    private static function pickResponse(int $status, string $serial, string $body, $privateKey): ResponseInterface
    {
        return new Response($status, [
            'Wechatpay-Nonce' => $nonce = Formatter::nonce(),
            'Wechatpay-Serial' => $serial,
            'Wechatpay-Timestamp' => $timestamp = strval(Formatter::timestamp()),
            'Wechatpay-Signature' => Rsa::sign(Formatter::response($timestamp, $nonce, $body), $privateKey),
        ], $body);
    }

    /**
     * @return array<string,array{string,resource|mixed,string|resource|mixed,string,string,string,string,string,callable}>
     */
    public function normalRequestsDataProvider(): array
    {
        [$mchid, $privateKey, $publicKey, $mchSerial, $platSerial] = $this->mockConfiguration();

        return [
            'HTTP 204 STATUS' => [
                $mchid, $privateKey, $publicKey, $mchSerial, $platSerial,
                'PATCH', 'v3/pay/transcations',
                $body = '',
                static function(RequestInterface $request) use ($privateKey, $platSerial, $body, $mchid, $mchSerial, $publicKey): ResponseInterface {
                    self::verification($request, $mchid, $mchSerial, $publicKey);

                    return self::pickResponse(204, $platSerial, $body, $privateKey);
                },
            ],
            'HTTP 202 STATUS' => [
                $mchid, $privateKey, $publicKey, $mchSerial, $platSerial,
                'PUT', 'v3/pay/transcations',
                $body = '',
                static function(RequestInterface $request) use ($privateKey, $platSerial, $body, $mchid, $mchSerial, $publicKey): ResponseInterface {
                    self::verification($request, $mchid, $mchSerial, $publicKey);

                    return self::pickResponse(202, $platSerial, $body, $privateKey);
                },
            ],
            'HTTP 200 STATUS' => [
                $mchid, $privateKey, $publicKey, $mchSerial, $platSerial,
                'POST', 'v3/pay/transcations',
                $body = (string)json_encode(['code_url' => 'weixin://wxpay/bizpayurl?pr=qnu8GBtzz'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                static function(RequestInterface $request) use ($privateKey, $platSerial, $body, $mchid, $mchSerial, $publicKey): ResponseInterface {
                    self::verification($request, $mchid, $mchSerial, $publicKey);

                    return self::pickResponse(200, $platSerial, $body, $privateKey);
                },
            ],
        ];
    }

    /**
     * @dataProvider normalRequestsDataProvider
     *
     * @param string $mchid
     * @param resource|mixed $privateKey
     * @param string|resource|mixed $publicKey
     * @param string $mchSerial
     * @param string $platSerial
     * @param string $expected
     * @param string $method
     * @param string $uri
     * @param callable $respondor
     */
    public function testRequest(
        string $mchid, $privateKey, $publicKey, string $mchSerial, string $platSerial,
        string $method, string $uri, string $expected, callable $respondor)
    {
        $instance = new ClientDecorator([
            'mchid' => $mchid,
            'serial' => $mchSerial,
            'privateKey' => $privateKey,
            'certs' => [$platSerial => $publicKey],
            'handler' => $this->guzzleMockStack(),
        ]);

        $this->mock->reset();
        $this->mock->append($respondor);

        self::assertEquals($expected, (string) $instance->request($method, $uri)->getBody());
    }

    /**
     * @dataProvider normalRequestsDataProvider
     *
     * @param string $mchid
     * @param resource|mixed $privateKey
     * @param string|resource|mixed $publicKey
     * @param string $mchSerial
     * @param string $platSerial
     * @param string $expected
     * @param string $method
     * @param string $uri
     * @param callable $respondor
     */
    public function testRequestAsync(
        string $mchid, $privateKey, $publicKey, string $mchSerial, string $platSerial,
        string $method, string $uri, string $expected, callable $respondor)
    {
        $instance = new ClientDecorator([
            'mchid' => $mchid,
            'serial' => $mchSerial,
            'privateKey' => $privateKey,
            'certs' => [$platSerial => $publicKey],
            'handler' => $this->guzzleMockStack(),
        ]);

        $this->mock->reset();
        $this->mock->append($respondor);

        $instance->requestAsync($method, $uri)->then(static function(ResponseInterface $response) use($expected) {
            static::assertEquals($expected, (string) $response->getBody());
        })->wait();
    }
}
