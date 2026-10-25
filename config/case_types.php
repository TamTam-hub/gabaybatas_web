<?php

declare(strict_types=1);

if (!function_exists('gabaybatas_case_types')) {
    /**
     * Complaint types offered to residents. KP covers every dispute between
     * residents of the same city except the excluded ones (see
     * kp_not_covered_reasons()), so "Other Dispute" catches the rest.
     */
    function gabaybatas_case_types(): array
    {
        return [
            'Public Disturbance',
            'Theft',
            'Physical Injury',
            'Property Damage',
            'Family Dispute (No Violence)',
            'Threat or Harassment',
            'Noise Complaint',
            'Boundary Dispute',
            'Unpaid Debt or Money Claim',
            'Oral Defamation (Slight)',
            'Trespassing',
            'Rental Dispute',
            'Swindling or Estafa (Small Amount)',
            'Other Dispute',
        ];
    }
}

if (!function_exists('gabaybatas_case_type_aliases')) {
    /** Former type names still accepted (e.g. from older app versions). */
    function gabaybatas_case_type_aliases(): array
    {
        return [
            'domestic dispute' => 'Family Dispute (No Violence)',
        ];
    }
}

if (!function_exists('gabaybatas_normalize_case_type')) {
    /** The official spelling of a case type, or '' if it is not offered. */
    function gabaybatas_normalize_case_type(string $value): string
    {
        $normalized = strtolower(trim((string)(preg_replace('/\s+/', ' ', $value) ?? '')));
        if ($normalized === '') {
            return '';
        }

        foreach (gabaybatas_case_types() as $type) {
            if (strtolower($type) === $normalized) {
                return $type;
            }
        }

        return gabaybatas_case_type_aliases()[$normalized] ?? '';
    }
}

if (!function_exists('gabaybatas_case_type_allowed')) {
    function gabaybatas_case_type_allowed(string $value): bool
    {
        return gabaybatas_normalize_case_type($value) !== '';
    }
}
