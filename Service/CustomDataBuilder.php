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

use Thelia\Model\CartItem;
use Thelia\Model\Country;
use Thelia\Model\Currency;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Order;

/**
 * The `custom_data` of AddToCart and Purchase: the same contents as the 2.x line (product reference, title,
 * description, unit price, quantity, delivery category for an order). Unit prices include taxes, like the value.
 */
final readonly class CustomDataBuilder
{
    public const HOME_DELIVERY = 'home_delivery';
    public const IN_STORE = 'in_store';
    public const CURBSIDE = 'curbside';

    /**
     * The value is the quantity added by this event, not the quantity the line reaches.
     *
     * @return array<string, mixed>
     */
    public function forAddedCartItem(CartItem $cartItem, float $addedQuantity, ?Currency $currency, string $locale, Country $taxCountry): array
    {
        $product = $cartItem->getProduct();
        $product->setLocale($locale);
        $unitPrice = round($cartItem->getRealTaxedPrice($taxCountry), 2);

        return $this->customData(
            [$this->content(
                (string) $product->getRef(),
                $product->getTitle(),
                $product->getDescription(),
                $unitPrice,
                $addedQuantity,
            )],
            $currency,
            $unitPrice * $addedQuantity,
        );
    }

    /**
     * The value is what the customer pays: products and postage with taxes, discount deducted.
     *
     * @return array<string, mixed>
     */
    public function forOrder(Order $order): array
    {
        $deliveryCategory = $this->deliveryCategory((int) $order->getDeliveryModuleId());
        $contents = [];

        foreach ($order->getOrderProducts() as $orderProduct) {
            $isInPromo = 1 === (int) $orderProduct->getWasInPromo();
            $unitTax = 0.0;
            foreach ($orderProduct->getOrderProductTaxes() as $orderProductTax) {
                $unitTax += (float) ($isInPromo ? $orderProductTax->getPromoAmount() : $orderProductTax->getAmount());
            }

            $content = $this->content(
                (string) $orderProduct->getProductRef(),
                $orderProduct->getTitle(),
                $orderProduct->getDescription(),
                round((float) ($isInPromo ? $orderProduct->getPromoPrice() : $orderProduct->getPrice()), 2) + round($unitTax, 2),
                (float) $orderProduct->getQuantity(),
            );

            if (null !== $deliveryCategory) {
                $content['delivery_category'] = $deliveryCategory;
            }

            $contents[] = $content;
        }

        return $this->customData($contents, $order->getCurrency(), $order->getTotalAmount());
    }

    /**
     * @param list<array<string, mixed>> $contents
     *
     * @return array<string, mixed>
     */
    private function customData(array $contents, ?Currency $currency, float $value): array
    {
        return array_filter(
            [
                'contents' => $contents,
                'currency' => null === $currency ? null : strtolower((string) $currency->getCode()),
                'value' => round($value, 2),
            ],
            static fn (mixed $item): bool => null !== $item,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function content(string $reference, ?string $title, ?string $description, float $unitPrice, float $quantity): array
    {
        return array_filter(
            [
                'id' => $reference,
                'title' => $title,
                'description' => $description,
                'item_price' => round($unitPrice, 2),
                'quantity' => $quantity == (int) $quantity ? (int) $quantity : $quantity,
            ],
            static fn (mixed $item): bool => null !== $item && '' !== $item,
        );
    }

    private function deliveryCategory(int $deliveryModuleId): ?string
    {
        $deliveryModule = ModuleQuery::create()->findPk($deliveryModuleId);
        if (null === $deliveryModule) {
            return null;
        }

        try {
            $instance = $deliveryModule->createInstance();
        } catch (\ReflectionException) {
            return null;
        }

        if (!method_exists($instance, 'getDeliveryMode')) {
            return null;
        }

        return match ($instance->getDeliveryMode()) {
            'delivery' => self::HOME_DELIVERY,
            'pickup' => self::IN_STORE,
            default => self::CURBSIDE,
        };
    }
}
