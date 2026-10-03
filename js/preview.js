(() => {
    const module = ExternalModules.UWMadison.QualtricsImport;
    if (!module) return;

    let currentData = null;
    let activeFilter = 'all';

    const formatPhone = (phone) => {
        if (!phone) return '';
        const digits = String(phone).replace(/\D/g, '');
        if (digits.length === 10) {
            return `(${digits.substr(0,3)}) ${digits.substr(3,3)}-${digits.substr(6,4)}`;
        }
        return phone;
    };

    const getBadgeHtml = (status, category) => {
        let badgeClass = 'badge-clean';
        if (status === 'skipped_case_2') badgeClass = 'badge-case-2';
        else if (status === 'rejected_case_3') badgeClass = 'badge-case-3';
        else if (status === 'accepted_case_4') badgeClass = 'badge-case-4';
        else if (status === 'skipped_case_1' || status === 'rejected_invalid_phone' || status === 'skipped_ineligible') {
            badgeClass = 'badge-invalid';
        }
        return `<span class="badge-status ${badgeClass}">${category}</span>`;
    };

    const renderTable = (evaluations) => {
        const $tbody = $('#previewTableBody');
        $tbody.empty();

        if (!evaluations || evaluations.length === 0) {
            $tbody.html('<tr><td colspan="7" class="text-center text-muted p-4">No survey responses found matching criteria.</td></tr>');
            return;
        }

        const filtered = evaluations.filter(item => {
            if (activeFilter === 'all') return true;
            if (activeFilter === 'ready' && (item.status === 'accepted_clean' || item.status === 'accepted_case_4')) return true;
            if (activeFilter === 'clean' && item.status === 'accepted_clean') return true;
            if (activeFilter === 'case_4' && item.status === 'accepted_case_4') return true;
            if (activeFilter === 'case_2' && item.status === 'skipped_case_2') return true;
            if (activeFilter === 'case_3' && item.status === 'rejected_case_3') return true;
            if (activeFilter === 'excluded' && (item.status === 'skipped_case_1' || item.status === 'rejected_invalid_phone' || item.status === 'skipped_ineligible')) return true;
            return false;
        });

        if (filtered.length === 0) {
            $tbody.html('<tr><td colspan="7" class="text-center text-muted p-4">No records found for selected filter.</td></tr>');
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
                    <td><strong>${row.candidate_name || '-'}</strong></td>
                    <td>${formatPhone(row.candidate_phone)}</td>
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
        $('#cntTotal').text(total || 0);
        $('#cntReady').text((counts.clean_new || 0) + (counts.case_4_suspected || 0));
        $('#cntCase2').text(counts.case_2_duplicate || 0);
        $('#cntCase3').text(counts.case_3_household || 0);
        $('#cntCase4').text(counts.case_4_suspected || 0);
        $('#cntExcluded').text((counts.case_1_existing_id || 0) + (counts.invalid_phone || 0) + (counts.missing_required || 0));
    };

    const loadPreview = () => {
        $('#previewLoading').show();
        $('#previewContent').hide();
        $('#btnRunImport').prop('disabled', true);

        module.ajax('previewImport', {})
            .then(res => {
                $('#previewLoading').hide();
                $('#previewContent').show();

                if (!res.success) {
                    alert('Error generating preview: ' + (res.message || 'Unknown error'));
                    return;
                }

                currentData = res.data;
                updateCounts(currentData.counts || {}, currentData.evaluations ? currentData.evaluations.length : 0);
                renderTable(currentData.evaluations || []);

                const readyCount = (currentData.counts.clean_new || 0) + (currentData.counts.case_4_suspected || 0);
                if (readyCount > 0 || currentData.note_updates_count > 0) {
                    $('#btnRunImport').prop('disabled', false);
                }
            })
            .catch(err => {
                $('#previewLoading').hide();
                alert('AJAX error while loading preview: ' + err);
            });
    };

    const runLiveImport = () => {
        if (!confirm('Are you sure you want to run the live import now? This will create new REDCap records and update existing notes.')) {
            return;
        }

        $('#btnRunImport').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Importing...');
        $('#btnRefresh').prop('disabled', true);

        module.ajax('runImport', {})
            .then(res => {
                $('#btnRunImport').html('<i class="fas fa-play"></i> Run Import Now');
                $('#btnRefresh').prop('disabled', false);

                if (!res.success) {
                    alert('Import failed: ' + (res.message || 'Unknown error'));
                    return;
                }

                const d = res.data;
                const imported = d.ready_to_import_count || 0;
                const notes = d.note_updates_count || 0;

                alert(`Import completed successfully!\n\nNew Records Imported: ${imported}\nExisting Records Updated: ${notes}`);
                loadPreview();
            })
            .catch(err => {
                $('#btnRunImport').prop('disabled', false).html('<i class="fas fa-play"></i> Run Import Now');
                $('#btnRefresh').prop('disabled', false);
                alert('AJAX error during import execution: ' + err);
            });
    };

    $(document).ready(() => {
        // Event handlers
        $('#btnRefresh').on('click', () => {
            loadPreview();
        });

        $('#btnRunImport').on('click', () => {
            runLiveImport();
        });

        $('.filter-pill').on('click', function() {
            $('.filter-pill').removeClass('active');
            $(this).addClass('active');
            activeFilter = $(this).data('filter');
            if (currentData) {
                renderTable(currentData.evaluations || []);
            }
        });

        // Initial preview load
        loadPreview();
    });
})();
