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

/**
 * Whether the visitor behind a request agreed to be tracked by Meta. The module ships an Axeptio reader
 * ({@see AxeptioVisitorConsent}); a shop using another consent tool aliases this interface to its own reader.
 */
interface VisitorConsentInterface
{
    public function allowsTracking(Request $request): bool;
}
