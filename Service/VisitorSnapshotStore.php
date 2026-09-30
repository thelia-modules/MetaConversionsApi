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

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\MetaData;
use Thelia\Model\MetaDataQuery;
use Thelia\Model\OrderQuery;

/**
 * Keeps the {@see VisitorSnapshot} of an order in the core `meta_data` table, as JSON. An order without one was not
 * placed by a consenting visitor through the checkout (refusal, back office, command line): it sends no Purchase.
 *
 * The snapshot is personal data kept only as long as it is needed: deleted as soon as Purchase is queued, purged after
 * the retention period when the order is never paid ({@see purgeOlderThan()}), exported and erased with the customer
 * ({@see VisitorSnapshotPersonalData}).
 */
final readonly class VisitorSnapshotStore
{
    public const META_KEY = 'meta_conversions_api_visitor';
    public const ELEMENT_KEY = 'order';

    public function save(int $orderId, VisitorSnapshot $snapshot): void
    {
        MetaDataQuery::setVal(
            self::META_KEY,
            self::ELEMENT_KEY,
            $orderId,
            json_encode($snapshot->toArray(), \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_UNESCAPED_SLASHES),
        );
    }

    public function find(int $orderId): ?VisitorSnapshot
    {
        $value = MetaDataQuery::getVal(self::META_KEY, self::ELEMENT_KEY, $orderId);

        return \is_string($value) ? $this->decode($value) : null;
    }

    public function delete(int $orderId): void
    {
        $this->query()->filterByElementId($orderId)->delete();
    }

    /**
     * @return array<int, VisitorSnapshot> keyed by order id
     */
    public function findForCustomer(int $customerId): array
    {
        $snapshots = [];

        foreach ($this->query()->filterByElementId($this->orderIdsOf($customerId), Criteria::IN)->find() as $metaData) {
            /** @var MetaData $metaData */
            $snapshot = $this->decode((string) $metaData->getValue());
            if (null !== $snapshot) {
                $snapshots[(int) $metaData->getElementId()] = $snapshot;
            }
        }

        return $snapshots;
    }

    public function deleteForCustomer(int $customerId): int
    {
        return $this->query()->filterByElementId($this->orderIdsOf($customerId), Criteria::IN)->delete();
    }

    /**
     * Deletes the snapshots written more than $days days ago; returns how many.
     */
    public function purgeOlderThan(int $days): int
    {
        return $this->query()
            ->filterByUpdatedAt(new \DateTimeImmutable(\sprintf('-%d days', max(0, $days))), Criteria::LESS_THAN)
            ->delete();
    }

    private function query(): MetaDataQuery
    {
        return MetaDataQuery::create()
            ->filterByMetaKey(self::META_KEY)
            ->filterByElementKey(self::ELEMENT_KEY);
    }

    /**
     * @return list<int>
     */
    private function orderIdsOf(int $customerId): array
    {
        return array_map('intval', OrderQuery::create()->filterByCustomerId($customerId)->select(['Id'])->find()->getData());
    }

    private function decode(string $value): ?VisitorSnapshot
    {
        try {
            $data = json_decode($value, true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($data) ? VisitorSnapshot::fromArray($data) : null;
    }
}
