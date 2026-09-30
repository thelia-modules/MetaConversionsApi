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

use Thelia\Domain\Customer\Service\CustomerPersonalDataProviderInterface;
use Thelia\Model\Customer;

/**
 * The visitor details frozen on the orders of a customer, in the export of the customer's data and erased with the
 * customer.
 */
final readonly class VisitorSnapshotPersonalData implements CustomerPersonalDataProviderInterface
{
    public function __construct(
        private VisitorSnapshotStore $snapshotStore,
    ) {
    }

    public function getPersonalDataSectionName(): string
    {
        return 'meta_conversions_api';
    }

    public function exportPersonalData(Customer $customer): array
    {
        $export = [];

        foreach ($this->snapshotStore->findForCustomer((int) $customer->getId()) as $orderId => $snapshot) {
            $export[] = ['order_id' => $orderId] + $snapshot->toArray();
        }

        return $export;
    }

    public function anonymizePersonalData(Customer $customer): void
    {
        $this->snapshotStore->deleteForCustomer((int) $customer->getId());
    }
}
