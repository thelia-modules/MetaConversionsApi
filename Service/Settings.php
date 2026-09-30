<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MetaConversionsApi\Service;

use MetaConversionsApi\MetaConversionsApi;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Nothing leaves the shop unless the tracker is active, has a pixel and a token, and the environment variable
 * META_CONVERSION_ENV is "prod": a copy of the production database never writes into the merchant's Meta account.
 */
final readonly class Settings
{
    public const PRODUCTION_ENVIRONMENT = 'prod';

    public function __construct(
        #[Autowire(env: 'default::META_CONVERSION_ENV')]
        private ?string $environment = null,
    ) {
    }

    public function isSendingEnabled(): bool
    {
        return $this->isActive()
            && '' !== $this->pixelId()
            && '' !== $this->accessToken()
            && $this->isProductionEnvironment();
    }

    public function isActive(): bool
    {
        return (bool) MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_ACTIVE);
    }

    public function isProductionEnvironment(): bool
    {
        return self::PRODUCTION_ENVIRONMENT === $this->environment;
    }

    public function pixelId(): string
    {
        return trim((string) MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_PIXEL_ID));
    }

    public function accessToken(): string
    {
        return trim((string) MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_TOKEN));
    }

    public function hasAccessToken(): bool
    {
        return '' !== $this->accessToken();
    }

    /**
     * The code of the Meta test events tool, sent only while the test mode is on.
     */
    public function testEventCode(): ?string
    {
        if (!(bool) MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_TEST_MODE)) {
            return null;
        }

        $code = trim((string) MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_TEST_EVENT_CODE));

        return '' === $code ? null : $code;
    }

    public function sendsPersonalData(): bool
    {
        return (bool) MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_TRACK_PERSONAL_DATA);
    }

    /**
     * On by default, the 2.x line had no such setting: an upgraded shop waits for the visitor's consent.
     */
    public function requiresConsent(): bool
    {
        return (bool) MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_CONSENT_REQUIRED, '1');
    }

    public function consentVendor(): string
    {
        $vendor = trim((string) MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_CONSENT_VENDOR));

        return '' === $vendor ? MetaConversionsApi::DEFAULT_CONSENT_VENDOR : $vendor;
    }

    public function snapshotRetentionDays(): int
    {
        $days = (int) MetaConversionsApi::getConfigValue(
            MetaConversionsApi::META_TRACKER_SNAPSHOT_RETENTION_DAYS,
            (string) MetaConversionsApi::DEFAULT_SNAPSHOT_RETENTION_DAYS,
        );

        return $days > 0 ? $days : MetaConversionsApi::DEFAULT_SNAPSHOT_RETENTION_DAYS;
    }
}
