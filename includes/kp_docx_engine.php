<?php
/**
 * KP DOCX Template Engine
 *
 * Generates KP forms from the original Barangay Muñoz .docx templates
 * using PHPWord's TemplateProcessor. Preserves fonts, spacing, boldness,
 * margins, and layout from the original templates.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/kp.php';

use PhpOffice\PhpWord\TemplateProcessor;

// ─── Directory Constants ─────────────────────────────────────────────────────

define('KP_TEMPLATE_DIR',  __DIR__ . '/../storage/kp_templates/');
define('KP_DOCUMENT_DIR',  __DIR__ . '/../storage/kp_documents/');
define('KP_UPLOAD_DIR',    __DIR__ . '/../storage/kp_uploads/');

if (!function_exists('kp_ensure_dirs')) {
    /** Ensure all storage directories exist. */
    function kp_ensure_dirs(): void
    {
        foreach ([KP_TEMPLATE_DIR, KP_DOCUMENT_DIR, KP_UPLOAD_DIR] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }
}

if (!function_exists('kp_case_doc_dir')) {
    /** Get the document directory for a specific case, creating it if needed. */
    function kp_case_doc_dir(int $caseId): string
    {
        $dir = KP_DOCUMENT_DIR . 'case_' . $caseId . '/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }
}

if (!function_exists('kp_next_document_version')) {
    function kp_next_document_version(mysqli $conn, int $caseId, int $formNumber): int
    {
        $stmt = $conn->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 AS next_version FROM kp_documents WHERE case_id = ? AND form_number = ?');
        if (!$stmt) {
            return 1;
        }
        $stmt->bind_param('ii', $caseId, $formNumber);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return max(1, (int)($row['next_version'] ?? 1));
    }
}

if (!function_exists('kp_template_placeholders')) {
    /**
     * Return all ${placeholder} names used in the XML parts of a DOCX.
     *
     * @return array<int, string>
     */
    function kp_template_placeholders(string $templatePath): array
    {
        if (!class_exists('ZipArchive') || !is_file($templatePath)) {
            return [];
        }

        $zip = new ZipArchive();
        if ($zip->open($templatePath) !== true) {
            return [];
        }

        $names = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (!is_string($name) || !preg_match('#^word/(document|header\d+|footer\d+)\.xml$#', $name)) {
                continue;
            }

            $xml = (string)$zip->getFromIndex($index);
            if (preg_match_all('/\$\{([^}]+)\}/', $xml, $matches)) {
                foreach ($matches[1] as $placeholder) {
                    $names[(string)$placeholder] = true;
                }
            }
        }
        $zip->close();

        $result = array_keys($names);
        sort($result);
        return $result;
    }
}

