<?php

namespace TechLegend\LaravelNotifyAfrica;

use InvalidArgumentException;

final class PhoneNumberNormalizer
{
    public function __construct(
        private readonly ?string $defaultCountryCallingCode = null,
    ) {}

    public function normalize(string $number): string
    {
        $trimmed = trim($number);
        if ($trimmed === '') {
            throw new InvalidArgumentException('[Notify Africa] Phone number cannot be empty.');
        }

        $withoutPlus = ltrim($trimmed, '+');
        $digits = preg_replace('/\D+/', '', $withoutPlus) ?? '';

        if ($digits === '') {
            throw new InvalidArgumentException('[Notify Africa] Phone number must contain at least one digit.');
        }

        $prefix = $this->normalizedPrefix();

        if ($prefix !== null && $this->looksLocal($digits)) {
            return $prefix.$digits;
        }

        return $digits;
    }

    /**
     * @param  array<int, string>  $numbers
     * @return array<int, string>
     */
    public function normalizeMany(array $numbers): array
    {
        return array_map(fn (string $n) => $this->normalize($n), $numbers);
    }

    private function normalizedPrefix(): ?string
    {
        if ($this->defaultCountryCallingCode === null || $this->defaultCountryCallingCode === '') {
            return null;
        }

        return preg_replace('/\D+/', '', $this->defaultCountryCallingCode) ?: null;
    }

    private function looksLocal(string $digits): bool
    {
        $len = strlen($digits);

        return $len >= 9 && $len <= 10;
    }
}
