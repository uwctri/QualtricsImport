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
        ?int $projectId = null
    ): ?array {
        $respId = (string)($rawRow['ResponseId'] ?? '');
        if (!str_starts_with($respId, 'R_')) {
            return null;
        }

        // 1. Check completed response filter if enabled
        if (!empty($config['only_completed'])) {
            $finished = $rawRow['Finished'] ?? $rawRow['finished'] ?? '';
            if ($finished !== '1' && $finished !== 1 && strtolower((string)$finished) !== 'true') {
                return null;
            }
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

        // 5. Evaluate REDCap filter logic if configured
        if (!empty($config['filter_logic'])) {
            $evalContext = array_merge($rawRow, $mapped);
            if (!self::evaluateFilterLogic($config['filter_logic'], $evalContext, $projectId)) {
                return null;
            }
        }

        // 6. Verify required fields
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
}
