<?php
/**
 * Katarungang Pambarangay (KP) Configuration
 * Barangay Muñoz, Catbalogan City, Samar
 *
 * Central configuration for the KP complaint workflow system.
 * Based on R.A. 7160 (Local Government Code of 1991), Chapter 7, Title One, Book III
 * and the Katarungang Pambarangay Handbook (SALIGAN/Philippines-Canada LGSP).
 */

declare(strict_types=1);

// ─── Barangay Header Information ─────────────────────────────────────────────
define('KP_BARANGAY_NAME',     'Muñoz');
define('KP_MUNICIPALITY',      'Catbalogan');
define('KP_PROVINCE',          'Samar');
define('KP_MUNICIPALITY_TYPE', 'Cuidad');          // Cuidad / Munisipiyo
define('KP_PUNONG_BARANGAY',   'HON. ULYSIS B. MACABARE');
// Lupon Secretary (concurrently the Barangay Secretary).
define('KP_LUPON_SECRETARY',   'CRISTEL VALLES');

// ─── KP Status Definitions ──────────────────────────────────────────────────
// Each KP status maps to the existing cases.status enum value.

if (!function_exists('kp_statuses')) {
    /**
     * Returns all valid KP statuses with their metadata.
     *
     * @return array<string, array{label: string, parent_status: string, stage: string, color: string}>
     */
    function kp_statuses(): array
    {
        return [
            // ── Intake ──
            'filed'                   => ['label' => 'Filed',                        'parent_status' => 'Pending',     'stage' => 'intake',        'color' => '#6b7280'],
            'under_review'            => ['label' => 'Under Review',                 'parent_status' => 'Pending',     'stage' => 'intake',        'color' => '#f59e0b'],
            'additional_info_required'=> ['label' => 'Additional Info Required',      'parent_status' => 'Pending',     'stage' => 'intake',        'color' => '#ef4444'],
            'accepted'                => ['label' => 'Accepted for KP Processing',   'parent_status' => 'In Progress', 'stage' => 'intake',        'color' => '#10b981'],
            'referred'                => ['label' => 'Referred',                     'parent_status' => 'In Progress', 'stage' => 'intake',        'color' => '#8b5cf6'],
            'not_eligible'            => ['label' => 'Not Eligible for KP',          'parent_status' => 'Resolved',    'stage' => 'intake',        'color' => '#6b7280'],

            // ── Mediation ──
            'mediation_scheduled'     => ['label' => 'Mediation Scheduled',          'parent_status' => 'In Progress', 'stage' => 'mediation',     'color' => '#3b82f6'],
            'mediation_ongoing'       => ['label' => 'Mediation Ongoing',            'parent_status' => 'In Progress', 'stage' => 'mediation',     'color' => '#2563eb'],
            'mediation_settled'       => ['label' => 'Settled in Mediation',         'parent_status' => 'In Progress', 'stage' => 'mediation',     'color' => '#059669'],
            'mediation_noshow_complainant' => ['label' => 'Complainant Absent (Mediation)', 'parent_status' => 'In Progress', 'stage' => 'mediation', 'color' => '#b45309'],

            // ── Conciliation (Pangkat) ──
            'pangkat_constitution'    => ['label' => 'Pangkat Constitution',          'parent_status' => 'In Progress', 'stage' => 'conciliation',  'color' => '#f97316'],
            'conciliation_scheduled'  => ['label' => 'Conciliation Scheduled',       'parent_status' => 'In Progress', 'stage' => 'conciliation',  'color' => '#ea580c'],
            'conciliation_ongoing'    => ['label' => 'Conciliation Ongoing',         'parent_status' => 'In Progress', 'stage' => 'conciliation',  'color' => '#d97706'],
            'conciliation_settled'    => ['label' => 'Settled in Conciliation',      'parent_status' => 'In Progress', 'stage' => 'conciliation',  'color' => '#059669'],
            'conciliation_noshow_complainant' => ['label' => 'Complainant Absent (Conciliation)', 'parent_status' => 'In Progress', 'stage' => 'conciliation', 'color' => '#b45309'],
            'conciliation_noshow_respondent'  => ['label' => 'Respondent Absent (Conciliation)',  'parent_status' => 'In Progress', 'stage' => 'conciliation', 'color' => '#b45309'],

            // ── Arbitration ──
            'arbitration_agreed'      => ['label' => 'Arbitration Agreed',           'parent_status' => 'In Progress', 'stage' => 'arbitration',   'color' => '#7c3aed'],
            'arbitration_hearing'     => ['label' => 'Arbitration Hearing',          'parent_status' => 'In Progress', 'stage' => 'arbitration',   'color' => '#6d28d9'],
            'arbitration_awarded'     => ['label' => 'Arbitration Award Issued',     'parent_status' => 'In Progress', 'stage' => 'arbitration',   'color' => '#059669'],

            // ── Settlement & Compliance ──
            'settlement_repudiation'  => ['label' => 'Repudiation Window',           'parent_status' => 'In Progress', 'stage' => 'settlement',    'color' => '#f59e0b'],
            'settlement_final'        => ['label' => 'Settlement Final & Executory', 'parent_status' => 'In Progress', 'stage' => 'settlement',    'color' => '#059669'],
            'compliance_monitoring'   => ['label' => 'Compliance Monitoring',        'parent_status' => 'In Progress', 'stage' => 'compliance',    'color' => '#0284c7'],

            // ── Execution ──
            'execution_pending'       => ['label' => 'Execution Pending',            'parent_status' => 'In Progress', 'stage' => 'execution',     'color' => '#dc2626'],
            'execution_completed'     => ['label' => 'Execution Completed',          'parent_status' => 'Resolved',    'stage' => 'execution',     'color' => '#16a34a'],

            // ── Disposition ──
            'certified_to_court'      => ['label' => 'Certified to File in Court',   'parent_status' => 'Resolved',    'stage' => 'disposition',   'color' => '#475569'],
            'dismissed'               => ['label' => 'Dismissed',                    'parent_status' => 'Resolved',    'stage' => 'disposition',   'color' => '#6b7280'],
            'closed'                  => ['label' => 'Closed',                       'parent_status' => 'Resolved',    'stage' => 'disposition',   'color' => '#16a34a'],
        ];
    }
}

