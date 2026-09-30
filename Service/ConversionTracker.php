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

namespace MetaConversionsApi\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Taxation\TaxEngine\TaxEngine;
use Thelia\Model\CartItem;
use Thelia\Model\Currency;
use Thelia\Model\Customer;
use Thelia\Model\Lang;
use Thelia\Model\Order;

/**
 * Turns what happens on the shop into events of the Conversions API, queued for the end of the request.
 *
 * Visitor events (PageView, AddToCart, InitiateCheckout, CompleteRegistration) are read from the request of the
 * visitor: never from the back office, and only when the visitor agreed to be tracked. Purchase is sent when the
 * order becomes paid, often without the visitor's browser: it reads the browser details frozen on the order when the
 * visitor placed it ({@see VisitorSnapshotStore}), and an order without them sends nothing.
 */
final readonly class ConversionTracker
{
    public const PAGE_VIEW = 'PageView';
    public const ADD_TO_CART = 'AddToCart';
    public const INITIATE_CHECKOUT = 'InitiateCheckout';
    public const COMPLETE_REGISTRATION = 'CompleteRegistration';
    public const PURCHASE = 'Purchase';

    /** Set once InitiateCheckout is sent, cleared when the visitor places an order: once per visit until then. */
    public const CHECKOUT_STARTED_SESSION_KEY = 'fb_begin_checkout';

    /** The back office and its API are never tracked. */
    private const ADMIN_PATH_PREFIXES = ['/admin', '/api/admin'];

    public function __construct(
        private Settings $settings,
        private VisitorConsentInterface $visitorConsent,
        private CustomerDataHasher $customerDataHasher,
        private CustomDataBuilder $customDataBuilder,
        private ConversionQueue $queue,
        private VisitorSnapshotStore $snapshotStore,
        private PixelEventIds $pixelEventIds,
        private RequestStack $requestStack,
        private SecurityContext $securityContext,
        private TaxEngine $taxEngine,
    ) {
    }

    public function trackPageView(): void
    {
        $request = $this->trackedVisitorRequest();
        if (null === $request) {
            return;
        }

        $this->queueVisitorEvent($request, self::PAGE_VIEW);
    }

    /**
     * Shares its identifier with the Meta pixel of the theme ({@see PixelEventIds}). A line offered by a promotion
     * (a free product added by a coupon) is not a conversion: nothing is sent.
     */
    public function trackAddToCart(CartItem $cartItem, float $addedQuantity, ?Currency $currency): void
    {
        if (1 === (int) $cartItem->getIsOffered()) {
            return;
        }

        $request = $this->trackedVisitorRequest();
        if (null === $request) {
            return;
        }

        $eventId = bin2hex(random_bytes(16));

        $this->queueVisitorEvent(
            $request,
            self::ADD_TO_CART,
            $this->customDataBuilder->forAddedCartItem($cartItem, $addedQuantity, $currency, $this->locale($request), $this->taxEngine->getDeliveryCountry()),
            eventId: $eventId,
        );

        $this->pixelEventIds->remember(self::ADD_TO_CART, $eventId);
    }

    public function trackInitiateCheckout(): void
    {
        $request = $this->trackedVisitorRequest();
        if (null === $request || !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        if ($session->get(self::CHECKOUT_STARTED_SESSION_KEY)) {
            return;
        }

        $session->set(self::CHECKOUT_STARTED_SESSION_KEY, 1);
        $this->queueVisitorEvent($request, self::INITIATE_CHECKOUT);
    }

    public function trackRegistration(Customer $customer): void
    {
        $request = $this->trackedVisitorRequest();
        if (null === $request) {
            return;
        }

        $this->queueVisitorEvent($request, self::COMPLETE_REGISTRATION, customer: $customer);
    }

    /**
     * Called when the visitor places the order: its browser details are frozen on the order for Purchase, and the next
     * delivery address chosen starts a new checkout.
     */
    public function rememberVisitor(Order $order): void
    {
        $request = $this->requestStack->getMainRequest();
        if (null !== $request && $request->hasSession()) {
            $request->getSession()->remove(self::CHECKOUT_STARTED_SESSION_KEY);
        }

        $request = $this->trackedVisitorRequest();
        if (null === $request || null === $order->getId()) {
            return;
        }

        $this->snapshotStore->save((int) $order->getId(), VisitorSnapshot::fromRequest($request));
    }

    /**
     * The order reference is the event identifier, as for the Purchase of the pixel. The snapshot is deleted once the
     * event is queued: it is no longer needed, and an order paid again sends no second Purchase.
     */
    public function trackPurchase(Order $order): void
    {
        if (!$this->settings->isSendingEnabled() || null === $order->getId()) {
            return;
        }

        $snapshot = $this->snapshotStore->find((int) $order->getId());
        if (null === $snapshot) {
            return;
        }

        $this->queue->add(new ConversionEvent(
            self::PURCHASE,
            time(),
            $this->userData($snapshot, $order->getCustomer()),
            $order->getRef(),
            $snapshot->sourceUrl,
            $this->customDataBuilder->forOrder($order),
        ));

        $this->snapshotStore->delete((int) $order->getId());
    }

    /**
     * The main request when it comes from a visitor of the shop who agreed to be tracked, null otherwise.
     */
    public function trackedVisitorRequest(): ?Request
    {
        if (!$this->settings->isSendingEnabled()) {
            return null;
        }

        $request = $this->requestStack->getMainRequest();
        if (null === $request) {
            return null;
        }

        foreach (self::ADMIN_PATH_PREFIXES as $prefix) {
            if (str_starts_with($request->getPathInfo(), $prefix)) {
                return null;
            }
        }

        return $this->visitorConsent->allowsTracking($request) ? $request : null;
    }

    /**
     * @param array<string, mixed>|null $customData
     */
    private function queueVisitorEvent(Request $request, string $name, ?array $customData = null, ?string $eventId = null, ?Customer $customer = null): void
    {
        $customer ??= $this->currentCustomer();

        $this->queue->add(new ConversionEvent(
            $name,
            time(),
            $this->userData(VisitorSnapshot::fromRequest($request), $customer),
            $eventId,
            $request->getUri(),
            $customData,
        ));
    }

    /**
     * @return array<string, string|list<string>>
     */
    private function userData(VisitorSnapshot $snapshot, ?Customer $customer): array
    {
        $userData = $snapshot->userData();

        if (null !== $customer && $this->settings->sendsPersonalData()) {
            $userData += $this->customerDataHasher->hash($customer);
        }

        return $userData;
    }

    private function currentCustomer(): ?Customer
    {
        $customer = $this->securityContext->getCustomerUser();

        return $customer instanceof Customer ? $customer : null;
    }

    private function locale(Request $request): string
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        $lang = $session instanceof Session ? $session->getLang() : null;

        return (string) ($lang ?? Lang::getDefaultLanguage())->getLocale();
    }
}
