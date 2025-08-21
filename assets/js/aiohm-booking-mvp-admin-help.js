jQuery(document).ready(function($) {
    let debugInfo = {};

    // Initialize system information
    const systemInfo = JSON.parse($('#system-info').val() || '{}');

    // Utility function to show messages
    function showMessage(message, type = 'success') {
        const $messagesContainer = $('#support-messages');
        const messageHtml = `
            <div class="support-message ${type}">
                <span class="dashicons ${type === 'success' ? 'dashicons-yes-alt' : type === 'error' ? 'dashicons-warning' : 'dashicons-info'}"></span>
                ${message}
            </div>
        `;

        $messagesContainer.show().append(messageHtml);

        // Auto-remove after 5 seconds
        setTimeout(() => {
            $messagesContainer.find('.support-message').last().fadeOut(300, function() {
                $(this).remove();
                if ($messagesContainer.find('.support-message').length === 0) {
                    $messagesContainer.hide();
                }
            });
        }, 5000);
    }

    // Collect Debug Information
    $('#collect-debug-info').on('click', function() {
        const $btn = $(this);
        const originalText = $btn.html();

        $btn.addClass('loading').prop('disabled', true);

        // Collect comprehensive debug information
        collectDebugInformation().then(function(debug) {
            debugInfo = debug;

            const debugText = formatDebugInformation(debug);
            $('#debug-information').val(debugText);
            $('#debug-text').val(debugText);
            $('#debug-output').slideDown(300);            
            
            showMessage(aiohm_booking_mvp_admin_help_ajax.i18n.debugCollected, 'success');
        }).catch(function(error) {
            showMessage('Error collecting debug information: ' + error.message, 'error');
        }).finally(function() {
            $btn.removeClass('loading').prop('disabled', false).html(originalText);
        });
    });

    // Test Booking System functionality removed - button no longer exists

    // Copy Debug Info to Clipboard
    $('#copy-debug-info').on('click', function() {
        const debugText = $('#debug-text').val();
        if (!debugText) {
            showMessage(aiohm_booking_mvp_admin_help_ajax.i18n.noDebugToCopy, 'error');
            return;
        }

        navigator.clipboard.writeText(debugText).then(function() {
            showMessage(aiohm_booking_mvp_admin_help_ajax.i18n.debugCopied, 'success');
        }).catch(function() {
            // Fallback for older browsers
            $('#debug-text').select();
            document.execCommand('copy');
            showMessage(aiohm_booking_mvp_admin_help_ajax.i18n.debugCopied, 'success');
        });
    });

    // Download Debug Info as File
    $('#download-debug-info').on('click', function() {
        const debugText = $('#debug-text').val();
        if (!debugText) {
            showMessage(aiohm_booking_mvp_admin_help_ajax.i18n.noDebugToDownload, 'error');
            return;
        }

        const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
        const filename = `aiohm-booking-debug-${timestamp}.txt`;

        const blob = new Blob([debugText], { type: 'text/plain' });
        const url = window.URL.createObjectURL(blob);

        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);        
        
        showMessage(aiohm_booking_mvp_admin_help_ajax.i18n.debugDownloaded, 'success');
    });

    // Combined Support Form
    $('#combined-support-form').on('submit', function(e) {
        e.preventDefault();

        const $form = $(this);
        const $submitBtn = $form.find('button[type="submit"]');
        const originalText = $submitBtn.html();

        $submitBtn.addClass('loading').prop('disabled', true);

        const reportType = $('#report-type').val();
        const isFeatureRequest = reportType === 'feature-request';

        const formData = {
            action: isFeatureRequest ? 'aiohm_booking_mvp_submit_feature_request' : 'aiohm_booking_mvp_submit_support_request',
            nonce: aiohm_booking_mvp_admin_help_ajax?.nonce || '',
            email: $('#support-email').val(),
            title: $('#support-title').val(),
            type: reportType,
            description: $('#support-description').val(),
            debug_information: $('#debug-information').val(),
            include_debug: $('#include-debug-info').is(':checked'),
            system_info: systemInfo
        };

        // For feature requests, map fields to expected structure
        if (isFeatureRequest) {
            formData.category = reportType;
        } else {
            formData.subject = reportType;
        }

        if (formData.include_debug && Object.keys(debugInfo).length > 0) {
            formData.debug_info = debugInfo;
        }

        $.post(ajaxurl, formData)
        .done(function(response) {
            if (response.success) {
                const messageType = isFeatureRequest ? aiohm_booking_mvp_admin_help_ajax.i18n.featureRequest : aiohm_booking_mvp_admin_help_ajax.i18n.supportRequest;
                showMessage(aiohm_booking_mvp_admin_help_ajax.i18n.requestSubmitted.replace('%s', messageType), 'success');
                $form[0].reset();
                $('#debug-output').slideUp(300);
            } else {
                showMessage(aiohm_booking_mvp_admin_help_ajax.i18n.requestError + (response.data?.message || aiohm_booking_mvp_admin_help_ajax.i18n.unknownError), 'error');
            }
        })
        .fail(function() {            
            showMessage(aiohm_booking_mvp_admin_help_ajax.i18n.requestServerError, 'error');
        })
        .finally(function() {
            $submitBtn.removeClass('loading').prop('disabled', false).html(originalText);
        });
    });

    // Collect comprehensive debug information
    function collectDebugInformation() {
        return new Promise(function(resolve, reject) {
            const debug = {
                timestamp: new Date().toISOString(),
                system: systemInfo,
                browser: {
                    userAgent: navigator.userAgent,
                    language: navigator.language,
                    platform: navigator.platform,
                    cookieEnabled: navigator.cookieEnabled,
                    onLine: navigator.onLine
                },
                screen: {
                    width: screen.width,
                    height: screen.height,
                    colorDepth: screen.colorDepth,
                    pixelDepth: screen.pixelDepth
                },
                window: {
                    innerWidth: window.innerWidth,
                    innerHeight: window.innerHeight,
                    devicePixelRatio: window.devicePixelRatio || 1
                },
                wordpress: {
                    adminUrl: ajaxurl?.replace('admin-ajax.php', '') || 'Unknown',
                    currentPage: window.location.href,
                    referrer: document.referrer || 'None'
                }
            };

            // Collect plugin-specific information via AJAX
            $.post(ajaxurl, {
                action: 'aiohm_booking_mvp_get_debug_info',
                nonce: aiohm_booking_mvp_admin_help_ajax?.nonce || ''
            }).done(function(response) {
                if (response.success) {
                    debug.plugin = response.data;
                }
                resolve(debug);
            }).fail(function() {
                // Continue even if plugin debug info fails
                debug.plugin = { error: aiohm_booking_mvp_admin_help_ajax.i18n.failedToCollectPluginInfo };
                resolve(debug);
            });
        });
    }

    // Format debug information for display
    function formatDebugInformation(debug) {
        const i18n = aiohm_booking_mvp_admin_help_ajax.i18n;
        let output = i18n.debugReportTitle;
        output += i18n.generated.replace('%s', debug.timestamp);

        output += i18n.systemInfoTitle;
        output += i18n.pluginVersion.replace('%s', debug.system.plugin_version);
        output += i18n.wpVersion.replace('%s', debug.system.wp_version);
        output += i18n.phpVersion.replace('%s', debug.system.php_version);
        output += i18n.siteUrl.replace('%s', debug.system.site_url);
        output += i18n.enabledModules.replace('%s', debug.system.enabled_modules);

        output += i18n.browserInfoTitle;
        output += i18n.userAgent.replace('%s', debug.browser.userAgent);
        output += i18n.language.replace('%s', debug.browser.language);
        output += i18n.platform.replace('%s', debug.browser.platform);
        output += i18n.cookiesEnabled.replace('%s', debug.browser.cookieEnabled);
        output += i18n.online.replace('%s', debug.browser.onLine);

        output += i18n.displayInfoTitle;
        output += i18n.screen.replace('%s', debug.screen.width).replace('%s', debug.screen.height);
        output += i18n.window.replace('%s', debug.window.innerWidth).replace('%s', debug.window.innerHeight);
        output += i18n.pixelRatio.replace('%s', debug.window.devicePixelRatio);
        output += i18n.colorDepth.replace('%s', debug.screen.colorDepth);

        output += i18n.wpInfoTitle;
        output += i18n.adminUrl.replace('%s', debug.wordpress.adminUrl);
        output += i18n.currentPage.replace('%s', debug.wordpress.currentPage);
        output += i18n.referrer.replace('%s', debug.wordpress.referrer);

        if (debug.plugin && !debug.plugin.error) {
            output += i18n.pluginInfoTitle;
            if (debug.plugin.settings) {
                output += i18n.settingsTitle;
                Object.keys(debug.plugin.settings).forEach(key => {
                    let value = debug.plugin.settings[key];
                    // Hide sensitive information
                    if (key.includes('key') || key.includes('token') || key.includes('secret')) {
                        value = value ? i18n.configured : i18n.notSet;
                    }
                    output += `  ${key}: ${value}\n`;
                });
                output += '\n';
            }

            if (debug.plugin.database) {
                output += i18n.dbTablesTitle;
                Object.keys(debug.plugin.database).forEach(table => {
                    const data = debug.plugin.database[table];
                    const exists = data.exists ? i18n.tableExists : i18n.tableMissing;
                    const rows = i18n.tableRows.replace('%d', data.rows || 0);
                    output += `  ${table}: ${exists} (${rows})\n`;
                });
                output += '\n';
            }

            if (debug.plugin.booking_system) {
                output += i18n.bookingSystemStatusTitle;
                Object.keys(debug.plugin.booking_system).forEach(component => {
                    const status = debug.plugin.booking_system[component];
                    output += `  ${component}: ${status}\n`;
                });
                output += '\n';
            }

            if (debug.plugin.errors && debug.plugin.errors.length > 0) {
                output += i18n.recentErrorsTitle;
                debug.plugin.errors.forEach(error => {
                    output += `  ${error}\n`;
                });
                output += '\n';
            }
        } else if (debug.plugin?.error) {
            output += i18n.pluginInfoTitle;
            output += i18n.pluginInfoError.replace('%s', debug.plugin.error);
        }

        return output;
    }
});

// Use the properly localized AJAX URL
var ajaxurl = aiohm_booking_mvp_admin_help_ajax.ajax_url;