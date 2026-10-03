(() => {
    // Retrieve module instance from REDCap framework namespace
    const module = ExternalModules.UWMadison.QualtricsImport;
    if (!module) return;

    const $modal = $('#external-modules-configure-modal');
    $modal.on('show.bs.modal', function () {
        if ($(this).data('module') !== module.prefix) return;

        if (typeof ExternalModules.Settings.prototype.resetConfigInstancesOld === 'undefined') {
            ExternalModules.Settings.prototype.resetConfigInstancesOld = ExternalModules.Settings.prototype.resetConfigInstances;
        }

        ExternalModules.Settings.prototype.resetConfigInstances = function () {
            ExternalModules.Settings.prototype.resetConfigInstancesOld();
            if ($modal.data('module') !== module.prefix) return;


            // Hide credential override options if system setting does not allow project overrides
            if (!module.allowProjectOverrides) {
                $modal.find('[field="override_credentials"]').hide();
                $modal.find('[field="project_qualtrics_api_token"]').hide();
                $modal.find('[field="project_qualtrics_data_center"]').hide();
            }

            // Hide qualtrics_id_field dropdown if storing ResponseId directly in record_id
            const updateStrategyVisibility = () => {
                const strategy = $modal.find('select[name="record_id_strategy"]').val();
                if (strategy === 'qualtrics_response_id') {
                    $modal.find('[field="qualtrics_id_field"]').hide();
                } else {
                    $modal.find('[field="qualtrics_id_field"]').show();
                }
            };
            $modal.find('select[name="record_id_strategy"]').off('change.strat').on('change.strat', updateStrategyVisibility);
            updateStrategyVisibility();
        };
    });

    $modal.on('hide.bs.modal', function () {
        if ($(this).data('module') !== module.prefix) return;
        if (typeof ExternalModules.Settings.prototype.resetConfigInstancesOld !== 'undefined') {
            ExternalModules.Settings.prototype.resetConfigInstances = ExternalModules.Settings.prototype.resetConfigInstancesOld;
        }
    });
})();
