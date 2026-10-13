<?php

declare(strict_types=1);

namespace GeorgRinger\Linkvalidator2HttpstatusIo\Configuration;

use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final readonly class ExtensionSettings
{
    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function getAccessKey(): string
    {
        return trim($this->get('accessKey', ''));
    }

    public function getUserAgent(): string
    {
        return trim($this->get('userAgent', 'chrome')) ?: 'chrome';
    }

    public function getTimeout(): int
    {
        return max(1, (int)$this->get('timeout', '20'));
    }

    public function getCacheLifetime(): int
    {
        return max(0, (int)$this->get('cacheLifetime', '168')) * 3600;
    }

    private function get(string $path, string $default): string
    {
        try {
            return (string)($this->extensionConfiguration->get('linkvalidator2_httpstatus_io', $path) ?? $default);
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            return $default;
        }
    }
}
