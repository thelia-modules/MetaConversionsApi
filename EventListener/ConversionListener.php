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
use Thelia\Core\Event\Cart\CartEvent;
use Thelia\Core\Event\Customer\CustomerCreateOrUpdateEvent;
use Thelia\Core\Event\Customer\CustomerCreateOrUpdateMinimalEvent;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\Customer;
use Thelia\Model\OrderStatusQuery;

/**
 * Every listener runs after the core one (priority 128), once the cart line, the customer or the order is written.
 *
 * - AddToCart: a product added to the cart.
 * - InitiateCheckout: a delivery address chosen in the checkout, once per visit until an order is placed.
 * - CompleteRegistration: an account created by the visitor (CREATE_CUSTOMER_MINIMAL is the registration of the
 *   front office, CUSTOMER_CREATEACCOUNT the full form; the back office is never tracked).
 * - Purchase: an order that reaches the paid status.
 *
 * A failure of the module is logged and never thrown: the cart, the registration, the order and its payment go on.
 */
final readonly class ConversionListener implements EventSubscriberInterface
{
    public const PRIORITY = 50;

    public function __construct(
        private ConversionTracker $tracker,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::CART_ADDITEM => ['onCartAddItem', self::PRIORITY],
            TheliaEvents::CART_SET_DELIVERY_ADDRESS => ['onDeliveryAddressChosen', self::PRIORITY],
            TheliaEvents::CREATE_CUSTOMER_MINIMAL => ['onCustomerCreateAccount', self::PRIORITY],
            TheliaEvents::CUSTOMER_CREATEACCOUNT => ['onCustomerCreateAccount', self::PRIORITY],
            TheliaEvents::ORDER_BEFORE_PAYMENT => ['onOrderPlaced', self::PRIORITY],
            TheliaEvents::ORDER_UPDATE_STATUS => ['onOrderStatusUpdate', self::PRIORITY],
        ];
    }

    public function onCartAddItem(CartEvent $event): void
    {
        $this->safely(ConversionTracker::ADD_TO_CART, function () use ($event): void {
            $cartItem = $event->getCartItem();
            if (null === $cartItem) {
                return;
            }

            $this->tracker->trackAddToCart(
                $cartItem,
                (float) ($event->getQuantity() ?? $cartItem->getQuantity()),
                $event->getCart()->getCurrency(),
            );
        });
    }

    public function onDeliveryAddressChosen(): void
    {
        $this->safely(ConversionTracker::INITIATE_CHECKOUT, $this->tracker->trackInitiateCheckout(...));
    }

    public function onCustomerCreateAccount(CustomerCreateOrUpdateEvent|CustomerCreateOrUpdateMinimalEvent $event): void
    {
        $this->safely(ConversionTracker::COMPLETE_REGISTRATION, function () use ($event): void {
            $customer = $event instanceof CustomerCreateOrUpdateEvent ? $event->customer : $event->getCustomer();
            if (!$customer instanceof Customer) {
                return;
            }

            $this->tracker->trackRegistration($customer);
        });
    }

    public function onOrderPlaced(OrderEvent $event): void
    {
        $this->safely('order placed', fn () => $this->tracker->rememberVisitor($event->getOrder()));
    }

    public function onOrderStatusUpdate(OrderEvent $event): void
    {
        $this->safely(ConversionTracker::PURCHASE, function () use ($event): void {
            $paidStatus = OrderStatusQuery::getPaidStatus();
            if (null === $paidStatus || (int) $event->getStatus() !== (int) $paidStatus->getId()) {
                return;
            }

            $this->tracker->trackPurchase($event->getOrder());
        });
    }

    private function safely(string $what, callable $track): void
    {
        try {
            $track();
        } catch (\Throwable $throwable) {
            $this->logger->error(\sprintf('Meta Conversions API: %s not tracked (%s: %s)', $what, $throwable::class, $throwable->getMessage()));
        }
    }
}
