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
use MetaConversionsApi\Service\ConversionTracker;
use MetaConversionsApi\Service\PixelEventIds;
use MetaConversionsApi\Service\VisitorConsentInterface;
use MetaConversionsApi\Service\VisitorSnapshotStore;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Thelia\Core\Event\Cart\CartEvent;
use Thelia\Core\Event\Customer\CustomerCreateOrUpdateMinimalEvent;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\Country;
use Thelia\Model\CountryQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;

final class ConversionListenerTest extends ConversionTestCase
{
    public function testAddToCartIsQueuedWithTheAddedQuantityAndAnIdentifierSharedWithThePixel(): void
    {
        $this->onRequest($this->visitorRequest(path: '/product/helmet'));
        $cart = $this->fixtures->cart();
        $cartItem = $this->fixtures->cartItem($cart, $this->product('HELMET-1', 'Helmet'), null, ['quantity' => 3.0, 'price' => '10.000000']);

        $event = new CartEvent($cart);
        $event->setCartItem($cartItem);
        $event->setQuantity(1);
        $this->listener()->onCartAddItem($event);

        $addToCart = $this->onlyQueuedEvent(ConversionTracker::ADD_TO_CART);
        self::assertNotNull($addToCart->eventId);
        self::assertSame($addToCart->eventId, (new PixelEventIds($this->requestStack))->pull(ConversionTracker::ADD_TO_CART), 'The pixel reads the identifier of the server event.');
        self::assertNull((new PixelEventIds($this->requestStack))->pull(ConversionTracker::ADD_TO_CART), 'The identifier is handed to the pixel once.');
        self::assertSame('https://shop.test/product/helmet', $addToCart->sourceUrl);
        self::assertSame(self::VISITOR_IP, $addToCart->userData['client_ip_address']);
        self::assertSame(self::VISITOR_USER_AGENT, $addToCart->userData['client_user_agent']);
        self::assertSame(self::VISITOR_FBP, $addToCart->userData['fbp']);
        $taxedPrice = round($cartItem->getRealTaxedPrice(Country::getDefaultCountry()), 2);
        self::assertGreaterThan(10.0, $taxedPrice, 'The tax rule of the fixture adds a tax: the price sent includes it.');
        self::assertSame(
            [
                'contents' => [['id' => 'HELMET-1', 'title' => 'Helmet', 'item_price' => $taxedPrice, 'quantity' => 1]],
                'currency' => strtolower((string) $cart->getCurrency()?->getCode()),
                'value' => $taxedPrice,
            ],
            $addToCart->customData,
        );
    }

    public function testNothingIsQueuedForAVisitorWhoRefusedOrHasNotAnsweredYet(): void
    {
        foreach ([false, null] as $consent) {
            $this->onRequest($this->visitorRequest($consent));

            $this->listener()->onDeliveryAddressChosen();
            $this->tracker()->trackPageView();
        }

        self::assertSame([], $this->queuedEventNames());
    }

    public function testTheConsentIsNotAskedForWhenTheShopDoesNotRequireIt(): void
    {
        MetaConversionsApi::setConfigValue(MetaConversionsApi::META_TRACKER_CONSENT_REQUIRED, '0');
        $this->onRequest($this->visitorRequest(null));

        $this->tracker()->trackPageView();

        self::assertSame([ConversionTracker::PAGE_VIEW], $this->queuedEventNames());
    }

    public function testNothingIsQueuedOutsideProduction(): void
    {
        $this->onRequest($this->visitorRequest());

        $this->listener('dev')->onDeliveryAddressChosen();
        $this->tracker('')->trackPageView();

        self::assertSame([], $this->queuedEventNames());
    }

    public function testNothingIsQueuedFromTheBackOffice(): void
    {
        $customer = $this->fixtures->customer($this->fixtures->customerTitle());

        foreach (['/admin/customer/create', '/api/admin/customers'] as $path) {
            $this->onRequest($this->visitorRequest(path: $path));
            $this->listener()->onCustomerCreateAccount((new CustomerCreateOrUpdateMinimalEvent())->setCustomer($customer));
        }

        self::assertSame([], $this->queuedEventNames());
    }

