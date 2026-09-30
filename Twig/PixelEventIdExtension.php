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

namespace MetaConversionsApi\Twig;

use MetaConversionsApi\Service\PixelEventIds;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `meta_conversions_api_event_id('AddToCart')`: the identifier of the last AddToCart sent by the server, null when
 * none is waiting. The theme passes it as `eventID` to the Meta pixel so that Meta counts the conversion once.
 */
final class PixelEventIdExtension extends AbstractExtension
{
    public function __construct(
        private readonly PixelEventIds $pixelEventIds,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('meta_conversions_api_event_id', $this->pixelEventIds->pull(...)),
        ];
    }
}
