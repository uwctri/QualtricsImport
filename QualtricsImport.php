<?php

namespace UWMadison\QualtricsImport;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;
use Project;
use Exception;
use Throwable;

require_once __DIR__ . '/src/AutoSanitizer.php';
require_once __DIR__ . '/src/Deduplicator.php';
require_once __DIR__ . '/src/FieldMapper.php';
require_once __DIR__ . '/src/QualtricsClient.php';
require_once __DIR__ . '/src/RecordManager.php';

class QualtricsImport extends AbstractExternalModule
{
    /**
     * REDCap hook to check if the module link should be displayed to the current user in project navigation.
     */
    public function redcap_module_link_check_display($project_id, $link)
    {
        return $this->hasImportPermission((int)$project_id) ? $link : null;
    }

    /**
     * Check if the current user has permission to access Qualtrics Import for a project.
     */
    public function hasImportPermission(int $projectId, ?string $username = null): bool
    {
        if (ExternalModules::isSuperUser())
            return true;

        $username = $username ?: ExternalModules::getUsername();
        if (empty($username))
            return false;

        $userRights = REDCap::getUserRights($username)[$username] ?? null;
        if (empty($userRights))
            return false;

        $permissionLevel = (string)($this->getProjectSetting('link_display_permission', $projectId) ?: 'all');
        $allowedUsers = (array)($this->getProjectSetting('allowed_users', $projectId) ?: []);

        return self::checkUserPermission(
            $permissionLevel,
            $userRights,
            $username,
            false,
            $allowedUsers
        );
    }

    /**
     * Pure permission evaluation logic (unit testable).
     */
    public static function checkUserPermission(
        string $permissionLevel,
        array $userRights,
        string $username,
        bool $isSuperUser = false,
        array $allowedUsers = []
    ): bool {
        if ($isSuperUser)
            return true;

        if (empty($username) || empty($userRights))
            return false;

        return match ($permissionLevel) {
            'design' => !empty($userRights['design']),
            'data_import_tool' => !empty($userRights['data_import_tool']) || !empty($userRights['design']),
            'record_create' => !empty($userRights['record_create']) || !empty($userRights['design']),
            'custom_users' => (function () use ($allowedUsers, $username) {
                $clean = array_map('strtolower', array_map('strval', array_filter($allowedUsers)));
                return in_array(strtolower($username), $clean, true);
            })(),
            default => true, // 'all' (default)
        };
    }

    /**
     * Hook called at top of every page.
     */
    public function redcap_every_page_top()
    {
        if (!$this->isPage('ExternalModules/manager/project.php'))
            return;

        $this->initializeJavascriptModuleObject();
        $this->passArgument('prefix', $this->PREFIX);
        $this->passArgument('allowProjectOverrides', (bool)$this->getSystemSetting('allow_project_overrides'));
        $this->includeJs('js/config_modal.js', 'defer');
    }

    /**
     * Filter project settings schema before rendering configure modal.
     */
    public function redcap_module_configuration_settings($project_id, $settings)
    {
        if (!$project_id || $this->getSystemSetting('allow_project_overrides'))
            return $settings;

        $keysToRemove = [
            'override_credentials',
            'project_qualtrics_api_token',
            'project_qualtrics_data_center',
        ];

        return array_values(array_filter($settings, function ($s) use ($keysToRemove) {
            return !in_array($s['key'] ?? '', $keysToRemove, true);
        }));
    }

