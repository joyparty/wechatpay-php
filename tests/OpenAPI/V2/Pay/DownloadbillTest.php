<?php declare(strict_types=1);

namespace WeChatPay\Tests\OpenAPI\V2\Pay;

use const DIRECTORY_SEPARATOR;

use function dirname;
use function substr_count;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\LazyOpenStream;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use WeChatPay\Builder;
use WeChatPay\Transformer;
use WeChatPay\ClientDecoratorInterface;
use PHPUnit\Framework\TestCase;

class DownloadbillTest extends TestCase
{
    const CSV_DATA_LINE_MAXIMUM_BYTES = 1024;
    const CSV_DATA_FIRST_BYTE = '`';
    const CSV_DATA_SEPERATOR = ',`';

    /** @var MockHandler $mock */
    private $mock;

    private function guzzleMockStack(): HandlerStack
    {
        $this->mock = new MockHandler();

        return HandlerStack::create($this->mock);
    }

    /**
     * @param string $mchid
     * @return array{\WeChatPay\BuilderChainable,HandlerStack}
     */
    private function prepareEnvironment(string $mchid): array
    {
        $instance = Builder::factory([
            'mchid'      => $mchid,
            'serial'     => 'nop',
            'privateKey' => 'any',
            'certs'      => ['any' => null],
            'secret'     => '',
            'handler'    => $this->guzzleMockStack(),
        ]);

        /** @var HandlerStack $stack */
        $stack = $instance->getDriver()->select(ClientDecoratorInterface::XML_BASED)->getConfig('handler');
        $stack = clone $stack;
        $stack->remove('transform_response');

        $endpoint = $instance->chain('v2/pay/downloadbill');

        return [$endpoint, $stack];
    }

    /**
     * @return array<string,array{string,array<string,string>,ResponseInterface}>
     */
    public function mockRequestsDataProvider(): array
    {
        $mchid  = '1230000109';
        $data = [
            'return_code' => 'FAIL',
            'return_msg'  => 'invalid reason',
            'error_code'  => '20001'
        ];
        $file   = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'bill.ALL.csv';
        $stream = new LazyOpenStream($file, 'rb');

        $xmlDataStructure = [
            'appid'     => 'wx8888888888888888',
            'mch_id'    => $mchid,
            'bill_type' => 'ALL',
            'bill_date' => '20140603',
        ];

        return [
            'return_code=FAIL' => [$mchid, $xmlDataStructure, new Response(200, [], Transformer::toXml($data))],
            'CSV stream'       => [$mchid, $xmlDataStructure, new Response(200, [], $stream)],
        ];
    }

    /**
     * @dataProvider mockRequestsDataProvider
     * @param string $mchid
     * @param array<string,string> $data
     * @param ResponseInterface $respondor
     */
    public function testPost(string $mchid, array $data, ResponseInterface $respondor)
    {
        list($endpoint, $stack) = $this->prepareEnvironment($mchid);

        $this->mock->reset();
        $this->mock->append($respondor);

        $res = $endpoint->post([
            'handler' => $stack,
            'xml'     => $data,
        ]);
        self::responseAssertion($res);

        $this->mock->reset();
        $this->mock->append($respondor);

        $res = $endpoint->post(['xml' => $data]);
        self::responseAssertion($res);
    }

    /**
     * @param ResponseInterface $response
     * @param boolean $testFinished
     */
    private static function responseAssertion(ResponseInterface $response, bool $testFinished = false)
    {
        $stream = $response->getBody();
        $stream->tell() && $stream->rewind();
        $firstFiveBytes = $stream->read(5);
        $stream->rewind();
        if ('<xml>' === $firstFiveBytes) {
            $txt = (string) $stream;
            $array = Transformer::toArray($txt);
            static::assertArrayHasKey('return_msg', $array);
            static::assertArrayHasKey('return_code', $array);
            static::assertArrayHasKey('error_code', $array);
        } else {
            $line = Utils::readLine($stream, self::CSV_DATA_LINE_MAXIMUM_BYTES);
            $headerCommaCount = substr_count($line, ',');
            $isRecord = false;
            do {
                $line = Utils::readLine($stream, self::CSV_DATA_LINE_MAXIMUM_BYTES);
                $isRecord = $line[0] === self::CSV_DATA_FIRST_BYTE;
                if ($isRecord) {
                    static::assertEquals($headerCommaCount, substr_count($line, self::CSV_DATA_SEPERATOR));
                }
            } while(!$stream->eof() && $isRecord);
            $summaryCommaCount = substr_count($line, ',');
            $line = Utils::readLine($stream, self::CSV_DATA_LINE_MAXIMUM_BYTES);
            static::assertTrue($line[0] === self::CSV_DATA_FIRST_BYTE);
            static::assertEquals($summaryCommaCount, substr_count($line, self::CSV_DATA_SEPERATOR));
            $stream->rewind();
            if ($testFinished) {
                $stream->close();
                static::assertFalse($stream->isSeekable());
            }
        }
    }

    /**
     * @dataProvider mockRequestsDataProvider
     * @param string $mchid
     * @param array<string,string> $data
     * @param ResponseInterface $respondor
     */
    public function testPostAsync(string $mchid, array $data, ResponseInterface $respondor)
    {
        list($endpoint, $stack) = $this->prepareEnvironment($mchid);

        $this->mock->reset();
        $this->mock->append($respondor);

        $endpoint->postAsync([
            'xml' => $data,
        ])->then(static function(ResponseInterface $response) {
            self::responseAssertion($response);
        })->wait();

        $this->mock->reset();
        $this->mock->append($respondor);

        $endpoint->postAsync([
            'handler' => $stack,
            'xml'     => $data,
        ])->then(static function(ResponseInterface $response) {
            self::responseAssertion($response, true);
        })->wait();
    }
}
