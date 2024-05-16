<?php

namespace MetaConversionsApi\EventListeners;

use MetaConversionsApi\Service\MetaOrderService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Cart\CartEvent;
use Thelia\Core\Event\Customer\CustomerCreateOrUpdateEvent;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
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
            $this->service->sendData( 'InitiateCheckout');
        }
    }

    public function onOrderPay(OrderEvent $event): void
    {
        $this->requestStack->getSession()->set('fb_begin_checkout', null);
    }

    public function onCustomerCreateAccount(CustomerCreateOrUpdateEvent $event): void
    {
        $this->service->sendData( 'CompleteRegistration', null, $event->getCustomer());
    }

    public function onCartAddItem(CartEvent $event): void
    {
        $this->service->sendData(
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
            $this->service->sendData(
                'Purchase',
                $event->getOrder()->getRef(),
                $event->getOrder()->getCustomer(),
                $this->service->getOrderData($event->getOrder())
            );
        }
    }
}