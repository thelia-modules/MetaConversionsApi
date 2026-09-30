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

namespace MetaConversionsApi\EventListener;

use MetaConversionsApi\Service\ConversionTracker;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * PageView for every page of the shop served to a visitor: a full HTML page answering a GET, not an XHR, a Turbo
 * frame, a live component, the API or the back office. It runs after the Symfony response listener (priority 0),
 * which sets the content type. A failure is logged, the page is served.
 */
final readonly class PageViewListener implements EventSubscriberInterface
{
    public const PRIORITY = -16;

    /** Paths that never serve a page: API, live components and profiler (`/_…`). */
    private const IGNORED_PATH_PREFIXES = ['/api', '/_'];

    public function __construct(
        private ConversionTracker $tracker,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onResponse', self::PRIORITY],
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->isPage($event->getRequest(), $event->getResponse())) {
            return;
        }

        try {
            $this->tracker->trackPageView();
        } catch (\Throwable $throwable) {
            $this->logger->error(\sprintf('Meta Conversions API: PageView not tracked (%s: %s)', $throwable::class, $throwable->getMessage()));
        }
    }

    private function isPage(Request $request, Response $response): bool
    {
        if (!$request->isMethod('GET') || Response::HTTP_OK !== $response->getStatusCode()) {
            return false;
        }

        if ($request->isXmlHttpRequest() || $request->headers->has('Turbo-Frame')) {
            return false;
        }

        foreach (self::IGNORED_PATH_PREFIXES as $prefix) {
            if (str_starts_with($request->getPathInfo(), $prefix)) {
                return false;
            }
        }

        return str_starts_with((string) $response->headers->get('Content-Type'), 'text/html');
    }
}
