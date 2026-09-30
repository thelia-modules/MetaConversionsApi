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

use MetaConversionsApi\EventListener\ConversionListener;
use MetaConversionsApi\MetaConversionsApi;
use MetaConversionsApi\Service\AxeptioVisitorConsent;
use MetaConversionsApi\Service\ConversionEvent;
use MetaConversionsApi\Service\ConversionQueue;
use MetaConversionsApi\Service\ConversionTracker;
use MetaConversionsApi\Service\CustomDataBuilder;
use MetaConversionsApi\Service\CustomerDataHasher;
use MetaConversionsApi\Service\PixelEventIds;
use MetaConversionsApi\Service\Settings;
use MetaConversionsApi\Service\VisitorConsentInterface;
use MetaConversionsApi\Service\VisitorSnapshotStore;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Taxation\TaxEngine\TaxEngine;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderProductTax;
use Thelia\Model\Product;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * Runs on a disposable test database (`php bin/test-prepare` with a DATABASE_NAME ending in `_test`), never on the
 * shop's. The services are built by hand around a request stack of the test: the one of the container is shared, and
 * its settings read the real environment, where nothing is ever sent. Nothing leaves the machine.
 */
abstract class ConversionTestCase extends IntegrationTestCase
{
    protected const PIXEL_ID = '123456789012345';
    protected const ACCESS_TOKEN = 'secret-access-token-for-the-tests';
    protected const VISITOR_IP = '203.0.113.7';
    protected const VISITOR_USER_AGENT = 'VisitorBrowser/1.0';
    protected const VISITOR_FBP = 'fb.1.1700000000000.123456789';

    protected FixtureFactory $fixtures;
    protected RequestStack $requestStack;
    protected ConversionQueue $queue;
    protected TestHandler $logs;

    protected function setUp(): void
    {
        $databaseName = $_SERVER['DATABASE_NAME'] ?? getenv('DATABASE_NAME');
        if (!\is_string($databaseName) || !str_ends_with($databaseName, '_test')) {
            self::fail(\sprintf('Refusing to run on the database "%s": use a *_test database.', (string) $databaseName));
        }

        parent::setUp();

        // The Propel configuration of the test environment is shared and cached: check the database it really opens.
        $statement = $this->getPropelConnection()->query('SELECT DATABASE()');
        $connectedDatabase = false === $statement ? null : $statement->fetchColumn();
        if ($connectedDatabase !== $databaseName) {
            self::fail(\sprintf('Refusing to run: DATABASE_NAME is "%s" but Propel is connected to "%s".', $databaseName, (string) $connectedDatabase));
        }

        $this->fixtures = $this->createFixtureFactory();
        $this->requestStack = new RequestStack();
        $this->queue = new ConversionQueue();
        $this->logs = new TestHandler();

        MetaConversionsApi::setConfigValue(MetaConversionsApi::META_TRACKER_PIXEL_ID, self::PIXEL_ID);
        MetaConversionsApi::setConfigValue(MetaConversionsApi::META_TRACKER_TOKEN, self::ACCESS_TOKEN);
        MetaConversionsApi::setConfigValue(MetaConversionsApi::META_TRACKER_ACTIVE, '1');
        MetaConversionsApi::setConfigValue(MetaConversionsApi::META_TRACKER_TRACK_PERSONAL_DATA, '1');
        MetaConversionsApi::setConfigValue(MetaConversionsApi::META_TRACKER_CONSENT_REQUIRED, '1');
        MetaConversionsApi::setConfigValue(MetaConversionsApi::META_TRACKER_CONSENT_VENDOR, '');
        MetaConversionsApi::setConfigValue(MetaConversionsApi::META_TRACKER_TEST_MODE, '0');
        MetaConversionsApi::setConfigValue(MetaConversionsApi::META_TRACKER_TEST_EVENT_CODE, '');
    }

