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

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * The identifier of the last server event of a kind, kept in the session until the theme hands it to the Meta pixel
 * (Twig: `meta_conversions_api_event_id('AddToCart')`), so that Meta counts the conversion once.
 */
final readonly class PixelEventIds
{
    public const SESSION_KEY = 'metaconversionsapi.pixel_event_ids';

    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function remember(string $eventName, string $eventId): void
    {
        $session = $this->session();
        if (null === $session) {
            return;
        }

        $eventIds = $session->get(self::SESSION_KEY, []);
        $eventIds = \is_array($eventIds) ? $eventIds : [];
        $eventIds[$eventName] = $eventId;

        $session->set(self::SESSION_KEY, $eventIds);
    }

    /**
     * Read once: the identifier is removed so that the pixel never sends it twice.
     */
    public function pull(string $eventName): ?string
    {
        $session = $this->session();
        if (null === $session) {
            return null;
        }

        $eventIds = $session->get(self::SESSION_KEY, []);
        if (!\is_array($eventIds) || !\is_string($eventIds[$eventName] ?? null)) {
            return null;
        }

        $eventId = $eventIds[$eventName];
        unset($eventIds[$eventName]);
        $session->set(self::SESSION_KEY, $eventIds);

        return $eventId;
    }

    private function session(): ?SessionInterface
    {
        $request = $this->requestStack->getMainRequest();

        return null !== $request && $request->hasSession() ? $request->getSession() : null;
    }
}
