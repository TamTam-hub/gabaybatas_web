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

if (!function_exists('gabaybatas_complaint_statement')) {
    /**
     * The complainant's statement printed on KP Form No. 7. Used by both the
     * resident app (api/v1/cases_submit.php) and the barangay's Add Case page,
     * so the complaint reads the same however it was filed.
     */
    function gabaybatas_complaint_statement(
        string $complainant,
        string $age,
        string $complainantArea,
        string $respondent,
        string $respondentArea,
        string $reason
    ): string {
        return sprintf(
            'Ako po hi %s, %s anyos, ngan naukoy ha %s. Ginrereklamo ko hi %s, nga naukoy ha %s, tungod %s. Karuyag ko unta ipatawag hiya ha barangay basi magkastorya kami hin maopay.',
            $complainant,
            $age,
            $complainantArea,
            $respondent,
            $respondentArea,
            rtrim($reason, " \t\n\r\0\x0B.")
        );
    }
}

if (!function_exists('gabaybatas_parse_complaint_statement')) {
    /**
     * Splits a statement made by gabaybatas_complaint_statement() back into its
     * parts, so it can be corrected field by field. Anything after the
     * statement (e.g. "[Resident Update ...]" notes from the app) is returned
     * as 'extra'. Returns null for free-text descriptions of older cases.
     *
     * @return array{name: string, age: string, area: string, respondent: string, respondent_area: string, reason: string, extra: string}|null
     */
    function gabaybatas_parse_complaint_statement(string $text): ?array
    {
        $pattern = '/^Ako po hi (.+?), (\d+) anyos, ngan naukoy ha (.+?)\. Ginrereklamo ko hi (.+?), nga naukoy ha (.+?), tungod (.+?)\. '
            . 'Karuyag ko unta ipatawag hiya ha barangay basi magkastorya kami hin maopay\.(.*)$/su';
        if (!preg_match($pattern, trim($text), $m)) {
            return null;
        }
        return [
            'name'            => $m[1],
            'age'             => $m[2],
            'area'            => $m[3],
            'respondent'      => $m[4],
            'respondent_area' => $m[5],
            'reason'          => $m[6],
            'extra'           => trim($m[7]),
        ];
    }
}

if (!function_exists('gabaybatas_case_type_color')) {
    /**
     * A fixed chart colour for each case type, so a type looks the same on
     * every chart (admin Home, Analytics). Soft mid-tone palette.
     */
    function gabaybatas_case_type_color(string $type): string
    {
        $colors = [
            'Public Disturbance'                 => '#6366f1',
            'Theft'                              => '#14b8a6',
            'Physical Injury'                    => '#ef4444',
            'Property Damage'                    => '#f59e0b',
            'Family Dispute (No Violence)'       => '#ec4899',
            'Threat or Harassment'               => '#f97316',
            'Noise Complaint'                    => '#8b5cf6',
            'Boundary Dispute'                   => '#10b981',
            'Unpaid Debt or Money Claim'         => '#0ea5e9',
            'Oral Defamation (Slight)'           => '#a855f7',
            'Trespassing'                        => '#3b82f6',
            'Rental Dispute'                     => '#84cc16',
            'Swindling or Estafa (Small Amount)' => '#eab308',
            'Other Dispute'                      => '#94a3b8',
        ];
        $official = gabaybatas_normalize_case_type($type);
        if (isset($colors[$official])) {
            return $colors[$official];
        }
        // Older type names keep a stable colour of their own.
        $spare = ['#64748b', '#0891b2', '#be185d', '#65a30d', '#7c3aed', '#c2410c'];
        return $spare[abs(crc32(strtolower(trim($type)))) % count($spare)];
    }
}
