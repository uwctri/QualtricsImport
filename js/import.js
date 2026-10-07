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
        if (digits.length === 10) {
            return `(${digits.substr(0,3)}) ${digits.substr(3,3)}-${digits.substr(6,4)}`;
        }
        return phone;
    };

    const formatStartDate = (dt) => {
        if (!dt) return '-';
        const cleaned = String(dt).replace('T', ' ').replace(/Z$/, '').trim();
        if (cleaned.length >= 16) {
            return cleaned.substring(0, 16);
        }
        return cleaned;
    };

    const getBadgeHtml = (status, category) => {
        let badgeClass = 'badge-clean';
        if (status === 'skipped_case_2') badgeClass = 'badge-case-2';
        else if (status === 'rejected_case_3') badgeClass = 'badge-case-3';
        else if (status === 'accepted_case_4') badgeClass = 'badge-case-4';
        else if (status === 'skipped_case_1') badgeClass = 'badge-imported';
        else if (status === 'rejected_invalid_phone' || status === 'skipped_ineligible') {
            badgeClass = 'badge-invalid';
        }
        return `<span class="badge-status ${badgeClass}">${category}</span>`;
    };

    const updateStatusFilterSummary = () => {
        const count = selectedStatuses.size;
        let label = 'All';
        if (count === 0) {
            label = 'None';
        } else if (count === ALL_STATUS_KEYS.length) {
            label = 'All';
        } else if (count === 1) {
            const singleKey = Array.from(selectedStatuses)[0];
            label = STATUS_LABELS[singleKey] || singleKey;
        } else {
            label = `${count} selected`;
        }
        $('#statusFilterSummary').text(label);
    };

    const renderTable = (evaluations) => {
        const $tbody = $('#previewTableBody');
        $tbody.empty();

        if (!evaluations || evaluations.length === 0) {
            $tbody.html('<tr><td colspan="7" class="text-center text-muted p-4">No survey responses found matching criteria.</td></tr>');
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
            const msg = searchTerm
                ? `No records matching "<strong>${$('<div>').text(searchTerm).html()}</strong>"`
                : 'No records found for selected status filter.';
            $tbody.html(`<tr><td colspan="8" class="text-center text-muted p-4">${msg}</td></tr>`);
            return;
        }

        filtered.forEach(row => {
            let scoresHtml = '-';
            if (row.scores) {
                scoresHtml = `<span class="similarity-score">F: ${(row.scores.first * 100).toFixed(0)}% / L: ${(row.scores.last * 100).toFixed(0)}%</span>`;
            }

            const matchedRecord = row.matched_record_id ? `Record ${row.matched_record_id}` : '-';
            const allocatedId = row.record_id ? `<strong>${row.record_id}</strong>` : '<span class="text-muted">-</span>';

            const tr = `
                <tr>
                    <td>${getBadgeHtml(row.status, row.category)}</td>
                    <td class="text-nowrap"><small>${formatStartDate(row.start_date)}</small></td>
                    <td><strong>${row.candidate_name || '-'}</strong></td>
                    <td class="text-nowrap">${formatPhone(row.candidate_phone)}</td>
                    <td><small class="text-muted">${row.qualtrics_id}</small></td>
                    <td>${allocatedId}</td>
                    <td>${matchedRecord} ${scoresHtml !== '-' ? '<br>' + scoresHtml : ''}</td>
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
            if (currentData) {
                renderTable(currentData.evaluations || []);
            }
        });

        // Status filter: Select All ("All")
        $('#btnStatusSelectAll').on('click', () => {
            $('.status-checkbox').prop('checked', true);
            selectedStatuses = new Set(ALL_STATUS_KEYS);
            updateStatusFilterSummary();
            if (currentData) {
                renderTable(currentData.evaluations || []);
            }
        });

        // Status filter: Clear All ("Clear")
        $('#btnStatusClearAll').on('click', () => {
            $('.status-checkbox').prop('checked', false);
            selectedStatuses.clear();
            updateStatusFilterSummary();
            if (currentData) {
                renderTable(currentData.evaluations || []);
            }
        });

        // Search input handler
        $('#previewSearch').on('input', function () {
            searchTerm = $(this).val().trim();
            if (searchTerm) {
                $('#previewSearchClearContainer').show();
            } else {
                $('#previewSearchClearContainer').hide();
            }
            if (currentData) {
                renderTable(currentData.evaluations || []);
            }
        });

        // Clear search input
        $('#btnSearchClear').on('click', () => {
            $('#previewSearch').val('');
            searchTerm = '';
            $('#previewSearchClearContainer').hide();
            if (currentData) {
                renderTable(currentData.evaluations || []);
            }
            $('#previewSearch').focus();
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

            // Render available fields
            const $fCont = $('#diagFieldsContainer').empty();
            if (diag.qualtrics_fields && diag.qualtrics_fields.length > 0) {
                diag.qualtrics_fields.forEach(f => {
                    $fCont.append(`<span class="badge badge-light border m-1 p-1"><code>${$('<div>').text(f).html()}</code></span> `);
                });
            } else {
                $fCont.html('<span class="text-muted">No survey field keys retrieved.</span>');
            }

            // Render sample JSON
            $('#diagSampleJson').text(diag.sample_raw_values ? JSON.stringify(diag.sample_raw_values, null, 2) : 'No raw row data available.');

            $('#diagnosticsModal').modal('show');
        });

        // Initial preview load if survey is configured
        if (module.hasSurveyId) {
            loadPreview();
        } else {
            $('#previewLoading').hide();
            $('#previewContent').show();
            renderTable([]);
        }
    });
})();
