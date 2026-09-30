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

namespace MetaConversionsApi\Tests;

use MetaConversionsApi\EventListener\PageViewListener;
use MetaConversionsApi\Service\ConversionTracker;
use Monolog\Logger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class PageViewListenerTest extends ConversionTestCase
{
    public function testAPageOfTheShopQueuesAPageViewWithoutAnyNetworkCall(): void
    {
        $this->respond($this->onRequest($this->visitorRequest(path: '/helmets')));

        $pageView = $this->onlyQueuedEvent(ConversionTracker::PAGE_VIEW);
        self::assertSame('https://shop.test/helmets', $pageView->sourceUrl);
        self::assertNull($pageView->eventId);
    }

    public function testWhatIsNotAPageOfTheShopQueuesNothing(): void
    {
        $notPages = [
            'back office' => [$this->visitorRequest(path: '/admin/orders'), new Response('<html></html>')],
            'API' => [$this->visitorRequest(path: '/api/front/products'), new Response('{}', 200, ['Content-Type' => 'application/ld+json'])],
            'live component' => [$this->visitorRequest(path: '/_components/Cart'), new Response('<div></div>')],
            'redirection' => [$this->visitorRequest(path: '/login'), new Response('', 302, ['Location' => '/'])],
            'JSON' => [$this->visitorRequest(path: '/cart.json'), new Response('{}', 200, ['Content-Type' => 'application/json'])],
        ];

        $xhr = $this->visitorRequest(path: '/cart');
        $xhr->headers->set('X-Requested-With', 'XMLHttpRequest');
        $notPages['XHR'] = [$xhr, new Response('<div></div>')];

        $frame = $this->visitorRequest(path: '/cart');
        $frame->headers->set('Turbo-Frame', 'cart');
        $notPages['Turbo frame'] = [$frame, new Response('<turbo-frame></turbo-frame>')];

        $post = Request::create('https://shop.test/cart', 'POST', [], ['axeptio_authorized_vendors' => ',facebook_pixel,']);
        $notPages['POST'] = [$post, new Response('<html></html>')];

        foreach ($notPages as [$request, $response]) {
            $this->respond($this->onRequest($request), $response);
        }

        self::assertSame([], $this->queuedEventNames());
    }

    private function respond(Request $request, ?Response $response = null): void
    {
        $response ??= new Response('<html></html>');
        if (!$response->headers->has('Content-Type')) {
            $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
        }

        $kernel = self::$kernel;
        self::assertInstanceOf(HttpKernelInterface::class, $kernel);

        (new PageViewListener($this->tracker(), new Logger('test', [$this->logs])))->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
    }
}
