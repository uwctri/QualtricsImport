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
    </div>

    <!-- Controls Toolbar -->
    <div class="qualtrics-card mb-3 p-2 px-3 bg-light d-flex align-items-center justify-content-between flex-wrap">
        <div class="d-flex align-items-center flex-wrap">
            <div class="d-flex align-items-center mr-3 my-1">
                <label for="previewSince" class="mb-0 mr-2 font-weight-bold text-secondary small"><i class="far fa-calendar-alt"></i> Date Range:</label>
                <select id="previewSince" class="form-control form-control-sm" style="width: auto;">
                    <option value="auto" selected>Auto (Past 24h or Last Import)</option>
                    <option value="past_24h">Past 24 Hours</option>
                    <?php if (!empty($lastSync)): ?>
                    <option value="last_sync">Since Last Import (<?= htmlspecialchars($lastSync) ?>)</option>
                    <?php endif; ?>
                    <option value="past_7d">Past 7 Days</option>
                    <option value="past_30d">Past 30 Days</option>
                    <option value="all">All Time (No Date Filter)</option>
                </select>
            </div>
            <div class="d-flex align-items-center mr-3 my-1">
                <label for="previewLimit" class="mb-0 mr-2 font-weight-bold text-secondary small"><i class="fas fa-list-ol"></i> Limit:</label>
                <input type="number" id="previewLimit" class="form-control form-control-sm" value="1000" min="1" max="10000" style="width: 90px;" title="Maximum responses to evaluate (default 1000)">
            </div>
            <div id="previewScopeNotice" class="my-1"></div>
        </div>
        <div class="d-flex align-items-center my-1">
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
        <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;"></div>
        <h5 class="mt-3 text-muted">Retrieving and evaluating responses from Qualtrics...</h5>
    </div>

    <!-- Preview Content -->
    <div id="previewContent" style="display: none;">
        <!-- Filter Tabs / Pills and Search Box -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
            <div class="filter-pills mb-2 mb-md-0">
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
            <div class="import-search-box" style="min-width: 260px; max-width: 360px;">
                <div class="input-group input-group-sm">
                    <div class="input-group-prepend">
                        <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                    </div>
                    <input type="text" id="previewSearch" class="form-control" placeholder="Search table (name, phone, ID, notes)...">
                    <div class="input-group-append" id="previewSearchClearContainer" style="display: none;">
                        <button class="btn btn-outline-secondary" type="button" id="btnSearchClear" title="Clear search">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Responses Table -->
        <div class="qualtrics-card">
            <div class="table-responsive">
                <table class="table table-hover table-striped table-preview mb-0">
                    <thead>
                        <tr>
                            <th class="sortable" data-sort="status" style="width: 180px;">Status <i class="fas fa-sort sort-icon"></i></th>
                            <th class="sortable" data-sort="name">Candidate Name <i class="fas fa-sort sort-icon"></i></th>
                            <th class="sortable" data-sort="phone">Phone <i class="fas fa-sort sort-icon"></i></th>
                            <th class="sortable" data-sort="qualtrics_id">Qualtrics ID <i class="fas fa-sort sort-icon"></i></th>
                            <th class="sortable" data-sort="record_id">Target Record ID <i class="fas fa-sort sort-icon"></i></th>
                            <th class="sortable" data-sort="matched">Matched Record / Score <i class="fas fa-sort sort-icon"></i></th>
                            <th class="sortable" data-sort="description">Decision Reason / Notes <i class="fas fa-sort sort-icon"></i></th>
                        </tr>
                    </thead>
                    <tbody id="previewTableBody">
                    </tbody>
                </table>
            </div>
            <div class="p-2 px-3 text-muted small bg-light border-top d-flex justify-content-between align-items-center" id="tableRecordInfo">
                <span>0 responses</span>
            </div>
        </div>
    </div>
</div>
