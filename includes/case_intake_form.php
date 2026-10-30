<?php
/**
 * Complaint intake fields shared by the barangay's Add Case and Edit Case
 * pages. They match the resident app's Submit a Case page, so KP Forms 7–9
 * are filled in the same way however a complaint was entered.
 */

require_once __DIR__ . '/../config/case_types.php';

const GABAYBATAS_FAMILY_DISPUTE_TYPE = 'Family Dispute (No Violence)';

if (!function_exists('gabaybatas_intake_from_post')) {
    /** @return array<string, string> */
    function gabaybatas_intake_from_post(): array
    {
        $form = [];
        foreach ([
            'complainant_name', 'complainant_age', 'complainant_address', 'complainant_area', 'contact',
            'respondent_name', 'respondent_address', 'respondent_area', 'case_type', 'reason', 'statement',
        ] as $key) {
            $form[$key] = trim((string)($_POST[$key] ?? ''));
        }
        $form['case_type'] = gabaybatas_normalize_case_type($form['case_type']);
        return $form;
    }
}

if (!function_exists('gabaybatas_intake_validate')) {
    /**
     * Same rules as the app. In free-text mode (older cases whose statement
     * is not in the standard format) the statement itself is edited.
     *
     * @return array<string, string> field => message
     */
    function gabaybatas_intake_validate(array $form, bool $freeText = false): array
    {
        $errors = [];
        if (mb_strlen($form['complainant_name']) < 3) {
            $errors['complainant_name'] = 'Enter the complainant\'s full name.';
        }
        if (!ctype_digit($form['complainant_age']) || (int)$form['complainant_age'] < 12 || (int)$form['complainant_age'] > 120) {
            $errors['complainant_age'] = 'Enter an age from 12 to 120.';
        }
        if ($form['complainant_address'] === '') {
            $errors['complainant_address'] = 'Required.';
        }
        if (!preg_match('/^(09\d{9}|\+639\d{9})$/', preg_replace('/[\s-]/', '', $form['contact']))) {
            $errors['contact'] = 'Use 09XXXXXXXXX or +639XXXXXXXXX.';
        }
        if (mb_strlen($form['respondent_name']) < 3) {
            $errors['respondent_name'] = 'Enter the respondent\'s full name.';
        }
        if ($form['respondent_address'] === '') {
            $errors['respondent_address'] = 'Required.';
        }
        if ($form['case_type'] === '') {
            $errors['case_type'] = 'Select a case type.';
        }
        if ($form['complainant_area'] === '') {
            $errors['complainant_area'] = 'Required.';
        }
        if ($form['respondent_area'] === '') {
            $errors['respondent_area'] = 'Required.';
        }
        $text = $freeText ? 'statement' : 'reason';
        if (mb_strlen($form[$text]) < 10) {
            $errors[$text] = 'Enter at least 10 characters.';
        } elseif (!$freeText && mb_strlen($form['reason']) > 1000) {
            $errors['reason'] = 'Keep the reason within 1,000 characters.';
        }
        return $errors;
    }
}

