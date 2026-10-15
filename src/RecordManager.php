<?php

namespace UWMadison\QualtricsImport;

use REDCap;
use Project;
use Throwable;

class RecordManager
{
    /**
     * Determine the record ID field name for a given project in a context-safe way.
     */
    public static function getRecordIdField(int $projectId, ?array $dict = null): string
    {
        if (!empty($dict)) {
            $firstField = array_key_first($dict);
            if (!empty($firstField))
                return (string)$firstField;
        }

        try {
            $proj = new Project($projectId);
            if (!empty($proj->table_pk))
                return (string)$proj->table_pk;
        } catch (Throwable) {
        }

        try {
            $dict = REDCap::getDataDictionary($projectId, 'array');
            if (!empty($dict)) {
                $firstField = array_key_first($dict);
                if (!empty($firstField))
                    return (string)$firstField;
            }
        } catch (Throwable) {
        }

        return 'record_id';
    }

    /**
     * Fetch existing records from REDCap for deduplication matching and seed determination.
     */
    public static function fetchExistingRecords(int $projectId, array $config, ?array $dict = null): array
    {
        $idField = self::getRecordIdField($projectId, $dict);
        $strategy = $config['record_id_strategy'] ?? 'seed_increment';
        $qualtricsIdField = ($strategy === 'qualtrics_response_id') ? $idField : ($config['qualtrics_id_field'] ?? 'qualtrics_id');
        $phoneField = $config['dedup_phone_field'] ?? 'phone1';
        $firstField = $config['dedup_first_name_field'] ?? 'first_name';
        $lastField = $config['dedup_last_name_field'] ?? 'last_name';
        $notesField = $config['import_notes_field'] ?? '';

        $fieldsToExport = array_unique(array_filter([
            $idField,
            $qualtricsIdField,
            $phoneField,
            $firstField,
            $lastField,
            $notesField,
        ]));

        $rawRecords = REDCap::getData([
            'project_id' => $projectId,
            'return_format' => 'json',
            'fields' => $fieldsToExport,
        ]);

        $decoded = json_decode($rawRecords, true) ?: [];
        $pool = [];
        $targetEvent = $config['target_event'] ?? '';

        foreach ($decoded as $row) {
            $recId = (string)($row[$idField] ?? '');
            if ($recId === '')
                continue;

            $pool[] = [
                'record_id' => $recId,
                'qualtrics_id' => (string)($row[$qualtricsIdField] ?? ''),
                'phone1' => AutoSanitizer::sanitizePhone($row[$phoneField] ?? '', true),
                'first_name' => (string)($row[$firstField] ?? ''),
                'last_name' => (string)($row[$lastField] ?? ''),
                'redcap_event_name' => (string)($row['redcap_event_name'] ?? $targetEvent),
                'notes' => (string)($notesField ? ($row[$notesField] ?? '') : ''),
            ];
        }

        return $pool;
    }

    /**
     * Determine the next record ID according to project strategy.
     */
    public static function getNextRecordId(
        int $projectId,
        array $poolRecords,
        array $config,
        int &$seedCounter,
        string $candidateResponseId = ''
    ): string {
        $strategy = $config['record_id_strategy'] ?? 'seed_increment';

        if ($strategy === 'qualtrics_response_id')
            return $candidateResponseId;

        if ($strategy === 'autonumber')
            return (string)REDCap::reserveNewRecordId($projectId);

        // Default: seed_increment
        if ($seedCounter === 0) {
            $minSeed = max((int)($config['min_seed_id'] ?? 1000), 1);
            $numericIds = [0];
            foreach ($poolRecords as $rec) {
                $id = $rec['record_id'];
                if (ctype_digit($id))
                    $numericIds[] = (int)$id;
            }
            $seedCounter = max(max($numericIds) + 1, $minSeed);
        }

        $allocated = (string)$seedCounter;
        $seedCounter++;
        return $allocated;
    }

    /**
     * Commit new candidate records and note updates to REDCap.
     */
    public static function commitImports(int $projectId, array $newRecords, array $noteUpdates): array
    {
        $results = [
            'imported_count' => 0,
            'notes_updated_count' => 0,
            'errors' => [],
        ];

        if (!empty($noteUpdates)) {
            $noteSaveRes = REDCap::saveData($projectId, 'json', json_encode(array_values($noteUpdates)));
            if (!empty($noteSaveRes['errors']))
                $results['errors'][] = "Notes error: " . json_encode($noteSaveRes['errors']);
            $results['notes_updated_count'] = count($noteSaveRes['ids'] ?? []);
        }

        if (!empty($newRecords)) {
            $importSaveRes = REDCap::saveData($projectId, 'json', json_encode(array_values($newRecords)));
            if (!empty($importSaveRes['errors']))
                $results['errors'][] = "Import error: " . json_encode($importSaveRes['errors']);
            $results['imported_count'] = count($importSaveRes['ids'] ?? []);
        }

        return $results;
    }
}
