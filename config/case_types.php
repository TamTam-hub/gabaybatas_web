<?php

declare(strict_types=1);

if (!function_exists('gabaybatas_case_types')) {
    function gabaybatas_case_types(): array
    {
        return [
            'Public Disturbance',
            'Theft',
            'Physical Injury',
            'Property Damage',
            'Domestic Dispute',
            'Threat or Harassment',
            'Noise Complaint',
            'Boundary Dispute',
        ];
    }
}

if (!function_exists('gabaybatas_case_type_allowed')) {
    function gabaybatas_case_type_allowed(string $value): bool
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return false;
        }

        foreach (gabaybatas_case_types() as $type) {
            if (strtolower($type) === $normalized) {
                return true;
            }
        }

        return false;
    }
}
