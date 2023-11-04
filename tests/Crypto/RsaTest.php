<?php declare(strict_types=1);

namespace WeChatPay\Tests\Crypto;

use const PHP_MAJOR_VERSION;
use const OPENSSL_PKCS1_OAEP_PADDING;
use const OPENSSL_PKCS1_PADDING;

use function file_get_contents;
use function method_exists;
use function openssl_pkey_get_private;
use function openssl_pkey_get_public;
use function openssl_x509_read;
use function preg_match;
use function random_bytes;
use function sprintf;
use function str_replace;
use function substr;
use function rtrim;

use UnexpectedValueException;

use WeChatPay\Crypto\Rsa;
use PHPUnit\Framework\TestCase;

class RsaTest extends TestCase
{
    const BASE64_EXPRESSION = '#^[a-zA-Z0-9\+/]+={0,2}$#';

    const FIXTURES = __DIR__ . '/../fixtures/mock.%s.%s';

    const EVELOPE = '#-{5}BEGIN[^-]+-{5}\r?\n(?<base64>[^-]+)\r?\n-{5}END[^-]+-{5}#';


    public function testClassConstants()
    {
        self::assertIsString(Rsa::KEY_TYPE_PRIVATE);
        self::assertIsString(Rsa::KEY_TYPE_PUBLIC);
    }

    /**
     * @param string $type
     * @param string $suffix
     */
    private function getMockContents(string $type, string $suffix): string
    {
        $file = sprintf(self::FIXTURES, $type, $suffix);
        $pkey = file_get_contents($file);

        preg_match(self::EVELOPE, $pkey ?: '', $matches);

        return str_replace(["\r", "\n"], '', $matches['base64'] ?: '');
    }

    public function testFromPkcs8()
    {
        $thing = $this->getMockContents('pkcs8', 'key');

        self::assertIsString($thing);
        if (method_exists($this, 'assertMatchesRegularExpression')) {
            $this->assertMatchesRegularExpression(self::BASE64_EXPRESSION, $thing);
        } else {
            self::assertRegExp(self::BASE64_EXPRESSION, $thing);
        }

        $pkey = Rsa::fromPkcs8($thing);

        if (8 === PHP_MAJOR_VERSION) {
            self::assertIsObject($pkey);
        } else {
            self::assertIsResource($pkey);
        }
    }

    public function testPkcs1ToSpki()
    {
        /**
         * @var string $spki
         * @var string $pkcs1
         */
        list(, , list($spki), list($pkcs1)) = array_values($this->keyPhrasesDataProvider());

        self::assertStringStartsWith('public.spki://', $spki);
        self::assertStringStartsWith('public.pkcs1://', $pkcs1);
        self::assertEquals(substr($spki, 14), Rsa::pkcs1ToSpki(substr($pkcs1, 15)));
    }

    /**
     * @return array<string,array{string,string}>
     */
    public function pkcs1PhrasesDataProvider(): array
    {
        return [
            '`private.pkcs1://`' => [$this->getMockContents('pkcs1', 'key'), Rsa::KEY_TYPE_PRIVATE],
            '`public.pkcs1://`'  => [$this->getMockContents('pkcs1', 'pem'), Rsa::KEY_TYPE_PUBLIC],
        ];
    }

    /**
     * @dataProvider pkcs1PhrasesDataProvider
     *
     * @param string $thing
     */
    public function testFromPkcs1(string $thing, string $type)
    {
        if (method_exists($this, 'assertMatchesRegularExpression')) {
            $this->assertMatchesRegularExpression(self::BASE64_EXPRESSION, $thing);
        } else {
            self::assertRegExp(self::BASE64_EXPRESSION, $thing);
        }

        $pkey = Rsa::fromPkcs1($thing, $type);

        if (8 === PHP_MAJOR_VERSION) {
            self::assertIsObject($pkey);
        } else {
            self::assertIsResource($pkey);
        }
    }

    public function testFromSpki()
    {
        $thing = $this->getMockContents('spki', 'pem');

        self::assertIsString($thing);
        if (method_exists($this, 'assertMatchesRegularExpression')) {
            $this->assertMatchesRegularExpression(self::BASE64_EXPRESSION, $thing);
        } else {
            self::assertRegExp(self::BASE64_EXPRESSION, $thing);
        }

        $pkey = Rsa::fromSpki($thing);

        if (8 === PHP_MAJOR_VERSION) {
            self::assertIsObject($pkey);
        } else {
            self::assertIsResource($pkey);
        }
    }

