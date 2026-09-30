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

use MetaConversionsApi\Service\ConversionQueue;
use MetaConversionsApi\Service\ConversionsApiClient;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The events of the request leave in one call once the response is sent (kernel.terminate), or at the end of a
 * command: no page and no order waits for Meta.
 */
final readonly class SendConversionsListener implements EventSubscriberInterface
{
    public function __construct(
        private ConversionQueue $queue,
        private ConversionsApiClient $client,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => ['send', 0],
            ConsoleEvents::TERMINATE => ['send', 0],
        ];
    }

    public function send(): void
    {
        $this->client->send($this->queue->drain());
    }
}
