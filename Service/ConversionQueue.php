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

use Symfony\Contracts\Service\ResetInterface;

/**
 * The events of the current request, sent together once the response is gone
 * ({@see \MetaConversionsApi\EventListener\SendConversionsListener}).
 */
final class ConversionQueue implements ResetInterface
{
    /** @var list<ConversionEvent> */
    private array $events = [];

    public function add(ConversionEvent $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return list<ConversionEvent>
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * @return list<ConversionEvent>
     */
    public function drain(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    public function reset(): void
    {
        $this->events = [];
    }
}
