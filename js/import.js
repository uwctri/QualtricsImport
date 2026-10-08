(() => {
    const module = ExternalModules.UWMadison.QualtricsImport;
    if (!module) return;

    let currentData = null;
    const STATUS_MAP = {
        'ready': ['accepted_clean'],
        'imported': ['skipped_case_1'],
        'case_4': ['accepted_case_4'],
        'case_2': ['skipped_case_2'],
        'case_3': ['rejected_case_3'],
        'excluded': ['skipped_ineligible', 'rejected_invalid_phone']
    };
    const STATUS_LABELS = {
        'ready': 'Ready',
        'imported': 'Imported',
        'case_4': 'Possible Dups',
        'case_2': 'Duplicates',
        'case_3': 'Shared Phone',
        'excluded': 'Excluded'
    };
    const ALL_STATUS_KEYS = Object.keys(STATUS_MAP);
    let selectedStatuses = new Set(ALL_STATUS_KEYS);

    let searchTerm = '';
    let sortColumn = null;
    let sortDirection = 'asc';

    const formatPhone = (phone) => {
        if (!phone) return '';
        const digits = String(phone).replace(/\D/g, '');
        if (digits.length !== 10) return phone;
        return `(${digits.substr(0, 3)}) ${digits.substr(3, 3)}-${digits.substr(6, 4)}`;
    };

    const formatStartDate = (dt) => {
        if (!dt) return '-';
        const cleaned = String(dt).replace('T', ' ').replace(/Z$/, '').trim();
        return cleaned.length >= 16 ? cleaned.substring(0, 16) : cleaned;
    };

    const getBadgeHtml = (status, category) => {
        if (status === 'skipped_case_2') return `<span class="badge-status badge-case-2">${category}</span>`;
        if (status === 'rejected_case_3') return `<span class="badge-status badge-case-3">${category}</span>`;
        if (status === 'accepted_case_4') return `<span class="badge-status badge-case-4">${category}</span>`;
        if (status === 'skipped_case_1') return `<span class="badge-status badge-imported">${category}</span>`;
        if (status === 'rejected_invalid_phone' || status === 'skipped_ineligible') {
            return `<span class="badge-status badge-invalid">${category}</span>`;
        }
        return `<span class="badge-status badge-clean">${category}</span>`;
    };

    const updateStatusFilterSummary = () => {
        const count = selectedStatuses.size;
        if (count === 0) return $('#statusFilterSummary').text('None');
        if (count === ALL_STATUS_KEYS.length) return $('#statusFilterSummary').text('All');
        if (count === 1) {
            const singleKey = Array.from(selectedStatuses)[0];
            return $('#statusFilterSummary').text(STATUS_LABELS[singleKey] || singleKey);
        }
        $('#statusFilterSummary').text(`${count} selected`);
    };

    const renderTable = (evaluations) => {
        const $tbody = $('#previewTableBody');
        $tbody.empty();

        if (!evaluations || evaluations.length === 0) {
            $tbody.html(`
                <tr>
                    <td colspan="7" class="p-0 border-0">
                        <div class="empty-preview-state text-center">
                            <div class="empty-icon-wrapper mb-3">
                                <i class="fas fa-inbox fa-3x text-muted" style="opacity: 0.35;"></i>
                            </div>
                            <h5 class="text-secondary font-weight-normal mb-1">No Survey Responses Found</h5>
                            <p class="text-muted small mb-0" style="max-width: 500px; margin: 0 auto;">
                                No responses were returned from Qualtrics for the selected date range. Try selecting a different date range or clicking Refresh above.
                            </p>
                        </div>
                    </td>
                </tr>
            `);
            $('#tableRecordInfo').html('<span>0 responses</span>');
            return;
        }

        // 1. Filter by selected statuses
        let filtered = evaluations.filter(item => {
            if (selectedStatuses.size === ALL_STATUS_KEYS.length) return true;
            if (selectedStatuses.size === 0) return false;
            for (const key of selectedStatuses) {
                if (STATUS_MAP[key] && STATUS_MAP[key].includes(item.status)) {
                    return true;
                }
            }
            return false;
        });

        const statusFilteredTotal = filtered.length;

        // 2. Filter by search term across all columns
        if (searchTerm) {
            const query = searchTerm.toLowerCase();
            filtered = filtered.filter(item => {
                const searchCorpus = [
                    item.category || '',
                    item.status || '',
                    item.start_date || '',
                    item.candidate_name || '',
                    item.candidate_phone || '',
                    item.qualtrics_id || '',
                    item.record_id || '',
                    item.matched_record_id || '',
                    item.description || ''
                ].join(' ').toLowerCase();
                return searchCorpus.includes(query);
            });
        }

        // 3. Sort by active column
        if (sortColumn) {
            filtered.sort((a, b) => {
                let comp = 0;
                switch (sortColumn) {
                    case 'status':
                        comp = (a.category || '').localeCompare(b.category || '', undefined, { sensitivity: 'base' });
                        break;
                    case 'start_date':
                        comp = (a.start_date || '').localeCompare(b.start_date || '');
                        break;
                    case 'name':
                        comp = (a.candidate_name || '').localeCompare(b.candidate_name || '', undefined, { sensitivity: 'base' });
                        break;
                    case 'phone':
                        const pA = String(a.candidate_phone || '').replace(/\D/g, '');
                        const pB = String(b.candidate_phone || '').replace(/\D/g, '');
                        comp = pA.localeCompare(pB, undefined, { numeric: true });
                        break;
                    case 'qualtrics_id':
                        comp = (a.qualtrics_id || '').localeCompare(b.qualtrics_id || '', undefined, { numeric: true, sensitivity: 'base' });
                        break;
                    case 'record_id':
                        const rA = parseInt(a.record_id, 10) || 0;
                        const rB = parseInt(b.record_id, 10) || 0;
                        comp = rA - rB;
                        break;
                    case 'matched':
                        const mA = parseInt(a.matched_record_id, 10) || 0;
                        const mB = parseInt(b.matched_record_id, 10) || 0;
                        comp = mA - mB;
                        break;
                    case 'description':
                        comp = (a.description || '').localeCompare(b.description || '', undefined, { sensitivity: 'base' });
                        break;
                }
                return sortDirection === 'asc' ? comp : -comp;
            });
        }

        // Update footer info
        if (searchTerm) {
            $('#tableRecordInfo').html(`<span>Showing <strong>${filtered.length}</strong> of ${evaluations.length} responses matching search</span>`);
        } else if (statusFilteredTotal < evaluations.length) {
            $('#tableRecordInfo').html(`<span>Showing <strong>${filtered.length}</strong> of ${evaluations.length} total responses (filtered by status)</span>`);
        } else {
            $('#tableRecordInfo').html(`<span>Showing all <strong>${filtered.length}</strong> responses</span>`);
        }

        if (filtered.length === 0) {
            let emptyIcon = 'fa-filter';
            let emptyTitle = 'No Responses Matching Filter';
            let emptyMsg = 'No records matched the selected status filters. Select one or more statuses from the dropdown above to view responses.';

            if (searchTerm) {
                emptyIcon = 'fa-search';
                emptyTitle = 'No Matching Records';
                emptyMsg = `No records matching "<strong>${$('<div>').text(searchTerm).html()}</strong>". Try clearing your search or adjusting your status filters.`;
            } else if (selectedStatuses.size === 0) {
                emptyIcon = 'fa-filter';
                emptyTitle = 'No Statuses Selected';
                emptyMsg = 'All status filters are unchecked. Click the <strong>Status</strong> dropdown and select at least one status.';
            }

            $tbody.html(`
                <tr>
                    <td colspan="7" class="p-0 border-0">
                        <div class="empty-preview-state text-center">
                            <div class="empty-icon-wrapper mb-3">
                                <i class="fas ${emptyIcon} fa-3x text-muted" style="opacity: 0.35;"></i>
                            </div>
                            <h5 class="text-secondary font-weight-normal mb-1">${emptyTitle}</h5>
                            <p class="text-muted small mb-0" style="max-width: 500px; margin: 0 auto;">
                                ${emptyMsg}
                            </p>
                        </div>
                    </td>
                </tr>
            `);
            return;
        }

        filtered.forEach(row => {
            const allocatedId = row.record_id ? `<strong>${row.record_id}</strong>` : '<span class="text-muted">-</span>';

            const tr = `
                <tr>
                    <td>${getBadgeHtml(row.status, row.category)}</td>
                    <td class="text-nowrap"><small>${formatStartDate(row.start_date)}</small></td>
                    <td><strong>${row.candidate_name || '-'}</strong></td>
                    <td class="text-nowrap">${formatPhone(row.candidate_phone)}</td>
                    <td><small class="text-muted">${row.qualtrics_id}</small></td>
                    <td>${allocatedId}</td>
                    <td><small>${row.description}</small></td>
                </tr>
            `;
            $tbody.append(tr);
        });
    };

    const updateCounts = (counts, total) => {
        $('#cntTotalBadge').text(`${(total || 0).toLocaleString()} total`);
        $('#cntReady').text((counts.clean_new || 0).toLocaleString());
        $('#cntImported').text((counts.case_1_existing_id || 0).toLocaleString());
        $('#cntCase4').text((counts.case_4_suspected || 0).toLocaleString());
        $('#cntCase2').text((counts.case_2_duplicate || 0).toLocaleString());
        $('#cntCase3').text((counts.case_3_household || 0).toLocaleString());
        $('#cntExcluded').text(((counts.missing_required || 0) + (counts.invalid_phone || 0)).toLocaleString());
    };

    const getPreviewParams = () => {
        let limit = parseInt($('#previewLimit').val(), 10);
        if (isNaN(limit) || limit < 1) limit = 1000;
        const sinceType = $('#previewSince').val() || 'auto';
        return { limit, sinceType };
    };

    const showStatusAlert = (message, type = 'info') => {
        const alertHtml = `
            <div class="alert alert-${type} alert-dismissible fade show mb-3" role="alert">
                ${message}
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        `;
        $('#importAlertArea').html(alertHtml);
    };

    const loadPreview = () => {
        if (!module.hasSurveyId) {
            $('#previewLoading').hide();
            $('#previewContent').show();
            renderTable([]);
            return;
        }

        $('#previewLoading').show();
        $('#previewContent').hide();
        $('#btnRunImport').prop('disabled', true);
        $('#previewScopeNotice').empty();

        const params = getPreviewParams();

        module.ajax('previewImport', params)
            .then(res => {
                $('#previewLoading').hide();
                $('#previewContent').show();

                if (!res || !res.success || !res.data) {
                    renderTable([]);
                    return;
                }

                currentData = res.data;
                updateCounts(currentData.counts || {}, currentData.evaluations ? currentData.evaluations.length : 0);
                renderTable(currentData.evaluations || []);

                if (currentData.limit_applied) {
                    $('#previewScopeNotice').html(`<span class="badge badge-warning text-dark"><i class="fas fa-exclamation-triangle"></i> Capped at ${currentData.limit_applied.toLocaleString()} of ${currentData.survey_rows_retrieved.toLocaleString()} responses</span>`);
                } else if (currentData.start_date_used) {
                    const d = new Date(currentData.start_date_used);
                    $('#previewScopeNotice').html(`<span class="text-muted small"><i class="far fa-clock"></i> Since: ${d.toLocaleDateString()} ${d.toLocaleTimeString()} (${currentData.survey_rows_evaluated || 0} evaluated)</span>`);
                } else {
                    $('#previewScopeNotice').html(`<span class="text-muted small"><i class="fas fa-infinity"></i> All time (${currentData.survey_rows_evaluated || 0} evaluated)</span>`);
                }

                const readyCount = (currentData.counts.clean_new || 0) + (currentData.counts.case_4_suspected || 0);
                if (readyCount > 0 || currentData.note_updates_count > 0) {
                    $('#btnRunImport').prop('disabled', false);
                }

                if (currentData.diagnostics) {
                    $('#btnDiagnostics').show();
                    console.log('Qualtrics Import Diagnostics:', currentData.diagnostics);
                }
            })
            .catch(() => {
                $('#previewLoading').hide();
                $('#previewContent').show();
                renderTable([]);
            });
    };

    const runLiveImport = () => {
        if (!confirm('Are you sure you want to run the live import now? This will create new REDCap records and update existing notes.')) {
            return;
        }

        $('#btnRunImport').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Importing...');
        $('#btnRefresh').prop('disabled', true);
        $('#importAlertArea').empty();

        const params = getPreviewParams();

        module.ajax('runImport', params)
            .then(res => {
                $('#btnRunImport').html('<i class="fas fa-play"></i> Run Import Now');
                $('#btnRefresh').prop('disabled', false);

                if (!res || !res.success) {
                    showStatusAlert('Import failed: ' + (res && res.message ? res.message : 'Unknown error'), 'danger');
                    return;
                }

                const d = res.data;
                const imported = d.ready_to_import_count || 0;
                const notes = d.note_updates_count || 0;

                showStatusAlert(`<strong>Import completed successfully!</strong> ${imported} record(s) imported, ${notes} note(s) updated.`, 'success');
                loadPreview();
            })
            .catch(err => {
                $('#btnRunImport').prop('disabled', false).html('<i class="fas fa-play"></i> Run Import Now');
                $('#btnRefresh').prop('disabled', false);
                showStatusAlert('AJAX error during import execution: ' + err, 'danger');
            });
    };

    $(document).ready(() => {
        // Restore remembered limit if present
        try {
            const savedLimit = localStorage.getItem('qualtrics_import_preview_limit');
            if (savedLimit) {
                $('#previewLimit').val(savedLimit);
            }
        } catch (e) {
            // Ignore localStorage errors
        }

        // Event handlers
        $('#btnRefresh').on('click', () => {
            loadPreview();
        });

        $('#btnRunImport').on('click', () => {
            runLiveImport();
        });

        $('#previewSince').on('change', () => {
            loadPreview();
        });

        $('#previewLimit').on('change', function() {
            try {
                localStorage.setItem('qualtrics_import_preview_limit', $(this).val());
            } catch (e) {
                // Ignore localStorage errors
            }
            loadPreview();
        });

        // Status filter checkbox toggle
        $(document).on('change', '.status-checkbox', function() {
            const val = $(this).val();
            if ($(this).is(':checked')) {
                selectedStatuses.add(val);
            } else {
                selectedStatuses.delete(val);
            }
            updateStatusFilterSummary();
            if (!currentData) return;
            renderTable(currentData.evaluations || []);
        });

        // Status filter: Select All ("All")
        $('#btnStatusSelectAll').on('click', () => {
            $('.status-checkbox').prop('checked', true);
            selectedStatuses = new Set(ALL_STATUS_KEYS);
            updateStatusFilterSummary();
            if (!currentData) return;
            renderTable(currentData.evaluations || []);
        });

        // Status filter: Clear All ("Clear")
        $('#btnStatusClearAll').on('click', () => {
            $('.status-checkbox').prop('checked', false);
            selectedStatuses.clear();
            updateStatusFilterSummary();
            if (!currentData) return;
            renderTable(currentData.evaluations || []);
        });

        // Search input handler
        $('#previewSearch').on('input', function () {
            searchTerm = $(this).val().trim();
            $('#previewSearchClearContainer').toggle(Boolean(searchTerm));
            if (!currentData) return;
            renderTable(currentData.evaluations || []);
        });

        // Clear search input
        $('#btnSearchClear').on('click', () => {
            $('#previewSearch').val('');
            searchTerm = '';
            $('#previewSearchClearContainer').hide();
            $('#previewSearch').focus();
            if (!currentData) return;
            renderTable(currentData.evaluations || []);
        });

        // Column header sort handler
        $(document).on('click', 'th.sortable', function () {
            const col = $(this).data('sort');
            if (sortColumn === col) {
                sortDirection = (sortDirection === 'asc') ? 'desc' : 'asc';
            } else {
                sortColumn = col;
                sortDirection = 'asc';
            }

            // Reset all column header states
            $('th.sortable').removeClass('sorted-asc sorted-desc');
            $('th.sortable .sort-icon').removeClass('fa-sort-up fa-sort-down text-primary').addClass('fa-sort');

            // Apply active sort indicator
            const $thisTh = $(this);
            $thisTh.addClass(sortDirection === 'asc' ? 'sorted-asc' : 'sorted-desc');
            const $icon = $thisTh.find('.sort-icon');
            $icon.removeClass('fa-sort').addClass(sortDirection === 'asc' ? 'fa-sort-up text-primary' : 'fa-sort-down text-primary');

            if (currentData) {
                renderTable(currentData.evaluations || []);
            }
        });

    /**
     * Build an alias resolver that maps Qualtrics QIDs to DataExportTags (and vice versa),
     * as well as custom mapped REDCap fields.
     */
    const buildAliasResolver = (tagMap, customMappings) => {
        tagMap = tagMap || {};
        customMappings = customMappings || [];

        const qidToTag = {};
        const tagToQid = {};
        Object.keys(tagMap).forEach(q => {
            const tag = String(tagMap[q]).trim();
            const qUpper = String(q).trim().toUpperCase();
            qidToTag[qUpper] = tag;
            // Normalize Q29 vs QID29
            if (qUpper.startsWith('QID')) {
                qidToTag['Q' + qUpper.slice(3)] = tag;
            } else if (qUpper.startsWith('Q') && /^\d+$/.test(qUpper.slice(1))) {
                qidToTag['QID' + qUpper.slice(1)] = tag;
            }

            if (!tagToQid[tag.toLowerCase()]) {
                tagToQid[tag.toLowerCase()] = q;
            }
        });

        const customMap = {};
        customMappings.forEach(m => {
            if (m.qualtrics_field && m.redcap_field) {
                customMap[String(m.qualtrics_field).trim().toLowerCase()] = String(m.redcap_field).trim();
            }
        });

        return (key, sampleKeysUpper = {}) => {
            const kUpper = String(key).toUpperCase();
            const kLower = String(key).toLowerCase();
            let aliasDesc = null;

            // 1. Direct match in qidToTag (e.g. QID29 or Q29)
            if (qidToTag[kUpper]) {
                aliasDesc = `alias: ${qidToTag[kUpper]}`;
            }
            // 2. Regex match for QID_suffix (e.g. QID29_TEXT or QID10_1)
            else if (/^(Q(?:ID)?\d+)_(.+)$/i.test(key)) {
                const m = key.match(/^(Q(?:ID)?\d+)_(.+)$/i);
                const baseQid = m[1].toUpperCase();
                const suffix = m[2];
                let tag = qidToTag[baseQid];
                if (!tag) {
                    if (baseQid.startsWith('QID')) tag = qidToTag['Q' + baseQid.slice(3)];
                    else if (baseQid.startsWith('Q')) tag = qidToTag['QID' + baseQid.slice(1)];
                }
                if (tag) {
                    if (suffix.toUpperCase() === 'TEXT') {
                        aliasDesc = `alias: ${tag}`;
                    } else {
                        aliasDesc = `alias: ${tag}_${suffix}`;
                    }
                }
            }
            // 3. Reverse match: key is a DataExportTag (e.g. first_name, last_name)
            else if (tagToQid[kLower]) {
                const origQid = tagToQid[kLower];
                const textKey = `${origQid}_TEXT`;
                if (sampleKeysUpper[textKey.toUpperCase()]) {
                    aliasDesc = `alias for ${sampleKeysUpper[textKey.toUpperCase()]}`;
                } else if (sampleKeysUpper[origQid.toUpperCase()]) {
                    aliasDesc = `alias for ${sampleKeysUpper[origQid.toUpperCase()]}`;
                } else {
                    aliasDesc = `alias for ${origQid}`;
                }
            }
            // 4. Reverse choice or suffix match (e.g. first_name_TEXT, symptoms_1, symptoms___1)
            else if (/^(.+?)_(?:TEXT|\d+|__\d+)$/i.test(key)) {
                const m = key.match(/^(.+?)_(?:TEXT|(\d+)|__(\d+))$/i);
                const baseTag = (m[1] || '').toLowerCase();
                const choiceNum = m[2] || m[3];
                if (tagToQid[baseTag]) {
                    const origQid = tagToQid[baseTag];
                    if (choiceNum) {
                        const candChoice = `${origQid}_${choiceNum}`;
                        if (sampleKeysUpper[candChoice.toUpperCase()]) {
                            aliasDesc = `alias for ${sampleKeysUpper[candChoice.toUpperCase()]}`;
                        } else {
                            aliasDesc = `alias for ${origQid}_${choiceNum}`;
                        }
                    } else {
                        const candText = `${origQid}_TEXT`;
                        if (sampleKeysUpper[candText.toUpperCase()]) {
                            aliasDesc = `alias for ${sampleKeysUpper[candText.toUpperCase()]}`;
                        } else {
                            aliasDesc = `alias for ${origQid}_TEXT`;
                        }
                    }
                }
            }

            // Append custom mapping target if present
            if (customMap[kLower]) {
                const rcTarget = customMap[kLower];
                if (aliasDesc) {
                    aliasDesc += ` \u2192 REDCap: ${rcTarget}`;
                } else {
                    aliasDesc = `maps to REDCap: ${rcTarget}`;
                }
            }

            return aliasDesc;
        };
    };

    /**
     * Formats sample survey response JSON line-by-line, adding uniform, right-aligned
     * comments on lines that have an alias or mapping.
     */
    const formatSampleJsonWithAliases = (sampleObj, tagMap, customMappings) => {
        if (!sampleObj || typeof sampleObj !== 'object' || (Array.isArray(sampleObj) && sampleObj.length === 0) || Object.keys(sampleObj).length === 0) {
            return '<span class="text-muted">No raw survey response data available.</span>';
        }

        const resolveAlias = buildAliasResolver(tagMap, customMappings);
        const sampleKeysUpper = {};
        Object.keys(sampleObj).forEach(k => { sampleKeysUpper[k.toUpperCase()] = k; });

        const jsonStr = JSON.stringify(sampleObj, null, 2);
        const lines = jsonStr.split('\n');

        const lineAliases = [];
        let maxLen = 0;

        // First pass: identify lines with aliases and find the longest line length among them
        lines.forEach((line, idx) => {
            const match = line.match(/^(\s*"([^"]+)"\s*:\s*.+?)(,?)$/);
            if (match) {
                const key = match[2];
                const alias = resolveAlias(key, sampleKeysUpper);
                if (alias) {
                    lineAliases[idx] = alias;
                    if (line.length > maxLen) {
                        maxLen = line.length;
                    }
                }
            }
        });

        // Set uniform target column for comments (at least 48, or maxLen + 4)
        const targetCol = Math.max(48, maxLen + 4);

        // Second pass: pad and append right-aligned comments
        const resultLines = lines.map((line, idx) => {
            const safeLine = line.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            const alias = lineAliases[idx];
            if (!alias) {
                return safeLine;
            }
            const padLen = Math.max(3, targetCol - line.length);
            const padding = ' '.repeat(padLen);
            const safeAlias = alias.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            const commentHtml = `<span style="color: #6edff6; font-style: italic;">// ${safeAlias}</span>`;
            return safeLine + padding + commentHtml;
        });

        return resultLines.join('\n');
    };

        // Diagnostics modal opener
        $('#btnDiagnostics').on('click', () => {
            if (!currentData || !currentData.diagnostics) {
                alert('No diagnostics data available yet. Please refresh the preview.');
                return;
            }
            const diag = currentData.diagnostics;

            // Render custom mappings
            const $mBody = $('#diagMappingsBody').empty();
            if (diag.parsed_custom_mappings && diag.parsed_custom_mappings.length > 0) {
                diag.parsed_custom_mappings.forEach(m => {
                    const vm = m.value_map && Object.keys(m.value_map).length > 0 ? JSON.stringify(m.value_map) : '<span class="text-muted">None</span>';
                    $mBody.append(`<tr><td><code>${$('<div>').text(m.qualtrics_field).html()}</code></td><td><code>${$('<div>').text(m.redcap_field).html()}</code></td><td><small>${vm}</small></td></tr>`);
                });
            } else {
                $mBody.append('<tr><td colspan="3" class="text-muted">No custom field mappings configured.</td></tr>');
            }

            // Render static defaults
            const $sBody = $('#diagStaticBody').empty();
            if (diag.parsed_static_defaults && diag.parsed_static_defaults.length > 0) {
                diag.parsed_static_defaults.forEach(s => {
                    $sBody.append(`<tr><td><code>${$('<div>').text(s.field_name).html()}</code></td><td><code>${$('<div>').text(s.field_value).html()}</code></td></tr>`);
                });
            } else {
                $sBody.append('<tr><td colspan="2" class="text-muted">No static defaults configured.</td></tr>');
            }

            // Prepare alias resolver
            const resolveAlias = buildAliasResolver(diag.tag_map, diag.parsed_custom_mappings);
            const sampleKeysUpper = {};
            if (diag.sample_raw_values && typeof diag.sample_raw_values === 'object') {
                Object.keys(diag.sample_raw_values).forEach(k => { sampleKeysUpper[k.toUpperCase()] = k; });
            }

            // Render available fields
            const $fCont = $('#diagFieldsContainer').empty();
            if (diag.qualtrics_fields && diag.qualtrics_fields.length > 0) {
                diag.qualtrics_fields.forEach(f => {
                    const alias = resolveAlias(f, sampleKeysUpper);
                    let aliasBadge = '';
                    if (alias) {
                        const clean = alias.replace(/^alias:\s*/, '').replace(/^alias for\s*/, '');
                        aliasBadge = ` <span class="badge badge-info ml-1 font-weight-normal" style="font-size: 10px;">${$('<div>').text(clean).html()}</span>`;
                    }
                    $fCont.append(`<span class="badge badge-light border m-1 p-1" style="font-size: 11px;"><code>${$('<div>').text(f).html()}</code>${aliasBadge}</span> `);
                });
            } else {
                $fCont.html('<span class="text-muted">No survey field keys retrieved.</span>');
            }

            // Render sample JSON with right-aligned alias comments
            const formattedJson = formatSampleJsonWithAliases(diag.sample_raw_values, diag.tag_map, diag.parsed_custom_mappings);
            $('#diagSampleJson').html(formattedJson);

            $('#diagnosticsModal').modal('show');
        });

        // Initial preview load if survey is configured
        if (!module.hasSurveyId) {
            $('#previewLoading').hide();
            $('#previewContent').show();
            renderTable([]);
            return;
        }
        loadPreview();
    });
})();