if (!function_exists('kp_status_label')) {
    function kp_status_label(string $kpStatus): string
    {
        $all = kp_statuses();
        return $all[$kpStatus]['label'] ?? ucwords(str_replace('_', ' ', $kpStatus));
    }
}

if (!function_exists('kp_status_to_parent')) {
    /** Map a detailed KP status to the legacy cases.status enum value. */
    function kp_status_to_parent(string $kpStatus): string
    {
        $all = kp_statuses();
        return $all[$kpStatus]['parent_status'] ?? 'Pending';
    }
}

if (!function_exists('kp_status_color')) {
    function kp_status_color(string $kpStatus): string
    {
        $all = kp_statuses();
        return $all[$kpStatus]['color'] ?? '#6b7280';
    }
}

if (!function_exists('kp_status_stage')) {
    function kp_status_stage(string $kpStatus): string
    {
        $all = kp_statuses();
        return $all[$kpStatus]['stage'] ?? 'intake';
    }
}

if (!function_exists('kp_status_info')) {
    function kp_status_info(string $kpStatus): array
    {
        $all = kp_statuses();
        return $all[$kpStatus] ?? [
            'label' => ucwords(str_replace('_', ' ', $kpStatus)),
            'parent_status' => 'Pending',
            'stage' => 'intake',
            'color' => '#6b7280',
        ];
    }
}

// ─── KP Dispositions (how a case legally ended) ─────────────────────────────

if (!function_exists('kp_dispositions')) {
    /**
     * Legal conclusions a case can reach, with the certification form (if any)
     * that evidences it.
     *
     * @return array<string, array{label: string, form: int|null}>
     */
    function kp_dispositions(): array
    {
        return [
            'not_covered'                => ['label' => 'Not covered by KP',                                  'form' => null],
            'settled_complied'           => ['label' => 'Settled and complied with',                          'form' => null],
            'executed'                   => ['label' => 'Settlement/award executed',                          'form' => null],
            'execution_lapsed_mtc'       => ['label' => '6-month execution period lapsed (enforce in MTC)',  'form' => null],
            'cfa_settlement_repudiated'  => ['label' => 'Settlement repudiated — certified to file action',   'form' => 20],
            'cfa_arbitration_repudiated' => ['label' => 'Arbitration agreement repudiated — certified to file action', 'form' => 20],
            'cfa_conciliation_failed'    => ['label' => 'Conciliation failed — certified to file action',     'form' => 21],
            'cfa_respondent_absent'      => ['label' => 'Respondent absent — certified to file action; counterclaim barred', 'form' => 22],
            'barred_complainant_absent'  => ['label' => 'Complainant absent — complaint dismissed and action barred', 'form' => 23],
        ];
    }
}

if (!function_exists('kp_disposition_label')) {
    function kp_disposition_label(?string $disposition): string
    {
        if ($disposition === null || $disposition === '') {
            return '';
        }
        return kp_dispositions()[$disposition]['label'] ?? ucwords(str_replace('_', ' ', $disposition));
    }
}

// ─── Reasons a complaint is not covered by KP ───────────────────────────────

