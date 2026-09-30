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

/**
 * One server event of the Conversions API, personal data already hashed.
 */
final readonly class ConversionEvent
{
    public const ACTION_SOURCE_WEBSITE = 'website';

    /**
     * @param array<string, string|list<string>> $userData
     * @param array<string, mixed>|null          $customData
     */
    public function __construct(
        public string $name,
        public int $time,
        public array $userData,
        public ?string $eventId = null,
        public ?string $sourceUrl = null,
        public ?array $customData = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter(
            [
                'event_name' => $this->name,
                'event_time' => $this->time,
                'event_id' => $this->eventId,
                'event_source_url' => $this->sourceUrl,
                'action_source' => self::ACTION_SOURCE_WEBSITE,
                'user_data' => $this->userData,
                'custom_data' => $this->customData,
            ],
            static fn (mixed $value): bool => null !== $value,
        );
    }
}
