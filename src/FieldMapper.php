<?php

namespace UWMadison\QualtricsImport;

use LogicTester;
use Project;
use Throwable;

class FieldMapper
{
    /**
     * Standard Qualtrics metadata fields that should not be mapped into REDCap fields.
     */
    public const STANDARD_QUALTRICS_METADATA = [
        'StartDate',
        'EndDate',
        'Status',
        'IPAddress',
        'Progress',
        'Duration (in seconds)',
        'Finished',
        'RecordedDate',
        'ResponseId',
        'RecipientLastName',
        'RecipientFirstName',
        'RecipientEmail',
        'ExternalReference',
        'LocationLatitude',
        'LocationLongitude',
        'DistributionChannel',
        'UserLanguage',
    ];

    /**
     * Map a raw Qualtrics response row into a candidate REDCap record.
     */
    public static function mapRecord(
        array $rawRow,
        array $config,
        array $redcapFieldNames = [],
        array $redcapMetadata = [],
        ?int $projectId = null,
        ?string &$exclusionReason = null
    ): ?array {
        $exclusionReason = null;
        $respId = (string)($rawRow['ResponseId'] ?? '');
        if (!str_starts_with($respId, 'R_')) {
            $exclusionReason = "ResponseId does not start with 'R_' ({$respId})";
            return null;
        }

        // 1. Check completed response filter if enabled
        if (!empty($config['only_completed'])) {
            $finished = $rawRow['Finished'] ?? $rawRow['finished'] ?? '';
            if ($finished !== '1' && $finished !== 1 && strtolower((string)$finished) !== 'true') {
                $completeness = self::analyzeResponseCompleteness($rawRow);
                if ($completeness['status'] === 'completely_blank') {
                    $exclusionReason = "Incomplete response: response was completely blank";
                } elseif ($completeness['status'] === 'mostly_blank') {
                    $exclusionReason = "Incomplete response: response was {$completeness['summary']}";
                } else {
                    $exclusionReason = "Incomplete response (Finished is '{$finished}')";
                }
                return null;
            }
        }

        $mappingMode = $config['mapping_mode'] ?? 'auto_map_blacklist';
        $skipFields = array_filter(array_map('trim', explode(',', $config['skip_fields'] ?? '')));

        // Build lowercase lookup table for raw row keys
        $rawLower = [];
        foreach ($rawRow as $k => $v) {
            $rawLower[strtolower(trim((string)$k))] = (string)$k;
        }

        $customMappings = self::parseCustomMappings($config);
        $mapped = [];
        $matchedRawKeys = [];

        // 2. Perform custom mappings first with case/suffix-tolerance
        foreach ($customMappings as $cm) {
            $qField = $cm['qualtrics_field'];
            $rField = $cm['redcap_field'];
            $valKey = null;
            $val = self::findQualtricsValue($rawRow, $qField, $rawLower, $valKey);
            if ($val !== null) {
                if ($valKey !== null) {
                    $matchedRawKeys[$valKey] = true;
                }
                if (isset($cm['value_map'][(string)$val])) {
                    $val = $cm['value_map'][(string)$val];
                }
                $mapped[$rField] = $val;
            }
        }

        // In auto_map_blacklist mode, auto-map any remaining raw keys that directly match REDCap fields
        if ($mappingMode !== 'whitelist') {
            foreach ($rawRow as $key => $val) {
                if (isset($matchedRawKeys[$key]) || isset($mapped[$key])) {
                    continue;
                }
                if (in_array($key, self::STANDARD_QUALTRICS_METADATA, true) || in_array($key, $skipFields, true)) {
                    continue;
                }
                if (!empty($redcapFieldNames) && !in_array($key, $redcapFieldNames, true)) {
                    continue;
                }
                $mapped[$key] = $val;
            }
        }

        // 3. Automatic Data Sanitization (Dates and Phones)
        $importNotesField = $config['import_notes_field'] ?? '';
        foreach ($mapped as $field => $val) {
            if (in_array($field, AutoSanitizer::CORE_SYSTEM_FIELDS, true) || $field === $importNotesField) {
                continue;
            }
            $mapped[$field] = AutoSanitizer::sanitizeDataValue($field, $val, $redcapMetadata[$field] ?? null);
        }

        // 4. Set standard baseline fields
        $qualtricsIdField = $config['qualtrics_id_field'] ?? 'qualtrics_id';
        if ($qualtricsIdField !== '') {
            $mapped[$qualtricsIdField] = $respId;
        }
        $mapped['qualtrics_id'] = $respId;
        if (!empty($config['target_event'])) {
            $mapped['redcap_event_name'] = $config['target_event'];
        }

        $phoneField = $config['dedup_phone_field'] ?? 'phone1';
        $firstField = $config['dedup_first_name_field'] ?? 'first_name';
        $lastField = $config['dedup_last_name_field'] ?? 'last_name';

        $mapped['phone1'] = AutoSanitizer::sanitizePhone($mapped[$phoneField] ?? $rawRow['phone1'] ?? '', true);
        $rawFirstName = $mapped[$firstField] ?? $rawRow['first_name'] ?? '';
        if (is_numeric(trim((string)$rawFirstName))) {
            $rawFirstName = '';
        }
        $rawLastName = $mapped[$lastField] ?? $rawRow['last_name'] ?? '';
        if (is_numeric(trim((string)$rawLastName))) {
            $rawLastName = '';
        }
        $mapped['first_name'] = Deduplicator::sanitizeName($rawFirstName);
        $mapped['last_name'] = Deduplicator::sanitizeName($rawLastName);

        // Sanitize middle initial/name and strip pandas/missing placeholders like 'Nan', 'None', 'N/A'
        $rawMiddle = trim((string)($mapped['middle_initial'] ?? $rawRow['middle_initial'] ?? ''));
        if (in_array(strtolower($rawMiddle), ['nan', 'null', 'none', 'n/a', 'na'], true)) {
            $mapped['middle_initial'] = '';
        } else {
            $mapped['middle_initial'] = Deduplicator::sanitizeName($rawMiddle);
        }

        if (isset($mapped['middle_name']) && in_array(strtolower(trim((string)$mapped['middle_name'])), ['nan', 'null', 'none', 'n/a', 'na'], true)) {
            $mapped['middle_name'] = '';
        }

        $mapped['display_name'] = trim("{$mapped['first_name']} {$mapped['last_name']}");

        // Apply configurable static fields and import flags
        $today = date('Y-m-d');
        $staticDefaults = self::parseStaticDefaults($config);
        foreach ($staticDefaults as $sf) {
            $sField = $sf['field_name'];
            $sVal = $sf['field_value'];
            if ($sField === '') {
                continue;
            }
            // Protect core demographic fields from being overwritten by numeric flags (e.g. import flag = 1)
            if (in_array($sField, ['first_name', 'last_name'], true) && is_numeric($sVal)) {
                continue;
            }
            if (strtolower($sVal) === 'today') {
                $mapped[$sField] = $today;
            } elseif (strtolower($sVal) === 'now') {
                $mapped[$sField] = date('Y-m-d H:i:s');
            } else {
                $mapped[$sField] = $sVal;
            }
        }



        // 5. Evaluate REDCap filter logic if configured
        if (!empty($config['filter_logic'])) {
            $evalContext = array_merge($rawRow, $mapped);
            if (!self::evaluateFilterLogic($config['filter_logic'], $evalContext, $projectId)) {
                $completeness = self::analyzeResponseCompleteness($rawRow);
                if ($completeness['status'] === 'completely_blank') {
                    $exclusionReason = "Excluded by filter logic (response was completely blank)";
                } elseif ($completeness['status'] === 'mostly_blank') {
                    $exclusionReason = "Excluded by filter logic (response was {$completeness['summary']})";
                } else {
                    $exclusionReason = "Excluded by filter logic: {$config['filter_logic']}";
                }
                return null;
            }
        }

        // 6. Verify required fields
        $reqFieldsRaw = $config['required_fields'] ?? 'phone1,first_name,last_name';
        $requiredFields = array_filter(array_map('trim', explode(',', $reqFieldsRaw)));
        $missing = [];
        foreach ($requiredFields as $rf) {
            if (!isset($mapped[$rf]) || trim((string)$mapped[$rf]) === '') {
                $missing[] = $rf;
            }
        }
        if (!empty($missing)) {
            $completeness = self::analyzeResponseCompleteness($rawRow);
            $missingText = 'Missing required field(s): ' . implode(', ', $missing);
            if ($completeness['status'] === 'completely_blank') {
                $exclusionReason = "Response was completely blank. {$missingText}";
            } elseif ($completeness['status'] === 'mostly_blank') {
                $exclusionReason = "{$completeness['summary']}. {$missingText}";
            } else {
                $exclusionReason = $missingText;
            }
            return null;
        }

        return $mapped;
    }

