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

use Thelia\Model\Customer;

/**
 * The customer part of the `user_data` of an event, hashed in SHA-256 after the normalisation Meta asks for: lower
 * case and trimmed; names reduced to their letters; phones in digits only with the country calling code of the
 * address and without the national trunk 0 (`33612345678`); zip code without spaces. A value Meta would refuse (an
 * invalid e-mail, a country that is not two letters) is left out instead of losing the whole event.
 * The customer reference goes out hashed too.
 */
final readonly class CustomerDataHasher
{
    /**
     * @return array<string, list<string>>
     */
    public function hash(Customer $customer): array
    {
        $address = $customer->getDefaultAddress();
        $countryCode = (string) $address?->getCountry()?->getIsoalpha2();

        $fields = [
            'em' => [$this->normalizeEmail((string) $customer->getEmail())],
            'ph' => [
                $this->normalizePhone((string) $address?->getPhone(), $countryCode),
                $this->normalizePhone((string) $address?->getCellphone(), $countryCode),
            ],
            'fn' => [$this->normalizeName((string) $customer->getFirstname())],
            'ln' => [$this->normalizeName((string) $customer->getLastname())],
            'ct' => [$this->normalizeCity((string) $address?->getCity())],
            'zp' => [$this->normalizeZipCode((string) $address?->getZipcode())],
            'country' => [$this->normalizeCountry($countryCode)],
            'external_id' => [$this->normalize((string) $customer->getRef())],
        ];

        $hashed = [];
        foreach ($fields as $key => $values) {
            $hashes = array_values(array_unique(array_map(
                static fn (string $value): string => hash('sha256', $value),
                array_filter($values, static fn (?string $value): bool => null !== $value && '' !== $value),
            )));

            if ([] !== $hashes) {
                $hashed[$key] = $hashes;
            }
        }

        return $hashed;
    }

    public function normalizeEmail(string $email): ?string
    {
        $email = filter_var($this->normalize($email), \FILTER_SANITIZE_EMAIL);

        if (!\is_string($email) || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }

    private function normalize(string $value): string
    {
        return trim(mb_strtolower($value));
    }

    /**
     * Digits only, with the calling code: `+33 6 12 34 56 78`, `+33 (0)6 12 34 56 78`, `0033612345678` and
     * `06 12 34 56 78` for a French address all give `33612345678`. The national trunk 0 is dropped, except where it belongs to the number (Italy,
     * San Marino, Vatican). Without a known country, only the international forms get their calling code.
     */
    public function normalizePhone(string $phone, string $countryCode): ?string
    {
        $phone = trim(str_replace('(0)', '', $phone));
        $isInternational = str_starts_with($phone, '+') || str_starts_with($phone, '00');
        $digits = (string) preg_replace('/\D/', '', $phone);

        if ('' === $digits) {
            return null;
        }

        if ($isInternational) {
            return str_starts_with($phone, '+') ? $digits : substr($digits, 2);
        }

        $callingCode = CountryCallingCodes::forCountry($countryCode);
        if (null === $callingCode) {
            return $digits;
        }

        if (str_starts_with($digits, '0') && !CountryCallingCodes::keepsTrunkZero($countryCode)) {
            return $callingCode.substr($digits, 1);
        }

        return str_starts_with($digits, $callingCode) && \strlen($digits) > 10 ? $digits : $callingCode.$digits;
    }

    public function normalizeName(string $name): string
    {
        return (string) preg_replace('/[^\p{L}]/u', '', $this->normalize($name));
    }

    private function normalizeCity(string $city): string
    {
        return trim((string) preg_replace('/[0-9.\s\-()]/', '', $this->normalize($city)));
    }

    private function normalizeZipCode(string $zipCode): string
    {
        $zipCode = (string) preg_replace('/ /', '', $this->normalize($zipCode));

        return explode('-', $zipCode)[0];
    }

    private function normalizeCountry(string $country): ?string
    {
        $country = (string) preg_replace('/[^a-z]/', '', $this->normalize($country));

        return 2 === \strlen($country) ? $country : null;
    }
}