    /**
     * @return array<string,array{\OpenSSLAsymmetricKey|resource|string|mixed,string}>
     */
    public function keyPhrasesDataProvider(): array
    {
        return [
            '`private.pkcs1://` string'               => ['private.pkcs1://' . $this->getMockContents('pkcs1', 'key'),          Rsa::KEY_TYPE_PRIVATE],
            '`private.pkcs8://` string'               => ['private.pkcs8://' . $this->getMockContents('pkcs8', 'key'),          Rsa::KEY_TYPE_PRIVATE],
            '`public.spki://` string'                 => ['public.spki://' . $this->getMockContents('spki', 'pem'),             Rsa::KEY_TYPE_PUBLIC],
            '`public.pkcs1://` string'                => ['public.pkcs1://' . $this->getMockContents('pkcs1', 'pem'),           Rsa::KEY_TYPE_PUBLIC],
            '`file://` PKCS#1 privateKey path string' => [$f = 'file://' . sprintf(self::FIXTURES, 'pkcs1', 'key'),             Rsa::KEY_TYPE_PRIVATE],
            'OpenSSLAsymmetricKey/resource(private)1' => [openssl_pkey_get_private($f),                                         Rsa::KEY_TYPE_PRIVATE],
            'PKCS#1 privateKey contents'              => [$f = (string)file_get_contents($f),                                   Rsa::KEY_TYPE_PRIVATE],
            'OpenSSLAsymmetricKey/resource(private)2' => [openssl_pkey_get_private($f),                                         Rsa::KEY_TYPE_PRIVATE],
            '`file://` PKCS#8 privateKey path string' => [$f = 'file://' . sprintf(self::FIXTURES, 'pkcs8', 'key'),             Rsa::KEY_TYPE_PRIVATE],
            'OpenSSLAsymmetricKey/resource(private)3' => [openssl_pkey_get_private($f),                                         Rsa::KEY_TYPE_PRIVATE],
            'PKCS#8 privateKey contents'              => [$f = (string)file_get_contents($f),                                   Rsa::KEY_TYPE_PRIVATE],
            'OpenSSLAsymmetricKey/resource(private)4' => [openssl_pkey_get_private($f),                                         Rsa::KEY_TYPE_PRIVATE],
            '`file://` SPKI publicKey path string'    => [$f = 'file://' . sprintf(self::FIXTURES, 'spki', 'pem'),              Rsa::KEY_TYPE_PUBLIC],
            'OpenSSLAsymmetricKey/resource(public)1'  => [openssl_pkey_get_public($f),                                          Rsa::KEY_TYPE_PUBLIC],
            'SKPI publicKey contents'                 => [$f = (string)file_get_contents($f),                                   Rsa::KEY_TYPE_PUBLIC],
            'OpenSSLAsymmetricKey/resource(public)2'  => [openssl_pkey_get_public($f),                                          Rsa::KEY_TYPE_PUBLIC],
            'pkcs1 publicKey contents'                => [(string)file_get_contents(sprintf(self::FIXTURES, 'pkcs1', 'pem')),   Rsa::KEY_TYPE_PUBLIC],
            '`file://` x509 certificate string'       => [$f = 'file://' . sprintf(self::FIXTURES, 'sha256', 'crt'),            Rsa::KEY_TYPE_PUBLIC],
            'x509 certificate contents string'        => [$f = (string)file_get_contents($f),                                   Rsa::KEY_TYPE_PUBLIC],
            'OpenSSLCertificate/resource'             => [openssl_x509_read($f),                                                Rsa::KEY_TYPE_PUBLIC],
            '`file://` PKCS#8 encrypted privateKey'   => [
                [
                    $f = 'file://' . sprintf(self::FIXTURES, 'encrypted.pkcs8', 'key'),
                    $w = rtrim((string)file_get_contents(sprintf(self::FIXTURES, 'pwd', 'txt')))
                ],
                Rsa::KEY_TYPE_PRIVATE
            ],
            'PKCS#8 encrypted privateKey contents'   => [[(string)file_get_contents($f), $w], Rsa::KEY_TYPE_PRIVATE],
        ];
    }

    /**
     * @dataProvider keyPhrasesDataProvider
     *
     * @param \OpenSSLAsymmetricKey|resource|string|mixed $thing
     */
    public function testFrom($thing, string $type)
    {
        $pkey = Rsa::from($thing, $type);

        if (8 === PHP_MAJOR_VERSION) {
            self::assertIsObject($pkey);
        } else {
            self::assertIsResource($pkey);
        }
    }

