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
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="statusDropdownBtn" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <i class="fas fa-filter text-primary mr-1"></i> Status: <span id="statusFilterSummary" class="font-weight-bold text-dark">All</span>
                    </button>
                    <div class="dropdown-menu p-3 shadow" aria-labelledby="statusDropdownBtn" style="min-width: 320px;" onclick="event.stopPropagation()">
                        <div class="d-flex justify-content-between align-items-center pb-2 mb-2 border-bottom">
                            <span class="small font-weight-bold text-muted text-uppercase"><i class="fas fa-tasks mr-1"></i> Filter By Status</span>
                            <div>
                                <button type="button" class="btn btn-sm btn-link p-0 mr-3 font-weight-bold text-primary" id="btnStatusSelectAll">All</button>
                                <button type="button" class="btn btn-sm btn-link p-0 text-secondary" id="btnStatusClearAll">Clear</button>
                            </div>
                        </div>
                        <div class="custom-control custom-checkbox my-2">
                            <input type="checkbox" class="custom-control-input status-checkbox" id="chkStatus_ready" value="ready" checked>
                            <label class="custom-control-label d-flex justify-content-between align-items-center w-100" for="chkStatus_ready" style="cursor: pointer;">
                                <span><i class="fas fa-check-circle text-success mr-1"></i> Ready to Import</span>
                                <span class="badge badge-success ml-auto" id="cntReady">0</span>
                            </label>
                        </div>
                        <div class="custom-control custom-checkbox my-2">
                            <input type="checkbox" class="custom-control-input status-checkbox" id="chkStatus_imported" value="imported" checked>
                            <label class="custom-control-label d-flex justify-content-between align-items-center w-100" for="chkStatus_imported" style="cursor: pointer;">
                                <span><i class="fas fa-history text-info mr-1"></i> Imported (Already in REDCap)</span>
                                <span class="badge badge-info ml-auto" id="cntImported">0</span>
                            </label>
                        </div>
                        <div class="custom-control custom-checkbox my-2">
                            <input type="checkbox" class="custom-control-input status-checkbox" id="chkStatus_case_4" value="case_4" checked>
                            <label class="custom-control-label d-flex justify-content-between align-items-center w-100" for="chkStatus_case_4" style="cursor: pointer;">
                                <span><i class="fas fa-user-tag text-primary mr-1"></i> Possible Duplicates (Case 4)</span>
                                <span class="badge badge-primary ml-auto" id="cntCase4">0</span>
                            </label>
                        </div>
                        <div class="custom-control custom-checkbox my-2">
                            <input type="checkbox" class="custom-control-input status-checkbox" id="chkStatus_case_2" value="case_2" checked>
                            <label class="custom-control-label d-flex justify-content-between align-items-center w-100" for="chkStatus_case_2" style="cursor: pointer;">
                                <span><i class="fas fa-clone text-danger mr-1"></i> Duplicate Submissions (Case 2)</span>
                                <span class="badge badge-danger ml-auto" id="cntCase2">0</span>
                            </label>
                        </div>
                        <div class="custom-control custom-checkbox my-2">
                            <input type="checkbox" class="custom-control-input status-checkbox" id="chkStatus_case_3" value="case_3" checked>
                            <label class="custom-control-label d-flex justify-content-between align-items-center w-100" for="chkStatus_case_3" style="cursor: pointer;">
                                <span><i class="fas fa-users text-warning mr-1"></i> Shared Phone Conflicts (Case 3)</span>
                                <span class="badge badge-warning ml-auto" id="cntCase3">0</span>
                            </label>
                        </div>
                        <div class="custom-control custom-checkbox my-2">
                            <input type="checkbox" class="custom-control-input status-checkbox" id="chkStatus_excluded" value="excluded" checked>
                            <label class="custom-control-label d-flex justify-content-between align-items-center w-100" for="chkStatus_excluded" style="cursor: pointer;">
                                <span><i class="fas fa-ban text-secondary mr-1"></i> Excluded / Ineligible</span>
                                <span class="badge badge-secondary ml-auto" id="cntExcluded">0</span>
                            </label>
                        </div>
                    </div>
                </div>
                <span class="badge badge-light border text-muted small p-1 px-2" id="cntTotalBadge">0 total</span>
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

<!-- Diagnostics Modal -->
<div class="modal fade" id="diagnosticsModal" tabindex="-1" role="dialog" aria-labelledby="diagnosticsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
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
                <div id="diagFieldsContainer" class="p-2 border rounded bg-light mb-3" style="max-height: 120px; overflow-y: auto;">
                </div>

                <h6><i class="fas fa-file-code text-muted"></i> Sample Survey Response (First Record)</h6>
                <pre id="diagSampleJson" class="bg-dark text-light p-2 rounded" style="max-height: 180px; overflow-y: auto; font-size: 11px;"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