if (!function_exists('kp_format_document_address')) {
    /**
     * Keep official addresses complete while wrapping them at natural commas.
     */
    function kp_format_document_address(string $address): string
    {
        $address = trim((string)preg_replace('/\s+/', ' ', $address));
        if ($address === '') {
            return '';
        }

        $parts = array_values(array_filter(
            array_map('trim', explode(',', $address)),
            static fn(string $part): bool => $part !== ''
        ));
        if (count($parts) < 2) {
            return wordwrap($address, 42, "\n", false);
        }

        $lines = [];
        $current = '';
        foreach ($parts as $part) {
            $candidate = $current === '' ? $part : $current . ', ' . $part;
            if ($current !== '' && strlen($candidate) > 42) {
                $lines[] = $current;
                $current = $part;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return implode("\n", $lines);
    }
}

if (!function_exists('kp_template_xml_value')) {
    function kp_template_xml_value(string $value): string
    {
        return str_replace(
            ["\r\n", "\r", "\n"],
            '</w:t><w:br/><w:t>',
            htmlspecialchars($value, ENT_XML1, 'UTF-8')
        );
    }
}

if (!function_exists('kp_apply_template_blocks')) {
    /**
     * Resolve optional blocks marked by paragraphs containing ${name} and
     * ${/name}: keep the content between them (dropping the marker paragraphs)
     * or remove the whole block. Works on a temporary copy of the template.
     *
     * @param array<string, bool> $blocks block name => keep
     * @return string|false Path of the temporary template, or false on failure
     */
    function kp_apply_template_blocks(string $templatePath, array $blocks)
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'kpblk');
        if ($tempPath === false || !copy($templatePath, $tempPath)) {
            return false;
        }
        $zip = new ZipArchive();
        if ($zip->open($tempPath) !== true) {
            return false;
        }
        $xml = (string)$zip->getFromName('word/document.xml');

        $paragraphBounds = static function (string $xml, int $offset): ?array {
            $startA = strrpos(substr($xml, 0, $offset), '<w:p>');
            $startB = strrpos(substr($xml, 0, $offset), '<w:p ');
            $start = max($startA === false ? -1 : $startA, $startB === false ? -1 : $startB);
            $end = strpos($xml, '</w:p>', $offset);
            return ($start < 0 || $end === false) ? null : [$start, $end + strlen('</w:p>')];
        };

        foreach ($blocks as $name => $keep) {
            $open = strpos($xml, '${' . $name . '}');
            $close = strpos($xml, '${/' . $name . '}');
            if ($open === false || $close === false || $close < $open) {
                $zip->close();
                return false;
            }
            $openP = $paragraphBounds($xml, $open);
            $closeP = $paragraphBounds($xml, $close);
            if (!$openP || !$closeP) {
                $zip->close();
                return false;
            }
            $inner = substr($xml, $openP[1], $closeP[0] - $openP[1]);
            $xml = substr($xml, 0, $openP[0]) . ($keep ? $inner : '') . substr($xml, $closeP[1]);
        }

        $zip->addFromString('word/document.xml', $xml);
        $zip->close();
        return $tempPath;
    }
}

// ─── Template Data Builder ───────────────────────────────────────────────────

if (!function_exists('kp_build_template_data')) {
    /**
     * Build the placeholder → value map for a given case.
     *
     * @param array  $case       The case row from the database
     * @param array  $extra      Additional key-value pairs (hearing date, etc.)
     * @return array<string, string>
     */
    function kp_build_template_data(array $case, array $extra = []): array
    {
        $dateSubmitted = $case['date_submitted'] ?? '';
        $ts = strtotime($dateSubmitted);

        $data = [
            // ── Barangay Header ──
            'barangay_name'       => KP_BARANGAY_NAME,
            'municipality'        => KP_MUNICIPALITY,
            'municipality_type'   => KP_MUNICIPALITY_TYPE,
            'province'            => KP_PROVINCE,
            'punong_barangay'     => KP_PUNONG_BARANGAY,

            // ── Case Info ──
            'case_number'         => $case['case_id'] ?? '',
            'case_type'           => $case['case_type'] ?? '',

            // ── Complainant ──
            'complainant_name'    => strtoupper($case['name'] ?? ''),
            'complainant_address' => kp_format_document_address(
                strtoupper($case['complainant_address'] ?? ($case['complainant_purok'] ?? ''))
            ),
            'complainant_age'     => (string)($case['complainant_age'] ?? $case['age'] ?? ''),
            'complainant_purok'   => $case['complainant_purok'] ?? '',

            // ── Respondent ──
            'respondent_name'     => strtoupper($case['complained_person'] ?? ''),
            'respondent_address'  => kp_format_document_address(
                strtoupper($case['respondent_address'] ?? ($case['respondent_purok'] ?? ''))
            ),
            'respondent_purok'    => $case['respondent_purok'] ?? '',

            // ── Description ──
            'complaint_narrative' => $case['description'] ?? '',
            'relief_sought'       => $case['relief_sought'] ?? '',

            // ── Dates ──
            'date_filed'          => $ts ? date('F j, Y', $ts) : '',
            'date_filed_day'      => $ts ? date('jS', $ts)     : '',
            'date_filed_day_number' => $ts ? date('j', $ts)   : '',
            'date_filed_month'    => $ts ? strtoupper(date('F', $ts)) : '',
            'date_filed_year'     => $ts ? date('Y', $ts)      : '',
            'date_today'          => $ts ? date('F j, Y', $ts) : '',
            'date_today_short'    => $ts ? date('M d, Y', $ts) : '',
            'date_today_day'     => $ts ? date('jS', $ts) : '',
            'date_today_month'    => $ts ? strtoupper(date('F', $ts)) : '',
            'date_today_year'     => $ts ? date('Y', $ts) : '',

            // ── Shared optional fields ──
            'hearing_date'        => '',
            'hearing_date_full'   => '',
            'hearing_day'         => '',
            'hearing_date_day'  => '',
            'hearing_month'     => '',
            'hearing_date_year' => '',
            'hearing_time'        => '',
            'hearing_location'    => '',
            'mediation_date_time' => '',
            'service_day'         => date('jS'),
            'service_month_year'  => strtoupper(date('F Y')),
            'service_month'      => strtoupper(date('F')),
            'service_year'       => date('Y'),
            // The complete address already contains the city and province.
            'complainant_city'    => '',
            'respondent_city'     => '',
            'pangkat_member_name' => '',
            'pangkat_member_1'    => '',
            'pangkat_member_2'    => '',
            'pangkat_chairman'    => '',
            'chosen_by_party'     => '',
            'presiding_officer_title' => 'Punong Barangay',
            'witness_name'        => '',
            'witness_address'     => '',
            'settlement_date_formatted' => '',
            'settlement_terms'    => '',
            'arbitration_award_text' => '',
            'repudiating_party'   => '',
            'repudiating_role'    => '',
            'repudiation_grounds' => '',
            'missed_hearing_date' => '',
            'cert_attester_name'  => 'HON. ULYSIS B. MACABARE',
            'cert_signer_name'    => 'HON. ULYSIS B. MACABARE',
            'execution_grounds'   => '',
            'moving_party_name'   => '',
            'monthly_case_list'   => '',
            'report_month_year'   => '',
            'total_certified'    => '0',
            'total_settled'      => '0',
        ];

        // Merge any extra data (hearing dates, pangkat members, etc.)
        return array_merge($data, $extra);
    }
}

