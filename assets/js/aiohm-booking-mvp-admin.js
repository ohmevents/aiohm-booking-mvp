jQuery(document).ready(function($) {

    /**
     * Handle payment module visibility based on accommodation module state
     * @param {boolean} accommodationEnabled - Optional override for accommodation state
     */
    function handlePaymentModuleVisibility(accommodationEnabled) {
        // Get accommodation module state
        const accommodationCheckbox = $('input[name="aiohm_booking_settings[enable_rooms]"]');
        const isAccommodationEnabled = accommodationEnabled !== undefined ? accommodationEnabled : accommodationCheckbox.is(':checked');

        // Find payment module cards
        const stripeCard = $('input[name="aiohm_booking_settings[enable_stripe]"]').closest('.aiohm-module-card');
        const paypalCard = $('input[name="aiohm_booking_settings[enable_paypal]"]').closest('.aiohm-module-card');

        if (isAccommodationEnabled) {
            // Show payment modules when accommodation is enabled
            stripeCard.slideDown(300);
            paypalCard.slideDown(300);

            // Remove disabled state
            stripeCard.removeClass('payment-disabled');
            paypalCard.removeClass('payment-disabled');
        } else {
            // Hide payment modules when accommodation is disabled
            stripeCard.slideUp(300);
            paypalCard.slideUp(300);

            // Add disabled state
            stripeCard.addClass('payment-disabled');
            paypalCard.addClass('payment-disabled');

            // Optionally uncheck payment modules when hiding them
            stripeCard.find('input[type="checkbox"]').prop('checked', false);
            paypalCard.find('input[type="checkbox"]').prop('checked', false);
        }
    }

    // Initialize payment module visibility on page load
    handlePaymentModuleVisibility();

    // Auto-close admin notices after 5 seconds and ensure they stay in correct position
    $('.aiohm-auto-close-notice').each(function() {
        const notice = $(this);

        // Force the notice to stay in its intended position (inside form, not at top of page)
        if (notice.closest('form').length === 0) {
            // If notice is not inside a form, move it there
            const targetForm = $('form[method="post"]').first();
            if (targetForm.length) {
                notice.detach().insertAfter(targetForm.find('input[name*="nonce"]').first());
            }
        }

        setTimeout(function() {
            notice.fadeOut(500, function() {
                notice.remove();
            });
        }, 5000); // 5 seconds
    });

    // Module toggle: quick AJAX save and refresh
    $('.aiohm-toggle input[type="checkbox"]').on('change', function() {
        const checkbox = $(this);
        const module = checkbox.attr('name').match(/\[(.*?)\]/)[1];
        const value = checkbox.is(':checked') ? '1' : '0';

        // Handle accommodation module change - hide/show payment modules immediately
        if (module === 'enable_rooms') {
            handlePaymentModuleVisibility(value === '1');
        }

        const overlay = $(`<div class="aiohm-saving-overlay">${aiohm_booking_mvp_admin.i18n.applyingChanges}</div>`);
        $('body').append(overlay);

        $.ajax({
            url: aiohm_booking_mvp_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'aiohm_booking_mvp_toggle_module',
                nonce: aiohm_booking_mvp_admin.nonce,
                module: module,
                value: value
            }
        }).always(function(){
            const url = new URL(window.location);
            url.searchParams.set('refresh', '1');
            url.searchParams.set('t', Date.now());
            window.location = url.toString();
        });
    });

    // Show/Hide API key functionality
    $('.aiohm-show-hide-key').on('click', function() {
        const targetId = $(this).data('target');
        const input = $('#' + targetId);
        const icon = $(this).find('.dashicons');

        if (input.attr('type') === 'password') {
            input.attr('type', 'text');
            icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
        } else {
            input.attr('type', 'password');
            icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
        }
    });

    // Test API key functionality
    $('.aiohm-test-api-key').on('click', function() {
        const button = $(this);
        const provider = button.data('provider');
        const targetId = button.data('target');
        const apiKey = $('#' + targetId).val().trim();
        const statusContainer = button.closest('.aiohm-provider-card').find('.aiohm-connection-status');

        if (!apiKey) {
            showStatus(statusContainer, aiohm_booking_mvp_admin.i18n.enterApiKey, 'error');
            return;
        }

        // Update button state
        const originalText = button.text();
        button.text(aiohm_booking_mvp_admin.i18n.testing).prop('disabled', true);
        showStatus(statusContainer, aiohm_booking_mvp_admin.i18n.testingConnection, 'testing');

        // Make AJAX request
        $.ajax({
            url: aiohm_booking_mvp_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'aiohm_booking_mvp_test_api_key',
                provider: provider,
                api_key: apiKey,
                nonce: aiohm_booking_mvp_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    showStatus(statusContainer, response.data, 'success');
                    button.closest('.aiohm-provider-card').addClass('connected');
                } else {
                    showStatus(statusContainer, response.data, 'error');
                    button.closest('.aiohm-provider-card').removeClass('connected');
                }
            },
            error: function() {
                showStatus(statusContainer, aiohm_booking_mvp_admin.i18n.connectionTestFailed, 'error');
                button.closest('.aiohm-provider-card').removeClass('connected');
            },
            complete: function() {
                button.text(originalText).prop('disabled', false);
            }
        });
    });

    // Save API key functionality
    $('.aiohm-save-api-key').on('click', function() {
        const button = $(this);
        const provider = button.data('provider');
        const targetId = button.data('target');
        const apiKey = $('#' + targetId).val().trim();
        const statusContainer = button.closest('.aiohm-provider-card').find('.aiohm-connection-status');


        // Update button state
        const originalText = button.text();
        button.text(aiohm_booking_mvp_admin.i18n.saving).prop('disabled', true);

        // Make AJAX request
        $.ajax({
            url: aiohm_booking_mvp_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'aiohm_booking_mvp_save_api_key',
                provider: provider,
                api_key: apiKey,
                nonce: aiohm_booking_mvp_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    showStatus(statusContainer, response.data, 'success');
                    // Auto-hide success message after 3 seconds
                    setTimeout(() => {
                        statusContainer.fadeOut();
                    }, 3000);
                } else {
                    showStatus(statusContainer, response.data, 'error');
                }
            },
            error: function(xhr, status, error) {
                showStatus(statusContainer, aiohm_booking_mvp_admin.i18n.saveFailed, 'error');
            },
            complete: function() {
                button.text(originalText).prop('disabled', false);
            }
        });
    });

    // Helper function to show status messages
    function showStatus(container, message, type) {
        container.removeClass('success error testing')
                .addClass(type)
                .text(message)
                .show();
    }

    // Initialize connection status on page load
    $('.aiohm-provider-card').each(function() {
        const card = $(this);
        const input = card.find('input[type="password"]');

        if (input.val().trim()) {
            card.addClass('connected');
        }
    });

    // Auto-save on Enter key in API key inputs
    $('.aiohm-api-key-wrapper input[type="password"]').on('keypress', function(e) {
        if (e.which === 13) { // Enter key
            $(this).closest('.aiohm-provider-card').find('.aiohm-save-api-key').click();
        }
    });

    // Handle URL highlight parameter for accommodation quantity
    const urlParams = new URLSearchParams(window.location.search);
    const highlight = urlParams.get('highlight');

    if (highlight === 'accommodation-quantity') {
        setTimeout(() => {
            const quantitySelect = $('.accommodation-quantity-select');
            if (quantitySelect.length) {
                // Scroll to the element
                $('html, body').animate({
                    scrollTop: quantitySelect.offset().top - 100
                }, 1000);

                // Add highlight effect
                quantitySelect.closest('.aiohm-setting-row').addClass('highlight-pulse');

                // Remove highlight after animation
                setTimeout(() => {
                    quantitySelect.closest('.aiohm-setting-row').removeClass('highlight-pulse');
                }, 3000);
            }
        }, 500);
    }

    // Set default AI provider functionality
    $('.aiohm-default-badge').on('click', function() {
        const badge = $(this);
        const provider = badge.data('provider');
        const isActive = badge.hasClass('active');

        // Don't do anything if already active
        if (isActive) {
            return;
        }


        // Make AJAX request to set default provider
        $.ajax({
            url: aiohm_booking_mvp_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'aiohm_booking_mvp_set_default_provider',
                provider: provider,
                nonce: aiohm_booking_mvp_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Remove active state from all badges
                    $('.aiohm-default-badge').removeClass('active');
                    $('.aiohm-provider-card').removeClass('default-provider');
                    $('.aiohm-default-badge .default-active').remove();
                    $('.aiohm-default-badge .make-default').remove();

                    // Update all badges to show "Make Default"
                    $('.aiohm-default-badge').each(function() {
                        $(this).append('<span class="make-default">Make Default</span>');
                    });

                    // Set the clicked provider as active
                    badge.addClass('active');
                    badge.closest('.aiohm-provider-card').addClass('default-provider');
                    badge.find('.make-default').remove();
                    badge.append('<span class="default-active">✓ Default</span>');

                    // Show success message briefly
                    const statusContainer = badge.closest('.aiohm-provider-card').find('.aiohm-connection-status');
                    showStatus(statusContainer, response.data.message, 'success');
                    setTimeout(() => {
                        statusContainer.fadeOut();
                    }, 2000);

                } else {
                    const statusContainer = badge.closest('.aiohm-provider-card').find('.aiohm-connection-status');
                    showStatus(statusContainer, response.data || aiohm_booking_mvp_admin.i18n.setDefaultProviderFailed, 'error');
                }
            },
            error: function(xhr, status, error) {
                const statusContainer = badge.closest('.aiohm-provider-card').find('.aiohm-connection-status');
                showStatus(statusContainer, 'Failed to set default provider. Please try again.', 'error');
            }
        });
    });

    // Individual accommodation save functionality
    $('.aiohm-individual-save-btn').on('click', function() {
        const button = $(this);
        const accommodationIndex = button.data('accommodation-index');
        const accommodationItem = button.closest('.aiohm-accommodation-item');


        // Get all input values for this accommodation
        const accommodationData = {
            title: accommodationItem.find('input[name*="[title]"]').val().trim(),
            description: accommodationItem.find('textarea[name*="[description]"]').val().trim(),
            earlybird_price: accommodationItem.find('input[name*="[earlybird_price]"]').val().trim(),
            price: accommodationItem.find('input[name*="[price]"]').val().trim(),
            type: accommodationItem.find('select[name*="[type]"]').val()
        };


        // Validate required fields
        if (accommodationIndex === undefined || accommodationIndex === null) {
            alert(aiohm_booking_mvp_admin.i18n.invalidAccommodationIndex);
            return;
        }

        // Update button state
        const originalContent = button.html();
        button.addClass('saving').html(`<span class="dashicons dashicons-update"></span> ${aiohm_booking_mvp_admin.i18n.saving}`);

        // Make AJAX request
        $.ajax({
            url: aiohm_booking_mvp_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'aiohm_booking_mvp_save_individual_accommodation',
                accommodation_index: accommodationIndex,
                accommodation_data: accommodationData,
                nonce: aiohm_booking_mvp_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Show success state
                    button.removeClass('saving').addClass('success')
                          .html(`<span class="dashicons dashicons-yes-alt"></span> ${aiohm_booking_mvp_admin.i18n.saved}`);

                    // Reset to original state after 2 seconds
                    setTimeout(() => {
                        button.removeClass('success').html(originalContent);
                    }, 2000);


                    // Optionally update any type breakdown displays
                    updateAccommodationTypeDisplay();

                } else {
                    button.removeClass('saving').html(originalContent);
                    alert(aiohm_booking_mvp_admin.i18n.errorPrefix + response.data);
                }
            },
            error: function(xhr, status, error) {
                button.removeClass('saving').html(originalContent);
                alert(aiohm_booking_mvp_admin.i18n.saveFailed);
            }
        });
    });

    // Function to update accommodation type display (if on same page)
    function updateAccommodationTypeDisplay() {
        // This would refresh the type breakdown section if present
        // For now, we'll just reload the type counts if the section exists
        if ($('.aiohm-type-breakdown').length > 0) {
            // Could make an AJAX call to refresh just the type breakdown
            // For simplicity, we'll leave this for future enhancement
        }
    }

    // Auto-save on Enter key in accommodation inputs
    $('.aiohm-accommodation-item input, .aiohm-accommodation-item textarea, .aiohm-accommodation-item select').on('keypress', function(e) {
        if (e.which === 13 && !$(this).is('textarea')) { // Enter key, but not in textarea
            $(this).closest('.aiohm-accommodation-item').find('.aiohm-individual-save-btn').click();
        }
    });

    // Force page refresh after settings save: clean URL only
    if (window.location.search.includes('refresh=1')) {
        const url = new URL(window.location);
        url.searchParams.delete('refresh');
        window.history.replaceState({}, '', url);
    }

    // Add loading state to save button - Let form submit normally
    $('.aiohm-save-button').on('click', function(e) {
        const button = $(this);
        const originalText = button.val();

        // Show loading state
        button.val(aiohm_booking_mvp_admin.i18n.saving).prop('disabled', true);

        // Show loading overlay
        const overlay = $(`<div class="aiohm-saving-overlay">${aiohm_booking_mvp_admin.i18n.savingSettings}</div>`);
        $('body').append(overlay);

        // Allow the form to submit normally - don't prevent default
        // The handle_custom_settings_save() method will process it and redirect
    });

    // Form customization real-time preview (sync with shortcode vars)
    function hexToRgba(hex, alpha) {
        const m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
        if (!m) return `rgba(69,125,88,${alpha})`;
        const r = parseInt(m[1], 16);
        const g = parseInt(m[2], 16);
        const b = parseInt(m[3], 16);
        return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    }

    $('.form-color-input').on('input change', function() {
        const field = $(this).data('field');
        const color = $(this).val();
        const modern = $('#booking-form-preview .aiohm-booking-modern');
        if (!modern.length) return;

        if (field === 'primary') {
            modern.css('--ohm-primary', color);
            modern.css('--ohm-primary-hover', color);
            modern.css('--ohm-primary-light', hexToRgba(color, 0.1));
        } else if (field === 'text') {
            // Only change header title and subtitle within the preview
            const header = modern.find('.booking-header');
            header.find('.booking-title, .booking-subtitle').css('color', color);
        }
        // Secondary currently not used by frontend form; reserved for future
    });

    $('.form-field-toggle').on('change', function() {
        const field = $(this).data('field');
        const isChecked = $(this).is(':checked');
        const fieldElement = $('.' + field + '-field');

        if (isChecked) {
            fieldElement.show();
        } else {
            fieldElement.hide();
        }
    });

    // AI Table Query functionality
    $('#submit-ai-table-query').on('click', function() {
        const button = $(this);
        const question = $('#ai-table-query-input').val().trim();
        
        if (!question) {
            alert(aiohm_booking_mvp_admin.i18n.enterDbQuestion);
            return;
        }
        
        submitAITableQuery(question);
    });
    
    // Example query click handlers
    $('.aiohm-example-queries a').on('click', function(e) {
        e.preventDefault();
        const query = $(this).data('query');
        $('#ai-table-query-input').val(query);
        submitAITableQuery(query);
    });
    
    // Response action handlers
    $('#copy-ai-response').on('click', function() {
        const responseText = $('#ai-response-text').text();
        if (!responseText) {
            alert(aiohm_booking_mvp_admin.i18n.noResponseToCopy);
            return;
        }
        
        navigator.clipboard.writeText(responseText).then(function() {
            const button = $('#copy-ai-response');
            const originalText = button.html();
            button.html(`<span class="dashicons dashicons-yes-alt"></span> ${aiohm_booking_mvp_admin.i18n.copied}`);
            setTimeout(() => {
                button.html(originalText);
            }, 2000);
        }).catch(function() {
            // Fallback for older browsers
            const textArea = document.createElement('textarea');
            textArea.value = responseText;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            alert(aiohm_booking_mvp_admin.i18n.copiedToClipboard);
        });
    });
    
    $('#clear-ai-response').on('click', function() {
        $('#ai-table-response-area').slideUp(300);
        $('#ai-response-text').empty();
        $('#ai-table-query-input').val('').focus();
    });
    
    // Enter key support for textarea
    $('#ai-table-query-input').on('keypress', function(e) {
        if (e.which === 13 && !e.shiftKey) { // Enter without Shift
            e.preventDefault();
            $('#submit-ai-table-query').click();
        }
    });
    
    function submitAITableQuery(question) {
        const loadingIndicator = $('#ai-query-loading');
        const responseArea = $('#ai-table-response-area');
        const submitButton = $('#submit-ai-table-query');
        
        // Store original button text for restoration
        const originalButtonHtml = submitButton.html();
        
        // Show loading state
        loadingIndicator.show();
        responseArea.hide();
        submitButton.prop('disabled', true).text(aiohm_booking_mvp_admin.i18n.loading);
        
        // Make AJAX request
        $.ajax({
            url: aiohm_booking_mvp_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'aiohm_booking_mvp_ai_table_query',
                question: question,
                nonce: aiohm_booking_mvp_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Display response
                    const data = response.data;
                    $('#ai-response-text').html(formatAIResponse(data.answer));
                    $('.aiohm-provider-badge').text(`Powered by ${data.provider_used || 'AI'}`);
                    
                    // Show response area
                    loadingIndicator.hide();
                    responseArea.slideDown(300);
                } else {
                    // Show error
                    alert(aiohm_booking_mvp_admin.i18n.aiQueryError + (response.data || aiohm_booking_mvp_admin.i18n.unknownError));
                    loadingIndicator.hide();
                }
            },
            error: function(xhr, status, error) {                
                alert(aiohm_booking_mvp_admin.i18n.connectionError);
                loadingIndicator.hide();
            },
            complete: function() {
                submitButton.prop('disabled', false).html(originalButtonHtml);
            }
        });
    }
    
    function formatAIResponse(responseText) {
        if (!responseText) return aiohm_booking_mvp_admin.i18n.noResponse;
        
        // Convert line breaks to proper HTML
        let formatted = responseText.replace(/\n\n/g, '</p><p>').replace(/\n/g, '<br>');
        
        // Wrap in paragraph tags if not already formatted
        if (!formatted.includes('<p>') && !formatted.includes('<br>')) {
            formatted = '<p>' + formatted + '</p>';
        } else if (formatted.includes('<br>') && !formatted.startsWith('<p>')) {
            formatted = '<p>' + formatted + '</p>';
        }
        
        // Style headers (lines ending with colon)
        formatted = formatted.replace(/([^<>\n]+:)(<br>|$)/g, '<strong>$1</strong>$2');
        
        // Style numbered lists
        formatted = formatted.replace(/(\d+\.\s[^<\n]+)/g, '<strong>$1</strong>');
        
        return formatted;
    }

    // AI Order Query functionality (for orders page)
    $('#submit-ai-order-query').on('click', function() {
        const button = $(this);
        const question = $('#ai-order-query-input').val().trim();
        
        if (!question) {
            alert(aiohm_booking_mvp_admin.i18n.enterOrderQuestion);
            return;
        }
        
        submitAIOrderQuery(question);
    });
    
    // Order-specific example query click handlers
    $('.aiohm-example-queries a[data-query]').on('click', function(e) {
        e.preventDefault();
        const query = $(this).data('query');
        
        // Check if we're on orders page or calendar page
        if ($('#ai-order-query-input').length) {
            $('#ai-order-query-input').val(query);
            submitAIOrderQuery(query);
        } else if ($('#ai-table-query-input').length) {
            $('#ai-table-query-input').val(query);
            submitAITableQuery(query);
        }
    });
    
    // Order response action handlers
    $('#copy-ai-order-response').on('click', function() {
        const responseText = $('#ai-order-response-text').text();
        if (!responseText) {
            alert(aiohm_booking_mvp_admin.i18n.noResponseToCopy);
            return;
        }
        
        navigator.clipboard.writeText(responseText).then(function() {
            const button = $('#copy-ai-order-response');
            const originalText = button.html();
            button.html(`<span class="dashicons dashicons-yes-alt"></span> ${aiohm_booking_mvp_admin.i18n.copied}`);
            setTimeout(() => {
                button.html(originalText);
            }, 2000);
        }).catch(function() {
            // Fallback for older browsers
            const textArea = document.createElement('textarea');
            textArea.value = responseText;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            alert(aiohm_booking_mvp_admin.i18n.copiedToClipboard);
        });
    });
    
    $('#clear-ai-order-response').on('click', function() {
        $('#ai-order-response-area').slideUp(300);
        $('#ai-order-response-text').empty();
        $('#ai-order-query-input').val('').focus();
    });
    
    // Enter key support for order queries
    $('#ai-order-query-input').on('keypress', function(e) {
        if (e.which === 13 && !e.shiftKey) { // Enter without Shift
            e.preventDefault();
            $('#submit-ai-order-query').click();
        }
    });
    
    function submitAIOrderQuery(question) {
        const loadingIndicator = $('#ai-order-query-loading');
        const responseArea = $('#ai-order-response-area');
        const submitButton = $('#submit-ai-order-query');
        
        // Store original button text for restoration
        const originalButtonHtml = submitButton.html();
        
        // Show loading state
        loadingIndicator.show();
        responseArea.hide();
        submitButton.prop('disabled', true).text(aiohm_booking_mvp_admin.i18n.loading);
        
        // Make AJAX request
        $.ajax({
            url: aiohm_booking_mvp_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'aiohm_booking_mvp_ai_order_query',
                question: question,
                nonce: aiohm_booking_mvp_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Display response
                    const data = response.data;
                    $('#ai-order-response-text').html(formatAIResponse(data.answer));
                    $('.aiohm-provider-badge').text(`Powered by ${data.provider_used || 'AI'}`);
                    
                    // Show response area
                    loadingIndicator.hide();
                    responseArea.slideDown(300);
                } else {
                    // Show error
                    alert(aiohm_booking_mvp_admin.i18n.aiQueryError + (response.data || aiohm_booking_mvp_admin.i18n.unknownError));
                    loadingIndicator.hide();
                }
            },
            error: function(xhr, status, error) {                
                alert(aiohm_booking_mvp_admin.i18n.connectionError);
                loadingIndicator.hide();
            },
            complete: function() {
                submitButton.prop('disabled', false).html(originalButtonHtml);
            }
        });
    }
    
    // Handle delete confirmation links
    $(document).on('click', '.aiohm-delete-order', function(e) {
        const confirmMessage = $(this).data('confirm');
        if (confirmMessage && !confirm(confirmMessage)) {
            e.preventDefault();
            return false;
        }
    });

    /**
     * Drag and Drop Fields Management
     */
    function initFieldsManager() {
        // Initialize sortable fields
        if ($('#sortable-fields').length && typeof $.ui !== 'undefined' && $.ui.sortable) {
            $('#sortable-fields').sortable({
                handle: '.field-handle',
                placeholder: 'ui-sortable-placeholder aiohm-field-toggle',
                helper: 'clone',
                tolerance: 'pointer',
                cursor: 'grabbing',
                opacity: 0.8,
                start: function(e, ui) {
                    ui.helper.addClass('ui-sortable-helper');
                    ui.placeholder.height(ui.helper.outerHeight());
                },
                stop: function(e, ui) {
                    // Update form preview after reordering
                    updateFormPreview();
                    
                    // Store field order in a hidden field for form submission
                    updateFieldOrder();
                },
                change: function(e, ui) {
                    // Add visual feedback during drag
                    ui.placeholder.effect('pulse', { times: 1 }, 200);
                }
            });
        }

        // Handle field toggle changes
        $('.form-field-toggle').on('change', function() {
            const fieldItem = $(this).closest('.aiohm-field-toggle');
            const isEnabled = $(this).is(':checked');
            const mandatoryToggle = fieldItem.find('.mandatory-toggle');
            
            // Add visual feedback
            if (isEnabled) {
                fieldItem.addClass('field-enabled').effect('highlight', { color: '#457d58' }, 500);
                mandatoryToggle.slideDown(200);
            } else {
                fieldItem.removeClass('field-enabled');
                mandatoryToggle.slideUp(200);
                // Uncheck mandatory when field is disabled
                fieldItem.find('.mandatory-checkbox').prop('checked', false);
            }
            
            // Update form preview
            updateFormPreview();
        });

        // Handle mandatory toggle changes
        $('.mandatory-checkbox').on('change', function() {
            const fieldItem = $(this).closest('.aiohm-field-item');
            const isRequired = $(this).is(':checked');
            
            // Add visual feedback
            if (isRequired) {
                fieldItem.addClass('field-required').effect('highlight', { color: '#ff6b35' }, 300);
            } else {
                fieldItem.removeClass('field-required');
            }
            
            // Update form preview
            updateFormPreview();
        });

        // Initialize field states on page load
        $('.form-field-toggle').each(function() {
            const fieldItem = $(this).closest('.aiohm-field-toggle');
            const isEnabled = $(this).is(':checked');
            const mandatoryToggle = fieldItem.find('.mandatory-toggle');
            
            if (isEnabled) {
                fieldItem.addClass('field-enabled');
                mandatoryToggle.show();
            } else {
                mandatoryToggle.hide();
            }
        });

        $('.mandatory-checkbox').each(function() {
            const fieldItem = $(this).closest('.aiohm-field-item');
            const isRequired = $(this).is(':checked');
            
            if (isRequired) {
                fieldItem.addClass('field-required');
            }
        });
    }

    /**
     * Update Form Preview
     */
    function updateFormPreview() {
        const preview = $('#booking-form-preview');
        if (!preview.length) return;

        // Get enabled fields in order
        const enabledFields = [];
        $('#sortable-fields .aiohm-field-toggle').each(function() {
            const checkbox = $(this).find('.form-field-toggle');
            if (checkbox.is(':checked')) {
                enabledFields.push(checkbox.data('field'));
            }
        });

        // Update preview form fields (simplified version)
        const formFields = preview.find('.booking-form-fields');
        if (formFields.length) {
            // Add visual indicator that preview is updating
            formFields.addClass('updating');
            
            setTimeout(() => {
                // Remove updating indicator
                formFields.removeClass('updating');
                
                // Add a subtle flash effect to show the update
                formFields.effect('highlight', { color: '#457d58' }, 300);
            }, 200);
        }
    }

    /**
     * Store field order for form submission
     */
    function updateFieldOrder() {
        const fieldOrder = [];
        $('#sortable-fields .aiohm-field-toggle').each(function() {
            fieldOrder.push($(this).data('field'));
        });
        
        // Update field order in hidden input
        const orderField = $('#field-order-input');
        if (orderField.length) {
            orderField.val(fieldOrder.join(','));
        }
    }

    /**
     * Enhanced setting field interactions
     */
    function initEnhancedFields() {
        // Add focus effects to form controls
        $('.form-control, .enhanced-select').on('focus', function() {
            $(this).closest('.aiohm-setting-row').addClass('field-focused');
        }).on('blur', function() {
            $(this).closest('.aiohm-setting-row').removeClass('field-focused');
        });

        // Add change effects to settings
        $('.form-control, .enhanced-select').on('change', function() {
            $(this).closest('.aiohm-setting-row').effect('highlight', { color: '#457d58' }, 300);
            
            // Update preview if it exists
            updateFormPreview();
        });
    }

    // Initialize all enhanced functionality
    initFieldsManager();
    initEnhancedFields();

});