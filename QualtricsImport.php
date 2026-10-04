<?php

namespace UWMadison\QualtricsImport;

use ExternalModules\AbstractExternalModule;
use REDCap;
use Exception;

require_once __DIR__ . '/src/AutoSanitizer.php';
require_once __DIR__ . '/src/Deduplicator.php';
require_once __DIR__ . '/src/FieldMapper.php';
require_once __DIR__ . '/src/QualtricsClient.php';
require_once __DIR__ . '/src/RecordManager.php';

class QualtricsImport extends AbstractExternalModule
{
    /**
     * Hook called at top of every page.
     */
    public function redcap_every_page_top()
    {
        if ($this->isPage('ExternalModules/manager/project.php')) {
            $this->initializeJavascriptModuleObject();
            $this->passArgument('prefix', $this->PREFIX);
            $this->passArgument('allowProjectOverrides', (bool)$this->getSystemSetting('allow_project_overrides'));
            $this->includeJs('js/config_modal.js', 'defer');
        }
    }

    /**
     * Filter project settings schema before rendering configure modal.
     */
    public function redcap_module_configuration_settings($project_id, $settings)
    {
        if ($project_id && !$this->getSystemSetting('allow_project_overrides')) {
            $keysToRemove = [
                'override_credentials',
                'project_qualtrics_api_token',
                'project_qualtrics_data_center',
            ];

            $settings = array_values(array_filter($settings, function ($s) use ($keysToRemove) {
                return !in_array($s['key'] ?? '', $keysToRemove, true);
            }));
        }

        return $settings;
    }

    /**
     * Native REDCap framework AJAX handler.
     */
    public function redcap_module_ajax($action, $payload, $project_id)
    {
        $limit = isset($payload['limit']) ? max(1, min(10000, (int)$payload['limit'])) : 1000;
        $sinceType = (string)($payload['sinceType'] ?? 'auto');
        $customStartDate = !empty($payload['startDate']) ? (string)$payload['startDate'] : null;

        return match ($action) {
            'testConnection' => $this->getQualtricsClient($project_id)->testConnection(),
            'getSurveys' => ['success' => true, 'surveys' => $this->getQualtricsClient($project_id)->getSurveys()],
            'previewImport' => ['success' => true, 'data' => $this->executeImportPipeline($project_id, true, false, $limit, $sinceType, $customStartDate)],
            'runImport' => ['success' => true, 'data' => $this->executeImportPipeline($project_id, false, false, $limit, $sinceType, $customStartDate)],
        };
    }

    /**
     * Resolve effective startDate for Qualtrics export based on sinceType and project history.
     */
    public function resolveStartDate(int $projectId, string $sinceType = 'auto'): ?string
    {
        $lastSyncTime = (string)$this->getProjectSetting('last_successful_sync_time', $projectId);
        return self::calculateStartDate($sinceType, $lastSyncTime);
    }

    /**
     * Calculate effective startDate string from sinceType and lastSyncTime.
     */
    public static function calculateStartDate(string $sinceType = 'auto', ?string $lastSyncTime = null, ?int $currentTime = null): ?string
    {
        return QualtricsClient::calculateStartDate($sinceType, $lastSyncTime, $currentTime);
    }

    /**
     * Recurring cron job for background Qualtrics sync.
     */
    public function cronQualtricsImport($cronInfo): string
    {
        $processedCount = 0;
        foreach ($this->getProjectsWithModuleEnabled() as $pid) {
            if (!$this->getProjectSetting('import_enabled', $pid)) {
                continue;
            }

            $surveyId = $this->getProjectSetting('qualtrics_survey_id', $pid);
            if (empty($surveyId)) {
                continue;
            }

            $frequency = (int)($this->getProjectSetting('sync_frequency', $pid) ?: 3600);
            $lastRun = (int)$this->getProjectSetting('last_cron_run_timestamp', $pid);
            $now = time();

            if (($now - $lastRun) < $frequency) {
                continue;
            }

            $this->executeImportPipeline($pid, false, true);
            $this->setProjectSetting('last_cron_run_timestamp', $now, $pid);
            $processedCount++;
        }

        return "Qualtrics Import cron completed. Synchronized {$processedCount} projects.";
    }

