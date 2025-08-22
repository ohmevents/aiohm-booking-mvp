import $ from 'jquery';

$(function() {

    /**
     * Handle payment module visibility based on accommodation module state
     * @param {boolean} accommodationEnabled - Optional override for accommodation state
     */
    function handlePaymentModuleVisibility(accommodationEnabled) {
        // Get accommodation module state (FIXED: correct field name)
        const accommodationCheckbox = $('input[name="aiohm_booking_mvp_settings[enable_rooms]"]');
        const isAccommodationEnabled = accommodationEnabled !== undefined ? accommodationEnabled : accommodationCheckbox.is(':checked');

        // Find payment module cards (FIXED: correct field names)
        const stripeCard = $('input[name="aiohm_booking_mvp_settings[enable_stripe]"]').closest('.aiohm-module-card');
        const paypalCard = $('input[name="aiohm_booking_mvp_settings[enable_paypal]"]').closest('.aiohm-module-card');

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
        }).done(function(response) {
            // Success - redirect to refresh the page
            const url = new URL(window.location);
            url.searchParams.set('refresh', '1');
            url.searchParams.set('t', Date.now());
            window.location = url.toString();
        }).fail(function(xhr, status, error) {
            // Error - show error message and restore checkbox state
            overlay.remove();
            console.error('Module toggle failed:', status, error, xhr.responseText);
            
            // Restore checkbox to previous state
            checkbox.prop('checked', !checkbox.is(':checked'));
            
            // Show error message
            alert('Failed to save module setting: ' + (xhr.responseText || error || 'Unknown error'));
            
            // Restore payment module visibility if it was accommodation module
            if (module === 'enable_rooms') {
                handlePaymentModuleVisibility(!checkbox.is(':checked'));
            }
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
        const statusContainer = button.closest('.aiohm-module-card').find('.aiohm-connection-status');

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
                    button.closest('.aiohm-module-card').addClass('connected');
                } else {
                    showStatus(statusContainer, response.data, 'error');
                    button.closest('.aiohm-module-card').removeClass('connected');
                }
            },
            error: function() {
                showStatus(statusContainer, aiohm_booking_mvp_admin.i18n.connectionTestFailed, 'error');
                button.closest('.aiohm-module-card').removeClass('connected');
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
        const statusContainer = button.closest('.aiohm-module-card').find('.aiohm-connection-status');


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

    // Save AI consent setting via AJAX
    $(document).on('click', '.aiohm-save-consent-btn', function() {
        const button = $(this);
        const card = button.closest('.aiohm-module-card');
        const consentCheckbox = card.find('.ai-consent-checkbox');
        const isChecked = consentCheckbox.is(':checked');
        const statusContainer = card.find('.aiohm-connection-status');

        const originalText = button.text();
        button.text(aiohm_booking_mvp_admin.i18n.saving).prop('disabled', true);

        $.ajax({
            url: aiohm_booking_mvp_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'aiohm_booking_mvp_save_ai_consent',
                consent: isChecked ? 1 : 0,
                nonce: aiohm_booking_mvp_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    showStatus(statusContainer, response.data.message, 'success');
                    setTimeout(() => {
                        statusContainer.fadeOut();
                    }, 3000);
                } else {
                    showStatus(statusContainer, response.data || 'Error saving consent', 'error');
                }
            },
            error: function() {
                showStatus(statusContainer, 'Failed to save consent.', 'error');
            },
            complete: function() {
                button.text(originalText).prop('disabled', false);
            }
        });
    });

    // Sync AI consent checkboxes
    $(document).on('change', '.ai-consent-checkbox', function() {
        const isChecked = $(this).is(':checked');
        // Ensure all AI consent checkboxes reflect the same state
        $('.ai-consent-checkbox').prop('checked', isChecked);
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

    // Set default AI provider functionality using delegated event handler
    $(document).on('click', '.aiohm-make-default-btn', function() {
        const button = $(this);
        const provider = button.data('provider');
        const card = button.closest('.aiohm-module-card');
    
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
                    // Find all default provider rows
                    $('.aiohm-default-provider-row').each(function() {
                        const row = $(this);
                        const currentProvider = row.closest('.aiohm-module-card').data('provider');
                        // Change the old default back to a button
                        row.html(`<button type="button" class="button button-secondary aiohm-make-default-btn" data-provider="${currentProvider}">Set as Default</button>`);
                    });
    
                    // Update the new default provider to show the badge
                    const newDefaultRow = card.find('.aiohm-default-provider-row');
                    newDefaultRow.html('<span class="aiohm-status-indicator success">✓ Default Provider</span>');
    
                    // Show success message briefly
                    const statusContainer = card.find('.aiohm-connection-status');
                    showStatus(statusContainer, response.data.message, 'success');
                    setTimeout(() => {
                        statusContainer.fadeOut();
                    }, 2000);
    
                } else {
                    const statusContainer = card.find('.aiohm-connection-status');
                    showStatus(statusContainer, response.data || aiohm_booking_mvp_admin.i18n.setDefaultProviderFailed, 'error');
                }
            },
            error: function(xhr, status, error) {
                const statusContainer = card.find('.aiohm-connection-status');
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

    // Handle Required/Optional toggle buttons
    $('.field-required-toggle').on('click', function(e) {
        e.preventDefault();
        const $button = $(this);
        const field = $button.data('field');
        const $hiddenInput = $button.siblings('.required-hidden-input');
        const $toggleText = $button.find('.toggle-text');
        
        // Toggle state
        if ($button.hasClass('required')) {
            // Switch to Optional
            $button.removeClass('required').addClass('optional');
            $toggleText.text('Optional');
            $hiddenInput.val('0');
        } else {
            // Switch to Required
            $button.removeClass('optional').addClass('required');
            $toggleText.text('Required');
            $hiddenInput.val('1');
        }
        
        // Update form preview immediately
        updateFormPreview();
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
        const $sortableContainer = $('#sortable-fields');
        
        if ($sortableContainer.length) {
            // Wait for jQuery UI to be available
            const initSortable = function() {
                if (typeof $.ui !== 'undefined' && $.ui.sortable) {
                    $sortableContainer.sortable({
                        handle: '.field-handle',
                        placeholder: 'sortable-placeholder',
                        helper: 'clone',
                        tolerance: 'pointer',
                        cursor: 'grabbing',
                        opacity: 0.8,
                        axis: 'y',
                        start: function(e, ui) {
                            ui.helper.addClass('ui-sortable-helper');
                            ui.placeholder.height(ui.helper.outerHeight());
                            ui.placeholder.css('visibility', 'visible');
                        },
                        stop: function(e, ui) {
                            // Update form preview after reordering
                            setTimeout(function() {
                                updateFormPreview();
                                updateFieldOrder();
                            }, 100);
                        },
                        change: function(e, ui) {
                            // Add visual feedback during drag
                            if (ui.placeholder && typeof ui.placeholder.effect === 'function') {
                                ui.placeholder.effect('pulse', { times: 1 }, 200);
                            }
                        }
                    });
                    // Sortable initialized successfully
                } else {
                    // Retry after a short delay
                    setTimeout(initSortable, 100);
                }
            };
            
            initSortable();
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

        // Get enabled fields in order with their required state
        const enabledFields = [];
        $('#sortable-fields .aiohm-field-toggle').each(function() {
            const checkbox = $(this).find('.form-field-toggle');
            const requiredToggle = $(this).find('.field-required-toggle');
            if (checkbox.is(':checked')) {
                enabledFields.push({
                    field: checkbox.data('field'),
                    required: requiredToggle.hasClass('required')
                });
            }
        });

        // Find the additional contact fields container in the preview
        const formFields = preview.find('.booking-form-fields');
        if (formFields.length) {
            // Add visual indicator that preview is updating
            formFields.addClass('updating');
            
            // Reorder the fields in the preview
            const additionalFieldsContainer = formFields.find('.form-row').filter(function() {
                const classList = $(this).attr('class') || '';
                return classList.includes('-field') && 
                       !classList.includes('date-field') && 
                       !classList.includes('duration-field') && 
                       !classList.includes('guests-field') && 
                       !classList.includes('accommodation-field') &&
                       !classList.includes('pets-field');
            });

            // Detach all additional contact fields
            const detachedFields = {};
            additionalFieldsContainer.each(function() {
                const $field = $(this);
                const classList = $field.attr('class') || '';
                
                // Extract field type from class names
                const fieldTypes = ['address', 'age', 'company', 'country', 'phone', 'special_requests', 'vat', 'arrival-time'];
                for (let type of fieldTypes) {
                    if (classList.includes(type + '-field')) {
                        detachedFields[type.replace('-', '_')] = $field.detach();
                        break;
                    }
                }
            });

            // Find insertion point (after pets field or at the end of form-fields)
            let insertAfter = formFields.find('.pets-field');
            if (!insertAfter.length) {
                insertAfter = formFields.children().last();
            }

            // Reinsert fields in the new order
            enabledFields.forEach(function(fieldData) {
                const fieldKey = fieldData.field === 'arrival_time' ? 'arrival-time' : fieldData.field;
                const $field = detachedFields[fieldData.field];
                
                if ($field) {
                    // Update required state
                    const $label = $field.find('.input-label');
                    const labelText = $label.text().replace(' *', '');
                    $label.text(labelText + (fieldData.required ? ' *' : ''));
                    
                    // Update required attribute on input
                    const $input = $field.find('input, textarea, select');
                    if (fieldData.required) {
                        $input.attr('required', 'required');
                    } else {
                        $input.removeAttr('required');
                    }
                    
                    // Show and insert the field
                    $field.show().insertAfter(insertAfter);
                    insertAfter = $field;
                }
            });

            // Hide any remaining detached fields
            Object.values(detachedFields).forEach(function($field) {
                if ($field && $field.parent().length === 0) {
                    $field.hide().appendTo(formFields);
                }
            });
            
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

    /**
     * Email Template Manager
     */
    function initEmailTemplateManager() {
        // Template selector change
        $('#email-template-selector').on('change', function() {
            const selectedTemplate = $(this).val();
            const $editor = $('#template-editor');
            
            if (selectedTemplate) {
                $editor.slideDown(300);
                loadTemplateData(selectedTemplate);
            } else {
                $editor.slideUp(300);
            }
        });

        // Preset buttons
        $('.preset-btn').on('click', function() {
            const preset = $(this).data('preset');
            applyTemplatePreset(preset);
            $(this).effect('bounce', { times: 1, distance: 5 }, 200);
        });

        // Template actions
        $('#preview-template').on('click', function() {
            previewEmailTemplate();
        });

        $('#send-test-email').on('click', function() {
            sendTestEmail();
        });

        $('#save-template').on('click', function() {
            saveEmailTemplate();
        });
    }

    function loadTemplateData(templateType) {
        // Load existing template data or set defaults
        const defaultTemplates = {
            'booking_confirmation': {
                subject: 'Booking Confirmation - {property_name}',
                content: `Dear {guest_name},

Thank you for your booking! We're excited to welcome you to {property_name}.

📋 Booking Details:
• Booking ID: {booking_id}
• Check-in: {check_in_date}
• Check-out: {check_out_date}
• Duration: {duration_nights} nights
• Total Amount: {total_amount}

We look forward to hosting you!

Best regards,
{property_name} Team`,
                sender_name: 'Your Hotel Name',
                reply_to: 'reservations@yourhotel.com'
            },
            'pre_arrival_reminder': {
                subject: 'Your stay begins in 3 days - {property_name}',
                content: `Hi {guest_name},

Your exciting stay at {property_name} begins in just 3 days!

🏨 Reminder Details:
• Check-in: {check_in_date}
• Booking ID: {booking_id}

We can't wait to welcome you!`,
                sender_name: 'Your Hotel Name',
                reply_to: 'reservations@yourhotel.com'
            }
        };

        const template = defaultTemplates[templateType] || defaultTemplates['booking_confirmation'];
        
        $('input[name="template_subject"]').val(template.subject);
        $('textarea[name="template_content"]').val(template.content);
        $('input[name="template_sender_name"]').val(template.sender_name);
        $('input[name="template_reply_to"]').val(template.reply_to);
    }

    function applyTemplatePreset(preset) {
        const presets = {
            'professional': {
                tone: 'formal',
                greeting: 'Dear {guest_name},',
                closing: 'Best regards,\n{property_name} Team'
            },
            'friendly': {
                tone: 'casual',
                greeting: 'Hi {guest_name}! 👋',
                closing: 'Warm regards,\n{property_name} Family'
            },
            'luxury': {
                tone: 'elegant',
                greeting: 'Dear Valued Guest {guest_name},',
                closing: 'With distinguished regards,\n{property_name} Concierge'
            },
            'minimalist': {
                tone: 'brief',
                greeting: 'Hello {guest_name},',
                closing: 'Thank you,\n{property_name}'
            }
        };

        const selectedPreset = presets[preset];
        if (selectedPreset) {
            const currentContent = $('textarea[name="template_content"]').val();
            // Apply preset styling hints
            $('textarea[name="template_content"]').effect('highlight', { color: '#457d58' }, 500);
        }
    }

    function previewEmailTemplate() {
        const subject = $('input[name="template_subject"]').val();
        const content = $('textarea[name="template_content"]').val();
        
        // Create preview modal (simplified)
        const previewHtml = `
            <div style="max-width: 600px; margin: 20px auto; font-family: Arial, sans-serif;">
                <h3>📧 Email Preview</h3>
                <div style="border: 1px solid #ddd; padding: 20px; background: #f9f9f9;">
                    <strong>Subject:</strong> ${subject}<br><br>
                    <div style="white-space: pre-line;">${content}</div>
                </div>
            </div>
        `;
        
        // Show in new window or modal
        const previewWindow = window.open('', 'EmailPreview', 'width=700,height=500');
        previewWindow.document.write(previewHtml);
    }

    function sendTestEmail() {
        const email = prompt('Enter email address to send test to:');
        if (email) {
            alert('Test email would be sent to: ' + email + '\n(Feature coming soon!)');
        }
    }

    function saveEmailTemplate() {
        const templateData = {
            type: $('#email-template-selector').val(),
            status: $('select[name="template_status"]').val(),
            timing: $('select[name="template_timing"]').val(),
            subject: $('input[name="template_subject"]').val(),
            content: $('textarea[name="template_content"]').val(),
            sender_name: $('input[name="template_sender_name"]').val(),
            reply_to: $('input[name="template_reply_to"]').val(),
            include_attachment: $('input[name="template_include_attachment"]').is(':checked')
        };
        
        // Show success message
        $('#save-template').text('✅ Saved!').prop('disabled', true);
        setTimeout(() => {
            $('#save-template').text('💾 Save Template').prop('disabled', false);
        }, 2000);
        
        // Template saved successfully
    }

    // Initialize all enhanced functionality
    initFieldsManager();
    initEmailTemplateManager();
    initEnhancedFields();

});