// ─── DOCX Generator ─────────────────────────────────────────────────────────

if (!function_exists('kp_generate_docx')) {
    /**
     * Generate a KP form DOCX from template.
     *
     * @param int    $formNumber  KP form number (7, 8, 9, 91=9-A, 10-28)
     * @param array  $case        Case row from database
     * @param array  $extra       Extra placeholder values
     * @return string|false       Path to generated DOCX, or false on failure
     */
    function kp_generate_docx(int $formNumber, array $case, array $extra = [])
    {
        $formInfo = kp_form_info($formNumber);
        if (!$formInfo || !$formInfo['template']) {
            return false;
        }

        kp_ensure_dirs();

        $templatePath = KP_TEMPLATE_DIR . $formInfo['template'];
        if (!file_exists($templatePath)) {
            return false;
        }

        $caseId = (int)($case['id'] ?? 0);
        $caseCode = $case['case_id'] ?? ('C-' . str_pad((string)$caseId, 4, '0', STR_PAD_LEFT));

        // Build output filename
        $formLabel = kp_form_file_label($formNumber);
        $version = max(1, (int)($extra['document_version'] ?? 1));
        $outputName = "KP_Form_{$formLabel}_{$caseCode}_v{$version}.docx";
        $outputDir  = kp_case_doc_dir($caseId);
        $outputPath = $outputDir . $outputName;

        // Optional blocks (${name} ... ${/name}): true keeps the content, false removes it.
        $blocks = $extra['__blocks'] ?? [];
        unset($extra['__blocks']);

        // Build template data
        $data = kp_build_template_data($case, $extra);

        $blockTemplate = null;
        if ($blocks) {
            $blockTemplate = kp_apply_template_blocks($templatePath, $blocks);
            if ($blockTemplate === false) {
                error_log("KP template blocks could not be resolved (Form #{$formNumber}).");
                return false;
            }
            $templatePath = $blockTemplate;
        }

        try {
            $template = new TemplateProcessor($templatePath);
            $placeholders = kp_template_placeholders($templatePath);
            $missingValues = [];

            foreach ($placeholders as $placeholder) {
                if (!array_key_exists($placeholder, $data)) {
                    $missingValues[] = $placeholder;
                    $data[$placeholder] = '';
                }
            }

            if ($missingValues) {
                error_log(
                    'KP template has missing data values; generated as blank fields. '
                    . 'Form #' . $formNumber . ': ' . implode(', ', $missingValues)
                );
            }

            if (!$placeholders && in_array($formNumber, [7, 9, 91], true)) {
                error_log(
                    'KP template contains no placeholders and may retain sample-specific values. '
                    . 'Form #' . $formNumber . ': ' . basename($templatePath)
                );
            }

            // Replace all placeholders
            foreach ($data as $key => $value) {
                $template->setValue($key, kp_template_xml_value((string)$value));
            }

            // Save
            $template->saveAs($outputPath);
            if ($blockTemplate) {
                @unlink($blockTemplate);
            }

            $unresolved = kp_template_placeholders($outputPath);
            if ($unresolved) {
                @unlink($outputPath);
                error_log(
                    'KP DOCX generation left unresolved placeholders. '
                    . 'Form #' . $formNumber . ': ' . implode(', ', $unresolved)
                );
                return false;
            }

            return $outputPath;
        } catch (\Exception $e) {
            if ($blockTemplate) {
                @unlink($blockTemplate);
            }
            error_log("KP DOCX generation error (Form #{$formNumber}, Case #{$caseId}): " . $e->getMessage());
            return false;
        }
    }
}