    public function testInitiateCheckoutIsSentOncePerVisitUntilAnOrderIsPlaced(): void
    {
        $this->onRequest($this->visitorRequest());
        $listener = $this->listener();

        $listener->onDeliveryAddressChosen();
        $listener->onDeliveryAddressChosen();
        self::assertSame([ConversionTracker::INITIATE_CHECKOUT], $this->queuedEventNames());

        $this->queue->drain();
        $listener->onOrderPlaced(new OrderEvent($this->fixtures->order()));
        $listener->onDeliveryAddressChosen();

        self::assertSame([ConversionTracker::INITIATE_CHECKOUT], $this->queuedEventNames(), 'A new checkout starts after the order.');
    }

    public function testCompleteRegistrationCarriesTheCustomerDataHashedAfterNormalisation(): void
    {
        $this->onRequest($this->visitorRequest(path: '/register'));
        $customer = $this->fixtures->customer($this->fixtures->customerTitle(), ['email' => ' John.Doe@Example.COM ', 'firstname' => 'John', 'lastname' => "O'Brien"]);
        $this->fixtures->address($customer, null, null, ['zipcode' => '75 001', 'city' => 'Saint-Denis'])
            ->setIsDefault(1)
            ->save($this->getPropelConnection());

        $this->listener()->onCustomerCreateAccount((new CustomerCreateOrUpdateMinimalEvent())->setCustomer($customer));

        $userData = $this->onlyQueuedEvent(ConversionTracker::COMPLETE_REGISTRATION)->userData;
        self::assertSame([hash('sha256', 'john.doe@example.com')], $userData['em']);
        self::assertSame([hash('sha256', 'john')], $userData['fn']);
        self::assertSame([hash('sha256', 'obrien')], $userData['ln'], 'Names keep their letters only.');
        self::assertSame([hash('sha256', '75001')], $userData['zp']);
        self::assertSame([hash('sha256', 'saintdenis')], $userData['ct']);
        self::assertSame([hash('sha256', mb_strtolower((string) $customer->getRef()))], $userData['external_id']);
        self::assertStringNotContainsString('example.com', (string) json_encode($userData));
    }

    public function testTheConsentCanBeReadFromTheAxeptioChoicesCookie(): void
    {
        $accepted = Request::create('https://shop.test/', 'GET', [], ['axeptio_cookies' => '{"$$token":"abc","facebook_pixel":true}']);
        $refused = Request::create('https://shop.test/', 'GET', [], ['axeptio_cookies' => '{"$$token":"abc","facebook_pixel":false}']);
        $broken = Request::create('https://shop.test/', 'GET', [], ['axeptio_cookies' => '{not json']);

        foreach ([$accepted, $refused, $broken] as $request) {
            $this->onRequest($request);
            $this->tracker()->trackPageView();
        }

        self::assertCount(1, $this->queue->events(), 'Only the visitor who accepted is tracked.');
    }

    public function testAProductOfferedByACouponIsNotAnAddToCart(): void
    {
        $this->onRequest($this->visitorRequest(path: '/cart'));
        $cart = $this->fixtures->cart();
        $cartItem = $this->fixtures->cartItem($cart, $this->product('GIFT-1', 'Gift'), null, ['isOffered' => 1]);

        $event = new CartEvent($cart);
        $event->setCartItem($cartItem);
        $this->listener()->onCartAddItem($event);

        self::assertSame([], $this->queuedEventNames());
    }

    public function testAFailureOfTheModuleIsLoggedAndNeverStopsTheShop(): void
    {
        $this->onRequest($this->visitorRequest());
        $failingConsent = new class implements VisitorConsentInterface {
            public function allowsTracking(Request $request): bool
            {
                throw new \RuntimeException('consent tool down');
            }
        };

        $listener = $this->listener(visitorConsent: $failingConsent);
        $listener->onDeliveryAddressChosen();
        $listener->onOrderPlaced(new OrderEvent($this->fixtures->order()));

        self::assertSame([], $this->queuedEventNames());
        self::assertTrue($this->logs->hasErrorThatContains('consent tool down'));
    }

