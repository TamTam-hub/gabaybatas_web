<?php
/**
 * KP Form Data Builder
 *
 * Builds the placeholder values of KP Forms 9-A and 10–27 from the
 * proceedings recorded for a case (hearings, Pangkat, settlements,
 * repudiations, executions). Forms 7, 8 and 9 keep their original data path.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/kp.php';

const KP_BLANK_LINE = '____________________';
const KP_DOCUMENT_PLACE = 'Barangay Muñoz, Catbalogan City';

if (!function_exists('kp_date_parts')) {
    /**
     * Day ("24th"), MONTH, year and full date of a date; blanks when unknown.
     *
     * @return array{day: string, month: string, year: string, full: string}
     */
    function kp_date_parts(?string $date): array
    {
        $ts = $date ? strtotime($date) : false;
        if (!$ts) {
            return ['day' => '______', 'month' => '______________', 'year' => '20____', 'full' => KP_BLANK_LINE];
        }
        return [
            'day'   => date('jS', $ts),
            'month' => strtoupper(date('F', $ts)),
            'year'  => date('Y', $ts),
            'full'  => date('F j, Y', $ts),
        ];
    }
}

if (!function_exists('kp_hearing_fields')) {
    /** Placeholder values describing a hearing. */
    function kp_hearing_fields(?array $hearing): array
    {
        $parts = kp_date_parts($hearing['hearing_date'] ?? null);
        $time  = !empty($hearing['hearing_time']) ? date('h:i A', strtotime((string)$hearing['hearing_time'])) : '__________';
        return [
            'hearing_date'      => $parts['full'],
            'hearing_date_day'  => $parts['day'],
            'hearing_month'     => $parts['month'],
            'hearing_date_year' => $parts['year'],
            'hearing_day'       => !empty($hearing['hearing_date']) ? date('l', strtotime((string)$hearing['hearing_date'])) : '',
            'hearing_time'      => $time,
            'hearing_location'  => trim((string)($hearing['location'] ?? '')),
        ];
    }
}

if (!function_exists('kp_latest')) {
    /** Last element of a list (lists from kp_filter_hearings are oldest first). */
    function kp_latest(array $list): ?array
    {
        return $list ? $list[count($list) - 1] : null;
    }
}

if (!function_exists('kp_pangkat_officers')) {
    /** @return array{chairman: string, secretary: string, members: string[]} */
    function kp_pangkat_officers(array $pangkat): array
    {
        $officers = ['chairman' => '', 'secretary' => '', 'members' => []];
        foreach ($pangkat as $member) {
            $name = strtoupper(trim((string)$member['member_name']));
            if ($member['role'] === 'chairman') {
                $officers['chairman'] = $name;
            } else {
                if ($member['role'] === 'secretary') {
                    $officers['secretary'] = $name;
                }
                $officers['members'][] = $name;
            }
        }
        return $officers;
    }
}

if (!function_exists('kp_presiding_officer')) {
    /**
     * The officer presiding at a stage: the Punong Barangay in mediation, the
     * Pangkat Chairman in conciliation.
     *
     * @return array{name: string, title: string, forum: string}
     */
    function kp_presiding_officer(string $stage, array $officers): array
    {
        if ($stage === 'conciliation') {
            return ['name' => $officers['chairman'], 'title' => 'Pangkat Chairman', 'forum' => 'Pangkat ng Tagapagkasundo'];
        }
        return ['name' => KP_PUNONG_BARANGAY, 'title' => 'Punong Barangay', 'forum' => 'Punong Barangay'];
    }
}

if (!function_exists('kp_arbitrator')) {
    /** @return array{name: string, title: string, body: string} */
    function kp_arbitrator(string $arbitrator, array $officers): array
    {
        if ($arbitrator === 'pangkat') {
            return ['name' => $officers['chairman'], 'title' => 'Pangkat Chairman', 'body' => 'Pangkat ng Tagapagkasundo'];
        }
        return ['name' => KP_PUNONG_BARANGAY, 'title' => 'Punong Barangay', 'body' => 'Punong Barangay'];
    }
}