// ─── Save Document Record to DB ──────────────────────────────────────────────

if (!function_exists('kp_save_document_record')) {
    /**
     * Save a generated document record to the kp_documents table.
     *
     * @param mysqli $conn
     * @param int    $caseId
     * @param int    $formNumber
     * @param string $filePath
     * @param string $htmlContent  Optional HTML preview content
     * @param int|null $generatedBy  User ID who generated it
     * @return int|false  The document ID, or false on failure
     */
    function kp_save_document_record(
        mysqli $conn,
        int    $caseId,
        int    $formNumber,
        string $filePath,
        string $htmlContent = '',
        ?int   $generatedBy = null,
        ?string $inputJson = null
    ) {
        $formInfo = kp_form_info($formNumber);
        if (!$formInfo) {
            return false;
        }

        $formTitle = $formInfo['title'];
        $stage     = $formInfo['stage'];
        $now       = date('Y-m-d H:i:s');

        $version = kp_next_document_version($conn, $caseId, $formNumber);
        $previousId = null;
        $previous = $conn->prepare('SELECT id FROM kp_documents WHERE case_id = ? AND form_number = ? AND is_current = 1 ORDER BY id DESC LIMIT 1');
        if ($previous) {
            $previous->bind_param('ii', $caseId, $formNumber);
            $previous->execute();
            $previousRow = $previous->get_result()->fetch_assoc();
            $previousId = $previousRow ? (int)$previousRow['id'] : null;
            $previous->close();
        }
        if ($previousId !== null) {
            $clear = $conn->prepare('UPDATE kp_documents SET is_current = 0 WHERE id = ? LIMIT 1');
            if ($clear) {
                $clear->bind_param('i', $previousId);
                $clear->execute();
                $clear->close();
            }
        }

        $stmt = $conn->prepare(
            "INSERT INTO kp_documents (case_id, form_number, form_title, stage, status, template_version, version_number, is_current, previous_document_id, file_path, content_html, input_json, generated_by, generated_at)
             VALUES (?, ?, ?, ?, 'Draft', '2.0', ?, 1, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('iissiisssis', $caseId, $formNumber, $formTitle, $stage, $version, $previousId, $filePath, $htmlContent, $inputJson, $generatedBy, $now);
        $result = $stmt->execute();
        $docId = $result ? (int)$stmt->insert_id : false;
        $stmt->close();
        return $docId;
    }
}

// ─── Download Handler Helper ─────────────────────────────────────────────────

if (!function_exists('kp_serve_docx_download')) {
    /**
     * Serve a DOCX file for download.
     *
     * @param string $filePath    Absolute path to the DOCX file
     * @param string $filename    Download filename
     */
    function kp_serve_docx_download(string $filePath, string $filename): void
    {
        if (!file_exists($filePath)) {
            http_response_code(404);
            echo 'Document not found.';
            exit;
        }

        require_once __DIR__ . '/download_signal.php';
        gabaybatas_download_ready();
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        readfile($filePath);
        exit;
    }
}

// ─── Monthly Transmittal Generator ───────────────────────────────────────────

if (!function_exists('kp_generate_monthly_transmittal')) {
    /**
     * Generate KP Form #28 (Monthly Transmittal of Final Reports) DOCX.
     *
     * @param int     $month       Month number (1-12)
     * @param int     $year        Year (e.g. 2026)
     * @param mysqli  $conn        Database connection
     * @param string  $signerName  Name of secretary signing the transmittal
     * @return string|false        Path to generated DOCX or false
     */
    function kp_generate_monthly_transmittal(int $month, int $year, mysqli $conn, string $signerName = 'HON. ULYSIS B. MACABARE')
    {
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate   = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));
        $monthName = date('F', mktime(0, 0, 0, $month, 1, $year));
        $monthYearLabel = "{$monthName} {$year}";

        // Query resolved cases in this month
        $caseLines = [];
        $lineNo = 1;

        $stmt = $conn->prepare("SELECT c.case_id, c.name, c.complained_person, s.settlement_type, s.settlement_date 
            FROM kp_settlements s 
            JOIN cases c ON s.case_id = c.id 
            WHERE s.final_at IS NOT NULL AND s.repudiated = 0 AND s.settlement_date BETWEEN ? AND ?");
        if ($stmt) {
            $stmt->bind_param('ss', $startDate, $endDate);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $type = $row['settlement_type'] === 'arbitration_award' ? 'Arbitration Award' : 'Amicable Settlement';
                $caseLines[] = "{$lineNo}. {$row['case_id']} — {$row['name']} vs. {$row['complained_person']} — {$type}";
                $lineNo++;
            }
            $stmt->close();
        }

        // Certified/dismissed cases
        $stmt2 = $conn->prepare("SELECT case_id, name, complained_person, kp_status, date_submitted 
            FROM cases 
            WHERE kp_status IN ('certified_to_court','dismissed') AND date_submitted BETWEEN ? AND ?");
        if ($stmt2) {
            $stmt2->bind_param('ss', $startDate, $endDate);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            while ($row2 = $res2->fetch_assoc()) {
                $st = $row2['kp_status'] === 'dismissed' ? 'Dismissed' : 'Certified to Court';
                $caseLines[] = "{$lineNo}. {$row2['case_id']} — {$row2['name']} vs. {$row2['complained_person']} — {$st}";
                $lineNo++;
            }
            $stmt2->close();
        }

        $caseListText = empty($caseLines) ? 'No cases resolved for this period.' : implode("\n", $caseLines);

        $dummyCase = [
            'id' => 0,
            'case_id' => 'REPORT',
            'name' => '',
            'complained_person' => '',
            'date_submitted' => $startDate,
        ];

        $extra = [
            'report_month_year' => $monthYearLabel,
            'monthly_case_list' => $caseListText,
            'cert_signer_name'  => $signerName,
        ];

        $reportDir = KP_DOCUMENT_DIR . 'reports/';
        if (!is_dir($reportDir)) mkdir($reportDir, 0755, true);

        $formInfo = kp_form_info(28);
        $templatePath = KP_TEMPLATE_DIR . ($formInfo['template'] ?? '');
        if (!file_exists($templatePath)) return false;

        $template = new \PhpOffice\PhpWord\TemplateProcessor($templatePath);
        $data = kp_build_template_data($dummyCase, $extra);
        foreach ($data as $key => $value) {
            $template->setValue($key, kp_template_xml_value((string)$value));
        }

        $outputPath = $reportDir . "KP_Form_28_Transmittal_{$year}_{$month}.docx";
        $template->saveAs($outputPath);
        return $outputPath;
    }
}
?>