    protected function tracker(string $environment = Settings::PRODUCTION_ENVIRONMENT, ?VisitorConsentInterface $visitorConsent = null): ConversionTracker
    {
        $settings = new Settings($environment);
        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return new ConversionTracker(
            $settings,
            $visitorConsent ?? new AxeptioVisitorConsent($settings),
            new CustomerDataHasher(),
            new CustomDataBuilder(),
            $this->queue,
            new VisitorSnapshotStore(),
            new PixelEventIds($this->requestStack),
            $this->requestStack,
            new SecurityContext($this->requestStack),
            new TaxEngine($this->requestStack, $dispatcher),
        );
    }

    protected function listener(string $environment = Settings::PRODUCTION_ENVIRONMENT, ?VisitorConsentInterface $visitorConsent = null): ConversionListener
    {
        return new ConversionListener($this->tracker($environment, $visitorConsent), new Logger('test', [$this->logs]));
    }

    /**
     * Replaces the main request: the next event reads this one.
     */
    protected function onRequest(Request $request): Request
    {
        while (null !== $this->requestStack->pop()) {
        }

        if (!$request->hasSession()) {
            $request->setSession(new Session(new MockArraySessionStorage()));
        }

        $this->requestStack->push($request);

        return $request;
    }

    /**
     * A visitor of the shop; $consent null means the visitor has not answered the consent banner yet.
     */
    protected function visitorRequest(?bool $consent = true, string $path = '/cart'): Request
    {
        $cookies = ['_fbp' => self::VISITOR_FBP];

        if (null !== $consent) {
            $cookies['axeptio_authorized_vendors'] = $consent ? ',google_analytics,facebook_pixel,' : ',google_analytics,';
        }

        return Request::create('https://shop.test'.$path, 'GET', [], $cookies, [], [
            'REMOTE_ADDR' => self::VISITOR_IP,
            'HTTP_USER_AGENT' => self::VISITOR_USER_AGENT,
        ]);
    }

    protected function product(string $reference, string $title): Product
    {
        return $this->fixtures->product(
            $this->fixtures->category(),
            $this->fixtures->taxRule(),
            $this->fixtures->currency(),
            ['ref' => $reference, 'title' => $title],
        );
    }

    /**
     * Two helmets at 10 before taxes, 2 of tax each when $unitTax is given, a postage of 5, and $discount off.
     */
    protected function orderWithTwoProducts(?string $unitTax = null, string $discount = '0'): Order
    {
        $order = $this->fixtures->order(null, ['postage' => '5.000000']);
        $order->setRef('ORD-META-'.$order->getId())->setDiscount($discount)->save($this->getPropelConnection());

        $orderProduct = new OrderProduct();
        $orderProduct->setOrderId($order->getId());
        $orderProduct->setProductRef('HELMET-1');
        $orderProduct->setProductSaleElementsRef('HELMET-1-M');
        $orderProduct->setTitle('Helmet');
        $orderProduct->setQuantity(2.0);
        $orderProduct->setPrice('10.000000');
        $orderProduct->setPromoPrice('8.000000');
        $orderProduct->setWasNew(0);
        $orderProduct->setWasInPromo(0);
        $orderProduct->setVirtual(0);
        $orderProduct->setIsOffered(0);
        $orderProduct->save($this->getPropelConnection());

        if (null !== $unitTax) {
            (new OrderProductTax())
                ->setOrderProductId($orderProduct->getId())
                ->setTitle('VAT')
                ->setAmount($unitTax)
                ->setPromoAmount($unitTax)
                ->save($this->getPropelConnection());
        }

        return $order;
    }

    /**
     * @return list<string>
     */
    protected function queuedEventNames(): array
    {
        return array_map(static fn (ConversionEvent $event): string => $event->name, $this->queue->events());
    }

    protected function onlyQueuedEvent(string $name): ConversionEvent
    {
        $events = array_values(array_filter($this->queue->events(), static fn (ConversionEvent $event): bool => $event->name === $name));
        self::assertCount(1, $events, \sprintf('Expected one %s event, queued: %s', $name, implode(', ', $this->queuedEventNames())));

        return $events[0];
    }
}
