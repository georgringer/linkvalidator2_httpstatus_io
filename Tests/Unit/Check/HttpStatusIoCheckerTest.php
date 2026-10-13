<?php

declare(strict_types=1);

namespace GeorgRinger\Linkvalidator2HttpstatusIo\Tests\Unit\Check;

use GeorgRinger\Linkvalidator2\Check\CheckResult;
use GeorgRinger\Linkvalidator2\Check\CheckStatus;
use GeorgRinger\Linkvalidator2\Extraction\LinkCandidate;
use GeorgRinger\Linkvalidator2\Target\LinkTarget;
use GeorgRinger\Linkvalidator2HttpstatusIo\Cache\StatusCodeStore;
use GeorgRinger\Linkvalidator2HttpstatusIo\Check\HttpStatusIoChecker;
use GeorgRinger\Linkvalidator2HttpstatusIo\Configuration\ExtensionSettings;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class HttpStatusIoCheckerTest extends UnitTestCase
{
    private string $storeDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storeDirectory = sys_get_temp_dir() . '/statuscode_io_test_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        GeneralUtility::rmdir($this->storeDirectory, true);
        parent::tearDown();
    }

    #[Test]
    public function otherHostsThanLinkedInAreSkipped(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects(self::never())->method('request');

        self::assertSame(CheckStatus::Skipped, $this->check($requestFactory, 'secret', 'https://example.com/')->status);
        self::assertSame(CheckStatus::Skipped, $this->check($requestFactory, 'secret', 'https://notlinkedin.com/')->status);
    }

    #[Test]
    public function allowedDomainsAndTheirSubdomainsAreSentToTheApi(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects(self::exactly(2))->method('request')->willReturn($this->createApiResponse(403));

        self::assertSame(CheckStatus::Unknown, $this->check($requestFactory, 'secret', 'https://httpbin.org/status/403')->status);
        self::assertSame(CheckStatus::Unknown, $this->check($requestFactory, 'secret', 'https://www.httpbin.org/status/403')->status);
    }

    #[Test]
    public function answerIsReusedFromTheStore(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects(self::once())->method('request')->willReturn($this->createApiResponse(404));

        self::assertSame('url.notFound', $this->check($requestFactory, 'secret')->errorType);
        self::assertSame('url.notFound', $this->check($requestFactory, 'secret')->errorType);
    }

    #[Test]
    public function errorOfTheApiIsNotStored(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects(self::exactly(2))->method('request')->willReturn(new Response(429, [], '{}'));

        $this->check($requestFactory, 'secret');
        $this->check($requestFactory, 'secret');
    }

    #[Test]
    public function checkerIsSkippedWithoutAccessKey(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects(self::never())->method('request');

        self::assertSame(CheckStatus::Skipped, $this->check($requestFactory, '')->status);
    }

    #[Test]
    public function accessKeyAndUrlAreSentToTheApi(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects(self::once())->method('request')->with(
            'https://api.httpstatus.io/v1/status',
            'POST',
            self::callback(static fn(array $options): bool => $options['headers']['X-Billing-Token'] === 'secret'
                && json_decode($options['body'], true)['requestUrl'] === 'https://www.linkedin.com/company/x'
                && json_decode($options['body'], true)['userAgent'] === 'chrome'),
        )->willReturn($this->createApiResponse(200));

        self::assertTrue($this->check($requestFactory, 'secret')->isOk());
    }

    /**
     * @return array<string, array{int, CheckStatus, string}>
     */
    public static function finalStatusCodeIsClassifiedDataProvider(): array
    {
        return [
            'ok' => [200, CheckStatus::Ok, ''],
            'not found' => [404, CheckStatus::Broken, 'url.notFound'],
            'gone' => [410, CheckStatus::Broken, 'url.gone'],
            'forbidden' => [403, CheckStatus::Unknown, 'url.httpStatus'],
            'server error' => [503, CheckStatus::Unknown, 'url.httpStatus'],
        ];
    }

    #[Test]
    #[DataProvider('finalStatusCodeIsClassifiedDataProvider')]
    public function finalStatusCodeOfTheChainIsClassified(int $statusCode, CheckStatus $expectedStatus, string $expectedErrorType): void
    {
        $requestFactory = $this->createStub(RequestFactory::class);
        $requestFactory->method('request')->willReturn($this->createApiResponse($statusCode));

        $result = $this->check($requestFactory, 'secret');

        self::assertSame($expectedStatus, $result->status);
        self::assertSame($expectedErrorType, $result->errorType);
    }

    #[Test]
    public function errorOfTheApiIsSkipped(): void
    {
        $requestFactory = $this->createStub(RequestFactory::class);
        $requestFactory->method('request')->willReturn(new Response(429, [], '{}'));

        self::assertSame(CheckStatus::Skipped, $this->check($requestFactory, 'secret')->status);
    }

    #[Test]
    public function answerWithoutChainIsSkipped(): void
    {
        $requestFactory = $this->createStub(RequestFactory::class);
        $requestFactory->method('request')->willReturn(new Response(200, [], '{"response":{"error":"x"}}'));

        self::assertSame(CheckStatus::Skipped, $this->check($requestFactory, 'secret')->status);
    }

    private function check(RequestFactory $requestFactory, string $accessKey, string $url = 'https://www.linkedin.com/company/x'): CheckResult
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturnCallback(
            static fn(string $extension, string $path): string => match ($path) {
                'accessKey' => $accessKey,
                'timeout' => '20',
                'cacheLifetime' => '168',
                default => 'chrome',
            }
        );
        $context = $this->createStub(Context::class);
        $context->method('getPropertyFromAspect')->willReturn(1_000_000);
        $subject = new HttpStatusIoChecker($requestFactory, new ExtensionSettings($extensionConfiguration), new StatusCodeStore($this->storeDirectory), $context, new NullLogger());

        return $subject->check(
            new LinkTarget('url', 'url:' . $url, ['url' => $url]),
            new LinkCandidate('tt_content', 1, 1, 0, 'bodytext', '', ''),
        );
    }

    private function createApiResponse(int $finalStatusCode): Response
    {
        return new Response(200, [], json_encode(['response' => ['chain' => [
            ['url' => 'http://example.com/', 'statusCode' => 301],
            ['url' => 'https://www.linkedin.com/company/x', 'statusCode' => $finalStatusCode],
        ]]], JSON_THROW_ON_ERROR));
    }
}
