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

            // Only show trigger_call_log if Call Log EM is installed
            const callLogSettingRow = $modal.find('tr[data-key="trigger_call_log"]');
            const callLogHeaderRow = $modal.find('tr[data-key="call_log_header"]');

            if (!module.isCallLogInstalled) {
                callLogSettingRow.hide();
                callLogHeaderRow.hide();
            } else {
                callLogSettingRow.show();
                callLogHeaderRow.show();
            }
        };
    });

    $modal.on('hide.bs.modal', function () {
        if ($(this).data('module') !== module.prefix) return;
        if (typeof ExternalModules.Settings.prototype.resetConfigInstancesOld !== 'undefined') {
            ExternalModules.Settings.prototype.resetConfigInstances = ExternalModules.Settings.prototype.resetConfigInstancesOld;
        }
    });
})();
