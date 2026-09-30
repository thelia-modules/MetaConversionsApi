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

namespace MetaConversionsApi\Tests;

use MetaConversionsApi\EventListener\PurgeVisitorSnapshotsListener;
use MetaConversionsApi\MetaConversionsApi;
use MetaConversionsApi\Service\Settings;
use MetaConversionsApi\Service\VisitorSnapshot;
use MetaConversionsApi\Service\VisitorSnapshotPersonalData;
use MetaConversionsApi\Service\VisitorSnapshotStore;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Maintenance\MaintenancePurgeEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Customer\Service\CustomerPersonalDataExporter;

/**
 * The visitor details frozen on an order are personal data: exported and erased with the customer, purged after the
 * retention period when the order is never paid.
 */
final class VisitorSnapshotRetentionTest extends ConversionTestCase
{
    public function testTheSnapshotsOfACustomerAreExportedThenErased(): void
    {
        $order = $this->orderWithTwoProducts();
        $otherOrder = $this->orderWithTwoProducts();
        $store = new VisitorSnapshotStore();
        $store->save((int) $order->getId(), $this->snapshot());
        $store->save((int) $otherOrder->getId(), $this->snapshot());
        $customer = $order->getCustomer();
        $provider = new VisitorSnapshotPersonalData($store);

        self::assertSame(
            [['order_id' => (int) $order->getId(), 'client_ip_address' => self::VISITOR_IP, 'client_user_agent' => self::VISITOR_USER_AGENT, 'fbp' => self::VISITOR_FBP, 'source_url' => 'https://shop.test/order/pay']],
            $provider->exportPersonalData($customer),
        );

        $provider->anonymizePersonalData($customer);

        self::assertSame([], $provider->exportPersonalData($customer));
        self::assertNotNull($store->find((int) $otherOrder->getId()), 'The orders of other customers keep theirs.');
    }

    public function testTheShopExportsTheSnapshotsAndPurgesThem(): void
    {
        $order = $this->orderWithTwoProducts();
        (new VisitorSnapshotStore())->save((int) $order->getId(), $this->snapshot());
        $container = static::getContainer();

        $exporter = $container->get(CustomerPersonalDataExporter::class);
        self::assertInstanceOf(CustomerPersonalDataExporter::class, $exporter);
        self::assertCount(1, $exporter->export($order->getCustomer())['meta_conversions_api'] ?? []);

        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        self::assertNotEmpty(array_filter(
            $dispatcher->getListeners(TheliaEvents::MAINTENANCE_PURGE),
            static fn (mixed $listener): bool => \is_array($listener) && $listener[0] instanceof PurgeVisitorSnapshotsListener,
        ));
    }

    public function testTheMaintenancePurgeDeletesTheSnapshotsOlderThanTheRetentionPeriod(): void
    {
        MetaConversionsApi::setConfigValue(MetaConversionsApi::META_TRACKER_SNAPSHOT_RETENTION_DAYS, '30');
        $store = new VisitorSnapshotStore();
        $oldOrder = $this->orderWithTwoProducts();
        $recentOrder = $this->orderWithTwoProducts();
        $store->save((int) $oldOrder->getId(), $this->snapshot());
        $store->save((int) $recentOrder->getId(), $this->snapshot());
        $this->getPropelConnection()
            ->prepare('UPDATE meta_data SET updated_at = DATE_SUB(NOW(), INTERVAL 31 DAY) WHERE meta_key = :key AND element_id = :id')
            ->execute([':key' => VisitorSnapshotStore::META_KEY, ':id' => $oldOrder->getId()]);

        $event = new MaintenancePurgeEvent();
        (new PurgeVisitorSnapshotsListener($store, new Settings()))->onMaintenancePurge($event);

        self::assertNull($store->find((int) $oldOrder->getId()));
        self::assertNotNull($store->find((int) $recentOrder->getId()));
        self::assertStringContainsString('1 deleted', implode("\n", $event->getResults()));
    }

    private function snapshot(): VisitorSnapshot
    {
        return VisitorSnapshot::fromRequest($this->visitorRequest(path: '/order/pay'));
    }
}
