<?php

namespace UWMadison\QualtricsImport;

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
        array $redcapMetadata = []
    ): ?array {
        $respId = (string)($rawRow['ResponseId'] ?? '');
        if (!str_starts_with($respId, 'R_')) {
            return null;
        }

        // 1. Evaluate filter logic if configured
        if (!empty($config['filter_logic']) && !self::evaluateFilterLogic($config['filter_logic'], $rawRow)) {
            return null;
        }

        $mappingMode = $config['mapping_mode'] ?? 'auto_map_blacklist';
        $skipFields = array_filter(array_map('trim', explode(',', $config['skip_fields'] ?? '')));

        // Parse custom value mappings
        $customMappings = [];
        if (!empty($config['custom_value_mappings']) && is_array($config['custom_value_mappings'])) {
            foreach ($config['custom_value_mappings'] as $cm) {
                $qField = trim($cm['qualtrics_field'] ?? '');
                $rField = trim($cm['redcap_field'] ?? '');
                if ($qField !== '' && $rField !== '') {
                    $customMappings[$qField] = [
                        'redcap_field' => $rField,
                        'value_map' => !empty($cm['value_map_json']) ? json_decode($cm['value_map_json'], true) : [],
                    ];
                }
            }
        }

        $mapped = [];

        // 2. Perform mapping based on mode
        if ($mappingMode === 'whitelist') {
            foreach ($customMappings as $qField => $mapping) {
                if (isset($rawRow[$qField])) {
                    $val = $rawRow[$qField];
                    if (isset($mapping['value_map'][(string)$val])) {
                        $val = $mapping['value_map'][(string)$val];
                    }
                    $mapped[$mapping['redcap_field']] = $val;
                }
            }
        } else {
            // auto_map_blacklist mode
            foreach ($rawRow as $key => $val) {
                if (in_array($key, self::STANDARD_QUALTRICS_METADATA, true) || in_array($key, $skipFields, true)) {
                    continue;
                }

                $targetField = $key;
                $valueMap = null;
                if (isset($customMappings[$key])) {
                    $targetField = $customMappings[$key]['redcap_field'];
                    $valueMap = $customMappings[$key]['value_map'];
                }

                if (!empty($redcapFieldNames) && !in_array($targetField, $redcapFieldNames, true)) {
                    continue;
                }

                if (isset($valueMap[(string)$val])) {
                    $val = $valueMap[(string)$val];
                }

                $mapped[$targetField] = $val;
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
        $mapped['qualtrics_id'] = $respId;
        if (!empty($config['target_event'])) {
            $mapped['redcap_event_name'] = $config['target_event'];
        }

        $phoneField = $config['dedup_phone_field'] ?? 'phone1';
        $firstField = $config['dedup_first_name_field'] ?? 'first_name';
        $lastField = $config['dedup_last_name_field'] ?? 'last_name';

        $mapped['phone1'] = AutoSanitizer::sanitizePhone($mapped[$phoneField] ?? $rawRow['phone1'] ?? '', true);
        $mapped['first_name'] = Deduplicator::sanitizeName($mapped[$firstField] ?? $rawRow['first_name'] ?? '');
        $mapped['last_name'] = Deduplicator::sanitizeName($mapped[$lastField] ?? $rawRow['last_name'] ?? '');
        $mapped['middle_initial'] = Deduplicator::sanitizeName($mapped['middle_initial'] ?? $rawRow['middle_initial'] ?? '');
        $mapped['display_name'] = trim("{$mapped['first_name']} {$mapped['last_name']}");

        // 5. Verify required fields
        $reqFieldsRaw = $config['required_fields'] ?? 'phone1,first_name,last_name';
        $requiredFields = array_filter(array_map('trim', explode(',', $reqFieldsRaw)));
        foreach ($requiredFields as $rf) {
            if (!isset($mapped[$rf]) || trim((string)$mapped[$rf]) === '') {
                return null;
            }
        }

        return $mapped;
    }

    /**
     * Evaluator for simple filter logic expressions like [screen_score] == '0' or Finished == '1'.
     */
    public static function evaluateFilterLogic(string $logic, array $record): bool
    {
        $logic = trim($logic);
        if ($logic === '') {
            return true;
        }

        if (preg_match('/^\[?([a-zA-Z0-9_\-]+)\]?\s*(==|!=)\s*[\'"]?([^\'"]*)[\'"]?$/', $logic, $m)) {
            $actualVal = isset($record[$m[1]]) ? (string)$record[$m[1]] : '';
            return ($m[2] === '==') ? (trim($actualVal) === trim($m[3])) : (trim($actualVal) !== trim($m[3]));
        }

        return true;
    }
}