    public function testAnInvalidUtf8UserAgentIsFrozenOnTheOrderWithoutStoppingIt(): void
    {
        $order = $this->orderWithTwoProducts();
        $request = $this->visitorRequest(path: '/order/pay');
        $request->headers->set('User-Agent', "Broken\xC3\x28Agent");
        $this->onRequest($request);

        $this->listener()->onOrderPlaced(new OrderEvent($order));

        self::assertSame([], $this->logs->getRecords(), 'Nothing failed.');
        $snapshot = (new VisitorSnapshotStore())->find((int) $order->getId());
        self::assertNotNull($snapshot);
        self::assertSame("Broken\u{FFFD}(Agent", $snapshot->clientUserAgent);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function phones(): iterable
    {
        yield 'French mobile, national form' => ['FR', '06 12 34 56 78', '', '33612345678'];
        yield 'French landline, international form' => ['FR', '+33 (0)1 23 45 67 89', '', '33123456789'];
        yield 'French number typed with 0033' => ['FR', '0033 6 12 34 56 78', '', '33612345678'];
        yield 'German mobile, national form' => ['DE', '0151 2345 6789', '', '4915123456789'];
        yield 'German landline with the calling code, no plus' => ['DE', '', '49 30 1234567', '49301234567'];
    }

    #[DataProvider('phones')]
    public function testPhonesCarryTheCallingCodeOfTheAddressCountry(string $countryCode, string $phone, string $cellphone, string $expected): void
    {
        $this->onRequest($this->visitorRequest(path: '/register'));
        $customer = $this->fixtures->customer($this->fixtures->customerTitle());
        $country = CountryQuery::create()->findOneByIsoalpha2($countryCode);
        self::assertNotNull($country, 'The test database has no country '.$countryCode);
        $this->fixtures->address($customer, $country)
            ->setPhone($phone)
            ->setCellphone($cellphone)
            ->setIsDefault(1)
            ->save($this->getPropelConnection());

        $this->listener()->onCustomerCreateAccount((new CustomerCreateOrUpdateMinimalEvent())->setCustomer($customer));

        $userData = $this->onlyQueuedEvent(ConversionTracker::COMPLETE_REGISTRATION)->userData;
        self::assertSame([hash('sha256', $expected)], $userData['ph']);
        self::assertSame([hash('sha256', strtolower($countryCode))], $userData['country']);
    }

    public function testNoCustomerDataLeavesWhenThePersonalDataOptionIsOff(): void
    {
        MetaConversionsApi::setConfigValue(MetaConversionsApi::META_TRACKER_TRACK_PERSONAL_DATA, '0');
        $this->onRequest($this->visitorRequest(path: '/register'));
        $customer = $this->fixtures->customer($this->fixtures->customerTitle());

        $this->listener()->onCustomerCreateAccount((new CustomerCreateOrUpdateMinimalEvent())->setCustomer($customer));

        self::assertSame(
            ['client_ip_address', 'client_user_agent', 'fbp'],
            array_keys($this->onlyQueuedEvent(ConversionTracker::COMPLETE_REGISTRATION)->userData),
        );
    }

    public function testPurchaseOnThePaymentProviderCallReadsTheVisitorFrozenOnTheOrder(): void
    {
        $order = $this->orderWithTwoProducts();
        $listener = $this->listener();

        $this->onRequest($this->visitorRequest(path: '/order/pay'));
        $listener->onOrderPlaced(new OrderEvent($order));

        // The payment provider calls the shop: another address, another agent, no cookie and no session of the visitor.
        $this->onRequest(Request::create('https://shop.test/payzen/notification', 'POST', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.1',
            'HTTP_USER_AGENT' => 'PaymentProvider/2.0',
        ]));
        $listener->onOrderStatusUpdate($this->paidStatusEvent($order));

        $purchase = $this->onlyQueuedEvent(ConversionTracker::PURCHASE);
        self::assertSame($order->getRef(), $purchase->eventId);
        self::assertSame('https://shop.test/order/pay', $purchase->sourceUrl);
        self::assertSame(self::VISITOR_IP, $purchase->userData['client_ip_address']);
        self::assertSame(self::VISITOR_USER_AGENT, $purchase->userData['client_user_agent']);
        self::assertSame(self::VISITOR_FBP, $purchase->userData['fbp']);
        self::assertArrayHasKey('em', $purchase->userData);
        self::assertSame(25.0, $purchase->customData['value'] ?? null, 'Two products at 10 and a postage of 5.');
        self::assertSame(
            ['id' => 'HELMET-1', 'title' => 'Helmet', 'item_price' => 10.0, 'quantity' => 2],
            array_diff_key($purchase->customData['contents'][0] ?? [], ['delivery_category' => true]),
        );
    }

