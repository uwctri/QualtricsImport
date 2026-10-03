<?php

namespace UWMadison\QualtricsImport;

/** @var QualtricsImport $module */
$projectId = (int)$module->getProjectId();

$surveyId = trim((string)$module->getProjectSetting('qualtrics_survey_id', $projectId));
$hasSurveyId = !empty($surveyId);

$module->initializeJavascriptModuleObject();
$module->passArgument('projectId', $projectId);
$module->passArgument('prefix', $module->PREFIX);
$module->passArgument('hasSurveyId', $hasSurveyId);
$module->includeCss('css/import.css');
$module->includeJs('js/import.js');

$mappingMode = (string)($module->getProjectSetting('mapping_mode', $projectId) ?: 'auto_map_blacklist');
$lastSync = (string)$module->getProjectSetting('last_successful_sync_time', $projectId);
?>

<div class="qualtrics-import-container">
    <div id="importAlertArea"></div>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <div>
            <h2 class="mb-1"><i class="fas fa-cloud-download-alt text-primary"></i> Qualtrics Import</h2>
            <div class="text-muted small">
                <span class="mr-3"><strong>Survey:</strong> <code><?= htmlspecialchars($surveyId ?: 'Not Configured') ?></code></span>
                <span class="mr-3"><strong>Mode:</strong> <?= htmlspecialchars($mappingMode === 'whitelist' ? 'Whitelist Only' : 'Auto-Map') ?></span>
                <span><strong>Last Sync:</strong> <?= htmlspecialchars($lastSync ?: 'None') ?></span>
            </div>
        </div>
        <div class="d-flex align-items-center mt-2 mt-md-0">
            <button id="btnRefresh" class="btn btn-outline-secondary mr-2" <?= !$hasSurveyId ? 'disabled' : '' ?>>
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
            <button id="btnRunImport" class="btn btn-success" disabled>
                <i class="fas fa-play"></i> Run Import Now
            </button>
        </div>
    </div>

    <!-- Loading Spinner -->
    <div id="previewLoading" class="text-center p-5 qualtrics-card" style="<?= !$hasSurveyId ? 'display: none;' : '' ?>">
        <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
            <span class="sr-only">Loading...</span>
        </div>
        <h5 class="mt-3 text-muted">Retrieving and evaluating responses from Qualtrics...</h5>
    </div>

    <!-- Preview Content -->
    <div id="previewContent" style="display: none;">
        <!-- Filter Tabs / Pills -->
        <div class="filter-pills">
            <div class="filter-pill active" data-filter="all">
                All Responses <span id="cntTotal" class="badge">0</span>
            </div>
            <div class="filter-pill" data-filter="ready">
                Ready to Import <span id="cntReady" class="badge">0</span>
            </div>
            <div class="filter-pill" data-filter="case_2">
                Duplicate Submissions <span id="cntCase2" class="badge">0</span>
            </div>
            <div class="filter-pill" data-filter="case_3">
                Shared Phone Conflicts <span id="cntCase3" class="badge">0</span>
            </div>
            <div class="filter-pill" data-filter="case_4">
                Possible Duplicates <span id="cntCase4" class="badge">0</span>
            </div>
            <div class="filter-pill" data-filter="excluded">
                Excluded / Ineligible <span id="cntExcluded" class="badge">0</span>
            </div>
        </div>

        <!-- Responses Table -->
        <div class="qualtrics-card">
            <div class="table-responsive">
                <table class="table table-hover table-striped table-preview mb-0">
                    <thead>
                        <tr>
                            <th style="width: 180px;">Status</th>
                            <th>Candidate Name</th>
                            <th>Phone</th>
                            <th>Qualtrics ID</th>
                            <th>Target Record ID</th>
                            <th>Matched Record / Score</th>
                            <th>Decision Reason / Notes</th>
                        </tr>
                    </thead>
                    <tbody id="previewTableBody">
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