    /**
     * Evaluator for REDCap filter logic expressions like [screen_score] = '0' or [age] >= 18.
     */
    public static function evaluateFilterLogic(string $logic, array $record, ?int $projectId = null): bool
    {
        $logic = trim($logic);
        if ($logic === '') {
            return true;
        }

        // Attempt native REDCap LogicTester if Project context is available
        if ($projectId !== null && class_exists(LogicTester::class) && class_exists(Project::class)) {
            try {
                $proj = new Project($projectId);
                $eventId = !empty($record['redcap_event_name']) && isset($proj->uniqueEventNames[$record['redcap_event_name']])
                    ? array_search($record['redcap_event_name'], $proj->uniqueEventNames)
                    : $proj->firstEventId;
                $recordData = [$eventId => $record];
                return (bool)LogicTester::apply($logic, $recordData, $proj);
            } catch (Throwable) {
                // Fall back to standalone logic parser below
            }
        }

        return self::evaluateLogicString($logic, $record);
    }

    /**
     * Standalone REDCap logic evaluation supporting AND/OR clauses and standard comparison operators.
     */
    public static function evaluateLogicString(string $logic, array $record): bool
    {
        $orClauses = preg_split('/\s+or\s+/i', trim($logic));
        foreach ($orClauses as $orClause) {
            $andClauses = preg_split('/\s+and\s+/i', trim($orClause));
            $andPassed = true;
            foreach ($andClauses as $condition) {
                if (!self::evaluateAtomicCondition(trim($condition), $record)) {
                    $andPassed = false;
                    break;
                }
            }
            if ($andPassed) {
                return true;
            }
        }
        return false;
    }