if (!function_exists('gabaybatas_intake_render')) {
    /**
     * Prints the Complainant, Respondent, Case and Reason of Complaint cards.
     *
     * @param array{date_filed: string, status_html: string, free_text?: bool} $opts
     */
    function gabaybatas_intake_render(array $form, array $errors, array $opts): void
    {
        $val = static fn(string $key): string => htmlspecialchars($form[$key] ?? '');
        $err = static fn(string $key): string => isset($errors[$key])
            ? '<small class="acs-error">' . htmlspecialchars($errors[$key]) . '</small>'
            : '';
        $freeText = !empty($opts['free_text']);
        ?>
        <!-- Complainant -->
        <div class="card-box acs-card">
            <div class="acs-card-title"><i class="uil uil-user"></i> Complainant</div>
            <div class="acs-grid">
                <div class="acs-field acs-field-full">
                    <label>Full Name of Complainant <span>*</span></label>
                    <input type="text" name="complainant_name" value="<?= $val('complainant_name') ?>" placeholder="Full name of complainant" required minlength="3" data-preview="name">
                    <?= $err('complainant_name') ?>
                </div>
                <div class="acs-field acs-field-full">
                    <label>Complete Address of Complainant <span>*</span></label>
                    <input type="text" name="complainant_address" value="<?= $val('complainant_address') ?>" placeholder="House/Purok, Barangay Muñoz, Catbalogan City, Samar" required>
                    <?= $err('complainant_address') ?>
                </div>
                <div class="acs-field">
                    <label>Contact Number <span>*</span></label>
                    <input type="tel" name="contact" value="<?= $val('contact') ?>" placeholder="+63 912 345 6789" required>
                    <?= $err('contact') ?>
                </div>
                <div class="acs-field">
                    <label>Date Filed</label>
                    <input type="text" value="<?= htmlspecialchars($opts['date_filed']) ?>" readonly>
                </div>
            </div>
        </div>

        <!-- Respondent -->
        <div class="card-box acs-card">
            <div class="acs-card-title"><i class="uil uil-user-exclamation"></i> Person Being Complained (Respondent)</div>
            <div class="acs-grid">
                <div class="acs-field acs-field-full">
                    <label>Full Name of Respondent <span>*</span></label>
                    <input type="text" name="respondent_name" value="<?= $val('respondent_name') ?>" placeholder="Full name of respondent" required minlength="3" data-preview="respondent">
                    <?= $err('respondent_name') ?>
                </div>
                <div class="acs-field acs-field-full">
                    <label>Complete Address of Respondent <span>*</span></label>
                    <input type="text" name="respondent_address" value="<?= $val('respondent_address') ?>" placeholder="House/Purok, Barangay Muñoz, Catbalogan City, Samar" required>
                    <?= $err('respondent_address') ?>
                </div>
            </div>
        </div>

        <!-- Case -->
        <div class="card-box acs-card">
            <div class="acs-card-title"><i class="uil uil-file-alt"></i> Case</div>
            <div class="acs-grid">
                <div class="acs-field">
                    <label>Case Type <span>*</span></label>
                    <select name="case_type" id="acsCaseType" required>
                        <option value="">Select case type</option>
                        <?php foreach (gabaybatas_case_types() as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>" <?= ($form['case_type'] ?? '') === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $err('case_type') ?>
                </div>
                <div class="acs-field">
                    <label>Status</label>
                    <?= $opts['status_html'] ?>
                </div>
                <div class="acs-field acs-field-full acs-vawc" id="acsVawcNote" <?= ($form['case_type'] ?? '') === GABAYBATAS_FAMILY_DISPUTE_TYPE ? '' : 'hidden' ?>>
                    <i class="uil uil-shield-exclamation"></i>
                    <span>If the complaint involves violence or abuse against a woman or child, it cannot go through barangay mediation (RA 9262). Refer the complainant to the Barangay VAW Desk or the PNP Women and Children Protection Desk.</span>
                </div>
            </div>
        </div>

        <!-- Statement -->
        <div class="card-box acs-card">
            <div class="acs-card-title"><i class="uil uil-comment-alt-lines"></i> Reason of Complaint</div>
            <p class="acs-sub" style="margin-bottom:12px;">
                <?= $freeText
                    ? 'This older complaint was written as free text. Correct the details below and edit the statement directly.'
                    : 'Answer the three steps; the complaint statement for KP Form No. 7 is written from them.' ?>
            </p>

            <div class="acs-step">
                <div class="acs-step-num">1</div>
                <div class="acs-step-body">
                    <div class="acs-step-title">About the complainant</div>
                    <div class="acs-linked">Name: <strong data-show="name"><?= $val('complainant_name') ?: '—' ?></strong></div>
                    <div class="acs-grid acs-grid-step">
                        <div class="acs-field">
                            <label>Age <span>*</span></label>
                            <input type="text" name="complainant_age" value="<?= $val('complainant_age') ?>" placeholder="e.g. 34" inputmode="numeric" maxlength="3" required data-preview="age">
                            <?= $err('complainant_age') ?>
                        </div>
                        <div class="acs-field">
                            <label>Complainant's purok / area <span>*</span></label>
                            <input type="text" name="complainant_area" value="<?= $val('complainant_area') ?>" placeholder="e.g. Purok 3" maxlength="50" required data-preview="area">
                            <?= $err('complainant_area') ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="acs-step">
                <div class="acs-step-num">2</div>
                <div class="acs-step-body">
                    <div class="acs-step-title">Who they are complaining about</div>
                    <div class="acs-linked">Name: <strong data-show="respondent"><?= $val('respondent_name') ?: '—' ?></strong></div>
                    <div class="acs-field">
                        <label>Respondent's purok / area <span>*</span></label>
                        <input type="text" name="respondent_area" value="<?= $val('respondent_area') ?>" placeholder="e.g. Purok 5" maxlength="50" required data-preview="respondentArea">
                        <?= $err('respondent_area') ?>
                    </div>
                </div>
            </div>

            <div class="acs-step">
                <div class="acs-step-num">3</div>
                <div class="acs-step-body">
                    <?php if ($freeText): ?>
                        <div class="acs-step-title">Statement on KP Form No. 7</div>
                        <div class="acs-field">
                            <label>Complaint statement <span>*</span></label>
                            <textarea name="statement" rows="7" required><?= $val('statement') ?></textarea>
                            <?= $err('statement') ?>
                        </div>
                    <?php else: ?>
                        <div class="acs-step-title">What happened?</div>
                        <div class="acs-field">
                            <label>Reason of complaint <span>*</span></label>
                            <textarea name="reason" rows="5" maxlength="1000" placeholder="Describe what they did, when and where it happened." required data-preview="reason"><?= $val('reason') ?></textarea>
                            <small class="acs-hint">Tip: Detalyado nga rason han reklamo · <span id="acsReasonCount">0</span>/1000</small>
                            <?= $err('reason') ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$freeText): ?>
            <div class="acs-preview">
                <div class="acs-preview-label"><i class="uil uil-eye"></i> Statement on KP Form No. 7</div>
                <p id="acsPreview"></p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

if (!function_exists('gabaybatas_intake_assets')) {
    /** Script and styles for the intake cards (call once, after the form). */
    function gabaybatas_intake_assets(string $formId): void
    {
        ?>
<script>
(function () {
    const form = document.getElementById(<?= json_encode($formId) ?>);
    if (!form) return;
    const field = name => form.querySelector('[data-preview="' + name + '"]');
    const get = name => (field(name) ? field(name).value : '').trim();
    const blank = text => text || '____';

    // Live statement preview; same wording as gabaybatas_complaint_statement().
    function renderPreview() {
        form.querySelector('[data-show="name"]').textContent = get('name') || '—';
        form.querySelector('[data-show="respondent"]').textContent = get('respondent') || '—';
        const preview = document.getElementById('acsPreview');
        if (!preview) return;
        const reason = get('reason').replace(/[\s.]+$/, '');
        preview.textContent =
            'Ako po hi ' + blank(get('name')) + ', ' + blank(get('age')) + ' anyos, ngan naukoy ha ' + blank(get('area')) +
            '. Ginrereklamo ko hi ' + blank(get('respondent')) + ', nga naukoy ha ' + blank(get('respondentArea')) +
            ', tungod ' + blank(reason) + '. Karuyag ko unta ipatawag hiya ha barangay basi magkastorya kami hin maopay.';
        document.getElementById('acsReasonCount').textContent = get('reason').length;
    }
    form.querySelectorAll('[data-preview]').forEach(el => el.addEventListener('input', renderPreview));
    renderPreview();

    // Age: digits only, like the app.
    const age = form.querySelector('[name="complainant_age"]');
    age.addEventListener('input', () => { age.value = age.value.replace(/\D/g, '').slice(0, 3); renderPreview(); });

    const caseType = document.getElementById('acsCaseType');
    caseType.addEventListener('change', () => {
        document.getElementById('acsVawcNote').hidden = caseType.value !== <?= json_encode(GABAYBATAS_FAMILY_DISPUTE_TYPE) ?>;
    });
})();
</script>

<style>
.acs-body{padding:24px 28px 40px;}
.acs-head{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:14px;}
.acs-sub{font-size:13px;color:#5a6a7e;margin:0;}
.acs-back-btn{display:inline-flex;align-items:center;gap:6px;text-decoration:none;font-size:12.5px;font-weight:600;color:#1d4ed8;background:#eff6ff;border:1px solid #dbeafe;border-radius:9px;padding:8px 13px;transition:all .2s;white-space:nowrap;}
.acs-back-btn:hover{background:#dbeafe;}
.acs-card{padding:20px;margin-bottom:0;}
.acs-card-title{font-size:14px;font-weight:700;color:#1e293b;margin-bottom:14px;display:flex;align-items:center;gap:8px;}
.acs-card-title i{color:#1d4ed8;font-size:18px;}
.acs-alert{display:flex;align-items:center;gap:8px;border-radius:10px;padding:10px 12px;font-size:12.5px;margin-bottom:12px;}
.acs-alert.error{background:#fff1f2;border:1px solid #fecdd3;color:#be123c;}
.acs-alert.info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;}
.acs-form{display:flex;flex-direction:column;gap:14px;}
.acs-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.acs-grid-step{grid-template-columns:140px 1fr;}
.acs-field{display:flex;flex-direction:column;gap:6px;}
.acs-field-full{grid-column:1 / -1;}
.acs-field label{font-size:11px;letter-spacing:.6px;text-transform:uppercase;color:#6b7280;font-weight:700;}
.acs-field label span{color:#dc2626;}
.acs-field input,.acs-field select,.acs-field textarea{width:100%;background:#fafbff;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;font-size:13px;color:#1f2937;outline:none;resize:vertical;box-sizing:border-box;font-family:inherit;}
.acs-field input:focus,.acs-field select:focus,.acs-field textarea:focus{border-color:#3b82f6;}
.acs-field input[readonly]{color:#64748b;}
.acs-error{color:#dc2626;font-size:11.5px;font-weight:600;}
.acs-hint{color:#64748b;font-size:11.5px;}
.acs-status{display:inline-flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #fde68a;background:#fffbeb;color:#b45309;border-radius:10px;font-size:13px;font-weight:700;}
.acs-status-dot{width:8px;height:8px;border-radius:50%;background:#f59e0b;}
.acs-vawc{flex-direction:row;align-items:flex-start;gap:8px;background:#f5f3ff;border:1px solid #ddd6fe;color:#5b21b6;border-radius:10px;padding:10px 12px;font-size:12.5px;line-height:1.45;}
.acs-vawc[hidden]{display:none;}
.acs-vawc i{font-size:18px;}
.acs-step{display:flex;gap:12px;padding:14px 0;border-top:1px dashed #e2e8f0;}
.acs-step:first-of-type{border-top:none;padding-top:0;}
.acs-step-num{width:28px;height:28px;border-radius:50%;background:#1d4ed8;color:#fff;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.acs-step-body{flex:1;display:flex;flex-direction:column;gap:10px;min-width:0;}
.acs-step-title{font-weight:700;font-size:13.5px;color:#1e293b;}
.acs-linked{font-size:12.5px;color:#64748b;}
.acs-linked strong{color:#1e293b;}
.acs-preview{margin-top:6px;background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #1d4ed8;border-radius:10px;padding:12px 14px;}
.acs-preview-label{font-size:11px;letter-spacing:.6px;text-transform:uppercase;color:#1d4ed8;font-weight:700;margin-bottom:6px;display:flex;align-items:center;gap:6px;}
.acs-preview p{margin:0;font-size:13px;line-height:1.55;color:#334155;}
.acs-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:2px;}
.acs-cancel,.acs-save{display:inline-flex;align-items:center;justify-content:center;gap:6px;font-size:12.5px;font-weight:600;text-decoration:none;border-radius:9px;padding:9px 14px;}
.acs-cancel{border:1px solid #e2e8f0;color:#475569;background:#fff;}
.acs-save{border:none;background:#1d4ed8;color:#fff;cursor:pointer;}
.acs-save:hover{background:#1e40af;}
@media(max-width:900px){.acs-body{padding:16px;}.acs-head{flex-direction:column;align-items:flex-start;}.acs-grid,.acs-grid-step{grid-template-columns:1fr;}}
</style>
        <?php
    }
}

// ── Editing an existing case (barangay update_case.php and admin edit_case.php) ──

if (!function_exists('gabaybatas_case_edit_state')) {
    /**
     * The case's current details split into the intake fields. Older cases
     * whose statement is free text are edited in free-text mode.
     *
     * @return array{saved: array<string, string>, parsed: ?array, free_text: bool}
     */
    function gabaybatas_case_edit_state(array $case): array
    {
        $description = (string)($case['description'] ?? '');
        $parsed = gabaybatas_parse_complaint_statement($description);
        $freeText = $parsed === null;
        return [
            'saved' => [
                'complainant_name'    => (string)($case['name'] ?? ''),
                'complainant_age'     => (string)($case['complainant_age'] ?? ($parsed['age'] ?? '')),
                'complainant_address' => (string)($case['complainant_address'] ?? ''),
                'complainant_area'    => (string)(($case['complainant_purok'] ?? '') ?: ($parsed['area'] ?? '')),
                'contact'             => (string)($case['contact'] ?? ''),
                'respondent_name'     => (string)($case['complained_person'] ?? ''),
                'respondent_address'  => (string)($case['respondent_address'] ?? ''),
                'respondent_area'     => (string)(($case['respondent_purok'] ?? '') ?: ($parsed['respondent_area'] ?? '')),
                'case_type'           => gabaybatas_normalize_case_type((string)($case['case_type'] ?? '')),
                'reason'              => (string)($parsed['reason'] ?? ''),
                'statement'           => $freeText ? $description : '',
            ],
            'parsed'    => $parsed,
            'free_text' => $freeText,
        ];
    }
}

if (!function_exists('gabaybatas_case_edit_save')) {
    /**
     * Validates and saves corrected details, rebuilds the KP Form 7 statement
     * and records every changed field with its old and new value in the case
     * timeline (an internal record; residents do not see it).
     *
     * @param array{name: string, role: string, id: int} $actor
     * @return array<string, string> errors; empty when saved
     */
    function gabaybatas_case_edit_save(mysqli $conn, array $case, array $state, array $form, array $actor): array
    {
        $freeText = $state['free_text'];
        $saved = $state['saved'];
        $errors = gabaybatas_intake_validate($form, $freeText);
        if ($errors) {
            return $errors;
        }

        $labels = [
            'complainant_name'    => 'Complainant name',
            'complainant_age'     => 'Complainant age',
            'complainant_address' => 'Complainant address',
            'complainant_area'    => 'Complainant purok/area',
            'contact'             => 'Contact number',
            'respondent_name'     => 'Respondent name',
            'respondent_address'  => 'Respondent address',
            'respondent_area'     => 'Respondent purok/area',
            'case_type'           => 'Case type',
            'reason'              => 'Reason of complaint',
            'statement'           => 'Complaint statement',
        ];
        // Compared the way they are stored: contact without spaces, reason
        // without the final period (the statement adds its own).
        $normalize = static fn(string $key, string $value): string => match ($key) {
            'contact' => preg_replace('/[\s-]/', '', $value),
            'reason'  => rtrim(trim($value), " \t\n\r\0\x0B."),
            default   => trim($value),
        };
        $shown = static fn(string $value): string => $value === '' ? '(blank)' : '"' . $value . '"';

        $changes = [];
        foreach ($labels as $key => $label) {
            if (($key === 'reason' && $freeText) || ($key === 'statement' && !$freeText)) {
                continue;
            }
            $old = $normalize($key, $saved[$key]);
            $new = $normalize($key, $form[$key]);
            if ($old !== $new) {
                $changes[$label] = '• ' . $label . ': ' . $shown($old) . ' → ' . $shown($new);
            }
        }
        if (!$changes) {
            return ['form' => 'No changes to save.'];
        }

        $description = $freeText
            ? $form['statement']
            : gabaybatas_complaint_statement(
                $form['complainant_name'], $form['complainant_age'], $form['complainant_area'],
                $form['respondent_name'], $form['respondent_area'], $form['reason']
            );
        // Keep notes appended after the statement (e.g. resident updates from the app).
        if (!$freeText && $state['parsed']['extra'] !== '') {
            $description .= "\n\n" . $state['parsed']['extra'];
        }
        $contact = preg_replace('/[\s-]/', '', $form['contact']);
        $complainantPurok = mb_substr($form['complainant_area'], 0, 50);
        $respondentPurok = mb_substr($form['respondent_area'], 0, 50);
        $caseId = (int)$case['id'];

        $upd = $conn->prepare(
            'UPDATE cases SET name = ?, complainant_age = ?, complainant_address = ?, complainant_purok = ?, contact = ?,
                              complained_person = ?, respondent_address = ?, respondent_purok = ?, case_type = ?, description = ?
             WHERE id = ? LIMIT 1'
        );
        $upd->bind_param(
            'ssssssssssi',
            $form['complainant_name'], $form['complainant_age'], $form['complainant_address'], $complainantPurok, $contact,
            $form['respondent_name'], $form['respondent_address'], $respondentPurok, $form['case_type'], $description,
            $caseId
        );
        if (!$upd->execute()) {
            return ['form' => 'Unable to save the changes. Please try again.'];
        }

        $status = (string)(($case['kp_status'] ?? '') ?: 'filed');
        $when = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('M d, Y h:i A');
        kp_log_event(
            $conn, $caseId, kp_status_stage($status), $status, 'details_corrected',
            'Case details corrected by ' . $actor['name'] . ': ' . strtolower(implode(', ', array_keys($changes))),
            'Corrected by ' . $actor['name'] . ' (' . $actor['role'] . ') on ' . $when . ".\n" . implode("\n", $changes),
            (int)$actor['id']
        );
        return [];
    }
}

if (!function_exists('gabaybatas_case_status_correct')) {
    /**
     * Corrects a wrong status; returns an error message, or '' when saved.
     *
     * @param array{name: string, role: string, id: int} $actor
     */
    function gabaybatas_case_status_correct(mysqli $conn, array $case, string $newStatus, string $reason, array $actor): string
    {
        $current = !empty($case['kp_status']) ? (string)$case['kp_status'] : (string)($case['status'] ?? 'Pending');
        if ($newStatus === $current) {
            return 'Choose a different status to change it.';
        }
        if (!array_key_exists($newStatus, kp_correction_status_options($current))) {
            return 'This status change is not allowed under the KP procedure.';
        }
        if (trim($reason) === '') {
            return 'Please state the reason for changing the status.';
        }

        $isKpStatus = array_key_exists($newStatus, kp_statuses());
        $parentStatus = $isKpStatus ? kp_status_to_parent($newStatus) : $newStatus;
        $kpStatus = $isKpStatus ? $newStatus : null;
        $caseId = (int)$case['id'];
        $upd = $conn->prepare('UPDATE cases SET status = ?, kp_status = ? WHERE id = ? LIMIT 1');
        $upd->bind_param('ssi', $parentStatus, $kpStatus, $caseId);
        if (!$upd->execute()) {
            return 'Unable to update the status.';
        }

        $fromLabel = kp_status_label($current);
        $toLabel = kp_status_label($kpStatus ?: $parentStatus);
        kp_log_event(
            $conn, $caseId, $kpStatus ? kp_status_stage($kpStatus) : 'intake',
            $kpStatus ?: strtolower(str_replace(' ', '_', $parentStatus)), 'status_changed',
            "Case status updated to '{$toLabel}' by {$actor['name']}",
            'Corrected by ' . $actor['name'] . ' (' . $actor['role'] . ").\n"
                . '• Status: "' . $fromLabel . '" → "' . $toLabel . "\"\n"
                . '• Reason: ' . trim($reason),
            (int)$actor['id']
        );
        return '';
    }
}