    /**
     * @return array<string,array{string,string|\OpenSSLAsymmetricKey|resource|mixed,string|\OpenSSLAsymmetricKey|resource|mixed}>
     */
    public function keysProvider(): array
    {
        /**
         * @var string $pub1
         * @var string $pub2
         * @var string $pri1
         * @var string $pri2
         */
        list(
            list($pri1), list($pri2),
            list($pub1), list($pub2),
            list($pri3), list($pri4), list($pri5), list($pri6),
            list($pri7), list($pri8), list($pri9), list($pri0),
            list($pub3), list($pub4), list($pub5), list($pub6),
            list($pub7), list($crt1), list($crt2), list($crt3),
            list($encryptedKey1), list($encryptedKey2)
        ) = array_values($this->keyPhrasesDataProvider());

        $keys = [
            'plaintext, `public.spki://`, `private.pkcs1://`'        => [random_bytes( 8), Rsa::fromSpki(substr($pub1, 14)), Rsa::fromPkcs1(substr($pri1, 16))],
            'plaintext, `public.spki://`, `private.pkcs8://`'        => [random_bytes(16), Rsa::fromSpki(substr($pub1, 14)), Rsa::fromPkcs8(substr($pri2, 16))],
            'plaintext, `public.pkcs1://`, `private.pkcs1://`'       => [random_bytes(24), Rsa::fromPkcs1(substr($pub2, 15), Rsa::KEY_TYPE_PUBLIC), Rsa::fromPkcs1(substr($pri1, 16))],
            'plaintext, `public.pkcs1://`, `private.pkcs8://`'       => [random_bytes(32), Rsa::fromPkcs1(substr($pub2, 15), Rsa::KEY_TYPE_PUBLIC), Rsa::fromPkcs8(substr($pri2, 16))],
            'plaintext, `pkcs#1 pubkey content`, `private.pkcs1://`' => [random_bytes(40), Rsa::from($pub7, Rsa::KEY_TYPE_PUBLIC), Rsa::fromPkcs1(substr($pri1, 16))],
            'plaintext, `pkcs#1 pubkey content`, `private.pkcs8://`' => [random_bytes(48), Rsa::from($pub7, Rsa::KEY_TYPE_PUBLIC), Rsa::fromPkcs8(substr($pri2, 16))],
            'txt, `SPKI file://pubkey`, [`file://`,``] privateKey'   => [random_bytes(64), $pub3, [$pri3, '']],
            'txt, `SPKI pubkey content`, [`contents`,``] privateKey' => [random_bytes(72), $pub5, [$pri5, '']],
            'str, `SPKI file://pubkey`, [`file://`privateKey, pwd]'  => [random_bytes(64), $pub3, $encryptedKey1],
            'str, `SPKI pubkey content`, [`encrypted contents`,pwd]' => [random_bytes(72), $pub5, $encryptedKey2],
        ];

        foreach ([$pub3, $pub4, $pub5, $pub6, $crt1, $crt2, $crt3] as $pubIndex => $pub) {
            foreach ([$pri1, $pri2, $pri3, $pri4, $pri5, $pri6, $pri7, $pri8, $pri9, $pri0] as $priIndex => $pri) {
                $keys["plaintext, publicKey{$pubIndex}, privateKey{$priIndex}"] = [random_bytes(56), Rsa::from($pub, Rsa::KEY_TYPE_PUBLIC), Rsa::from($pri, Rsa::KEY_TYPE_PRIVATE)];
            }
        }

        return $keys;
    }

    /**
     * @dataProvider keysProvider
     * @param string $plaintext
     * @param object|resource|mixed $publicKey
     */
    public function testEncrypt(string $plaintext, $publicKey)
    {
        $ciphertext = Rsa::encrypt($plaintext, $publicKey);
        self::assertIsString($ciphertext);
        self::assertNotEquals($plaintext, $ciphertext);

        if (method_exists($this, 'assertMatchesRegularExpression')) {
            $this->assertMatchesRegularExpression(self::BASE64_EXPRESSION, $ciphertext);
        } else {
            self::assertRegExp(self::BASE64_EXPRESSION, $ciphertext);
        }
    }

    /**
     * @dataProvider keysProvider
     * @param string $plaintext
     * @param object|resource|mixed $publicKey
     * @param object|resource|mixed $privateKey
     */
    public function testDecrypt(string $plaintext, $publicKey, $privateKey)
    {
        $ciphertext = Rsa::encrypt($plaintext, $publicKey);
        self::assertIsString($ciphertext);
        self::assertNotEquals($plaintext, $ciphertext);

        if (method_exists($this, 'assertMatchesRegularExpression')) {
            $this->assertMatchesRegularExpression(self::BASE64_EXPRESSION, $ciphertext);
        } else {
            self::assertRegExp(self::BASE64_EXPRESSION, $ciphertext);
        }

        $mytext = Rsa::decrypt($ciphertext, $privateKey);
        self::assertIsString($mytext);
        self::assertEquals($plaintext, $mytext);
    }

