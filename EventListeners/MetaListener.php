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
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Log\Tlog;
use Thelia\Model\Customer;
use Thelia\Model\Order;

class MetaListener implements EventSubscriberInterface
{
    public function __construct(
        protected RequestStack $requestStack,
        protected MetaOrderService $service
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::ORDER_PAY => ['onOrderCreation', 50]
        ];
    }

    public function onOrderCreation(OrderEvent $event): void
    {
        $this->sendData('Purchase',  $event->getPlacedOrder()->getRef(), $event->getPlacedOrder()->getCustomer(), $event->getPlacedOrder());
    }

    protected function sendData($eventName, $eventId, ?Customer $customer = null, $data = null): void
    {
        $accessToken = MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_TOKEN);
        $pixelId = MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_PIXEL_ID);
        $isActive = (bool)MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_ACTIVE);
        $testEventCode = MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_TEST_EVENT_CODE);
        $isTest = (bool)MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_TEST_MODE);

        $request = $this->requestStack->getCurrentRequest();
        $cookies = $request?->cookies;

        if (!$isActive || !$pixelId || !$accessToken){
            return;
        }

        try {
            $api = MetaApi::init(null, null, $accessToken);
            $api->setLogger(new MetaCurlLogger());

            $userData = (new MetaUserData())
                ->setClientIpAddress($_SERVER['REMOTE_ADDR'])
                ->setClientUserAgent($_SERVER['HTTP_USER_AGENT'])
                ->setFbc($cookies?->get('_fbc'))
                ->setFbp($cookies?->get('_fbp'))
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

            if ($data !== null && $eventName === "Purchase") {
                $event->setCustomData($this->service->getCustomData($data));
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