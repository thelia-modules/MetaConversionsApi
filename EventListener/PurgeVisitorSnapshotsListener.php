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

use MetaConversionsApi\Service\Settings;
use MetaConversionsApi\Service\VisitorSnapshotStore;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Core\Event\Maintenance\MaintenancePurgeEvent;
use Thelia\Core\Event\TheliaEvents;

/**
 * `thelia maintenance:purge` deletes the visitor details of the orders never paid within the retention period.
 */
final readonly class PurgeVisitorSnapshotsListener implements EventSubscriberInterface
{
    public function __construct(
        private VisitorSnapshotStore $snapshotStore,
        private Settings $settings,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::MAINTENANCE_PURGE => 'onMaintenancePurge',
        ];
    }

    public function onMaintenancePurge(MaintenancePurgeEvent $event): void
    {
        $days = $this->settings->snapshotRetentionDays();

        $event->addResult(\sprintf(
            '<comment>Meta Conversions API visitor details (>%d days):</comment> <info>%d deleted</info>',
            $days,
            $this->snapshotStore->purgeOlderThan($days),
        ));
    }
}