    public function testPurchaseIsWorthWhatIsPaidWithTaxedUnitPrices(): void
    {
        $order = $this->orderWithTwoProducts(unitTax: '2.000000', discount: '3.000000');
        $listener = $this->listener();
        $this->onRequest($this->visitorRequest(path: '/order/pay'));
        $listener->onOrderPlaced(new OrderEvent($order));

        $listener->onOrderStatusUpdate($this->paidStatusEvent($order));

        $customData = $this->onlyQueuedEvent(ConversionTracker::PURCHASE)->customData ?? [];
        self::assertSame(26.0, $customData['value'] ?? null, 'Two helmets at 12 with taxes, 3 off, 5 of postage.');
        self::assertSame(12.0, $customData['contents'][0]['item_price'] ?? null);
    }

    public function testTheVisitorDetailsAreErasedOnceThePurchaseIsQueued(): void
    {
        $order = $this->orderWithTwoProducts();
        $listener = $this->listener();
        $this->onRequest($this->visitorRequest(path: '/order/pay'));
        $listener->onOrderPlaced(new OrderEvent($order));

        $listener->onOrderStatusUpdate($this->paidStatusEvent($order));
        $listener->onOrderStatusUpdate($this->paidStatusEvent($order));

        self::assertSame([ConversionTracker::PURCHASE], $this->queuedEventNames(), 'An order paid again sends no second Purchase.');
        self::assertNull((new VisitorSnapshotStore())->find((int) $order->getId()));
    }

    public function testNoPurchaseWhenTheVisitorRefusedWhilePlacingTheOrder(): void
    {
        $order = $this->orderWithTwoProducts();
        $listener = $this->listener();

        $this->onRequest($this->visitorRequest(false, '/order/pay'));
        $listener->onOrderPlaced(new OrderEvent($order));
        $this->onRequest($this->visitorRequest(true, '/order/placed'));
        $listener->onOrderStatusUpdate($this->paidStatusEvent($order));

        self::assertSame([], $this->queuedEventNames());
        self::assertNull((new VisitorSnapshotStore())->find((int) $order->getId()));
    }

    /**
     * An order created from the back office never goes through ORDER_BEFORE_PAYMENT: it carries no visitor, even when
     * it is paid later from the order page by an administrator who accepted the cookies of the shop.
     */
    public function testNoPurchaseForAnOrderCreatedInTheBackOffice(): void
    {
        $order = $this->orderWithTwoProducts();

        $this->onRequest($this->visitorRequest(true, '/admin/order/update/'.$order->getId()));
        $this->listener()->onOrderStatusUpdate($this->paidStatusEvent($order));

        self::assertSame([], $this->queuedEventNames());
    }

    public function testNoPurchaseForAnotherStatus(): void
    {
        $order = $this->orderWithTwoProducts();
        $listener = $this->listener();
        $this->onRequest($this->visitorRequest(path: '/order/pay'));
        $listener->onOrderPlaced(new OrderEvent($order));

        $event = new OrderEvent($order);
        $event->setStatus((int) OrderStatusQuery::getCancelledStatus()?->getId());
        $listener->onOrderStatusUpdate($event);

        self::assertSame([], $this->queuedEventNames());
    }

    public function testTheListenersRunAfterTheCoreOnesInTheShop(): void
    {
        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        foreach ([
            TheliaEvents::CART_ADDITEM => 'onCartAddItem',
            TheliaEvents::CART_SET_DELIVERY_ADDRESS => 'onDeliveryAddressChosen',
            TheliaEvents::CREATE_CUSTOMER_MINIMAL => 'onCustomerCreateAccount',
            TheliaEvents::CUSTOMER_CREATEACCOUNT => 'onCustomerCreateAccount',
            TheliaEvents::ORDER_BEFORE_PAYMENT => 'onOrderPlaced',
            TheliaEvents::ORDER_UPDATE_STATUS => 'onOrderStatusUpdate',
        ] as $eventName => $method) {
            $priority = null;
            foreach ($dispatcher->getListeners($eventName) as $listener) {
                if (\is_array($listener) && $listener[0] instanceof ConversionListener && $method === $listener[1]) {
                    $priority = $dispatcher->getListenerPriority($eventName, $listener);
                }
            }

            self::assertNotNull($priority, \sprintf('%s is not listened to', $eventName));
            self::assertLessThan(128, $priority, \sprintf('%s must run after the core listener', $eventName));
        }
    }

    private function paidStatusEvent(Order $order): OrderEvent
    {
        $event = new OrderEvent($order);
        $event->setStatus((int) OrderStatusQuery::create()->findOneByCode(OrderStatus::CODE_PAID)?->getId());

        return $event;
    }
}
