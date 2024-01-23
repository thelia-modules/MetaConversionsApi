<?php

namespace MetaConversionsApi\Service;

use FacebookAds\Object\ServerSide\Content as MetaContent;
use FacebookAds\Object\ServerSide\CustomData as MetaCustomData;
use FacebookAds\Object\ServerSide\DeliveryCategory as MetaDeliveryCategory;
use FacebookAds\Object\ServerSide\UserData as MetaUserData;
use Thelia\Model\Base\ModuleQuery;
use Thelia\Model\CustomerQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderProductTaxQuery;

class MetaOrderService
{
    public function getCustomerInfo(MetaUserData $userData, $customer = null): MetaUserData
    {
        if (null !== $customer) {
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

    public function getCustomData(Order $order): MetaCustomData
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

        $content->setDeliveryCategory($this->getDeliveryMode($deliveryModule->getFullNamespace()));

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
}