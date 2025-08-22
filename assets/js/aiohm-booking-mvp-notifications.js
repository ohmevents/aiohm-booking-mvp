/**
 * AIOHM Booking MVP - Notification Module JavaScript
 * Handles template tabs, SMTP testing, and template management
 */

import $ from 'jquery';

$(function() {
    
    // Initialize notification module
    initNotificationModule();
    
    function initNotificationModule() {
        initTemplateTabs();
        initSMTPToggle();
        initSMTPTest();
        initTemplateActions();
    }
    
    /**
     * Initialize template tab functionality
     */
    function initTemplateTabs() {
        $('.aiohm-tab-btn').on('click', function() {
            const templateKey = $(this).data('template');
            
            // Update tab buttons
            $('.aiohm-tab-btn').removeClass('active');
            $(this).addClass('active');
            
            // Update template content
            $('.aiohm-template-content').removeClass('active');
            $(`.aiohm-template-content[data-template="${templateKey}"]`).addClass('active');
        });
    }
    
    /**
     * Initialize SMTP enable/disable toggle
     */
    function initSMTPToggle() {
        $('input[name="smtp_enabled"]').on('change', function() {
            const isEnabled = $(this).is(':checked');
            const smtpFields = $('.aiohm-smtp-fields');
            
            if (isEnabled) {
                smtpFields.css('opacity', '1').find('input, select').prop('disabled', false);
            } else {
                smtpFields.css('opacity', '0.5').find('input, select').prop('disabled', true);
            }
        }).trigger('change');
    }
    
    /**
     * Initialize SMTP connection testing
     */
    function initSMTPTest() {
        $('.aiohm-test-smtp-btn').on('click', function() {
            const button = $(this);
            const resultDiv = $('.aiohm-test-result');
            
            // Collect SMTP settings
            const smtpData = {
                action: 'aiohm_test_smtp_connection',
                nonce: aiohm_booking_mvp_admin.nonce,
                host: $('input[name="smtp_host"]').val(),
                port: $('input[name="smtp_port"]').val(),
                username: $('input[name="smtp_username"]').val(),
                password: $('input[name="smtp_password"]').val(),
                encryption: $('select[name="smtp_encryption"]').val(),
                from_email: $('input[name="from_email"]').val(),
                from_name: $('input[name="from_name"]').val()
            };
            
            // Validate required fields
            if (!smtpData.host || !smtpData.port || !smtpData.username || !smtpData.password || !smtpData.from_email) {
                showTestResult('Please fill in all SMTP fields before testing.', 'error');
                return;
            }
            
            // Show loading state
            button.addClass('aiohm-notification-loading').prop('disabled', true);
            resultDiv.hide();
            
            // Send AJAX request
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: smtpData,
                timeout: 30000,
                success: function(response) {
                    if (response.success) {
                        showTestResult(response.data.message || 'SMTP connection successful!', 'success');
                    } else {
                        showTestResult(response.data || 'SMTP connection failed. Please check your settings.', 'error');
                    }
                },
                error: function() {
                    showTestResult('Connection test failed. Please try again.', 'error');
                },
                complete: function() {
                    button.removeClass('aiohm-notification-loading').prop('disabled', false);
                }
            });
        });
    }
    
    /**
     * Show SMTP test result
     */
    function showTestResult(message, type) {
        const resultDiv = $('.aiohm-test-result');
        resultDiv.removeClass('success error')
               .addClass(type)
               .text(message)
               .fadeIn();
               
        // Auto-hide after 5 seconds
        setTimeout(function() {
            resultDiv.fadeOut();
        }, 5000);
    }
    
    /**
     * Initialize template action buttons
     */
    function initTemplateActions() {
        // Preview template
        $('.aiohm-preview-template-btn').on('click', function() {
            const templateKey = $(this).data('template');
            const templateContent = $(this).closest('.aiohm-template-content');
            const subject = templateContent.find('input[name="template_subject"]').val();
            const content = templateContent.find('textarea[name="template_content"]').val();
            
            showTemplatePreview(templateKey, subject, content);
        });
        
        // Reset template
        $('.aiohm-reset-template-btn').on('click', function() {
            const templateKey = $(this).data('template');
            
            if (confirm('Are you sure you want to reset this template to default? This action cannot be undone.')) {
                resetTemplate(templateKey);
            }
        });
    }
    
    /**
     * Show template preview in modal
     */
    function showTemplatePreview(templateKey, subject, content) {
        // Replace sample variables for preview
        const sampleData = {
            '{booking_id}': 'BK-2024-001',
            '{customer_name}': 'John Doe',
            '{customer_email}': 'john@example.com',
            '{check_in}': 'March 15, 2024',
            '{check_out}': 'March 18, 2024',
            '{total_amount}': '$350.00',
            '{rooms}': 'Deluxe Room #1',
            '{site_name}': 'Your Hotel'
        };
        
        let previewSubject = subject;
        let previewContent = content;
        
        // Replace variables
        Object.keys(sampleData).forEach(variable => {
            const value = sampleData[variable];
            previewSubject = previewSubject.replace(new RegExp(escapeRegExp(variable), 'g'), value);
            previewContent = previewContent.replace(new RegExp(escapeRegExp(variable), 'g'), value);
        });
        
        // Create modal
        const modal = $(`
            <div class="aiohm-modal-overlay">
                <div class="aiohm-modal">
                    <div class="aiohm-modal-header">
                        <h3>Email Preview: ${templateKey.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}</h3>
                        <button class="aiohm-modal-close">&times;</button>
                    </div>
                    <div class="aiohm-modal-body">
                        <div class="aiohm-email-preview">
                            <div class="aiohm-email-header">
                                <strong>Subject:</strong> ${previewSubject}
                            </div>
                            <div class="aiohm-email-content">
                                ${previewContent}
                            </div>
                        </div>
                    </div>
                    <div class="aiohm-modal-footer">
                        <button class="button aiohm-modal-close">Close Preview</button>
                    </div>
                </div>
            </div>
        `);
        
        // Add styles for email preview
        modal.find('.aiohm-email-preview').css({
            'border': '1px solid #ddd',
            'border-radius': '5px',
            'background': '#fff'
        });
        
        modal.find('.aiohm-email-header').css({
            'padding': '15px',
            'background': '#f8f9fa',
            'border-bottom': '1px solid #dee2e6',
            'font-family': 'Arial, sans-serif'
        });
        
        modal.find('.aiohm-email-content').css({
            'padding': '20px',
            'font-family': 'Arial, sans-serif',
            'line-height': '1.6'
        });
        
        // Show modal
        $('body').append(modal);
        
        // Handle modal close
        modal.find('.aiohm-modal-close').on('click', function() {
            modal.remove();
        });
        
        modal.on('click', function(e) {
            if (e.target === modal[0]) {
                modal.remove();
            }
        });
    }
    
    /**
     * Reset template to default
     */
    function resetTemplate(templateKey) {
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'aiohm_reset_email_template',
                nonce: aiohm_booking_mvp_admin.nonce,
                template_key: templateKey
            },
            success: function(response) {
                if (response.success) {
                    // Reload page to show reset template
                    location.reload();
                } else {
                    alert('Failed to reset template. Please try again.');
                }
            },
            error: function() {
                alert('Error resetting template. Please try again.');
            }
        });
    }
    
    /**
     * Escape special regex characters
     */
    function escapeRegExp(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }
    
    /**
     * Form validation for template saving
     */
    $('.aiohm-template-content form').on('submit', function() {
        const subject = $(this).find('input[name="template_subject"]').val().trim();
        const content = $(this).find('textarea[name="template_content"]').val().trim();
        
        if (!subject) {
            alert('Please enter a subject line for the email template.');
            return false;
        }
        
        if (!content) {
            alert('Please enter content for the email template.');
            return false;
        }
        
        return true;
    });
    
    // Handle dynamic form styling
    $('.form-control').on('focus', function() {
        $(this).closest('.aiohm-setting-row').addClass('focused');
    }).on('blur', function() {
        $(this).closest('.aiohm-setting-row').removeClass('focused');
    });
    
    // Auto-save draft functionality (optional)
    let autoSaveTimeout;
    $('.aiohm-template-textarea').on('input', function() {
        const textarea = $(this);
        
        // Clear existing timeout
        clearTimeout(autoSaveTimeout);
        
        // Set new timeout for auto-save draft
        autoSaveTimeout = setTimeout(function() {
            // Could implement auto-save draft functionality here
            // Auto-save draft for template
        }, 2000);
    });
});