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
 * International calling codes by ISO 3166-1 alpha-2 country (ITU-T E.164), for the phone numbers sent to Meta.
 * Thelia stores no calling code for its countries.
 */
final class CountryCallingCodes
{
    private const CALLING_CODES = [
        'AD' => '376', 'AE' => '971', 'AL' => '355', 'AM' => '374', 'AR' => '54', 'AT' => '43', 'AU' => '61',
        'AZ' => '994', 'BA' => '387', 'BE' => '32', 'BG' => '359', 'BR' => '55', 'BY' => '375', 'CA' => '1',
        'CH' => '41', 'CL' => '56', 'CN' => '86', 'CO' => '57', 'CY' => '357', 'CZ' => '420', 'DE' => '49',
        'DK' => '45', 'DZ' => '213', 'EE' => '372', 'EG' => '20', 'ES' => '34', 'FI' => '358', 'FO' => '298',
        'FR' => '33', 'GB' => '44', 'GE' => '995', 'GF' => '594', 'GI' => '350', 'GL' => '299', 'GP' => '590',
        'GR' => '30', 'HK' => '852', 'HR' => '385', 'HU' => '36', 'IE' => '353', 'IL' => '972', 'IN' => '91',
        'IS' => '354', 'IT' => '39', 'JP' => '81', 'KR' => '82', 'LI' => '423', 'LT' => '370', 'LU' => '352',
        'LV' => '371', 'MA' => '212', 'MC' => '377', 'MD' => '373', 'ME' => '382', 'MK' => '389', 'MQ' => '596',
        'MT' => '356', 'MU' => '230', 'MX' => '52', 'NC' => '687', 'NL' => '31', 'NO' => '47', 'NZ' => '64',
        'PF' => '689', 'PL' => '48', 'PM' => '508', 'PT' => '351', 'RE' => '262', 'RO' => '40', 'RS' => '381',
        'RU' => '7', 'SE' => '46', 'SG' => '65', 'SI' => '386', 'SK' => '421', 'SM' => '378', 'SN' => '221',
        'TN' => '216', 'TR' => '90', 'UA' => '380', 'US' => '1', 'VA' => '39', 'YT' => '262', 'ZA' => '27',
    ];

    /** Countries whose national numbers keep their leading 0 after the calling code. */
    private const TRUNK_ZERO_KEPT = ['IT', 'SM', 'VA'];

    private function __construct()
    {
    }

    public static function forCountry(string $countryCode): ?string
    {
        return self::CALLING_CODES[strtoupper($countryCode)] ?? null;
    }

    public static function keepsTrunkZero(string $countryCode): bool
    {
        return \in_array(strtoupper($countryCode), self::TRUNK_ZERO_KEPT, true);
    }
}
