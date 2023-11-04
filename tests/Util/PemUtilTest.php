<?php declare(strict_types=1);

namespace WeChatPay\Tests\Util;

use const PHP_MAJOR_VERSION;

use function file_get_contents;
use function openssl_x509_parse;
use function rtrim;
use function sprintf;

use WeChatPay\Util\PemUtil;
use PHPUnit\Framework\TestCase;

class PemUtilTest extends TestCase
{
    const FIXTURES = __DIR__ . '/../fixtures/mock.%s.%s';

    const SUBJECT_CN = 'WeChatPay Community CI';
    const SUBJECT_O  = 'WeChatPay Community';
    const SUBJECT_ST = 'Shanghai';
    const SUBJECT_C  = 'CN';

    /** @var array<string,string> */
    private static $certSubject = [
        'commonName'          => self::SUBJECT_CN,
        'organizationName'    => self::SUBJECT_O,
        'stateOrProvinceName' => self::SUBJECT_ST,
        'countryName'         => self::SUBJECT_C,
    ];

    /** @var ?array{string,string,string,string,string,string} */
    private static $environment;

    public static function setUpBeforeClass(): void
    {
        $serial     = rtrim((string)file_get_contents(sprintf(self::FIXTURES, 'serial', 'txt')));
        $certFile   = sprintf(self::FIXTURES, 'sha256', 'crt');
        $privFile   = sprintf(self::FIXTURES, 'pkcs8', 'key');
        $certString = (string)file_get_contents($certFile);
        $privString = (string)file_get_contents($privFile);

        self::$environment = [$serial, $certFile, $certString, $privFile, $privString, 'file://' . $certFile];
    }

    public static function tearDownAfterClass(): void
    {
        self::$environment = null;
    }

    public function testLoadCertificate(): void
    {
        list(, $certFile) = self::$environment ?? ['', ''];
        $cert = PemUtil::loadCertificate($certFile);
        if (8 === PHP_MAJOR_VERSION) {
            self::assertIsObject($cert);
        } else {
            self::assertIsResource($cert);
        }

        /** @var string|\OpenSSLCertificate|resource|mixed $cert */
        list('subject' => $subject, 'issuer' => $issuer) = openssl_x509_parse($cert, false) ?: [];
        self::assertEquals(self::$certSubject, $subject);
        self::assertEquals(self::$certSubject, $issuer);
    }

    public function testLoadCertificateFromString(): void
    {
        list(, , $certString) = self::$environment ?? ['', '', ''];
        $cert = PemUtil::loadCertificateFromString($certString);
        if (8 === PHP_MAJOR_VERSION) {
            self::assertIsObject($cert);
        } else {
            self::assertIsResource($cert);
        }

        /** @var string|\OpenSSLCertificate|resource|mixed $cert */
        list('subject' => $subject, 'issuer' => $issuer) = openssl_x509_parse($cert, false) ?: [];
        self::assertEquals(self::$certSubject, $subject);
        self::assertEquals(self::$certSubject, $issuer);
    }

    public function testLoadPrivateKey(): void
    {
        list(, , , $privateKeyFile) = self::$environment ?? ['', '', '', ''];
        $privateKey = PemUtil::loadPrivateKey($privateKeyFile);
        if (8 === PHP_MAJOR_VERSION) {
            self::assertIsObject($privateKey);
        } else {
            self::assertIsResource($privateKey);
        }
    }

    public function testLoadPrivateKeyFromString(): void
    {
        list(, , , , $privateKeyString) = self::$environment ?? ['', '', '', '', ''];
        $privateKey = PemUtil::loadPrivateKeyFromString($privateKeyString);
        if (8 === PHP_MAJOR_VERSION) {
            self::assertIsObject($privateKey);
        } else {
            self::assertIsResource($privateKey);
        }
    }

    public function testParseCertificateSerialNo(): void
    {
        list($serialNo, $certFile, $certString, , , $certFileProtocolString) = self::$environment  ?? ['', '', '', '', '', '', ''];
        $serialNoFromPemUtilFile = PemUtil::parseCertificateSerialNo(PemUtil::loadCertificate($certFile));
        $serialNoFromPemUtilString = PemUtil::parseCertificateSerialNo(PemUtil::loadCertificateFromString($certString));
        $serialNoFromCertString = PemUtil::parseCertificateSerialNo($certString);
        $serialNoFromCertFileProtocolString = PemUtil::parseCertificateSerialNo($certFileProtocolString);
        self::assertEquals($serialNo, $serialNoFromPemUtilFile);
        self::assertEquals($serialNo, $serialNoFromPemUtilString);
        self::assertEquals($serialNo, $serialNoFromCertString);
        self::assertEquals($serialNo, $serialNoFromCertFileProtocolString);
    }
}