    /**
     * Evaluate single condition like [screen_score] = '0', [age] >= 18, [field] != ''
     */
    public static function evaluateAtomicCondition(string $condition, array $record): bool
    {
        $condition = trim($condition, '() ');
        if ($condition === '') {
            return true;
        }

        if (preg_match('/^\[([a-zA-Z0-9_\-]+)(?:\(([a-zA-Z0-9_\-]+)\))?\]\s*(==|=|!=|<>|<=|>=|<|>)\s*[\'"]?([^\'"]*)[\'"]?$/', $condition, $m)) {
            $field = $m[1];
            $cbCode = $m[2] ?? '';
            $op = $m[3];
            $expected = $m[4];

            $val = '';
            if ($cbCode !== '') {
                if (isset($record["{$field}___{$cbCode}"])) {
                    $val = (string)$record["{$field}___{$cbCode}"];
                } elseif (isset($record[$field]) && is_array($record[$field])) {
                    $val = in_array($cbCode, $record[$field]) ? '1' : '0';
                }
            } else {
                $val = isset($record[$field]) ? (string)$record[$field] : '';
            }

            $valTrim = trim($val);
            $expTrim = trim($expected);

            $isNumeric = is_numeric($valTrim) && is_numeric($expTrim) && $valTrim !== '' && $expTrim !== '';

            return match ($op) {
                '=', '==' => $isNumeric ? ((float)$valTrim == (float)$expTrim) : ($valTrim === $expTrim),
                '!=', '<>' => $isNumeric ? ((float)$valTrim != (float)$expTrim) : ($valTrim !== $expTrim),
                '<' => (float)$valTrim < (float)$expTrim,
                '<=' => (float)$valTrim <= (float)$expTrim,
                '>' => (float)$valTrim > (float)$expTrim,
                '>=' => (float)$valTrim >= (float)$expTrim,
                default => true,
            };
        }

        return true;
    }

