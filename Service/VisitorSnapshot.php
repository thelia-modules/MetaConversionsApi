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
 * What the browser of a consenting visitor said about itself when the order was placed. The payment is often
 * confirmed later without that browser (the server call of a payment provider, a cheque recorded by the merchant),
 * so Purchase reads these values instead of those of the request that marks the order paid.
 */
final readonly class VisitorSnapshot
{
    public const FACEBOOK_CLICK_COOKIE = '_fbc';
    public const FACEBOOK_BROWSER_COOKIE = '_fbp';

    public function __construct(
        public ?string $clientIpAddress,
        public ?string $clientUserAgent,
        public ?string $facebookClickId,
        public ?string $facebookBrowserId,
        public ?string $sourceUrl,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            $request->getClientIp(),
            self::stringOrNull($request->headers->get('User-Agent')),
            self::stringOrNull($request->cookies->get(self::FACEBOOK_CLICK_COOKIE)),
            self::stringOrNull($request->cookies->get(self::FACEBOOK_BROWSER_COOKIE)),
            $request->getUri(),
        );
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            self::stringOrNull($data['client_ip_address'] ?? null),
            self::stringOrNull($data['client_user_agent'] ?? null),
            self::stringOrNull($data['fbc'] ?? null),
            self::stringOrNull($data['fbp'] ?? null),
            self::stringOrNull($data['source_url'] ?? null),
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter(
            [
                'client_ip_address' => $this->clientIpAddress,
                'client_user_agent' => $this->clientUserAgent,
                'fbc' => $this->facebookClickId,
                'fbp' => $this->facebookBrowserId,
                'source_url' => $this->sourceUrl,
            ],
            static fn (?string $value): bool => null !== $value,
        );
    }

    /**
     * The browser part of the `user_data` of an event.
     *
     * @return array<string, string>
     */
    public function userData(): array
    {
        $snapshot = $this->toArray();
        unset($snapshot['source_url']);

        return $snapshot;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
