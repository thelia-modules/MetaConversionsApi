<?php

namespace MetaConversionsApi\Service;

use FacebookAds\Api as MetaApi;
use FacebookAds\Logger\CurlLogger as MetaCurlLogger;
use FacebookAds\Object\ServerSide\ActionSource as MetaActionSource;
use FacebookAds\Object\ServerSide\Event as MetaEvent;
use FacebookAds\Object\ServerSide\EventRequest as MetaEventRequest;
use FacebookAds\Object\ServerSide\UserData as MetaUserData;
use FacebookAds\Object\ServerSide\Content as MetaContent;
use FacebookAds\Object\ServerSide\CustomData as MetaCustomData;
use FacebookAds\Object\ServerSide\DeliveryCategory as MetaDeliveryCategory;
use MetaConversionsApi\MetaConversionsApi;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Log\Tlog;
use Thelia\Model\Base\ConfigQuery;
use Thelia\Model\Base\Currency;
use Thelia\Model\Base\ModuleQuery;
use Thelia\Model\CartItem;
use Thelia\Model\Customer;
use Thelia\Model\CustomerQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderProductTaxQuery;

class MetaOrderService
{

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function getCustomerInfo(MetaUserData $userData, $customer = null): MetaUserData
    {
        if (null !== $customer && MetaConversionsApi::getConfigValue(MetaConversionsApi::META_TRACKER_TRACK_PERSONAL_DATA)) {
            $customerAddress = $customer->getDefaultAddress();

            $userData
                ->setEmail($customer->getEmail())
                ->setPhones([$customerAddress->getPhone(), $customerAddress->getCellphone()])
                ->setFirstName($customer->getFirstname())
                ->setLastName($customer->getLastname())
                ->setCity($customerAddress->getCity())
                ->setZipCode($customerAddress->getZipcode())
                ->setCountryCode(strtolower($customerAddress->getCountry()->getIsoalpha2()))
                ->setExternalId($customer->getRef());
        }

        return $userData;
    }

    public function getOrderData(Order $order): MetaCustomData
    {
        $contents = [];
        $orderTotalTaxedPrice = 0;

        $postage = (float)$order->getPostage();

        foreach ($order->getOrderProducts() as $orderProduct) {
            $contents[] = $this->getContent($orderProduct);

            $orderTotalTaxedPrice += $this->getOrderTotalTaxedPrice($orderProduct);
        }

        return (new MetaCustomData())
            ->setContents($contents)
            ->setCurrency(strtolower($order->getCurrency()->getCode()))
            ->setValue($orderTotalTaxedPrice + $postage);
    }

    public function getAddItemData(CartItem $cartItem, Currency $currency): MetaCustomData
    {
        $product = $cartItem->getProduct();

        $content = (new MetaContent())
            ->setProductId($product->getRef())
            ->setTitle($product->getTitle())
            ->setDescription($product->getDescription())
            ->setItemPrice($cartItem->getPrice())
            ->setQuantity($cartItem->getQuantity())
        ;

        return (new MetaCustomData())
            ->setContents([$content])
            ->setCurrency(strtolower($currency->getCode()))
            ->setValue($cartItem->getPrice() * $cartItem->getQuantity());
    }

    public function getContent(OrderProduct $orderProduct): MetaContent
    {
        $content = (new MetaContent())
            ->setProductId($orderProduct->getProductRef())
            ->setTitle($orderProduct->getTitle())
            ->setDescription($orderProduct->getDescription())
            ->setItemPrice($orderProduct->getPrice())
            ->setQuantity($orderProduct->getQuantity())
        ;

        $deliveryModule = ModuleQuery::create()->findOneById($orderProduct->getOrder()->getDeliveryModuleId());

        $content->setDeliveryCategory($this->getDeliveryMode($deliveryModule?->getFullNamespace()));

        return $content;
    }

    public function getDeliveryMode(string $deliveryModuleClass): string
    {
        $deliveryModule = new $deliveryModuleClass;

        if ($deliveryModule->getDeliveryMode() === "delivery") {
            return MetaDeliveryCategory::HOME_DELIVERY;
        }

        if ($deliveryModule->getDeliveryMode() === "pickup") {
            return MetaDeliveryCategory::IN_STORE;
        }

        return MetaDeliveryCategory::CURBSIDE;
    }

    public function getOrderTotalTaxedPrice(OrderProduct $orderProduct): float|int
    {
        if (null !== $orderProductTax = OrderProductTaxQuery::create()->findOneByOrderProductId($orderProduct->getId())) {
            if ($orderProduct->getWasInPromo() === 1) {
                return round((float)$orderProduct->getPromoPrice() + (float)$orderProductTax->getPromoAmount(), 2)
                    * $orderProduct->getQuantity();
            }

            if ($orderProduct->getWasInPromo() === 0) {
                return round((float)$orderProduct->getPrice() + (float)$orderProductTax->getAmount(), 2)
                    * $orderProduct->getQuantity();
            }
        }

        return 0;
    }

    public function sendData($eventName, $eventId = null, ?Customer $customer = null, $data = null): void
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

            $userData = $this->getCustomerInfo($userData, $customer);

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