    /**
     * Main execution pipeline for both dry-run preview and live import.
     */
    public function executeImportPipeline(
        int $projectId,
        bool $isDryRun = false,
        bool $isCron = false,
        ?int $limit = 1000,
        ?string $sinceType = 'auto',
        ?string $customStartDate = null
    ): array {
        $today = date('Y-m-d');
        $config = $this->getProjectSettings($projectId);
        $surveyId = trim($config['qualtrics_survey_id'] ?? '');

        if (empty($surveyId)) {
            throw new Exception("Qualtrics Survey ID is not configured for this project.");
        }

        // 1. Fetch survey responses from Qualtrics
        $client = $this->getQualtricsClient($projectId);
        if ($isCron) {
            $startDate = $this->getProjectSetting('last_successful_sync_time', $projectId);
        } elseif ($customStartDate !== null) {
            $startDate = $customStartDate;
        } else {
            $startDate = $this->resolveStartDate($projectId, $sinceType ?? 'auto');
        }

        $rawSurveyRows = $client->exportResponses($surveyId, $startDate);
        $totalRetrieved = count($rawSurveyRows);

        if ($limit !== null && $limit > 0 && $totalRetrieved > $limit) {
            $rawSurveyRows = array_slice($rawSurveyRows, 0, $limit);
        }

        // 2. Fetch existing records and metadata from REDCap
        $poolRecords = RecordManager::fetchExistingRecords($projectId, $config);
        $dict = REDCap::getDataDictionary($projectId, 'array') ?: [];
        $fieldNames = array_keys($dict);

        // 3. Processing queues
        $seedCounter = 0;
        $evaluations = [];
        $newRecordsToSave = [];
        $noteUpdatesToSave = [];
        $importedRecordIds = [];

        $categoryCounts = [
            'clean_new' => 0,
            'case_4_suspected' => 0,
            'case_2_duplicate' => 0,
            'case_3_household' => 0,
            'case_1_existing_id' => 0,
            'invalid_phone' => 0,
            'missing_required' => 0,
        ];

        // 4. Process each survey response
        foreach ($rawSurveyRows as $rawRow) {
            $mapped = FieldMapper::mapRecord($rawRow, $config, $fieldNames, $dict, $projectId);
            if ($mapped === null) {
                $evaluations[] = [
                    'category' => 'Ineligible / Missing Fields',
                    'status' => 'skipped_ineligible',
                    'description' => 'Response excluded by filter logic or missing required fields',
                    'qualtrics_id' => (string)($rawRow['ResponseId'] ?? ''),
                    'candidate_name' => trim(($rawRow['first_name'] ?? '') . ' ' . ($rawRow['last_name'] ?? '')),
                    'candidate_phone' => (string)($rawRow['phone1'] ?? ''),
                    'record_id' => null,
                    'scores' => null,
                ];
                $categoryCounts['missing_required']++;

                if (!$isDryRun) {
                    $this->log("Qualtrics Import: Response excluded", [
                        'project_id' => $projectId,
                        'qualtrics_id' => (string)($rawRow['ResponseId'] ?? ''),
                        'candidate_name' => trim(($rawRow['first_name'] ?? '') . ' ' . ($rawRow['last_name'] ?? '')),
                        'candidate_phone' => (string)($rawRow['phone1'] ?? ''),
                        'status' => 'skipped_ineligible',
                        'reason' => 'Excluded by filter logic, completion status, or missing required fields',
                    ]);
                }
                continue;
            }

            // Deduplication evaluation
            $eval = Deduplicator::evaluateCandidate($mapped, $poolRecords, $config, $today);

            $evalSummary = [
                'category' => $eval['category'],
                'status' => $eval['status'],
                'description' => $eval['description'],
                'qualtrics_id' => $mapped['qualtrics_id'],
                'candidate_name' => $mapped['display_name'],
                'candidate_phone' => $mapped['phone1'],
                'record_id' => null,
                'matched_record_id' => $eval['matched_record']['record_id'] ?? null,
                'scores' => $eval['scores'] ?? null,
            ];

            if ($eval['is_duplicate']) {
                // Case 3: Update existing record's notes
                if ($eval['status'] === 'rejected_case_3' && !empty($config['import_notes_field'])) {
                    $notesField = $config['import_notes_field'];
                    $origId = $eval['original_record_id'];
                    $noteEntry = $eval['note_entry'];

                    $origNotes = '';
                    foreach ($poolRecords as &$pr) {
                        if ($pr['record_id'] === $origId) {
                            $origNotes = $pr['notes'];
                            $newNotes = ($origNotes !== '') ? "{$origNotes}\n{$noteEntry}" : $noteEntry;
                            $pr['notes'] = $newNotes;
                            break;
                        }
                    }
                    unset($pr);

                    $targetEvent = $config['target_event'] ?? '';
                    $noteUpdateRecord = [
                        REDCap::getRecordIdField() => $origId,
                        $notesField => ($origNotes !== '') ? "{$origNotes}\n{$noteEntry}" : $noteEntry,
                    ];
                    if ($targetEvent !== '') {
                        $noteUpdateRecord['redcap_event_name'] = $targetEvent;
                    }
                    $noteUpdatesToSave[$origId] = $noteUpdateRecord;
                }

                match ($eval['status']) {
                    'skipped_case_1' => $categoryCounts['case_1_existing_id']++,
                    'skipped_case_2' => $categoryCounts['case_2_duplicate']++,
                    'rejected_case_3' => $categoryCounts['case_3_household']++,
                    default => $categoryCounts['invalid_phone']++,
                };

                $evaluations[] = $evalSummary;

                if (!$isDryRun) {
                    $this->log("Qualtrics Import: Duplicate response excluded", [
                        'project_id' => $projectId,
                        'qualtrics_id' => $mapped['qualtrics_id'],
                        'candidate_name' => $mapped['display_name'],
                        'candidate_phone' => $mapped['phone1'],
                        'category' => $eval['category'],
                        'status' => $eval['status'],
                        'matched_record_id' => $eval['matched_record']['record_id'] ?? $eval['original_record_id'] ?? null,
                        'description' => $eval['description'],
                    ]);
                }
                continue;
            }

            // Candidate accepted
            $allocatedId = RecordManager::getNextRecordId(
                $projectId,
                $poolRecords,
                $config,
                $seedCounter,
                $mapped['qualtrics_id']
            );

            $mapped[REDCap::getRecordIdField()] = $allocatedId;
            $mapped['import_date'] = $today;
            $mapped['first_import_date'] = $today;
            $mapped['study_status'] = '1';

            if (!empty($eval['candidate_note']) && !empty($config['import_notes_field'])) {
                $mapped[$config['import_notes_field']] = $eval['candidate_note'];
            }

            $evalSummary['record_id'] = $allocatedId;
            $evaluations[] = $evalSummary;

            $newRecordsToSave[] = $mapped;
            $importedRecordIds[] = $allocatedId;

            if ($eval['status'] === 'accepted_case_4') {
                $categoryCounts['case_4_suspected']++;
            } else {
                $categoryCounts['clean_new']++;
            }


            // Add accepted record to pool for intra-batch deduplication
            $poolRecords[] = [
                'record_id' => $allocatedId,
                'qualtrics_id' => $mapped['qualtrics_id'],
                'phone1' => $mapped['phone1'],
                'first_name' => $mapped['first_name'],
                'last_name' => $mapped['last_name'],
                'redcap_event_name' => $mapped['redcap_event_name'] ?? '',
                'notes' => $mapped[$config['import_notes_field'] ?? ''] ?? '',
            ];
        }

        $results = [
            'is_dry_run' => $isDryRun,
            'start_date_used' => $startDate,
            'survey_rows_retrieved' => $totalRetrieved,
            'survey_rows_evaluated' => count($rawSurveyRows),
            'limit_applied' => ($limit !== null && $totalRetrieved > $limit) ? $limit : null,
            'ready_to_import_count' => count($newRecordsToSave),
            'note_updates_count' => count($noteUpdatesToSave),
            'counts' => $categoryCounts,
            'evaluations' => $evaluations,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        // 5. Commit to REDCap if not dry run
        if (!$isDryRun) {
            $results['commit_results'] = RecordManager::commitImports($projectId, $newRecordsToSave, $noteUpdatesToSave);
            $this->setProjectSetting('last_successful_sync_time', gmdate('Y-m-d\TH:i:s\Z'), $projectId);
            $this->log("Qualtrics Import completed for project {$projectId}", [
                'project_id' => $projectId,
                'imported_count' => count($newRecordsToSave),
                'notes_updated_count' => count($noteUpdatesToSave),
                'counts' => json_encode($results['counts']),
            ]);
        }

        return $results;
    }

    /**
     * Instantiate QualtricsClient with resolved project or system credentials.
     */
    public function getQualtricsClient(int $projectId): QualtricsClient
    {
        $allowOverrides = (bool)$this->getSystemSetting('allow_project_overrides');
        $override = $allowOverrides && (bool)$this->getProjectSetting('override_credentials', $projectId);

        $token = $override
            ? $this->getProjectSetting('project_qualtrics_api_token', $projectId)
            : $this->getSystemSetting('qualtrics_api_token');

        $dataCenter = $override
            ? $this->getProjectSetting('project_qualtrics_data_center', $projectId)
            : $this->getSystemSetting('qualtrics_data_center');

        if (empty($token) || empty($dataCenter)) {
            throw new Exception("Qualtrics credentials (token and data center) must be configured in settings.");
        }

        return new QualtricsClient((string)$token, (string)$dataCenter);
    }

    public function passArgument(string $name, $value): void
    {
        echo "<script>" . $this->getJavascriptModuleObjectName() . "." . $name . " = " . json_encode($value) . ";</script>";
    }

    public function includeJs(string $path, $defer = false): void
    {
        $attr = ($defer === true || $defer === 'defer') ? ' defer' : '';
        echo '<script type="text/javascript" src="' . $this->getUrl($path) . '"' . $attr . '></script>';
    }

    public function includeCss(string $path): void
    {
        echo '<link rel="stylesheet" type="text/css" href="' . $this->getUrl($path) . '"/>';
    }
}
