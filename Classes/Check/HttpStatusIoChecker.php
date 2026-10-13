<?php

declare(strict_types=1);

namespace GeorgRinger\Linkvalidator2HttpstatusIo\Check;

use GeorgRinger\Linkvalidator2\Check\CheckResult;
use GeorgRinger\Linkvalidator2\Check\LinkCheckerInterface;
use GeorgRinger\Linkvalidator2\Extraction\LinkCandidate;
use GeorgRinger\Linkvalidator2\Target\LinkTarget;
use GeorgRinger\Linkvalidator2HttpstatusIo\Cache\StatusCodeStore;
use GeorgRinger\Linkvalidator2HttpstatusIo\Configuration\ExtensionSettings;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\LinkHandling\LinkService;

/**
 * Asks https://httpstatus.io/api/documentation for the status of an external URL. It is only
 * reached if the checkers before it (the own request) could not decide, and it skips itself
 * whenever the API gives no usable answer, so the earlier result and its details are kept.
 */
final readonly class HttpStatusIoChecker implements LinkCheckerInterface
{
    private const API_URL = 'https://api.httpstatus.io/v1/status';
    /** Trial phase with a quota of 500 requests: only these domains and their subdomains are sent to the API */
    private const ALLOWED_DOMAINS = ['linkedin.com', 'httpbin.org'];
    private const MAX_REDIRECTS = 5;
    private const BROKEN_STATUS_CODES = [404 => 'url.notFound', 410 => 'url.gone'];

    public function __construct(
        private RequestFactory $requestFactory,
        private ExtensionSettings $settings,
        private StatusCodeStore $store,
        private Context $context,
        private LoggerInterface $logger,
    ) {}

    public function getSupportedTypes(): array
    {
        return [LinkService::TYPE_URL];
    }

    public function check(LinkTarget $target, LinkCandidate $source): CheckResult
    {
        $accessKey = $this->settings->getAccessKey();
        $url = (string)($target->resolved['url'] ?? '');
        if ($accessKey === '' || !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            || !$this->isAllowedHost(strtolower((string)parse_url($url, PHP_URL_HOST)))
        ) {
            return CheckResult::skip();
        }
        $now = (int)$this->context->getPropertyFromAspect('date', 'timestamp');
        $cachedStatusCode = $this->store->get($url, $this->settings->getCacheLifetime(), $now);
        if ($cachedStatusCode !== null) {
            return $this->classifyStatusCode($cachedStatusCode);
        }
        $this->store->waitForNextCall();
        try {
            $response = $this->requestFactory->request(self::API_URL, 'POST', [
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::TIMEOUT => $this->settings->getTimeout(),
                RequestOptions::HEADERS => [
                    'Content-Type' => 'application/json; charset=utf-8',
                    'X-Billing-Token' => $accessKey,
                ],
                RequestOptions::BODY => json_encode([
                    'requestUrl' => $url,
                    'followRedirect' => true,
                    'maxRedirects' => self::MAX_REDIRECTS,
                    'userAgent' => $this->settings->getUserAgent(),
                ], JSON_THROW_ON_ERROR),
            ]);
        } catch (GuzzleException $e) {
            $this->logger->warning('Request to httpstatus.io failed', ['message' => $e->getMessage()]);
            return CheckResult::skip();
        }
        if ($response->getStatusCode() !== 200) {
            // 429: rate limit or quota of the plan, 4xx: rejected request, e.g. a wrong access key
            $this->logger->warning('httpstatus.io answered with an error', ['httpStatus' => $response->getStatusCode()]);
            return CheckResult::skip();
        }
        $data = json_decode((string)$response->getBody(), true);
        $chain = is_array($data) ? ($data['response']['chain'] ?? null) : null;
        $final = is_array($chain) && $chain !== [] ? end($chain) : null;
        $statusCode = is_array($final) ? (int)($final['statusCode'] ?? 0) : 0;
        if ($statusCode === 0) {
            return CheckResult::skip();
        }
        $this->store->set($url, $statusCode, $now);
        return $this->classifyStatusCode($statusCode);
    }

    private function isAllowedHost(string $host): bool
    {
        foreach (self::ALLOWED_DOMAINS as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }
        return false;
    }

    private function classifyStatusCode(int $statusCode): CheckResult
    {
        if ($statusCode >= 200 && $statusCode < 300) {
            return CheckResult::ok();
        }
        if (isset(self::BROKEN_STATUS_CODES[$statusCode])) {
            return CheckResult::broken(self::BROKEN_STATUS_CODES[$statusCode], ['httpStatus' => $statusCode]);
        }
        return CheckResult::unknown('url.httpStatus', ['httpStatus' => $statusCode]);
    }
}