    /**
     * @return array<string,array{string,array{\OpenSSLAsymmetricKey|resource|mixed,int},array{\OpenSSLAsymmetricKey|resource|mixed,int},?class-string<\UnexpectedValueException>}>
     */
    public function crossPaddingPhrasesProvider(): array
    {
        list(, , , , , , , $privateKeys, , , , , , $publicKeys) = array_values($this->keyPhrasesDataProvider());
        $privateKey = $privateKeys[0];
        $publicKey = $publicKeys[0];

        return [
            'encrypted as OPENSSL_PKCS1_OAEP_PADDING, and decrpted as OPENSSL_PKCS1_PADDING'  => [
                random_bytes(32), [$publicKey, OPENSSL_PKCS1_OAEP_PADDING], [$privateKey, OPENSSL_PKCS1_PADDING], UnexpectedValueException::class
            ],
            'encrypted as OPENSSL_PKCS1_PADDING, and decrpted as OPENSSL_PKCS1_OAEP_PADDING'  => [
                random_bytes(32), [$publicKey, OPENSSL_PKCS1_PADDING], [$privateKey, OPENSSL_PKCS1_OAEP_PADDING], UnexpectedValueException::class
            ],
            'encrypted as OPENSSL_PKCS1_OAEP_PADDING, and decrpted as OPENSSL_PKCS1_OAEP_PADDING'  => [
                random_bytes(32), [$publicKey, OPENSSL_PKCS1_OAEP_PADDING], [$privateKey, OPENSSL_PKCS1_OAEP_PADDING], null
            ],
            'encrypted as OPENSSL_PKCS1_PADDING, and decrpted as OPENSSL_PKCS1_PADDING'  => [
                random_bytes(32), [$publicKey, OPENSSL_PKCS1_PADDING], [$privateKey, OPENSSL_PKCS1_PADDING], null
            ],
        ];
    }

    /**
     * @dataProvider crossPaddingPhrasesProvider
     * @param string $plaintext
     * @param array{\OpenSSLAsymmetricKey|resource|mixed,int} $publicKeyAndPaddingMode
     * @param array{\OpenSSLAsymmetricKey|resource|mixed,int} $privateKeyAndPaddingMode
     * @param ?class-string<\UnexpectedValueException> $exception
     */
    public function testCrossEncryptDecryptWithDifferentPadding(
        string $plaintext, array $publicKeyAndPaddingMode, array $privateKeyAndPaddingMode, $exception = null
    ) {
        if ($exception) {
            $this->expectException($exception);
        }
        $ciphertext = Rsa::encrypt($plaintext, ...$publicKeyAndPaddingMode);
        $decrypted = Rsa::decrypt($ciphertext, ...$privateKeyAndPaddingMode);
        if ($exception === null) {
            self::assertNotEmpty($ciphertext);
            self::assertNotEmpty($decrypted);
            self::assertEquals($plaintext, $decrypted);
        }
    }

    /**
     * @dataProvider keysProvider
     * @param string $plaintext
     * @param object|resource|mixed $publicKey
     * @param object|resource|mixed $privateKey
     */
    public function testSign(string $plaintext, $publicKey, $privateKey)
    {
        $signature = Rsa::sign($plaintext, $privateKey);

        self::assertIsString($signature);
        self::assertNotEquals($plaintext, $signature);

        if (method_exists($this, 'assertMatchesRegularExpression')) {
            $this->assertMatchesRegularExpression(self::BASE64_EXPRESSION, $signature);
        } else {
            self::assertRegExp(self::BASE64_EXPRESSION, $signature);
        }
    }

    /**
     * @dataProvider keysProvider
     * @param string $plaintext
     * @param object|resource|mixed $publicKey
     * @param object|resource|mixed $privateKey
     */
    public function testVerify(string $plaintext, $publicKey, $privateKey)
    {
        $signature = Rsa::sign($plaintext, $privateKey);

        self::assertIsString($signature);
        self::assertNotEquals($plaintext, $signature);

        if (method_exists($this, 'assertMatchesRegularExpression')) {
            $this->assertMatchesRegularExpression(self::BASE64_EXPRESSION, $signature);
        } else {
            self::assertRegExp(self::BASE64_EXPRESSION, $signature);
        }

        self::assertTrue(Rsa::verify($plaintext, $signature, $publicKey));
    }
}
