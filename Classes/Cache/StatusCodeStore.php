<?php

declare(strict_types=1);

namespace GeorgRinger\Linkvalidator2HttpstatusIo\Cache;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Status codes answered by the API, one file per URL in var/statuscode_io/. It is not a TYPO3 cache
 * on purpose: flushing the caches must not throw away answers that cost a request of a small quota.
 */
final readonly class StatusCodeStore
{
    private const LAST_CALL_FILE = '.last-call';

    private string $directory;

    public function __construct(string $directory = '')
    {
        $this->directory = rtrim($directory !== '' ? $directory : Environment::getVarPath() . '/statuscode_io', '/');
    }

    public function get(string $url, int $lifetime, int $now): ?int
    {
        $file = $this->getFile($url);
        $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        if (!is_array($data) || !isset($data['statusCode'], $data['time']) || $data['time'] + $lifetime <= $now) {
            return null;
        }
        return (int)$data['statusCode'];
    }

    public function set(string $url, int $statusCode, int $now): void
    {
        GeneralUtility::mkdir_deep($this->directory);
        GeneralUtility::writeFile($this->getFile($url), json_encode(['url' => $url, 'statusCode' => $statusCode, 'time' => $now], JSON_THROW_ON_ERROR));
    }

    /**
     * Blocks until $interval seconds have passed since the last API call, and notes this one.
     * The quota of the plan is a call per second, a 429 would only waste the request.
     */
    public function waitForNextCall(float $interval = 1.0): void
    {
        GeneralUtility::mkdir_deep($this->directory);
        $file = $this->directory . '/' . self::LAST_CALL_FILE;
        $last = is_file($file) ? (float)file_get_contents($file) : 0.0;
        $wait = $last + $interval - microtime(true);
        if ($wait > 0) {
            usleep((int)ceil($wait * 1_000_000));
        }
        GeneralUtility::writeFile($file, (string)microtime(true));
    }

    private function getFile(string $url): string
    {
        return $this->directory . '/' . sha1($url) . '.json';
    }
}