if (!function_exists('kp_execution_movant')) {
    /**
     * Moving party recorded with a Motion for Execution ("Moving party: NAME (role)").
     *
     * @return array{name: string, role: string}
     */
    function kp_execution_movant(array $case, ?array $execution): array
    {
        $role = 'complainant';
        if ($execution && preg_match('/Moving party:.*\((complainant|respondent)\)/i', (string)$execution['notes'], $m)) {
            $role = strtolower($m[1]);
        }
        $name = $role === 'complainant' ? (string)($case['name'] ?? '') : (string)($case['complained_person'] ?? '');
        return ['name' => strtoupper($name), 'role' => $role];
    }
}

if (!function_exists('kp_find_by_id')) {
    function kp_find_by_id(array $rows, int $id): ?array
    {
        foreach ($rows as $row) {
            if ((int)$row['id'] === $id) {
                return $row;
            }
        }
        return null;
    }
}

if (!function_exists('kp_build_form_data')) {
    /**
     * Placeholder values for a KP form.
     *
     * @param array $case    Case row
     * @param array $context Output of kp_load_case_context()
     * @param array $input   Per-generation inputs (pangkat_member_id, witness_name, witness_address)
     * @return array{data: array<string, string>, blocks: array<string, bool>, error: string|null}
     */
    function kp_build_form_data(mysqli $conn, array $case, int $formNumber, array $context, array $input = []): array
    {
        $hearings    = $context['hearings'] ?? [];
        $officers    = kp_pangkat_officers($context['pangkat'] ?? []);
        $issued      = kp_date_parts(date('Y-m-d'));
        $complainant = strtoupper((string)($case['name'] ?? ''));
        $respondent  = strtoupper((string)($case['complained_person'] ?? ''));
        $blocks      = [];

        $data = [
            'date_issued'       => $issued['full'],
            'date_issued_day'   => $issued['day'],
            'date_issued_month' => $issued['month'],
            'date_issued_year'  => $issued['year'],
            'punong_barangay'   => KP_PUNONG_BARANGAY,
            'lupon_secretary'   => KP_LUPON_SECRETARY,
            'pangkat_chairman'  => $officers['chairman'],
            'pangkat_secretary' => $officers['secretary'],
            'place'             => KP_DOCUMENT_PLACE,
        ];
        $fail = static fn(string $error): array => ['data' => [], 'blocks' => [], 'error' => $error];

        $latestSettlement = static function (?string $type = null) use ($context): ?array {
            foreach ($context['settlements'] ?? [] as $row) {
                if ($type === null || $row['settlement_type'] === $type) {
                    return $row;
                }
            }
            return null;
        };

        switch ($formNumber) {
            case 91: // Officer's Return (back of Form 9)
                $hearing = kp_latest(kp_filter_hearings($hearings, 'mediation'));
                $data += kp_hearing_fields($hearing);
                $data['hearing_day_number'] = !empty($hearing['hearing_date']) ? date('j', strtotime((string)$hearing['hearing_date'])) : '____';
                $service = null;
                if ($hearing) {
                    $stmt = $conn->prepare("SELECT * FROM kp_service_attempts WHERE case_id = ? AND hearing_id = ? ORDER BY id DESC LIMIT 1");
                    $caseId = (int)$case['id'];
                    $hearingId = (int)$hearing['id'];
                    $stmt->bind_param('ii', $caseId, $hearingId);
                    $stmt->execute();
                    $service = $stmt->get_result()->fetch_assoc() ?: null;
                    $stmt->close();
                }
                $served = kp_date_parts($service['served_date'] ?? null);
                $data['service_day']   = $served['day'];
                $data['service_month'] = $served['month'];
                $data['service_year']  = $service ? $served['year'] : '';
                $modes = ['personal' => 1, 'refused' => 2, 'left_at_dwelling' => 3, 'left_at_office' => 4];
                $usedMode = $modes[$service['service_method'] ?? ''] ?? 0;
                foreach ($modes as $mode => $n) {
                    $data["served_mode_{$n}"] = $n === $usedMode ? $respondent : str_repeat('_', $n === 3 ? 29 : ($n === 4 ? 30 : 28));
                }
                $receiver = strtoupper(trim((string)($service['received_by'] ?? '')));
                $data['dwelling_receiver'] = $usedMode === 3 && $receiver !== '' ? $receiver : str_repeat('_', 40);
                $data['office_receiver']   = $usedMode === 4 && $receiver !== '' ? $receiver : str_repeat('_', 40);
                break;

            case 10:
                $data += kp_hearing_fields(kp_latest(kp_filter_hearings($hearings, 'conciliation', 'pangkat_constitution')));
                break;

            case 11:
                $member = kp_find_by_id($context['pangkat'] ?? [], (int)($input['pangkat_member_id'] ?? 0));
                if (!$member) {
                    return $fail('member_required');
                }
                $data['pangkat_member_name'] = strtoupper((string)$member['member_name']);
                break;

            case 12:
                $data += kp_hearing_fields(kp_latest(kp_filter_hearings($hearings, 'conciliation')));
                break;

            case 13:
                $witness = trim((string)($input['witness_name'] ?? ''));
                if ($witness === '') {
                    return $fail('witness_required');
                }
                $stage = kp_status_stage((string)$case['kp_status']);
                if (!in_array($stage, ['mediation', 'conciliation', 'arbitration'], true)) {
                    $last = kp_latest(array_values(array_filter($hearings, static fn($h) => ($h['purpose_type'] ?? 'regular') === 'regular' && in_array($h['stage'], ['mediation', 'conciliation', 'arbitration'], true))));
                    $stage = $last['stage'] ?? 'mediation';
                }
                $data += kp_hearing_fields(kp_latest(kp_filter_hearings($hearings, $stage)));
                if ($stage === 'arbitration') {
                    $agreement = $context['agreements'][0] ?? null;
                    $arbiter = kp_arbitrator((string)($agreement['arbitrator'] ?? 'punong_barangay'), $officers);
                    $data['presiding_name']  = $arbiter['name'];
                    $data['presiding_title'] = $arbiter['title'];
                } else {
                    $presiding = kp_presiding_officer($stage, $officers);
                    $data['presiding_name']  = $presiding['name'];
                    $data['presiding_title'] = $presiding['title'];
                }
                // The captain's signature picture prints only when he signs.
                $blocks['pb_signature'] = $data['presiding_name'] === KP_PUNONG_BARANGAY;
                $data['witness_name']    = strtoupper($witness);
                $data['witness_address'] = trim((string)($input['witness_address'] ?? ''));
                break;

            case 14:
                $agreement = $context['agreements'][0] ?? null;
                if (!$agreement) {
                    return $fail('record_missing');
                }
                $arbiter = kp_arbitrator((string)$agreement['arbitrator'], $officers);
                $parts = kp_date_parts($agreement['agreement_date']);
                $data += [
                    'arbitrator_body'  => $arbiter['body'],
                    'arbitrator_name'  => $arbiter['name'],
                    'arbitrator_title' => $arbiter['title'],
                    'agreement_day'    => $parts['day'],
                    'agreement_month'  => $parts['month'],
                    'agreement_year'   => $parts['year'],
                ];
                break;

            case 15:
                $award = $latestSettlement('arbitration_award');
                if (!$award) {
                    return $fail('record_missing');
                }
                $byPangkat = ($award['arbitrator'] ?? '') === 'pangkat';
                $arbiter = kp_arbitrator($byPangkat ? 'pangkat' : 'punong_barangay', $officers);
                $parts = kp_date_parts($award['settlement_date']);
                $data += [
                    'award_text'       => (string)$award['terms'],
                    'award_day'        => $parts['day'],
                    'award_month'      => $parts['month'],
                    'award_year'       => $parts['year'],
                    'award_place'      => KP_DOCUMENT_PLACE,
                    'arbitrator_name'  => $arbiter['name'],
                    'arbitrator_title' => $arbiter['title'],
                    'pangkat_member_1' => $officers['members'][0] ?? '',
                    'pangkat_member_2' => $officers['members'][1] ?? '',
                    // Attested by the Punong Barangay if made by the Pangkat, by the Lupon Secretary if made by the PB.
                    'attester_name'    => $byPangkat ? KP_PUNONG_BARANGAY : KP_LUPON_SECRETARY,
                    'attester_title'   => $byPangkat ? 'Punong Barangay' : 'Lupon Secretary',
                ];
                $blocks['pangkat_award_members'] = $byPangkat;
                break;

            case 16:
                $settlement = $latestSettlement('amicable');
                if (!$settlement) {
                    return $fail('record_missing');
                }
                $presiding = kp_presiding_officer((string)($settlement['stage'] ?? 'mediation'), $officers);
                $parts = kp_date_parts($settlement['settlement_date']);
                $data += [
                    'settlement_terms' => (string)$settlement['terms'],
                    'settlement_day'   => $parts['day'],
                    'settlement_month' => $parts['month'],
                    'settlement_year'  => $parts['year'],
                    'presiding_name'   => $presiding['name'],
                    'presiding_title'  => $presiding['title'],
                ];
                break;

            case 17:
                $repudiation = $context['repudiations'][0] ?? null;
                if (!$repudiation) {
                    return $fail('record_missing');
                }
                $isAgreement = $repudiation['target_type'] === 'arbitration_agreement';
                if ($isAgreement) {
                    $target = kp_find_by_id($context['agreements'] ?? [], (int)$repudiation['target_id']);
                    $stage = (string)($target['from_stage'] ?? 'mediation');
                } else {
                    $target = kp_find_by_id($context['settlements'] ?? [], (int)$repudiation['target_id']);
                    $stage = (string)($target['stage'] ?? 'mediation');
                }
                $presiding = kp_presiding_officer($stage, $officers);
                $parts = kp_date_parts($repudiation['sworn_date']);
                $party = strtoupper((string)$repudiation['party_name']);
                $data += [
                    'repudiation_object'      => $isAgreement ? 'agreement for arbitration' : 'settlement',
                    'sworn_day'               => $parts['day'],
                    'sworn_month'             => $parts['month'],
                    'sworn_year'              => $parts['year'],
                    'repudiating_complainant' => $repudiation['party_role'] === 'complainant' ? $party : '',
                    'repudiating_respondent'  => $repudiation['party_role'] === 'respondent' ? $party : '',
                    'presiding_name'          => $presiding['name'],
                    'presiding_title'         => $presiding['title'],
                ];
                foreach (['fraud', 'violence', 'intimidation'] as $ground) {
                    $chosen = $repudiation['ground'] === $ground;
                    $data["chk_{$ground}"]     = $chosen ? 'X' : '  ';
                    $data["details_{$ground}"] = $chosen ? (trim((string)$repudiation['details']) ?: KP_BLANK_LINE) : '';
                }
                break;

            case 18:
            case 19:
                $type = $formNumber === 18 ? 'explanation_complainant' : 'explanation_respondent';
                $hearing = kp_latest(kp_filter_hearings($hearings, null, $type));
                if (!$hearing) {
                    return $fail('record_missing');
                }
                $stage = (string)$hearing['stage'];
                $missed = kp_find_by_id($hearings, (int)($hearing['related_hearing_id'] ?? 0));
                $presiding = kp_presiding_officer($stage, $officers);
                $data += kp_hearing_fields($hearing);
                $data += [
                    'appear_before'       => $stage === 'conciliation' ? 'the Pangkat' : 'me',
                    'proceeding'          => $stage === 'conciliation' ? 'conciliation' : 'mediation',
                    'missed_hearing_date' => kp_date_parts($missed['hearing_date'] ?? null)['full'],
                    'forum'               => $presiding['forum'],
                    'presiding_name'      => $presiding['name'],
                    'presiding_title'     => $presiding['title'],
                ];
                break;

            case 20:
                $repudiation = $context['repudiations'][0] ?? null;
                if (!$repudiation) {
                    return $fail('record_missing');
                }
                $isAgreement = $repudiation['target_type'] === 'arbitration_agreement';
                $target = $isAgreement
                    ? kp_find_by_id($context['agreements'] ?? [], (int)$repudiation['target_id'])
                    : kp_find_by_id($context['settlements'] ?? [], (int)$repudiation['target_id']);
                $stage = (string)($isAgreement ? ($target['from_stage'] ?? 'mediation') : ($target['stage'] ?? 'mediation'));
                $by = strtoupper((string)$repudiation['party_name']) . ' on ground of ' . strtolower((string)$repudiation['ground']);
                $data['forum'] = kp_presiding_officer($stage, $officers)['forum'];
                if ($isAgreement) {
                    // Adapted KP Form 20 for a repudiated Agreement for Arbitration.
                    $data['cert_item_2'] = 'The parties entered into an Agreement for Arbitration;';
                    $data['cert_item_3'] = "The Agreement for Arbitration has been repudiated in a statement sworn to before the Punong Barangay by {$by}; and";
                } else {
                    $data['cert_item_2'] = 'A settlement was reached;';
                    $data['cert_item_3'] = "The settlement has been repudiated in a statement sworn to before the Punong Barangay by {$by}; and";
                }
                break;

            case 21:
            case 22:
                $mediation = kp_latest(kp_filter_hearings($hearings, 'mediation'));
                $respondentAbsent = $mediation && stripos((string)$mediation['outcome'], 'Respondent absent') === 0;
                $data['mediation_fact'] = $respondentAbsent
                    ? 'The respondent failed to appear for mediation before the Punong Barangay, and the dispute was referred to the Pangkat ng Tagapagkasundo;'
                    : ($formNumber === 21
                        ? 'There has been a personal confrontation between the parties before the Punong Barangay but mediation failed;'
                        : 'There was a personal confrontation between the parties before the Punong Barangay but mediation failed;');
                break;

            case 23:
            case 24:
                $type = $formNumber === 23 ? 'explanation_complainant' : 'explanation_respondent';
                $hearing = kp_latest(kp_filter_hearings($hearings, null, $type));
                if (!$hearing) {
                    return $fail('record_missing');
                }
                $byPangkat = $hearing['stage'] === 'conciliation';
                $data += [
                    'forum'               => $byPangkat ? 'Pangkat ng Tagapagkasundo' : 'Punong Barangay',
                    'dismissal_date'      => kp_date_parts($hearing['hearing_date'])['full'],
                    // Lupon Secretary certifies / Lupon Chairman attests; Pangkat Secretary / Pangkat Chairman likewise.
                    'cert_signer_name'    => $byPangkat ? $officers['secretary'] : KP_LUPON_SECRETARY,
                    'cert_signer_title'   => $byPangkat ? 'Pangkat Secretary' : 'Lupon Secretary',
                    'cert_attester_name'  => $byPangkat ? $officers['chairman'] : KP_PUNONG_BARANGAY,
                    'cert_attester_title' => $byPangkat ? 'Pangkat Chairman' : 'Lupon Chairman',
                ];
                break;

            case 25:
            case 26:
            case 27:
                $execution = $context['executions'][0] ?? null;
                if (!$execution) {
                    return $fail('record_missing');
                }
                $settlement = kp_find_by_id($context['settlements'] ?? [], (int)$execution['settlement_id']);
                if (!$settlement) {
                    return $fail('record_missing');
                }
                $isAward = $settlement['settlement_type'] === 'arbitration_award';
                $arbiterBody = kp_arbitrator((string)($settlement['arbitrator'] ?? 'punong_barangay'), $officers)['body'];
                $movant = kp_execution_movant($case, $execution);
                $data += [
                    'settlement_date_formatted' => kp_date_parts($settlement['settlement_date'])['full'],
                    'settlement_kind'           => $isAward ? 'arbitration award' : 'amicable settlement',
                    'settlement_terms'          => (string)$settlement['terms'],
                    'moving_party_name'         => $movant['name'],
                    'movant_title'              => $movant['role'] === 'complainant' ? 'Complainant' : 'Respondent',
                    'obliged_party'             => $movant['role'] === 'complainant' ? $respondent : $complainant,
                    'motion_date'               => kp_date_parts($execution['motion_date'])['full'],
                    'final_act'                 => $isAward ? "received the arbitration award rendered by the {$arbiterBody}" : 'signed an amicable settlement',
                    'challenge_not_filed'       => $isAward ? 'a petition for nullification of the arbitration award in court' : 'a sworn statement of repudiation of the settlement before the Lupon Chairman',
                    'final_event'               => $isAward ? "an arbitration award was rendered by the {$arbiterBody} in the above-entitled case" : 'an amicable settlement was signed by the parties in the above-entitled case',
                    'terms_label'               => $isAward ? 'dispositive portion of the award' : 'terms and conditions of the settlement',
                    'execution_amount'          => $execution['amount'] !== null && $execution['amount'] !== '' ? 'PHP ' . number_format((float)$execution['amount'], 2) : KP_BLANK_LINE,
                    'amount_basis'              => $isAward ? 'adjudged in the said arbitration award' : 'agreed upon in the said amicable settlement',
                ];
                if ($formNumber === 26) {
                    $data += kp_hearing_fields(kp_latest(kp_filter_hearings($hearings, 'execution', 'execution')));
                }
                break;
        }

        return ['data' => $data, 'blocks' => $blocks, 'error' => null];
    }
}
