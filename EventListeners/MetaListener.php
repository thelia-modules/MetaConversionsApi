<?php

namespace MetaConversionsApi\EventListeners;

use FacebookAds\Api as MetaApi;
use FacebookAds\Logger\CurlLogger as MetaCurlLogger;
use FacebookAds\Object\ServerSide\ActionSource as MetaActionSource;
use FacebookAds\Object\ServerSide\Event as MetaEvent;
use FacebookAds\Object\ServerSide\EventRequest as MetaEventRequest;
use FacebookAds\Object\ServerSide\UserData as MetaUserData;
use MetaConversionsApi\MetaConversionsApi;
use MetaConversionsApi\Service\MetaOrderService;
use OpenApi\Events\OpenApiEvents;
use OpenApi\OpenApi;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Cart\CartEvent;
use Thelia\Core\Event\Customer\CustomerCreateOrUpdateEvent;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Log\Tlog;
use Thelia\Model\Customer;
use Thelia\Model\Order;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;

class MetaListener implements EventSubscriberInterface
{
    public function __construct(
        protected RequestStack $requestStack,
        protected MetaOrderService $service
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::ORDER_UPDATE_STATUS => ['onOrderStatusUpdate', 50],
            TheliaEvents::CART_ADDITEM => ['onCartAddItem', 50],
            TheliaEvents::CUSTOMER_CREATEACCOUNT => ['onCustomerCreateAccount', 50],
            TheliaEvents::ORDER_PAY => ['onOrderPay', 50],
            TheliaEvents::ORDER_SET_DELIVERY_ADDRESS => ['onBeginCheckout', 50]
        ];
    }

    public function onBeginCheckout(OrderEvent $event): void
    {
        if(!$this->requestStack->getSession()->get('fb_begin_checkout')) {
            $this->requestStack->getSession()->set('fb_begin_checkout', 1);
            $this->sendData( 'InitiateCheckout');
        }
    }

    public function onOrderPay(OrderEvent $event): void
    {
        $this->requestStack->getSession()->set('fb_begin_checkout', null);
    }
    public function onCustomerCreateAccount(CustomerCreateOrUpdateEvent $event): void
    {
        $this->sendData( 'CompleteRegistration');
    }
    public function onCartAddItem(CartEvent $event): void
    {
        $this->sendData(
            'AddToCart',
            null,
            null,
            $this->service->getAddItemData($event->getCartItem(), $event->getCart()->getCurrency())
        );
    }

    public function onOrderStatusUpdate(OrderEvent $event): void
    {
        $orderStatusPay = OrderStatusQuery::create()->filterByCode(OrderStatus::CODE_PAID)->findOne();
        if ((int)$event->getStatus() === $orderStatusPay?->getId()) {
            $this->sendData(
                'Purchase',
                $event->getOrder()->getRef(),
                $event->getOrder()->getCustomer(),
                $this->service->getOrderData($event->getOrder())
            );
        }
    }

    protected function sendData($eventName, $eventId = null, ?Customer $customer = null, $data = null): void
    {
        $accessToken = MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_TOKEN);
        $pixelId = MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_PIXEL_ID);
        $isActive = (bool)MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_ACTIVE);
        $testEventCode = MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_TEST_EVENT_CODE);
        $isTest = (bool)MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_TEST_MODE);
        $metaConversionEnv = $_ENV['META_CONVERSION_ENV'];

        $request = $this->requestStack->getCurrentRequest();
        $cookies = $request?->cookies;

        if (!$isActive || !$pixelId || !$accessToken || $metaConversionEnv !== 'prod'){
            return;
        }

        if (null === $customer) {
            $customer = $this->requestStack->getSession()->get('thelia.customer_user');
        }

        try {
            $api = MetaApi::init(null, null, $accessToken);
            $api->setLogger(new MetaCurlLogger());

            $userData = (new MetaUserData())
                ->setClientIpAddress($_SERVER['REMOTE_ADDR'])
                ->setClientUserAgent($_SERVER['HTTP_USER_AGENT'])
                ->setFbc($cookies?->get('_fbc'))
                ->setFbp($cookies?->get('_fbp'))
                ->setFbLoginId(null)
            ;

            $userData = $this->service->getCustomerInfo($userData, $customer);

            $event = (new MetaEvent())
                ->setEventId($eventId)
                ->setEventName($eventName)
                ->setEventTime(time())
                ->setEventSourceUrl($request?->getRequestUri())
                ->setUserData($userData)
                ->setActionSource(MetaActionSource::WEBSITE)
            ;

            if ($data !== null) {
                $event->setCustomData($data);
            }

            $request = (new MetaEventRequest($pixelId))->setEvents([$event]);

            if ($isTest) {
                $request->setTestEventCode($testEventCode);
            }

            $response = $request->execute();
        }catch (\Exception $exception){
            Tlog::getInstance()->addAlert($exception->getMessage());
        }
    }
}