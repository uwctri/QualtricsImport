<?php

namespace UWMadison\QualtricsImport;

/** @var QualtricsImport $module */
$projectId = (int)$module->getProjectId();

if (!$module->hasImportPermission($projectId)) {
    echo "<div class='alert alert-danger m-4 p-3 font-weight-bold'><i class='fas fa-exclamation-triangle'></i> Access Denied: You do not have permission to access Qualtrics Import for this project.</div>";
    return;
}

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
            <button id="btnDiagnostics" class="btn btn-outline-info mr-2" style="display: none;" title="Inspect Qualtrics Survey fields and active module mappings">
                <i class="fas fa-stethoscope"></i> Diagnostics
            </button>
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
        <!-- Status Filter Dropdown & Search Box -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
            <div class="d-flex align-items-center mb-2 mb-md-0">
                <div class="dropdown status-filter-dropdown mr-2">
                    <button class="btn btn-sm dropdown-toggle shadow-sm" type="button" id="statusDropdownBtn" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <i class="fas fa-filter text-primary mr-2"></i>
                        <span class="text-secondary mr-1">Status:</span>
                        <span id="statusFilterSummary" class="font-weight-bold text-dark mr-2">All</span>
                        <span class="badge badge-secondary badge-pill" id="cntTotalBadge">0</span>
                    </button>
                    <div class="dropdown-menu shadow" aria-labelledby="statusDropdownBtn" onclick="event.stopPropagation()">
                        <div class="d-flex justify-content-between align-items-center pb-2 mb-2 border-bottom">
                            <span class="small font-weight-bold text-muted text-uppercase" style="letter-spacing: 0.5px;">
                                <i class="fas fa-sliders-h mr-1"></i> Filter by Status
                            </span>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-xs btn-outline-primary py-0 px-2 font-weight-bold" id="btnStatusSelectAll">All</button>
                                <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2 ml-1" id="btnStatusClearAll">Clear</button>
                            </div>
                        </div>
                        <label class="status-option-item" for="chkStatus_ready">
                            <div class="d-flex align-items-center">
                                <input type="checkbox" class="status-checkbox" id="chkStatus_ready" value="ready" checked>
                                <i class="fas fa-check-circle text-success fa-fw mr-2"></i>
                                <span class="status-name">Ready to Import</span>
                            </div>
                            <span class="badge badge-success ml-2" id="cntReady">0</span>
                        </label>
                        <label class="status-option-item" for="chkStatus_imported">
                            <div class="d-flex align-items-center">
                                <input type="checkbox" class="status-checkbox" id="chkStatus_imported" value="imported" checked>
                                <i class="fas fa-history text-info fa-fw mr-2"></i>
                                <span class="status-name">Imported</span>
                            </div>
                            <span class="badge badge-info ml-2" id="cntImported">0</span>
                        </label>
                        <label class="status-option-item" for="chkStatus_case_4">
                            <div class="d-flex align-items-center">
                                <input type="checkbox" class="status-checkbox" id="chkStatus_case_4" value="case_4" checked>
                                <i class="fas fa-user-tag text-primary fa-fw mr-2"></i>
                                <span class="status-name">Possible Duplicates</span>
                            </div>
                            <span class="badge badge-primary ml-2" id="cntCase4">0</span>
                        </label>
                        <label class="status-option-item" for="chkStatus_case_2">
                            <div class="d-flex align-items-center">
                                <input type="checkbox" class="status-checkbox" id="chkStatus_case_2" value="case_2" checked>
                                <i class="fas fa-clone text-danger fa-fw mr-2"></i>
                                <span class="status-name">Duplicate Submissions</span>
                            </div>
                            <span class="badge badge-danger ml-2" id="cntCase2">0</span>
                        </label>
                        <label class="status-option-item" for="chkStatus_case_3">
                            <div class="d-flex align-items-center">
                                <input type="checkbox" class="status-checkbox" id="chkStatus_case_3" value="case_3" checked>
                                <i class="fas fa-users text-warning fa-fw mr-2"></i>
                                <span class="status-name">Shared Phone Conflicts</span>
                            </div>
                            <span class="badge badge-warning ml-2" id="cntCase3">0</span>
                        </label>
                        <label class="status-option-item" for="chkStatus_excluded">
                            <div class="d-flex align-items-center">
                                <input type="checkbox" class="status-checkbox" id="chkStatus_excluded" value="excluded" checked>
                                <i class="fas fa-ban text-secondary fa-fw mr-2"></i>
                                <span class="status-name">Excluded / Ineligible</span>
                            </div>
                            <span class="badge badge-secondary ml-2" id="cntExcluded">0</span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="import-search-box" style="min-width: 170px; max-width: 280px;">
                <div class="input-group input-group-sm">
                    <div class="input-group-prepend">
                        <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                    </div>
                    <input type="text" id="previewSearch" class="form-control" placeholder="Search table...">
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
                            <th class="sortable text-nowrap" data-sort="status" style="width: 105px;">Status <i class="fas fa-sort sort-icon"></i></th>
                            <th class="sortable text-nowrap" data-sort="start_date" style="width: 115px;">Date <i class="fas fa-sort sort-icon"></i></th>
                            <th class="sortable text-nowrap" data-sort="name" style="width: 130px;">Candidate Name <i class="fas fa-sort sort-icon"></i></th>
                            <th class="sortable text-nowrap" data-sort="phone" style="width: 110px;">Phone <i class="fas fa-sort sort-icon"></i></th>
                            <th class="sortable text-nowrap" data-sort="qualtrics_id" style="width: 105px;">Qualtrics ID <i class="fas fa-sort sort-icon"></i></th>
                            <th class="sortable text-nowrap" data-sort="record_id" style="width: 80px;">Record ID <i class="fas fa-sort sort-icon"></i></th>
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

<!-- Diagnostics Modal -->
<div class="modal fade" id="diagnosticsModal" tabindex="-1" role="dialog" aria-labelledby="diagnosticsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="diagnosticsModalLabel"><i class="fas fa-stethoscope text-info"></i> Survey & Mapping Diagnostics</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <h6><i class="fas fa-map-signs text-primary"></i> Active Custom Mappings</h6>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered">
                        <thead class="thead-light">
                            <tr><th>Qualtrics Field</th><th>REDCap Target Field</th><th>Value Map</th></tr>
                        </thead>
                        <tbody id="diagMappingsBody"></tbody>
                    </table>
                </div>

                <h6><i class="fas fa-thumbtack text-secondary"></i> Active Static Defaults</h6>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered">
                        <thead class="thead-light">
                            <tr><th>Target Field</th><th>Static Value</th></tr>
                        </thead>
                        <tbody id="diagStaticBody"></tbody>
                    </table>
                </div>

                <h6><i class="fas fa-tags text-success"></i> Fields Received in Qualtrics Export</h6>
                <div id="diagFieldsContainer" class="p-2 border rounded bg-light mb-3" style="max-height: 140px; overflow-y: auto;">
                </div>

                <div class="d-flex justify-content-between align-items-center mb-1">
                    <h6 class="mb-0"><i class="fas fa-file-code text-muted"></i> Sample Survey Response (First Record)</h6>
                    <small class="text-muted"><i class="fas fa-info-circle text-info"></i> Aliased lines annotated with right-aligned comments</small>
                </div>
                <pre id="diagSampleJson" class="bg-dark text-light p-3 rounded" style="max-height: 320px; overflow-x: auto; overflow-y: auto; font-size: 11px; line-height: 1.5; white-space: pre; font-family: SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