if (!function_exists('kp_not_covered_reasons')) {
    /**
     * Grounds for excluding a complaint from KP proceedings (LGC Sec. 408,
     * SC Administrative Circular 14-93, RA 9262; SALIGAN KP Handbook, 2022).
     *
     * @return array<string, string> reason key => label
     */
    function kp_not_covered_reasons(): array
    {
        return [
            'vawc'                     => 'Violence against women and their children (RA 9262) — barangay KP proceedings are prohibited',
            'juridical_person'         => 'A party is a corporation, partnership, estate or other juridical person',
            'government_party'         => 'A party is the government or its agency, office or instrumentality',
            'public_officer'           => 'A party is a public officer or employee and the dispute relates to official functions',
            'penalty_exceeds'          => 'Offense punishable by imprisonment of more than 1 year or a fine of more than ₱5,000',
            'no_private_offended_party'=> 'Offense with no private offended party',
            'different_city'           => 'Parties live in different cities/municipalities (barangays not adjoining, or no agreement to submit)',
            'real_property_other_city' => 'Real property located in different cities/municipalities (no agreement to submit)',
            'labor'                    => 'Labor dispute arising from an employer–employee relationship',
            'agrarian'                 => 'Agrarian dispute under the Comprehensive Agrarian Reform Law',
            'urgent_court_action'      => 'Urgent court action needed (accused detained, habeas corpus, or provisional remedies)',
            'prescription'             => 'The action may prescribe (lapse) if it goes through KP first',
            'annul_compromise'         => 'Action to annul a judgment upon a compromise',
            'other'                    => 'Other ground (explain in the notes)',
        ];
    }
}

// ─── Nature of case (KP Monitoring Form No. 1) ──────────────────────────────

if (!function_exists('kp_case_natures')) {
    /** @return array<string, string> */
    function kp_case_natures(): array
    {
        return ['criminal' => 'Criminal', 'civil' => 'Civil', 'others' => 'Others'];
    }
}

if (!function_exists('kp_suggest_nature')) {
    /**
     * Suggested nature for a complaint type. Staff confirm or change it at
     * review, since some types (e.g. property damage) can be either.
     */
    function kp_suggest_nature(string $caseType): string
    {
        $map = [
            'theft'                => 'criminal',
            'physical injury'      => 'criminal',
            'threat or harassment' => 'criminal',
            'public disturbance'   => 'criminal',
            'property damage'      => 'criminal',
            'boundary dispute'     => 'civil',
            'noise complaint'      => 'others',
            'domestic dispute'     => 'others',
            'family dispute (no violence)'       => 'others',
            'unpaid debt or money claim'         => 'civil',
            'oral defamation (slight)'           => 'criminal',
            'trespassing'                        => 'criminal',
            'rental dispute'                     => 'civil',
            'swindling or estafa (small amount)' => 'criminal',
            'other dispute'                      => 'others',
        ];
        return $map[strtolower(trim($caseType))] ?? 'others';
    }
}

if (!function_exists('kp_case_nature')) {
    /** Recorded nature of a case, or the suggestion when none was recorded. */
    function kp_case_nature(array $case): string
    {
        $nature = (string)($case['kp_nature'] ?? '');
        return array_key_exists($nature, kp_case_natures()) ? $nature : kp_suggest_nature((string)($case['case_type'] ?? ''));
    }
}