    /**
     * Parse custom value mappings supporting both getSubSettings() structured array
     * and raw getProjectSettings() parallel arrays.
     */
    public static function parseCustomMappings(array $config): array
    {
        $mappings = [];

        if (!empty($config['custom_value_mappings']) && is_array($config['custom_value_mappings'])) {
            $isAssocList = false;
            foreach ($config['custom_value_mappings'] as $row) {
                if (!is_array($row) || (!isset($row['qualtrics_field']) && !isset($row['redcap_field']))) {
                    continue;
                }
                $isAssocList = true;
                $qField = trim((string)($row['qualtrics_field'] ?? ''));
                $rField = trim((string)($row['redcap_field'] ?? ''));
                $vJson = $row['value_map_json'] ?? '';
                if ($qField === '' || $rField === '') {
                    continue;
                }
                $mappings[] = [
                    'qualtrics_field' => $qField,
                    'redcap_field' => $rField,
                    'value_map' => self::parseValueMap($vJson),
                ];
            }
            if ($isAssocList) {
                return $mappings;
            }
        }

        // Handle raw parallel arrays from getProjectSettings()
        if (!empty($config['qualtrics_field']) && is_array($config['qualtrics_field']) &&
            !empty($config['redcap_field']) && is_array($config['redcap_field'])) {
            foreach ($config['qualtrics_field'] as $idx => $q) {
                $qField = trim((string)$q);
                $rField = trim((string)($config['redcap_field'][$idx] ?? ''));
                $vJson = $config['value_map_json'][$idx] ?? '';
                if ($qField === '' || $rField === '') {
                    continue;
                }
                $mappings[] = [
                    'qualtrics_field' => $qField,
                    'redcap_field' => $rField,
                    'value_map' => self::parseValueMap($vJson),
                ];
            }
        }

        return $mappings;
    }

    /**
     * Parse static field defaults supporting both getSubSettings() structured array
     * and raw getProjectSettings() parallel arrays.
     */
    public static function parseStaticDefaults(array $config): array
    {
        $defaults = [];

        if (!empty($config['static_field_defaults']) && is_array($config['static_field_defaults'])) {
            $isAssocList = false;
            foreach ($config['static_field_defaults'] as $row) {
                if (!is_array($row) || (!isset($row['field_name']) && !isset($row['field_value']))) {
                    continue;
                }
                $isAssocList = true;
                $fName = trim((string)($row['field_name'] ?? ''));
                $fVal = trim((string)($row['field_value'] ?? ''));
                if ($fName === '') {
                    continue;
                }
                $defaults[] = [
                    'field_name' => $fName,
                    'field_value' => $fVal,
                ];
            }
            if ($isAssocList) {
                return $defaults;
            }
        }

        // Handle raw parallel arrays from getProjectSettings()
        if (!empty($config['field_name']) && is_array($config['field_name'])) {
            foreach ($config['field_name'] as $idx => $fn) {
                $fName = trim((string)$fn);
                $fVal = trim((string)($config['field_value'][$idx] ?? ''));
                if ($fName === '') {
                    continue;
                }
                $defaults[] = [
                    'field_name' => $fName,
                    'field_value' => $fVal,
                ];
            }
        }

        return $defaults;
    }