    /**
     * Native REDCap framework AJAX handler.
     */
    public function redcap_module_ajax($action, $payload, $project_id)
    {
        if (!$this->hasImportPermission((int)$project_id))
            throw new Exception("You do not have permission to access Qualtrics Import for this project.");

        $limit = isset($payload['limit']) ? max(1, min(10000, (int)$payload['limit'])) : 1000;
        $sinceType = (string)($payload['sinceType'] ?? 'auto');
        $customStartDate = !empty($payload['startDate']) ? (string)$payload['startDate'] : null;
        $forceImportResponseIds = !empty($payload['forceImportResponseIds']) && is_array($payload['forceImportResponseIds'])
            ? $payload['forceImportResponseIds']
            : null;

        return match ($action) {
            'testConnection' => $this->getQualtricsClient($project_id)->testConnection(),
            'getSurveys' => ['success' => true, 'surveys' => $this->getQualtricsClient($project_id)->getSurveys()],
            'getManualExclusions' => ['success' => true, 'responseIds' => $this->getManuallyExcludedResponseIds((int)$project_id)],
            'saveManualExclusions' => [
                'success' => true,
                'responseIds' => $this->saveManuallyExcludedResponseIds((int)$project_id, $payload['responseIds'] ?? null),
            ],
            'previewImport' => ['success' => true, 'data' => $this->executeImportPipeline($project_id, true, false, $limit, $sinceType, $customStartDate)],
            'runImport' => ['success' => true, 'data' => $this->executeImportPipeline($project_id, false, false, $limit, $sinceType, $customStartDate, null, $forceImportResponseIds)],
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
            $projectId = (int)$pid;
            if (!$this->getProjectSetting('import_enabled', $projectId))
                continue;

            $surveyId = trim((string)$this->getProjectSetting('qualtrics_survey_id', $projectId));
            if ($surveyId === '') {
                $msg = "Qualtrics Survey ID is not configured for project {$projectId}.";
                $this->setProjectSetting('last_cron_error', $msg, $projectId);
                $this->log("Qualtrics Import: Cron skipped", ['project_id' => $projectId, 'error' => $msg]);
                error_log("Qualtrics Import Cron Warning (PID {$projectId}): {$msg}");
                continue;
            }

            if (!$this->hasQualtricsCredentials($projectId)) {
                $msg = "Qualtrics credentials (API token / data center) are not configured for project {$projectId}.";
                $this->setProjectSetting('last_cron_error', $msg, $projectId);
                $this->log("Qualtrics Import: Cron skipped", ['project_id' => $projectId, 'error' => $msg]);
                error_log("Qualtrics Import Cron Warning (PID {$projectId}): {$msg}");
                continue;
            }

            $frequency = (int)($this->getProjectSetting('sync_frequency', $projectId) ?: 3600);
            $lastRun = (int)$this->getProjectSetting('last_cron_run_timestamp', $projectId);
            $now = time();

            if (($now - $lastRun) < $frequency)
                continue;

            $this->setProjectSetting('last_cron_run_timestamp', $now, $projectId);

            try {
                $pipelineRes = $this->executeImportPipeline($projectId, false, true);

                if (!empty($pipelineRes['message'])) {
                    $msg = $pipelineRes['message'];
                    $this->setProjectSetting('last_cron_error', $msg, $projectId);
                    $this->log("Qualtrics Import: Cron sync error for project {$projectId}", [
                        'project_id' => $projectId,
                        'error' => $msg,
                    ]);
                    error_log("Qualtrics Import Cron Error (PID {$projectId}): {$msg}");
                } elseif (!empty($pipelineRes['commit_results']['errors'])) {
                    $errorsJson = json_encode($pipelineRes['commit_results']['errors']);
                    $this->setProjectSetting('last_cron_error', "Import commit error: {$errorsJson}", $projectId);
                    $this->log("Qualtrics Import: Cron import save errors for project {$projectId}", [
                        'project_id' => $projectId,
                        'errors' => $errorsJson,
                    ]);
                    error_log("Qualtrics Import Cron Commit Error (PID {$projectId}): {$errorsJson}");
                    $processedCount++;
                } else {
                    $this->removeProjectSetting('last_cron_error', $projectId);
                    $processedCount++;
                }
            } catch (Throwable $e) {
                $errorMsg = $e->getMessage();
                $errorLocation = $e->getFile() . ':' . $e->getLine();
                $this->setProjectSetting('last_cron_error', "{$errorMsg} ({$errorLocation})", $projectId);
                $this->log("Qualtrics Import: Cron sync error for project {$projectId}", [
                    'project_id' => $projectId,
                    'error' => $errorMsg,
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
                error_log("Qualtrics Import Cron Exception (PID {$projectId}): {$errorMsg} in {$errorLocation}\n" . $e->getTraceAsString());
            }
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
        ?string $customStartDate = null,
        ?array $excludedResponseIds = null,
        ?array $forceImportResponseIds = null
    ): array {
        $today = date('Y-m-d');
        $config = $this->getProjectSettings($projectId);
        $excludedResponseIds ??= $this->getManuallyExcludedResponseIds($projectId);
        $forceImportLookup = [];
        if (!empty($forceImportResponseIds)) {
            foreach ($forceImportResponseIds as $fid) {
                if (is_scalar($fid))
                    $forceImportLookup[trim((string)$fid)] = true;
            }
        }
        try {
            $subMappings = $this->getSubSettings('custom_value_mappings', $projectId);
            if (!empty($subMappings) && is_array($subMappings))
                $config['custom_value_mappings'] = $subMappings;
            $subDefaults = $this->getSubSettings('static_field_defaults', $projectId);
            if (!empty($subDefaults) && is_array($subDefaults))
                $config['static_field_defaults'] = $subDefaults;
        } catch (Throwable) {
            // Fall back to getProjectSettings
        }
        $surveyId = trim((string)($config['qualtrics_survey_id'] ?? ''));

        $emptyResponse = [
            'is_dry_run' => $isDryRun,
            'start_date_used' => null,
            'survey_rows_retrieved' => 0,
            'survey_rows_evaluated' => 0,
            'limit_applied' => null,
            'ready_to_import_count' => 0,
            'note_updates_count' => 0,
            'counts' => [
                'clean_new' => 0,
                'case_4_suspected' => 0,
                'case_2_duplicate' => 0,
                'case_3_household' => 0,
                'case_1_existing_id' => 0,
                'imported_case_4' => 0,
                'invalid_phone' => 0,
                'missing_required' => 0,
                'user_excluded' => 0,
            ],
            'evaluations' => [],
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        if (!empty($config['target_event'])) {
            $targetEvent = (string)$config['target_event'];
            $uniqueEventNames = (new Project($projectId))->getUniqueEventNames();
            if (isset($uniqueEventNames[$targetEvent]))
                $config['target_event'] = $uniqueEventNames[$targetEvent];
            elseif (!in_array($targetEvent, $uniqueEventNames, true)) {
                $emptyResponse['message'] = 'The configured Target Event is invalid. Please select a valid event in the module settings.';
                return $emptyResponse;
            }
        }

        if ($surveyId === '') {
            $emptyResponse['message'] = 'Qualtrics Survey ID is not configured.';
            return $emptyResponse;
        }

        if (!$this->hasQualtricsCredentials($projectId)) {
            $emptyResponse['message'] = 'Qualtrics credentials (token and data center) are not configured.';
            return $emptyResponse;
        }

        // 1. Fetch survey responses from Qualtrics
        $client = $this->getQualtricsClient($projectId);
        if ($isCron)
            $startDate = $this->getProjectSetting('last_successful_sync_time', $projectId);
        elseif ($customStartDate !== null)
            $startDate = $customStartDate;
        else
            $startDate = $this->resolveStartDate($projectId, $sinceType ?? 'auto');

        $rawSurveyRows = $client->exportResponses($surveyId, $startDate);
        $totalRetrieved = count($rawSurveyRows);

        if ($limit !== null && $limit > 0 && $totalRetrieved > $limit)
            $rawSurveyRows = array_slice($rawSurveyRows, 0, $limit);

        // 2. Fetch existing records and metadata from REDCap
        $dict = REDCap::getDataDictionary($projectId, 'array') ?: [];
        $fieldNames = array_keys($dict);
        $recordIdField = RecordManager::getRecordIdField($projectId, $dict);
        $poolRecords = RecordManager::fetchExistingRecords($projectId, $config, $dict);

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
            'imported_case_4' => 0,
            'invalid_phone' => 0,
            'missing_required' => 0,
            'user_excluded' => 0,
        ];

        // 4. Process each survey response
        foreach ($rawSurveyRows as $rawRow) {
            $respId = (string)($rawRow['ResponseId'] ?? '');
            $startDate = (string)(
                $rawRow['StartDate']
                ?? $rawRow['startDate']
                ?? $rawRow['startdate']
                ?? $rawRow['RecordedDate']
                ?? $rawRow['recordedDate']
                ?? $rawRow['recordeddate']
                ?? ''
            );

            // Pre-check Case 1: Already imported by Qualtrics ResponseId
            $alreadyImported = null;
            if ($respId !== '') {
                foreach ($poolRecords as $existing) {
                    if ($existing['qualtrics_id'] !== '' && $existing['qualtrics_id'] === $respId) {
                        $alreadyImported = $existing;
                        break;
                    }
                }
            }

            if ($alreadyImported !== null) {
                $isPossibleDuplicate = (!empty($alreadyImported['notes']) && stripos($alreadyImported['notes'], 'Possible duplicate') !== false);
                $matchedDuplicateRecord = null;
                $duplicateDescription = $isPossibleDuplicate ? $alreadyImported['notes'] : '';
                $duplicateScores = null;

                if (!$isPossibleDuplicate) {
                    $case4LastThreshold = (float)($config['case4_last_sim'] ?? 0.95);
                    $case4FirstThreshold = (float)($config['case4_first_sim'] ?? 0.90);
                    $candFirst = Deduplicator::sanitizeName($alreadyImported['first_name'] ?? '');
                    $candLast = Deduplicator::sanitizeName($alreadyImported['last_name'] ?? '');
                    $candPhone = $alreadyImported['phone1'] ?? '';

                    foreach ($poolRecords as $other) {
                        if ($other['record_id'] === $alreadyImported['record_id'])
                            continue;
                        $otherPhone = $other['phone1'] ?? '';
                        $otherFirst = $other['first_name'] ?? '';
                        $otherLast = $other['last_name'] ?? '';

                        if ($candPhone === '' || $otherPhone === '' || $candPhone !== $otherPhone) {
                            $firstSim = Deduplicator::nameSimilarity($candFirst, $otherFirst);
                            $lastSim = Deduplicator::nameSimilarity($candLast, $otherLast);

                            if ($lastSim >= $case4LastThreshold && $firstSim >= $case4FirstThreshold) {
                                $isPossibleDuplicate = true;
                                $matchedDuplicateRecord = $other;
                                $duplicateScores = ['first' => $firstSim, 'last' => $lastSim];
                                $duplicateDescription = sprintf(
                                    "Possible duplicate: Similar name to existing record %s ('%s %s') with different phone (scores: first=%.2f, last=%.2f)",
                                    $other['record_id'],
                                    $otherFirst,
                                    $otherLast,
                                    $firstSim,
                                    $lastSim
                                );
                                break;
                            }
                        }
                    }
                }

                $status = $isPossibleDuplicate ? 'imported_case_4' : 'skipped_case_1';
                $category = $isPossibleDuplicate ? 'Imported - Possible Duplicate' : 'Already Imported';
                if ($isPossibleDuplicate) {
                    $categoryCounts['imported_case_4']++;
                    $desc = "Qualtrics ResponseId '{$respId}' already exists in REDCap record {$alreadyImported['record_id']}. {$duplicateDescription}";
                } else {
                    $categoryCounts['case_1_existing_id']++;
                    $desc = "Qualtrics ResponseId '{$respId}' already exists in REDCap record {$alreadyImported['record_id']}";
                }

                $evalSummary = [
                    'category' => $category,
                    'status' => $status,
                    'description' => $desc,
                    'qualtrics_id' => $respId,
                    'start_date' => $startDate,
                    'candidate_name' => trim("{$alreadyImported['first_name']} {$alreadyImported['last_name']}"),
                    'candidate_phone' => $alreadyImported['phone1'],
                    'record_id' => $alreadyImported['record_id'],
                    'matched_record_id' => $matchedDuplicateRecord['record_id'] ?? $alreadyImported['record_id'],
                    'scores' => $duplicateScores,
                ];
                $evaluations[] = $evalSummary;

                if (!$isDryRun)
                    $this->log("Qualtrics Import: Duplicate response excluded", [
                        'project_id' => $projectId,
                        'qualtrics_id' => $respId,
                        'candidate_name' => $evalSummary['candidate_name'],
                        'candidate_phone' => $evalSummary['candidate_phone'],
                        'category' => $category,
                        'status' => $status,
                        'matched_record_id' => $evalSummary['matched_record_id'],
                        'description' => $evalSummary['description'],
                    ]);
                continue;
            }

            if (in_array($respId, $excludedResponseIds, true)) {
                $previewInfo = FieldMapper::extractCandidatePreviewInfo($rawRow, $config);
                $evaluations[] = [
                    'category' => 'Do Not Import',
                    'status' => 'skipped_manual',
                    'description' => 'Flagged by user; excluded from import.',
                    'qualtrics_id' => $respId,
                    'start_date' => $startDate,
                    'candidate_name' => $previewInfo['name'],
                    'candidate_phone' => $previewInfo['phone'],
                    'record_id' => null,
                    'unexcluded_status' => 'accepted_clean',
                    'unexcluded_category' => 'New Record',
                    'unexcluded_description' => 'No duplicate conflicts detected',
                    'scores' => null,
                ];
                $categoryCounts['user_excluded']++;

                if (!$isDryRun)
                    $this->log("Qualtrics Import: Response excluded by user", [
                        'project_id' => $projectId,
                        'qualtrics_id' => $respId,
                        'candidate_name' => $previewInfo['name'],
                        'status' => 'skipped_manual',
                    ]);
                continue;
            }

            $exclusionReason = null;
            $mapped = FieldMapper::mapRecord($rawRow, $config, $fieldNames, $dict, $projectId, $exclusionReason);
            if ($mapped === null) {
                $previewInfo = FieldMapper::extractCandidatePreviewInfo($rawRow, $config);
                $evaluations[] = [
                    'category' => 'Ineligible',
                    'status' => 'skipped_ineligible',
                    'description' => $exclusionReason ?: 'Response excluded by filter logic or missing required fields',
                    'qualtrics_id' => $respId,
                    'start_date' => $startDate,
                    'candidate_name' => $previewInfo['name'],
                    'candidate_phone' => $previewInfo['phone'],
                    'record_id' => null,
                    'scores' => null,
                ];
                $categoryCounts['missing_required']++;

                if (!$isDryRun)
                    $this->log("Qualtrics Import: Response excluded", [
                        'project_id' => $projectId,
                        'qualtrics_id' => $respId,
                        'candidate_name' => $previewInfo['name'],
                        'candidate_phone' => $previewInfo['phone'],
                        'status' => 'skipped_ineligible',
                        'reason' => $exclusionReason ?: 'Excluded by filter logic, completion status, or missing required fields',
                    ]);
                continue;
            }

            // Deduplication evaluation
            $eval = Deduplicator::evaluateCandidate($mapped, $poolRecords, $config, $today);

            if ($eval['status'] === 'skipped_case_4' && isset($forceImportLookup[$respId])) {
                $eval['status'] = 'accepted_case_4';
                $eval['is_duplicate'] = false;
            }

            $evalSummary = [
                'category' => $eval['category'],
                'status' => $eval['status'],
                'description' => $eval['description'],
                'qualtrics_id' => $mapped['qualtrics_id'],
                'start_date' => $startDate,
                'candidate_name' => $mapped['display_name'],
                'candidate_phone' => $mapped['phone1'],
                'record_id' => null,
                'matched_record_id' => $eval['matched_record']['record_id'] ?? null,
                'scores' => $eval['scores'] ?? null,
            ];

            // If response was mostly blank, append to decision reason and candidate notes
            $completeness = FieldMapper::analyzeResponseCompleteness($rawRow);
            if ($completeness['status'] === 'mostly_blank') {
                $blankNote = $completeness['summary'];
                $evalSummary['description'] = ($evalSummary['description'] !== '')
                    ? "{$evalSummary['description']} [{$blankNote}]"
                    : $blankNote;
                if (!empty($config['import_notes_field'])) {
                    $existingNote = $mapped[$config['import_notes_field']] ?? '';
                    $mapped[$config['import_notes_field']] = ($existingNote !== '')
                        ? "{$existingNote}; {$blankNote}"
                        : $blankNote;
                }
            }

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
                        $recordIdField => $origId,
                        $notesField => ($origNotes !== '') ? "{$origNotes}\n{$noteEntry}" : $noteEntry,
                    ];
                    if ($targetEvent !== '')
                        $noteUpdateRecord['redcap_event_name'] = $targetEvent;
                    $noteUpdatesToSave[$origId] = $noteUpdateRecord;
                }

                match ($eval['status']) {
                    'skipped_case_1' => $categoryCounts['case_1_existing_id']++,
                    'imported_case_4' => $categoryCounts['imported_case_4']++,
                    'skipped_case_2' => $categoryCounts['case_2_duplicate']++,
                    'rejected_case_3' => $categoryCounts['case_3_household']++,
                    'skipped_case_4' => $categoryCounts['case_4_suspected']++,
                    default => $categoryCounts['invalid_phone']++,
                };

                $evaluations[] = $evalSummary;

                if (!$isDryRun)
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

            $mapped[$recordIdField] = $allocatedId;
            if (!isset($mapped['import_date']))
                $mapped['import_date'] = $today;
            if (!isset($mapped['first_import_date']))
                $mapped['first_import_date'] = $today;

            if (!empty($eval['candidate_note']) && !empty($config['import_notes_field']))
                $mapped[$config['import_notes_field']] = $eval['candidate_note'];

            $evalSummary['record_id'] = $allocatedId;
            $evaluations[] = $evalSummary;

            $newRecordsToSave[] = $mapped;
            $importedRecordIds[] = $allocatedId;

            if ($eval['status'] === 'accepted_case_4')
                $categoryCounts['case_4_suspected']++;
            else
                $categoryCounts['clean_new']++;

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

        $surveyTagMap = [];
        try {
            $surveyTagMap = $client->getSurveyQuestionTags($surveyId);
        } catch (Throwable) {
            $surveyTagMap = [];
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
            'diagnostics' => [
                'parsed_custom_mappings' => FieldMapper::parseCustomMappings($config),
                'parsed_static_defaults' => FieldMapper::parseStaticDefaults($config),
                'tag_map' => $surveyTagMap,
                'qualtrics_fields' => !empty($rawSurveyRows) ? array_keys($rawSurveyRows[0]) : [],
                'sample_raw_values' => !empty($rawSurveyRows) ? array_slice($rawSurveyRows[0], 0, 100) : [],
            ],
        ];

        // 5. Commit to REDCap if not dry run
        if ($isDryRun)
            return $results;

        $results['commit_results'] = RecordManager::commitImports($projectId, $newRecordsToSave, $noteUpdatesToSave);
        $results['imported_count'] = $results['commit_results']['imported_count'];
        $results['notes_updated_count'] = $results['commit_results']['notes_updated_count'];
        if (empty($results['commit_results']['errors']))
            $this->setProjectSetting('last_successful_sync_time', gmdate('Y-m-d\TH:i:s\Z'), $projectId);

        $this->log("Qualtrics Import completed for project {$projectId}", [
            'project_id' => $projectId,
            'imported_count' => $results['imported_count'],
            'notes_updated_count' => $results['notes_updated_count'],
            'errors' => json_encode($results['commit_results']['errors']),
            'counts' => json_encode($results['counts']),
        ]);

        return $results;
    }

    /**
     * Get project-level Qualtrics ResponseIds manually excluded from imports.
     */
    private function getManuallyExcludedResponseIds(int $projectId): array
    {
        $stored = $this->getProjectSetting('manual-excluded-response-ids', $projectId);
        if (empty($stored))
            return [];

        $responseIds = is_array($stored) ? $stored : json_decode((string)$stored, true);
        if (!is_array($responseIds))
            throw new Exception('Saved manual import exclusions are invalid. Clear or repair the module setting before importing.');

        return $this->normalizeManualExclusionIds($responseIds);
    }

    /**
     * Persist project-level Qualtrics ResponseIds manually excluded from imports.
     */
    private function saveManuallyExcludedResponseIds(int $projectId, $responseIds): array
    {
        if (!is_array($responseIds))
            throw new Exception('The manual exclusion list must be an array of Qualtrics ResponseIds.');

        $responseIds = $this->normalizeManualExclusionIds($responseIds);
        if (count($responseIds) > 10000)
            throw new Exception('The manual exclusion list cannot contain more than 10,000 ResponseIds.');

        $this->setProjectSetting('manual-excluded-response-ids', json_encode($responseIds), $projectId);
        return $responseIds;
    }

    private function normalizeManualExclusionIds(array $responseIds): array
    {
        $normalized = [];
        foreach ($responseIds as $responseId) {
            if (!is_scalar($responseId))
                throw new Exception('The manual exclusion list contains an invalid ResponseId.');

            $responseId = trim((string)$responseId);
            if ($responseId !== '')
                $normalized[$responseId] = $responseId;
        }

        return array_values($normalized);
    }

    /**
     * Check whether Qualtrics API credentials (token and data center) are configured.
     */
    public function hasQualtricsCredentials(int $projectId): bool
    {
        $allowOverrides = (bool)$this->getSystemSetting('allow_project_overrides');
        $override = $allowOverrides && (bool)$this->getProjectSetting('override_credentials', $projectId);

        $token = $override
            ? $this->getProjectSetting('project_qualtrics_api_token', $projectId)
            : $this->getSystemSetting('qualtrics_api_token');

        $dataCenter = $override
            ? $this->getProjectSetting('project_qualtrics_data_center', $projectId)
            : $this->getSystemSetting('qualtrics_data_center');

        return !empty($token) && !empty($dataCenter);
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

        if (empty($token) || empty($dataCenter))
            throw new Exception("Qualtrics credentials (token and data center) must be configured in settings.");

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
