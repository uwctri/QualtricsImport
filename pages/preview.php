<?php

namespace UWMadison\QualtricsImport;

/** @var QualtricsImport $module */
$projectId = (int)$module->getProjectId();

$module->initializeJavascriptModuleObject();
$module->passArgument('projectId', $projectId);
$module->passArgument('prefix', $module->PREFIX);
$module->includeCss('css/preview.css');
$module->includeJs('js/preview.js');

$surveyId = (string)$module->getProjectSetting('qualtrics_survey_id', $projectId);
$mappingMode = (string)($module->getProjectSetting('mapping_mode', $projectId) ?: 'auto_map_blacklist');
$lastSync = (string)$module->getProjectSetting('last_successful_sync_time', $projectId);
?>

<div class="qualtrics-import-container">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2><i class="fas fa-cloud-download-alt text-primary"></i> Qualtrics Import Preview</h2>
            <p class="text-muted mb-0">Inspect and test incoming screening survey responses, deduplication decisions, and note annotations before committing to REDCap.</p>
        </div>
        <div>
            <button id="btnRefresh" class="btn btn-outline-secondary mr-2">
                <i class="fas fa-sync-alt"></i> Refresh Preview
            </button>
            <button id="btnRunImport" class="btn btn-success" disabled>
                <i class="fas fa-play"></i> Run Import Now
            </button>
        </div>
    </div>

    <!-- Overview Card -->
    <div class="qualtrics-card">
        <div class="qualtrics-card-header">
            <strong>Configuration Summary</strong>
            <span class="badge badge-info">Framework v17</span>
        </div>
        <div class="qualtrics-card-body">
            <div class="row">
                <div class="col-md-4">
                    <strong>Qualtrics Survey ID:</strong><br>
                    <code><?= htmlspecialchars($surveyId ?: 'Not Configured') ?></code>
                </div>
                <div class="col-md-4">
                    <strong>Mapping Mode:</strong><br>
                    <span><?= htmlspecialchars($mappingMode === 'whitelist' ? 'Whitelist Only' : 'Auto-Map Matching (with auto-sanitization)') ?></span>
                </div>
                <div class="col-md-4">
                    <strong>Last Successful Sync:</strong><br>
                    <span class="text-muted"><?= htmlspecialchars($lastSync ?: 'None') ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Spinner -->
    <div id="previewLoading" class="text-center p-5 qualtrics-card">
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
                Duplicates Skipped (Case 2) <span id="cntCase2" class="badge">0</span>
            </div>
            <div class="filter-pill" data-filter="case_3">
                Shared Phone Conflicts (Case 3) <span id="cntCase3" class="badge">0</span>
            </div>
            <div class="filter-pill" data-filter="case_4">
                Suspected Duplicates (Case 4) <span id="cntCase4" class="badge">0</span>
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