    /**
     * Decode or normalize JSON value map.
     */
    public static function parseValueMap($val): array
    {
        if (empty($val)) {
            return [];
        }
        if (is_array($val)) {
            return $val;
        }
        $decoded = json_decode((string)$val, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Case- and suffix-tolerant lookup of a Qualtrics field value in raw response row.
     * Prioritizes respondent text entries (_TEXT) over numeric choice recodes.
     * Also normalizes question references (e.g., Q4 <-> QID4 <-> QID4_TEXT).
     */
    public static function findQualtricsValue(array $rawRow, string $qField, ?array &$rawLower = null, ?string &$matchedKey = null): mixed
    {
        $matchedKey = null;
        $qFieldTrimmed = trim($qField);
        if ($qFieldTrimmed === '') {
            return null;
        }

        // Build lowercase index once
        if ($rawLower === null) {
            $rawLower = [];
            foreach ($rawRow as $k => $v) {
                $rawLower[strtolower(trim((string)$k))] = (string)$k;
            }
        }

        $lowerTarget = strtolower($qFieldTrimmed);

        // Candidate search list in priority order
        $candidates = [];

        // Check if $qFieldTrimmed is a question reference (e.g. Q4, QID4, 4, Q4_TEXT, QID4_TEXT)
        if (preg_match('/^q(?:id)?_?(\d+)(.*)$/i', $lowerTarget, $m)) {
            $num = $m[1];
            $extra = $m[2]; // e.g. '_text' or ''
            if ($extra === '_text') {
                $candidates[] = "qid{$num}_text";
                $candidates[] = "q{$num}_text";
                $candidates[] = "{$num}_text";
            } else {
                // Prefer _text variant first (contains respondent's actual text entry)
                $candidates[] = "qid{$num}_text";
                $candidates[] = "q{$num}_text";
                $candidates[] = "{$num}_text";
                // Then base keys
                $candidates[] = "qid{$num}";
                $candidates[] = "q{$num}";
                $candidates[] = "{$num}";
            }
        } else {
            if (!str_ends_with($lowerTarget, '_text')) {
                // Prefer _text variant if present
                $candidates[] = $lowerTarget . '_text';
            }
            $candidates[] = $lowerTarget;
            if (str_ends_with($lowerTarget, '_text')) {
                $candidates[] = substr($lowerTarget, 0, -5);
            }
        }

        // 1. Check candidates: if candidate exists in row and is non-empty, return it immediately
        foreach ($candidates as $cand) {
            if (!isset($rawLower[$cand])) {
                continue;
            }
            $realKey = $rawLower[$cand];
            $val = $rawRow[$realKey] ?? null;
            if ($val !== null && trim((string)$val) !== '') {
                $matchedKey = $realKey;
                return $val;
            }
        }

        // 2. Exact match fallback
        if (array_key_exists($qFieldTrimmed, $rawRow)) {
            $matchedKey = $qFieldTrimmed;
            return $rawRow[$qFieldTrimmed];
        }

        // 3. Any case-insensitive match (even if empty)
        if (isset($rawLower[$lowerTarget])) {
            $matchedKey = $rawLower[$lowerTarget];
            return $rawRow[$matchedKey];
        }

        return null;
    }

    /**
     * Extract candidate display name and phone for preview display even if validation failed.
     */
    public static function extractCandidatePreviewInfo(array $rawRow, array $config): array
    {
        $rawLower = [];
        foreach ($rawRow as $k => $v) {
            $rawLower[strtolower(trim((string)$k))] = (string)$k;
        }

        $customMappings = self::parseCustomMappings($config);
        $mappedVals = [];
        foreach ($customMappings as $cm) {
            $val = self::findQualtricsValue($rawRow, $cm['qualtrics_field'], $rawLower);
            if ($val !== null && trim((string)$val) !== '') {
                $mappedVals[$cm['redcap_field']] = $val;
            }
        }

        $phoneField = $config['dedup_phone_field'] ?? 'phone1';
        $firstField = $config['dedup_first_name_field'] ?? 'first_name';
        $lastField = $config['dedup_last_name_field'] ?? 'last_name';

        $first = $mappedVals[$firstField]
            ?? $rawRow[$firstField]
            ?? $rawRow['first_name']
            ?? $rawRow['RecipientFirstName']
            ?? self::findQualtricsValue($rawRow, 'first_name', $rawLower)
            ?? '';

        $last = $mappedVals[$lastField]
            ?? $rawRow[$lastField]
            ?? $rawRow['last_name']
            ?? $rawRow['RecipientLastName']
            ?? self::findQualtricsValue($rawRow, 'last_name', $rawLower)
            ?? '';

        $phone = $mappedVals[$phoneField]
            ?? $rawRow[$phoneField]
            ?? $rawRow['phone1']
            ?? self::findQualtricsValue($rawRow, 'phone1', $rawLower)
            ?? '';

        // If first or last name is numeric (e.g. '1'), discard it (it's a choice code, not a name)
        if (is_numeric(trim((string)$first))) {
            $first = '';
        }
        if (is_numeric(trim((string)$last))) {
            $last = '';
        }

        return [
            'name' => trim(Deduplicator::sanitizeName((string)$first) . ' ' . Deduplicator::sanitizeName((string)$last)),
            'phone' => AutoSanitizer::formatPhone((string)$phone),
        ];
    }

    /**
     * Analyze whether a Qualtrics response row was completely blank, mostly blank, or answered.
     *
     * @param array $rawRow The raw response row from Qualtrics.
     * @return array [
     *     'status' => 'completely_blank' | 'mostly_blank' | 'answered',
     *     'answered_count' => int,
     *     'total_questions' => int,
     *     'progress' => ?int,
     *     'summary' => string
     * ]
     */
    public static function analyzeResponseCompleteness(array $rawRow): array
    {
        $metadataLower = array_flip(array_map('strtolower', self::STANDARD_QUALTRICS_METADATA));
        $metadataLower['resolution'] = true;
        $metadataLower['_recordid'] = true;

        $answeredCount = 0;
        foreach ($rawRow as $key => $val) {
            $kLower = strtolower(trim((string)$key));
            if (isset($metadataLower[$kLower])) {
                continue;
            }
            if ($val !== null && trim((string)$val) !== '') {
                $answeredCount++;
            }
        }

        $progress = null;
        if (isset($rawRow['Progress']) && is_numeric($rawRow['Progress'])) {
            $progress = (int)$rawRow['Progress'];
        } elseif (isset($rawRow['progress']) && is_numeric($rawRow['progress'])) {
            $progress = (int)$rawRow['progress'];
        }

        // 1. Completely blank: no question answers, or explicit 0% progress with 0 answers
        if ($answeredCount === 0 || ($progress !== null && $progress === 0 && $answeredCount === 0)) {
            return [
                'status' => 'completely_blank',
                'answered_count' => 0,
                'progress' => $progress,
                'summary' => 'Response was completely blank',
            ];
        }

        // 2. Mostly blank: low progress (<= 25%) or only 1-2 questions answered when progress is unknown
        $isMostlyBlank = ($progress !== null && $progress > 0 && $progress <= 25)
            || ($progress === null && $answeredCount <= 2);

        if ($isMostlyBlank) {
            $summary = 'Response was mostly blank';
            if ($progress !== null && $progress > 0) {
                $summary .= " ({$progress}% progress)";
            }
            return [
                'status' => 'mostly_blank',
                'answered_count' => $answeredCount,
                'progress' => $progress,
                'summary' => $summary,
            ];
        }

        return [
            'status' => 'answered',
            'answered_count' => $answeredCount,
            'progress' => $progress,
            'summary' => ($progress !== null) ? "{$progress}% progress" : '',
        ];
    }
}