if (!function_exists('kp_case_outcome_label')) {
    /**
     * Outcome text shown to residents: the disposition label, plus the legal
     * ground when the complaint was not covered by KP.
     */
    function kp_case_outcome_label(mysqli $conn, int $caseId, ?string $disposition): string
    {
        $label = kp_disposition_label($disposition);
        if ($disposition !== 'not_covered' || $caseId <= 0) {
            return $label;
        }
        $stmt = $conn->prepare("SELECT reason FROM kp_referrals WHERE case_id = ? ORDER BY id DESC LIMIT 1");
        if (!$stmt) {
            return $label;
        }
        $stmt->bind_param('i', $caseId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $reason = $row ? (kp_not_covered_reasons()[$row['reason']] ?? '') : '';
        return $reason !== '' ? "{$label}: {$reason}" : $label;
    }
}

// ─── KP Status Transitions ──────────────────────────────────────────────────

if (!function_exists('kp_allowed_transitions')) {
    /**
     * Status changes permitted by the KP procedure (from → allowed targets).
     * Terminal statuses have no outgoing transitions.
     *
     * @return array<string, string[]>
     */
    function kp_allowed_transitions(): array
    {
        $review     = ['under_review', 'additional_info_required', 'accepted', 'not_eligible', 'referred'];
        $mediation  = ['mediation_scheduled', 'mediation_ongoing', 'mediation_settled', 'pangkat_constitution', 'mediation_noshow_complainant', 'arbitration_agreed'];
        $conciliation = ['conciliation_scheduled', 'conciliation_ongoing', 'conciliation_settled', 'certified_to_court', 'conciliation_noshow_complainant', 'conciliation_noshow_respondent', 'arbitration_agreed'];

        return [
            'filed'                           => $review,
            'under_review'                    => $review,
            'additional_info_required'        => $review,
            'referred'                        => [],
            'accepted'                        => ['mediation_scheduled'],
            'not_eligible'                    => [],

            'mediation_scheduled'             => $mediation,
            'mediation_ongoing'               => $mediation,
            'mediation_noshow_complainant'    => ['mediation_ongoing', 'dismissed'],
            'mediation_settled'               => ['settlement_repudiation'],

            'pangkat_constitution'            => ['pangkat_constitution', 'conciliation_scheduled', 'arbitration_agreed'],
            'conciliation_scheduled'          => $conciliation,
            'conciliation_ongoing'            => $conciliation,
            'conciliation_noshow_complainant' => ['conciliation_ongoing', 'dismissed'],
            'conciliation_noshow_respondent'  => ['conciliation_ongoing', 'certified_to_court'],
            'conciliation_settled'            => ['settlement_repudiation'],

            'arbitration_agreed'              => ['arbitration_hearing', 'certified_to_court'],
            'arbitration_hearing'             => ['arbitration_hearing', 'arbitration_awarded'],
            'arbitration_awarded'             => ['settlement_final'],

            'settlement_repudiation'          => ['settlement_final', 'certified_to_court'],
            'settlement_final'                => ['compliance_monitoring'],
            'compliance_monitoring'           => ['closed', 'execution_pending'],
            'execution_pending'               => ['execution_completed'],
            'execution_completed'             => ['closed'],

            'certified_to_court'              => [],
            'dismissed'                       => [],
            'closed'                          => [],
        ];
    }
}

if (!function_exists('kp_can_transition')) {
    function kp_can_transition(string $from, string $to): bool
    {
        $map = kp_allowed_transitions();
        return in_array($to, $map[$from] ?? [], true);
    }
}

if (!function_exists('kp_correction_status_options')) {
    /**
     * Status choices for the manual correction tool: the current status plus
     * only the statuses the procedure allows next. Legacy cases without a KP
     * status keep the general statuses and may enter the KP workflow as Filed.
     *
     * @return array<string, string> status key => label
     */
    function kp_correction_status_options(string $current): array
    {
        $legacy = ['Pending', 'In Progress', 'Resolved'];
        if (!array_key_exists($current, kp_statuses())) {
            $options = [];
            foreach (array_merge($legacy, ['filed']) as $status) {
                $options[$status] = kp_status_label($status);
            }
            if (!isset($options[$current]) && $current !== '') {
                $options = [$current => $current] + $options;
            }
            return $options;
        }

        $options = [$current => kp_status_label($current) . ' (current)'];
        foreach (kp_allowed_transitions()[$current] ?? [] as $status) {
            if ($status !== $current) {
                $options[$status] = kp_status_label($status);
            }
        }
        return $options;
    }
}

if (!function_exists('kp_post_acceptance_statuses')) {
    /** Statuses a case can only reach after being accepted for KP processing. */
    function kp_post_acceptance_statuses(): array
    {
        return array_values(array_diff(
            array_keys(kp_statuses()),
            ['filed', 'under_review', 'additional_info_required', 'referred', 'not_eligible']
        ));
    }
}

if (!function_exists('kp_terminal_statuses')) {
    function kp_terminal_statuses(): array
    {
        return ['not_eligible', 'referred', 'certified_to_court', 'dismissed', 'closed'];
    }
}

// ─── KP Case Stages (ordered) ───────────────────────────────────────────────

if (!function_exists('kp_stages')) {
    /**
     * Returns the ordered list of KP workflow stages with labels and icons.
     */
    function kp_stages(): array
    {
        return [
            'intake'        => ['label' => 'Filed / Review',    'icon' => 'uil-file-alt',        'order' => 1],
            'mediation'     => ['label' => 'Mediation',         'icon' => 'uil-balance-scale',    'order' => 2],
            'conciliation'  => ['label' => 'Conciliation',      'icon' => 'uil-comments-alt',     'order' => 3],
            'arbitration'   => ['label' => 'Arbitration',       'icon' => 'uil-user-check',       'order' => 4],
            'settlement'    => ['label' => 'Settlement',        'icon' => 'uil-file-check-alt',   'order' => 5],
            'compliance'    => ['label' => 'Compliance',        'icon' => 'uil-check-circle',     'order' => 6],
            'execution'     => ['label' => 'Execution',         'icon' => 'uil-bolt',             'order' => 7],
            'disposition'   => ['label' => 'Disposition',       'icon' => 'uil-archive',          'order' => 8],
        ];
    }
}

// ─── KP Form Registry ───────────────────────────────────────────────────────

if (!function_exists('kp_forms')) {
    /**
     * Complete registry of all 28 KP forms.
     *
     * @return array<int, array{title: string, title_waray: string, stage: string, trigger: string, signatories: string[], template: string|null}>
     */
    function kp_forms(): array
    {
        return [
            7  => [
                'title'        => 'Complaint (Reklamo/Sumbong)',
                'title_waray'  => 'Reklamo / Sumbong',
                'stage'        => 'intake',
                'trigger'      => 'Case accepted for KP processing',
                'signatories'  => ['Complainant (Tagreklamo)', 'Punong Barangay'],
                'template'     => 'KP_Form_07_Complaint.docx',
            ],
            8  => [
                'title'        => 'Notice of Hearing (Mediation)',
                'title_waray'  => 'Pahibaro han Pagdinig (Mediation)',
                'stage'        => 'mediation',
                'trigger'      => 'Mediation hearing scheduled',
                'signatories'  => ['Punong Barangay / Lupon Chairman'],
                'template'     => 'KP_Form_08_Notice_Hearing_Mediation.docx',
            ],
            9  => [
                'title'        => 'Summons (Pagpatawag)',
                'title_waray'  => 'Pagpatawag (Summons)',
                'stage'        => 'intake',
                'trigger'      => 'Case accepted — respondent summoned',
                'signatories'  => ['Punong Barangay / Pangkat Chairman'],
                'template'     => 'KP_Form_09_Summons.docx',
            ],
            // 9-A is stored as form_number 91 internally
            91 => [
                'title'        => "Officer's Return",
                'title_waray'  => "Officer's Return",
                'stage'        => 'intake',
                'trigger'      => 'Summons served to respondent',
                'signatories'  => ['Serving Officer', 'Respondent/Representative'],
                'template'     => 'KP_Form_09A_Officers_Return.docx',
            ],
            10 => [
                'title'        => 'Notice for Constitution of Pangkat',
                'title_waray'  => 'Pahibaro para han Pag-ukod han Pangkat',
                'stage'        => 'conciliation',
                'trigger'      => 'Mediation failed — Pangkat must be constituted',
                'signatories'  => ['Punong Barangay'],
                'template'     => 'KP_Form_10_Notice_Pangkat.docx',
            ],
            11 => [
                'title'        => 'Notice to Chosen Pangkat Member',
                'title_waray'  => 'Pahibaro ha Pinili nga Miyembro han Pangkat',
                'stage'        => 'conciliation',
                'trigger'      => 'Pangkat members selected',
                'signatories'  => ['Punong Barangay / Lupon Secretary'],
                'template'     => 'KP_Form_11_Notice_Pangkat_Member.docx',
            ],
            12 => [
                'title'        => 'Notice of Hearing (Conciliation)',
                'title_waray'  => 'Pahibaro han Pagdinig (Conciliation)',
                'stage'        => 'conciliation',
                'trigger'      => 'Conciliation hearing scheduled',
                'signatories'  => ['Pangkat Chairman'],
                'template'     => 'KP_Form_12_Notice_Hearing_Conciliation.docx',
            ],
            13 => [
                'title'        => 'Subpoena',
                'title_waray'  => 'Subpoena',
                'stage'        => 'mediation',
                'trigger'      => 'Witness testimony required',
                'signatories'  => ['Punong Barangay / Pangkat Chairman'],
                'template'     => 'KP_Form_13_Subpoena.docx',
            ],
            14 => [
                'title'        => 'Agreement for Arbitration',
                'title_waray'  => 'Kasabotan para han Arbitrasyon',
                'stage'        => 'arbitration',
                'trigger'      => 'Both parties agree to arbitration in writing',
                'signatories'  => ['Complainant', 'Respondent', 'Punong Barangay / Pangkat Chairman'],
                'template'     => 'KP_Form_14_Agreement_Arbitration.docx',
            ],
            15 => [
                'title'        => 'Arbitration Award',
                'title_waray'  => 'Desisyon han Arbitrasyon',
                'stage'        => 'arbitration',
                'trigger'      => 'Arbitrator renders the award after the 5-day repudiation period of the agreement, within 10 days thereafter',
                'signatories'  => ['Punong Barangay / Pangkat Chairman', 'Members', 'Attested by PB / Lupon Secretary'],
                'template'     => 'KP_Form_15_Arbitration_Award.docx',
            ],
            16 => [
                'title'        => 'Amicable Settlement',
                'title_waray'  => 'Hiniusa nga Kasabotan',
                'stage'        => 'settlement',
                'trigger'      => 'Parties reach settlement during mediation or conciliation',
                'signatories'  => ['Complainant', 'Respondent', 'Punong Barangay / Pangkat Chairman'],
                'template'     => 'KP_Form_16_Amicable_Settlement.docx',
            ],
            17 => [
                'title'        => 'Repudiation',
                'title_waray'  => 'Pagbasura',
                'stage'        => 'settlement',
                'trigger'      => 'Party repudiates a settlement (10 days) or an arbitration agreement (5 days) for fraud/violence/intimidation',
                'signatories'  => ['Repudiating party', 'Sworn before PB / Pangkat Chairman'],
                'template'     => 'KP_Form_17_Repudiation.docx',
            ],
            18 => [
                'title'        => 'Notice of Hearing — Complainant Failure to Appear',
                'title_waray'  => 'Pahibaro han Pagdinig — Waray Pag-atinder han Tagreklamo',
                'stage'        => 'mediation',
                'trigger'      => 'Complainant failed to appear — hearing to explain the absence',
                'signatories'  => ['Punong Barangay / Pangkat Chairman'],
                'template'     => 'KP_Form_18_Notice_Complainant_No_Show.docx',
            ],
            19 => [
                'title'        => 'Notice of Hearing — Respondent Failure to Appear',
                'title_waray'  => 'Pahibaro han Pagdinig — Waray Pag-atinder han Guinrereklamo',
                'stage'        => 'conciliation',
                'trigger'      => 'Respondent failed to appear before the Pangkat — hearing to explain the absence',
                'signatories'  => ['Punong Barangay / Pangkat Chairman'],
                'template'     => 'KP_Form_19_Notice_Respondent_No_Show.docx',
            ],
            20 => [
                'title'        => 'Certification to File Action (Lupon Secretary)',
                'title_waray'  => 'Sertipikasyon ha Pagpasaka hin Kaso (Lupon Secretary)',
                'stage'        => 'disposition',
                'trigger'      => 'Settlement or arbitration agreement repudiated by a party',
                'signatories'  => ['Lupon Secretary', 'Attested by Lupon Chairman'],
                'template'     => 'KP_Form_20_Cert_File_Action_Lupon.docx',
            ],
            21 => [
                'title'        => 'Certification to File Action (Pangkat Secretary)',
                'title_waray'  => 'Sertipikasyon ha Pagpasaka hin Kaso (Pangkat Secretary)',
                'stage'        => 'disposition',
                'trigger'      => 'Conciliation failed — no settlement reached before Pangkat',
                'signatories'  => ['Pangkat Secretary', 'Attested by Pangkat Chairman'],
                'template'     => 'KP_Form_21_Cert_File_Action_Pangkat.docx',
            ],
            22 => [
                'title'        => 'Certification to File Action (Respondent Willful Absence)',
                'title_waray'  => 'Sertipikasyon ha Pagpasaka hin Kaso (Waray Pag-atinder)',
                'stage'        => 'disposition',
                'trigger'      => 'Respondent willfully failed to appear at conciliation',
                'signatories'  => ['Pangkat Secretary', 'Attested by Pangkat Chairman'],
                'template'     => 'KP_Form_22_Cert_File_Action_Respondent_Absent.docx',
            ],
            23 => [
                'title'        => 'Certification to Bar Action',
                'title_waray'  => 'Sertipikasyon ha Pagpugong hin Kaso',
                'stage'        => 'disposition',
                'trigger'      => 'Complainant willfully failed to appear — complaint dismissed',
                'signatories'  => ['Lupon/Pangkat Secretary', 'Attested by Lupon/Pangkat Chairman'],
                'template'     => 'KP_Form_23_Cert_Bar_Action.docx',
            ],
            24 => [
                'title'        => 'Certification to Bar Counterclaim',
                'title_waray'  => 'Sertipikasyon ha Pagpugong hin Counterclaim',
                'stage'        => 'disposition',
                'trigger'      => 'Respondent willfully failed to appear — counterclaim barred',
                'signatories'  => ['Lupon/Pangkat Secretary', 'Attested by Lupon/Pangkat Chairman'],
                'template'     => 'KP_Form_24_Cert_Bar_Counterclaim.docx',
            ],
            25 => [
                'title'        => 'Motion for Execution',
                'title_waray'  => 'Mosyon para han Pag-ejecutar',
                'stage'        => 'execution',
                'trigger'      => 'Settlement/award is final but party has not complied',
                'signatories'  => ['Moving party (Complainant/Respondent)'],
                'template'     => 'KP_Form_25_Motion_Execution.docx',
            ],
            26 => [
                'title'        => 'Notice of Hearing (Re: Motion for Execution)',
                'title_waray'  => 'Pahibaro han Pagdinig (Pag-ejecutar)',
                'stage'        => 'execution',
                'trigger'      => 'Execution motion hearing scheduled',
                'signatories'  => ['Punong Barangay / Lupon Chairman'],
                'template'     => 'KP_Form_26_Notice_Hearing_Execution.docx',
            ],
            27 => [
                'title'        => 'Notice of Execution',
                'title_waray'  => 'Pahibaro han Pag-ejecutar',
                'stage'        => 'execution',
                'trigger'      => '5-day voluntary compliance period expired without compliance',
                'signatories'  => ['Punong Barangay'],
                'template'     => 'KP_Form_27_Notice_Execution.docx',
            ],
            28 => [
                'title'        => 'Monthly Transmittal of Final Reports',
                'title_waray'  => 'Bulan-bulan nga Pagpadara han mga Final nga Report',
                'stage'        => 'reporting',
                'trigger'      => 'First 5 days of each month — all settled/awarded cases',
                'signatories'  => ['Punong Barangay', 'Lupon/Pangkat Secretary'],
                'template'     => 'KP_Form_28_Monthly_Transmittal.docx',
            ],
        ];
    }
}

if (!function_exists('kp_form_label')) {
    /**
     * Printed form number of a KP form. Internal numbers 21–27 follow the
     * LGSP handbook; the DILG 2025 downloadable forms (and the DOJ KP Rules)
     * number them 20-A, 20-B, 21, 22, 23, 24 and 25.
     */
    function kp_form_label(int $formNumber): string
    {
        $labels = [91 => '9-A', 21 => '20-A', 22 => '20-B', 23 => '21', 24 => '22', 25 => '23', 26 => '24', 27 => '25'];
        return $labels[$formNumber] ?? (string)$formNumber;
    }
}

if (!function_exists('kp_form_file_label')) {
    /** Form number for file names, e.g. "07", "09A", "20A", "21". */
    function kp_form_file_label(int $formNumber): string
    {
        $label = str_replace('-', '', kp_form_label($formNumber));
        return preg_match('/^\d$/', $label) || preg_match('/^\d[A-Z]$/', $label) ? '0' . $label : $label;
    }
}

if (!function_exists('kp_form_info')) {
    /** Get info for a specific KP form by number. */
    function kp_form_info(int $formNumber): ?array
    {
        $all = kp_forms();
        return $all[$formNumber] ?? null;
    }
}

// ─── KP Timeline Rules (in days) ────────────────────────────────────────────

if (!function_exists('kp_deadlines')) {
    function kp_deadlines(): array
    {
        return [
            'notice_of_hearing_after_complaint'   => 1,    // Summons/notice on the next working day (LGC Sec. 410(b))
            'pangkat_convene_after_constitution'   => 3,    // Pangkat meets within 3 days of its constitution
            'mediation_period'                     => 15,   // 15 days for mediation
            'constitute_pangkat_after_mediation'   => 15,   // 15 days to constitute Pangkat
            'conciliation_period'                  => 15,   // 15 days for conciliation
            'conciliation_extension'               => 15,   // +15 days if meritorious
            'settlement_repudiation'               => 10,   // 10 days to repudiate settlement
            'arbitration_agreement_repudiation'    => 5,    // 5 days to repudiate arb. agreement
            'arbitration_award_due'                => 10,   // Award within 10 days after the agreement's repudiation period (LGC Sec. 413(b))
            'arbitration_award_distribution'       => 5,    // 5 days to distribute copies
            'execution_voluntary_compliance'       => 5,    // 5 days for voluntary compliance
            'execution_by_lupon_max_months'        => 6,    // 6 months max for Lupon execution
            'monthly_report_due_day'               => 5,    // First 5 days of each month
        ];
    }
}

// ─── Helper: Update KP status and sync parent status ─────────────────────────

if (!function_exists('kp_update_case_status')) {
    /**
     * Update a case's KP status and automatically sync the parent status.
     *
     * @param mysqli $conn
     * @param int    $caseId  The cases.id value
     * @param string $kpStatus The new KP status key
     * @return bool
     */
    function kp_update_case_status(mysqli $conn, int $caseId, string $kpStatus): bool
    {
        $parentStatus = kp_status_to_parent($kpStatus);
        $stmt = $conn->prepare("UPDATE cases SET kp_status = ?, status = ? WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ssi', $kpStatus, $parentStatus, $caseId);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }
}

// ─── Helper: Log a timeline event ────────────────────────────────────────────

if (!function_exists('kp_log_event')) {
    /**
     * Log a timeline event for a KP case.
     */
    function kp_log_event(
        mysqli $conn,
        int    $caseId,
        string $stage,
        string $status,
        string $eventType,
        string $title,
        string $details = '',
        ?int   $createdBy = null
    ): bool {
        $now = date('Y-m-d H:i:s');
        $stmt = $conn->prepare(
            "INSERT INTO kp_timeline_events (case_id, stage, status, event_type, title, details, event_at, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('issssssis', $caseId, $stage, $status, $eventType, $title, $details, $now, $createdBy, $now);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }
}

// ─── Helper: Validated status transition ─────────────────────────────────────

if (!function_exists('kp_transition_case')) {
    /**
     * Move a case to a new KP status only if the procedure allows it from the
     * case's current status. Optionally records the case disposition.
     */
    function kp_transition_case(mysqli $conn, int $caseId, string $to, ?string $disposition = null): bool
    {
        $stmt = $conn->prepare("SELECT kp_status FROM cases WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $caseId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $from = (string)($row['kp_status'] ?? 'filed');
        if (!$row || !kp_can_transition($from, $to)) {
            return false;
        }

        if (!kp_update_case_status($conn, $caseId, $to)) {
            return false;
        }

        if ($disposition !== null) {
            $upd = $conn->prepare("UPDATE cases SET kp_disposition = ? WHERE id = ? LIMIT 1");
            if ($upd) {
                $upd->bind_param('si', $disposition, $caseId);
                $upd->execute();
                $upd->close();
            }
        }
        return true;
    }
}

// ─── Helper: Load case proceedings ──────────────────────────────────────────

if (!function_exists('kp_load_case_context')) {
    /**
     * Load the proceeding records of a case that drive the workflow and forms.
     *
     * @return array{hearings: array, pangkat: array, settlements: array, agreements: array, repudiations: array, executions: array}
     */
    function kp_load_case_context(mysqli $conn, int $caseId): array
    {
        $queries = [
            'hearings'     => "SELECT * FROM kp_hearings WHERE case_id = ? ORDER BY hearing_date ASC, id ASC",
            'pangkat'      => "SELECT * FROM kp_pangkat WHERE case_id = ? ORDER BY id ASC",
            'settlements'  => "SELECT * FROM kp_settlements WHERE case_id = ? ORDER BY id DESC",
            'agreements'   => "SELECT * FROM kp_arbitration_agreements WHERE case_id = ? ORDER BY id DESC",
            'repudiations' => "SELECT * FROM kp_repudiations WHERE case_id = ? ORDER BY id DESC",
            'executions'   => "SELECT * FROM kp_executions WHERE case_id = ? ORDER BY id DESC",
        ];
        $context = [];
        foreach ($queries as $key => $sql) {
            $context[$key] = [];
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param('i', $caseId);
            $stmt->execute();
            $context[$key] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
        return $context;
    }
}

if (!function_exists('kp_filter_hearings')) {
    /** Hearings of a given stage and purpose type, oldest first. */
    function kp_filter_hearings(array $hearings, ?string $stage, string $purposeType = 'regular'): array
    {
        return array_values(array_filter($hearings, static function (array $h) use ($stage, $purposeType): bool {
            $type = (string)($h['purpose_type'] ?? 'regular');
            return ($stage === null || ($h['stage'] ?? '') === $stage) && $type === $purposeType;
        }));
    }
}

// ─── KP Form Availability ───────────────────────────────────────────────────

if (!function_exists('kp_available_forms')) {
    /**
     * KP forms that may be generated for a case, based on the proceedings that
     * have actually taken place.
     *
     * @param array $case    Case row (needs kp_status, kp_disposition)
     * @param array $context Output of kp_load_case_context()
     * @return int[]  Form numbers (91 = Officer's Return, back of Form 9)
     */
    function kp_available_forms(array $case, array $context): array
    {
        $status      = (string)($case['kp_status'] ?? 'filed');
        $disposition = (string)($case['kp_disposition'] ?? '');
        $hearings    = $context['hearings'] ?? [];
        $active      = !in_array($status, kp_terminal_statuses(), true);
        $forms       = [];

        if (!in_array($status, kp_post_acceptance_statuses(), true)) {
            return [];
        }

        // Complaint
        $forms[] = 7;

        // Mediation notice, summons and officer's return
        if (kp_filter_hearings($hearings, 'mediation')) {
            array_push($forms, 8, 9, 91);
        }

        // Pangkat constitution and conciliation notices
        if (kp_filter_hearings($hearings, 'conciliation', 'pangkat_constitution')) {
            $forms[] = 10;
        }
        if (!empty($context['pangkat'])) {
            $forms[] = 11;
        }
        if (kp_filter_hearings($hearings, 'conciliation')) {
            $forms[] = 12;
        }

        // Subpoena to witnesses, during any active hearing stage
        if ($active && (kp_filter_hearings($hearings, 'mediation') || kp_filter_hearings($hearings, 'conciliation') || kp_filter_hearings($hearings, 'arbitration'))) {
            $forms[] = 13;
        }

        // Arbitration
        if (!empty($context['agreements'])) {
            $forms[] = 14;
        }
        foreach ($context['settlements'] ?? [] as $settlement) {
            $forms[] = ($settlement['settlement_type'] ?? '') === 'arbitration_award' ? 15 : 16;
        }

        // Repudiation (sworn statement of a recorded repudiation)
        if (!empty($context['repudiations'])) {
            $forms[] = 17;
        }

        // Failure-to-appear explanation hearings
        if (kp_filter_hearings($hearings, null, 'explanation_complainant')) {
            $forms[] = 18;
        }
        if (kp_filter_hearings($hearings, null, 'explanation_respondent')) {
            $forms[] = 19;
        }

        // Certifications follow the recorded legal ground only
        switch ($disposition) {
            case 'cfa_settlement_repudiated':
            case 'cfa_arbitration_repudiated':
                $forms[] = 20;
                break;
            case 'cfa_conciliation_failed':
                $forms[] = 21;
                break;
            case 'cfa_respondent_absent':
                array_push($forms, 22, 24);
                break;
            case 'barred_complainant_absent':
                $forms[] = 23;
                break;
        }

        // Execution
        $executions = $context['executions'] ?? [];
        if ($executions) {
            $forms[] = 25;
            if (kp_filter_hearings($hearings, 'execution', 'execution')) {
                $forms[] = 26;
            }
            foreach ($executions as $execution) {
                if (!empty($execution['execution_issued_at'])) {
                    $forms[] = 27;
                    break;
                }
            }
        }

        $forms = array_values(array_unique($forms));
        sort($forms);
        return $forms;
    }
}
?>
