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
        $this->sendData('Purchase', $event->getPlacedOrder());
    }

    protected function sendData($eventName, $data = null): void
    {
        $accessToken = MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_TOKEN);
        $pixelId = MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_PIXEL_ID);
        $isActive = (bool)MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_ACTIVE);
        $testEventCode = MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_TEST_EVENT_CODE);
        $isTest = (bool)MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_TEST_MODE);

        if (!$isActive || !$pixelId || !$accessToken){
            return;
        }

        try {
            $api = MetaApi::init(null, null, $accessToken);
            $api->setLogger(new MetaCurlLogger());

            $userData = (new MetaUserData())
                ->setClientIpAddress($_SERVER['REMOTE_ADDR'])
                ->setClientUserAgent($_SERVER['HTTP_USER_AGENT'])
            ;

            if ($data !== null) {
                $userData = $this->service->getCustomerInfo($userData, $data);
            }

            $event = (new MetaEvent())
                ->setEventName($eventName)
                ->setEventTime(time())
                ->setEventSourceUrl($this->requestStack->getCurrentRequest()->getRequestUri())
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