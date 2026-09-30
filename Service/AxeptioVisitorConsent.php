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

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the Axeptio cookies: `axeptio_authorized_vendors` lists the accepted vendors between commas,
 * `axeptio_cookies` holds the answer of each vendor as JSON. No cookie, or a vendor missing from them, is a refusal.
 */
final readonly class AxeptioVisitorConsent implements VisitorConsentInterface
{
    public const AUTHORIZED_VENDORS_COOKIE = 'axeptio_authorized_vendors';
    public const CHOICES_COOKIE = 'axeptio_cookies';

    public function __construct(
        private Settings $settings,
    ) {
    }

    public function allowsTracking(Request $request): bool
    {
        if (!$this->settings->requiresConsent()) {
            return true;
        }

        $vendor = $this->settings->consentVendor();

        $authorizedVendors = $request->cookies->get(self::AUTHORIZED_VENDORS_COOKIE);
        if (\is_string($authorizedVendors)) {
            return \in_array($vendor, array_map('trim', explode(',', $authorizedVendors)), true);
        }

        $choices = $request->cookies->get(self::CHOICES_COOKIE);
        if (!\is_string($choices)) {
            return false;
        }

        try {
            $decodedChoices = json_decode($choices, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return \is_array($decodedChoices) && true === ($decodedChoices[$vendor] ?? null);
    }
}
