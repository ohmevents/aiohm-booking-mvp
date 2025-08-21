/**
 * AIOHM Booking MVP Calendar - Professional Calendar Management System
 * Handles calendar cell interactions, status management, and filtering functionality
 */
jQuery(document).ready(function($) {

    // Calendar Configuration Constants
    const CALENDAR_CONFIG = {
        SELECTORS: {
            calendarCells: '.aiohm-date-first-part, .aiohm-date-second-part',
            simpleMenu: '.aiohm-simple-menu',
            statusFilter: '#aiohm-calendar-status-filter',
            searchButton: '#aiohm-calendar-search-btn',
            resetButton: '#aiohm-calendar-reset-btn',
            bookingForms: {
                booking: '#aiohm-booking-sync-form',
                airbnb: '#aiohm-airbnb-sync-form'
            },
            syncButtons: {
                booking: '#aiohm-sync-booking-btn',
                airbnb: '#aiohm-sync-airbnb-btn'
            }
        },
        CLASSES: {
            statusClasses: 'aiohm-date-free aiohm-date-booked aiohm-date-pending aiohm-date-external aiohm-date-blocked aiohm-date-room-locked',
            dimFilter: 'aiohm-filtered-dim',
            editableCell: 'aiohm-editable-cell'
        },
        MESSAGES: {
            notEditable: aiohm_booking_mvp_admin.i18n.cellNotEditable,
            activeBooking: aiohm_booking_mvp_admin.i18n.activeBookingWarning,
            updateError: aiohm_booking_mvp_admin.i18n.updateError,
            connectionError: aiohm_booking_mvp_admin.i18n.saveSettingsError,
            syncError: aiohm_booking_mvp_admin.i18n.syncError
        },
        STATUSES: {
            free: { label: aiohm_booking_mvp_admin.i18n.statusFree, title: aiohm_booking_mvp_admin.i18n.statusAvailable },
            booked: { label: aiohm_booking_mvp_admin.i18n.statusBooked, title: aiohm_booking_mvp_admin.i18n.statusBooked },
            pending: { label: aiohm_booking_mvp_admin.i18n.statusPending, title: aiohm_booking_mvp_admin.i18n.statusPending },
            external: { label: aiohm_booking_mvp_admin.i18n.statusExternal, title: aiohm_booking_mvp_admin.i18n.statusExternal },
            blocked: { label: aiohm_booking_mvp_admin.i18n.statusBlocked, title: aiohm_booking_mvp_admin.i18n.statusBlocked }
        }
    };

    // Initialize Calendar System
    initializeCalendarSystem();

    /**
     * Initialize the complete calendar system with all event handlers
     */
    function initializeCalendarSystem() {
        initializeCellClickHandlers();
        initializeFilterSystem();
        initializeSyncModules();
        initializeGlobalEventHandlers();
        // Align left accommodations with right date rows
        setTimeout(alignCalendarLayout, 50);
        $(window).on('resize', debounce(alignCalendarLayout, 100));
    }

    /**
     * Initialize cell click handlers for calendar editing
     */
    function initializeCellClickHandlers() {
        $(document).on('click', CALENDAR_CONFIG.SELECTORS.calendarCells, handleCellClick);
    }

    /**
     * Handle calendar cell click events
     */
    function handleCellClick(event) {
        event.preventDefault();
        event.stopPropagation();

        const cellElement = $(this);
        const cellData = extractCellData(cellElement);

        if (!validateCellEditable(cellElement, cellData)) {
            return;
        }

        if (hasRealActiveBooking(cellElement)) {
            alert(CALENDAR_CONFIG.MESSAGES.activeBooking);
            return;
        }

        const customPrice = cellElement.data('price') || '';
        const currentStatus = determineCurrentStatus(cellElement);
        displayStatusMenu(cellElement, cellData.roomId, cellData.date, currentStatus, customPrice);
    }

    /**
     * Extract relevant data from a calendar cell
     */
    function extractCellData(cellElement) {
        return {
            roomId: cellElement.data('room-id'),
            date: cellElement.data('date'),
            isEditable: cellElement.data('editable')
        };
    }

    /**
     * Validate if a cell can be edited
     */
    function validateCellEditable(cellElement, cellData) {
        if (!cellData.isEditable) {
            alert(CALENDAR_CONFIG.MESSAGES.notEditable);
            return false;
        }

        if (!cellData.roomId || !cellData.date) {
            return false;
        }

        return true;
    }

    /**
     * Check if cell has real active booking (not admin-set status)
     */
    function hasRealActiveBooking(cellElement) {
        const hasRoomLocked = cellElement.hasClass('aiohm-date-room-locked');
        const hasCheckInOut = cellElement.hasClass('aiohm-date-check-in') ||
                              cellElement.hasClass('aiohm-date-check-out');
        const hasBookingLinks = cellElement.find('a.aiohm-booking-link, a.aiohm-silent-link-to-booking').length > 0;

        return hasRoomLocked && (hasCheckInOut || hasBookingLinks);
    }

    /**
     * Display status selection menu for a calendar cell
     */
    function displayStatusMenu(cellElement, roomId, date, currentStatus, customPrice) {
        removeExistingMenus();

        const hasActiveBooking = hasBookingButNotBlocked(cellElement);

        const menuElement = createStatusMenuElement(roomId, date, currentStatus, customPrice, hasActiveBooking);
        positionMenuNearCell(menuElement, cellElement);

        $('body').append(menuElement);
        attachMenuEventHandlers(menuElement, roomId, date);
        showMenuWithAnimation(menuElement);
    }

    /**
     * Determine the current status of a calendar cell
     */
    function determineCurrentStatus(cellElement) {
        const statusChecks = [
            { class: 'aiohm-date-external', status: 'external' },
            { class: 'aiohm-date-booked', status: 'booked' },
            { class: 'aiohm-date-pending', status: 'pending' },
            { class: 'aiohm-date-private', status: 'private' },
            { class: 'aiohm-date-blocked', status: 'blocked' }
        ];

        for (const check of statusChecks) {
            if (cellElement.hasClass(check.class)) {
                return check.status;
            }
        }

        // Check for generic booking
        if (hasBookingButNotBlocked(cellElement)) {
            return 'booked';
        }

        return 'free';
    }

    /**
     * Check if cell has booking but is not blocked
     */
    function hasBookingButNotBlocked(cellElement) {
        return cellElement.hasClass('aiohm-date-room-locked') &&
               !cellElement.hasClass('aiohm-date-blocked');
    }

    /**
     * Create the status menu HTML element
     */
    function createStatusMenuElement(roomId, date, currentStatus, customPrice, hasActiveBooking) {
        const menuContent = hasActiveBooking ?
            createActiveBookingMenuContent(roomId, date) :
            createStatusSelectionMenuContent(roomId, date, currentStatus, customPrice);

        return $(`
            <div class="aiohm-simple-menu">
                <div class="aiohm-menu-header">
                    <strong>${aiohm_booking_mvp_admin.i18n.roomDateTitle.replace('%1$s', roomId).replace('%2$s', date)}</strong>
                    <button class="aiohm-close-simple" type="button">&times;</button>
                </div>
                <div class="aiohm-menu-actions">
                    ${menuContent}
                </div>
            </div>
        `);
    }

    /**
     * Create menu content for cells with active bookings
     */
    function createActiveBookingMenuContent(roomId, date) {
        return `
            <p class="aiohm-warning">${aiohm_booking_mvp_admin.i18n.activeBookingMenuWarning}</p>
            <button class="aiohm-unblock-btn" data-room-id="${roomId}" data-date="${date}">
                ${aiohm_booking_mvp_admin.i18n.clearStatus}
            </button>
        `;
    }

    /**
     * Create menu content for status selection
     */
    function createStatusSelectionMenuContent(roomId, date, currentStatus, customPrice) {
        const statusPills = Object.keys(CALENDAR_CONFIG.STATUSES)
            .map(status => {
                const config = CALENDAR_CONFIG.STATUSES[status];
                const selectedClass = currentStatus === status ? 'selected' : '';
                return `<button type="button" class="aiohm-status-pill status-${status} ${selectedClass}" data-status="${status}">${config.label}</button>`;
            })
            .join('');

        const previousReason = currentStatus === 'blocked' ? aiohm_booking_mvp_admin.i18n.previouslyBlocked : '';

        return `
            <label class="aiohm-status-label">${aiohm_booking_mvp_admin.i18n.setStatus}</label>
            <div class="aiohm-status-pills" role="group" aria-label="${aiohm_booking_mvp_admin.i18n.chooseStatus}">
                ${statusPills}
            </div>
            <input type="hidden" class="aiohm-selected-status" value="${currentStatus}">
            <div class="aiohm-price-input-group">
                <label class="aiohm-status-label aiohm-price-label">${aiohm_booking_mvp_admin.i18n.customPrice}</label>
                <input type="number" class="aiohm-custom-price" placeholder="${aiohm_booking_mvp_admin.i18n.pricePlaceholder}" step="0.01" min="0" value="${customPrice || ''}">
            </div>
            <input type="text" class="aiohm-block-reason"
                   placeholder="${aiohm_booking_mvp_admin.i18n.reasonPlaceholder}"
                   maxlength="100"
                   value="${previousReason}">
            <button class="aiohm-set-status-btn" data-room-id="${roomId}" data-date="${date}">
                ${aiohm_booking_mvp_admin.i18n.updateStatus}
            </button>
        `;
    }

    /**
     * Position menu near the clicked cell with smart positioning
     */
    function positionMenuNearCell(menuElement, cellElement) {
        const cellOffset = cellElement.offset();
        const cellHeight = cellElement.outerHeight();
        const windowWidth = $(window).width();

        const basePosition = {
            position: 'absolute',
            top: cellOffset.top + cellHeight + 5,
            left: cellOffset.left,
            zIndex: 10000
        };

        menuElement.css(basePosition);

        // Adjust if menu would go off-screen
        const menuWidth = menuElement.outerWidth();
        if (cellOffset.left + menuWidth > windowWidth - 20) {
            menuElement.css('left', cellOffset.left - menuWidth + cellElement.outerWidth());
        }
    }

    /**
     * Attach event handlers to menu elements
     */
    function attachMenuEventHandlers(menuElement, roomId, date) {
        menuElement.find('.aiohm-close-simple').on('click', () => menuElement.remove());
        menuElement.find('.aiohm-set-status-btn').on('click', () => handleStatusUpdate(menuElement, roomId, date));
        menuElement.find('.aiohm-unblock-btn').on('click', () => handleStatusClear(menuElement, roomId, date));
        menuElement.find('.aiohm-block-reason').focus();

        // Status pill selection
        menuElement.on('click', '.aiohm-status-pill', function() {
            const selected = $(this).data('status');
            menuElement.find('.aiohm-status-pill').removeClass('selected');
            $(this).addClass('selected');
            menuElement.find('.aiohm-selected-status').val(selected);
        });
    }

    /**
     * Handle status update button click
     */
    function handleStatusUpdate(menuElement, roomId, date) {
        const selectedStatus = (menuElement.find('.aiohm-selected-status').val() || '').trim() || 'free';
        const reason = menuElement.find('.aiohm-block-reason').val().trim() || aiohm_booking_mvp_admin.i18n.updateStatus;
        const price = menuElement.find('.aiohm-custom-price').val().trim();

        updateDateStatus(roomId, date, selectedStatus, reason, price, menuElement);
    }

    /**
     * Handle status clear button click
     */
    function handleStatusClear(menuElement, roomId, date) {
        updateDateStatus(roomId, date, 'free', aiohm_booking_mvp_admin.i18n.clearStatus, menuElement);
    }

    /**
     * Show menu with fade-in animation
     */
    function showMenuWithAnimation(menuElement) {
        menuElement.fadeIn(150);
    }

    /**
     * Remove any existing status menus
     */
    function removeExistingMenus() {
        $(CALENDAR_CONFIG.SELECTORS.simpleMenu).remove();
    }

    /**
     * Update date status via AJAX
     */
    function updateDateStatus(roomId, date, status, reason, price, menuElement) {
        const updateButton = menuElement.find('.aiohm-set-status-btn, .aiohm-unblock-btn');
        const originalButtonText = updateButton.text();

        setButtonLoadingState(updateButton, aiohm_booking_mvp_admin.i18n.updating);

        $.ajax({
            url: aiohm_booking_mvp_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'aiohm_booking_mvp_set_date_status',
                room_id: roomId,
                date: date,
                status: status,
                reason: reason,
                price: price,
                nonce: aiohm_booking_mvp_admin.nonce
            },
            success: (response) => handleStatusUpdateSuccess(response, menuElement, roomId, date, status, reason),
            error: () => handleStatusUpdateError(updateButton, originalButtonText)
        });
    }

    /**
     * Handle successful status update response
     */
    function handleStatusUpdateSuccess(response, menuElement, roomId, date, status, reason) {
        try {
            const data = typeof response === 'string' ? JSON.parse(response) : response;

            if (data.success) {
                menuElement.remove();
                updateCellStatusVisually(roomId, date, status, reason);
            } else {                
                alert(aiohm_booking_mvp_admin.i18n.errorPrefix + (data.data || aiohm_booking_mvp_admin.i18n.unknownError));
                resetButtonFromLoading(menuElement);
            }
        } catch (error) {            
            alert(aiohm_booking_mvp_admin.i18n.errorProcessingResponse);
            resetButtonFromLoading(menuElement);
        }
    }

    /**
     * Handle status update error
     */
    function handleStatusUpdateError(updateButton, originalButtonText) {
        alert(CALENDAR_CONFIG.MESSAGES.updateError);
        resetButtonState(updateButton, originalButtonText);
    }

    /**
     * Set button to loading state
     */
    function setButtonLoadingState(button, loadingText) {
        button.prop('disabled', true).text(loadingText);
    }

    /**
     * Reset button from loading state
     */
    function resetButtonFromLoading(menuElement) {
        const button = menuElement.find('.aiohm-set-status-btn, .aiohm-unblock-btn');
        button.prop('disabled', false).text(button.hasClass('aiohm-unblock-btn') ? aiohm_booking_mvp_admin.i18n.clearStatus : aiohm_booking_mvp_admin.i18n.updateStatus);
    }

    /**
     * Reset button state with original text
     */
    function resetButtonState(button, originalText) {
        button.prop('disabled', false).text(originalText);
    }

    /**
     * Update cell status visually without page reload
     */
    function updateCellStatusVisually(roomId, date, status, reason) {
        const firstCell = $(`.aiohm-date-first-part[data-room-id="${roomId}"][data-date="${date}"]`);
        const secondCell = $(`.aiohm-date-second-part[data-room-id="${roomId}"][data-date="${date}"]`);

        const nextDate = calculateNextDate(date);
        const nextFirstCell = $(`.aiohm-date-first-part[data-room-id="${roomId}"][data-date="${nextDate}"]`);

        applyCellStatusClasses(firstCell, secondCell, nextFirstCell, status);
        updateCellTitles(firstCell, secondCell, nextFirstCell, date, nextDate, status, reason);
    }

    /**
     * Calculate next date string
     */
    function calculateNextDate(dateString) {
        const nextDate = new Date(dateString);
        nextDate.setDate(nextDate.getDate() + 1);
        return nextDate.toISOString().split('T')[0];
    }

    /**
     * Apply status classes to cells
     */
    function applyCellStatusClasses(firstCell, secondCell, nextFirstCell, status) {
        // Clear existing status classes
        [firstCell, secondCell, nextFirstCell].forEach(cell => {
            if (cell.length) {
                cell.removeClass(CALENDAR_CONFIG.CLASSES.statusClasses);
            }
        });

        if (status === 'free') {
            applyFreeStatus(firstCell, secondCell, nextFirstCell);
        } else {
            applyBlockedStatus(firstCell, secondCell, nextFirstCell, status);
        }
    }

    /**
     * Apply free status to cells
     */
    function applyFreeStatus(firstCell, secondCell, nextFirstCell) {
        firstCell.addClass('aiohm-date-free');
        secondCell.addClass('aiohm-date-free');

        if (nextFirstCell.length) {
            nextFirstCell.addClass('aiohm-date-free');
        }
    }

    /**
     * Apply blocked/admin status to cells
     */
    function applyBlockedStatus(firstCell, secondCell, nextFirstCell, status) {
        // Current day: first half free, second half gets status
        firstCell.addClass('aiohm-date-free');
        secondCell.addClass(`aiohm-date-room-locked aiohm-date-${status}`);

        if (status === 'blocked') {
            secondCell.addClass('aiohm-date-blocked');
        }

        // Next day: first half gets carry-over status
        if (nextFirstCell.length) {
            nextFirstCell.addClass(`aiohm-date-room-locked aiohm-date-${status}`);
            if (status === 'blocked') {
                nextFirstCell.addClass('aiohm-date-blocked');
            }
        }
    }

    /**
     * Update cell title attributes
     */
    function updateCellTitles(firstCell, secondCell, nextFirstCell, date, nextDate, status, reason) {
        const currentDateTitle = generateCellTitle(date, status, reason);

        firstCell.attr('title', currentDateTitle);
        secondCell.attr('title', currentDateTitle);

        if (nextFirstCell.length) {
            const nextDateTitle = generateNextDateTitle(nextDate, status);
            nextFirstCell.attr('title', nextDateTitle);
        }
    }

    /**
     * Generate title for current date
     */
    function generateCellTitle(date, status, reason) {
        const dateObj = new Date(date);
        const dateString = dateObj.toLocaleDateString('en-US', {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
            year: 'numeric'
        }) + ':';

        const statusConfig = CALENDAR_CONFIG.STATUSES[status];
        if (!statusConfig) {
            return dateString + ' ' + aiohm_booking_mvp_admin.i18n.statusAvailable;
        }

        const statusTitle = statusConfig.title;        
        const reasonText = reason && reason !== 'Set by admin' ? ` (${reason})` : '';

        return dateString + ' ' + statusTitle + reasonText;
    }

    /**
     * Generate title for next date (carry-over)
     */
    function generateNextDateTitle(nextDate, status) {
        const dateObj = new Date(nextDate);
        const dateString = dateObj.toLocaleDateString('en-US', {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
            year: 'numeric'
        }) + ':';

        if (status === 'free') {
            return dateString + ' ' + aiohm_booking_mvp_admin.i18n.statusAvailable;
        }

        const statusConfig = CALENDAR_CONFIG.STATUSES[status];
        const statusTitle = statusConfig ? statusConfig.title : 'Booked';
        
        return dateString + ' ' + statusTitle + ' ' + aiohm_booking_mvp_admin.i18n.carryOver;
    }

    /**
     * Initialize filtering system
     */
    function initializeFilterSystem() {
        $(CALENDAR_CONFIG.SELECTORS.searchButton).on('click', handleFilterSearch);
        $(CALENDAR_CONFIG.SELECTORS.resetButton).on('click', handleFilterReset);
    }

    /**
     * Handle filter search button click
     */
    function handleFilterSearch() {
        const button = $(CALENDAR_CONFIG.SELECTORS.searchButton);
        const originalText = button.text();
        const selectedStatus = $(CALENDAR_CONFIG.SELECTORS.statusFilter).val();
        
        setButtonLoadingState(button, aiohm_booking_mvp_admin.i18n.filtering);
        applyCalendarStatusFilter(selectedStatus);

        setTimeout(() => resetButtonState(button, originalText), 500);
    }

    /**
     * Handle filter reset button click
     */
    function handleFilterReset() {
        const button = $(CALENDAR_CONFIG.SELECTORS.resetButton);
        const originalText = button.text();
        
        setButtonLoadingState(button, aiohm_booking_mvp_admin.i18n.resetting);
        $(CALENDAR_CONFIG.SELECTORS.statusFilter).val('');
        applyCalendarStatusFilter('');

        setTimeout(() => resetButtonState(button, originalText), 500);
    }

    /**
     * Apply status filter to calendar
     */
    function applyCalendarStatusFilter(status) {
        const allCells = $('.aiohm-date-first-part').add('.aiohm-date-second-part');

        if (!status || status === '') {
            clearCalendarFilter(allCells);
            return;
        }

        applyFilterHighlighting(allCells, status);
    }

    /**
     * Clear all calendar filters
     */
    function clearCalendarFilter(allCells) {
        allCells.removeClass(CALENDAR_CONFIG.CLASSES.dimFilter);
        $('.aiohm-bookings-date-table tr').show();
        showFilterStatusMessage(aiohm_booking_mvp_admin.i18n.showingAllDates);
    }

    /**
     * Apply filter highlighting to matching cells
     */
    function applyFilterHighlighting(allCells, status) {
        // Dim all cells initially
        allCells.addClass(CALENDAR_CONFIG.CLASSES.dimFilter);

        // Find and highlight matching cells
        const matchingCells = $(`.aiohm-date-${status}`);
        matchingCells.removeClass(CALENDAR_CONFIG.CLASSES.dimFilter);

        // Count unique dates
        const uniqueDates = countUniqueMatchingDates(matchingCells);
        const statusConfig = CALENDAR_CONFIG.STATUSES[status];
        const statusLabel = statusConfig ? statusConfig.title : status.charAt(0).toUpperCase() + status.slice(1);        
        
        showFilterStatusMessage(aiohm_booking_mvp_admin.i18n.foundDates.replace('%1$d', uniqueDates).replace('%2$s', statusLabel));
    }

    /**
     * Count unique dates from matching cells
     */
    function countUniqueMatchingDates(matchingCells) {
        const uniqueDates = new Set();

        matchingCells.each(function() {
            const date = $(this).data('date');
            if (date) {
                uniqueDates.add(date);
            }
        });

        return uniqueDates.size;
    }

    /**
     * Show filter status message
     */
    function showFilterStatusMessage(message) {
        $('.aiohm-filter-status-message').remove();

        const statusMessage = $(`
            <div class="aiohm-filter-status-message"
                 style="margin-top: 10px; padding: 8px; background: #f0f8ff;
                        border-left: 4px solid #0073aa; font-size: 14px;">
                ${message}
            </div>
        `);

        $('.aiohm-bookings-calendar-footer-wrapper').append(statusMessage);

        setTimeout(() => {
            statusMessage.fadeOut(300, function() {
                $(this).remove();
            });
        }, 3000);
    }

    /**
     * Initialize sync modules for external calendar integration
     */
    function initializeSyncModules() {
        initializeBookingSyncModule();
        initializeAirbnbSyncModule();
        initializeSyncFormHandlers();
    }

    /**
     * Initialize Booking.com sync functionality
     */
    function initializeBookingSyncModule() {
        $(CALENDAR_CONFIG.SELECTORS.syncButtons.booking).on('click', function() {
            handleSyncAction($(this), '#aiohm-booking-sync-status', 'booking');
        });
    }

    /**
     * Initialize Airbnb sync functionality
     */
    function initializeAirbnbSyncModule() {
        $(CALENDAR_CONFIG.SELECTORS.syncButtons.airbnb).on('click', function() {
            handleSyncAction($(this), '#aiohm-airbnb-sync-status', 'airbnb');
        });
    }

    /**
     * Handle sync action for external calendar sources
     */
    function handleSyncAction(button, statusSelector, source) {
        if (button.hasClass('syncing')) {
            return; // Prevent multiple simultaneous syncs
        }

        const statusElement = $(statusSelector);

        setSyncInProgress(button, statusElement);

        $.ajax({
            url: aiohm_booking_mvp_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'aiohm_booking_mvp_sync_calendar',
                source: source,
                nonce: aiohm_booking_mvp_admin.nonce
            },
            success: (response) => handleSyncSuccess(response, statusElement),
            error: () => handleSyncError(statusElement),
            complete: () => completeSyncAction(button, statusElement)
        });
    }

    /**
     * Set sync in progress state
     */
    function setSyncInProgress(button, statusElement) {
        button.addClass('syncing').prop('disabled', true);
        statusElement.removeClass('success error').addClass('syncing').text(aiohm_booking_mvp_admin.i18n.syncing);
    }

    /**
     * Handle successful sync response
     */
    function handleSyncSuccess(response, statusElement) {
        try {
            const data = typeof response === 'string' ? JSON.parse(response) : response;

            if (data.success) {
                statusElement.removeClass('syncing error').addClass('success').text(aiohm_booking_mvp_admin.i18n.syncedSuccessfully);

                setTimeout(() => location.reload(), 1500);
            } else {
                statusElement.removeClass('syncing success').addClass('error').text(aiohm_booking_mvp_admin.i18n.syncFailed);
            }
        } catch (error) {            
            statusElement.removeClass('syncing success').addClass('error').text(aiohm_booking_mvp_admin.i18n.syncError);
        }
    }

    /**
     * Handle sync error
     */
    function handleSyncError(statusElement) {
        statusElement.removeClass('syncing success').addClass('error').text(CALENDAR_CONFIG.MESSAGES.syncError);
    }

    /**
     * Complete sync action and reset states
     */
    function completeSyncAction(button, statusElement) {
        button.removeClass('syncing').prop('disabled', false);

        setTimeout(() => {
            statusElement.removeClass('success error syncing').text('');
        }, 5000);
    }

    /**
     * Initialize sync form handlers
     */
    function initializeSyncFormHandlers() {
        $(CALENDAR_CONFIG.SELECTORS.bookingForms.booking).on('submit', function(event) {
            handleSyncFormSubmission(event, $(this), 'aiohm_save_booking_sync_settings');
        });

        $(CALENDAR_CONFIG.SELECTORS.bookingForms.airbnb).on('submit', function(event) {
            handleSyncFormSubmission(event, $(this), 'aiohm_save_airbnb_sync_settings');
        });
    }

    /**
     * Handle sync form submission
     */
    function handleSyncFormSubmission(event, form, action) {
        event.preventDefault();

        const submitButton = form.find('button[type="submit"]');
        const originalText = submitButton.text();

        setButtonLoadingState(submitButton, aiohm_booking_mvp_admin.i18n.saving);

        $.ajax({
            url: aiohm_booking_mvp_admin.ajax_url,
            type: 'POST',
            data: form.serialize() + `&action=${action}&nonce=${aiohm_booking_mvp_admin.nonce}`,
            success: (response) => handleFormSubmissionSuccess(response, submitButton, originalText),
            error: () => handleFormSubmissionError(submitButton, originalText)
        });
    }

    /**
     * Handle successful form submission
     */
    function handleFormSubmissionSuccess(response, submitButton, originalText) {
        try {
            const data = typeof response === 'string' ? JSON.parse(response) : response;

            if (data.success) {
                submitButton.text(aiohm_booking_mvp_admin.i18n.saved);
                setTimeout(() => submitButton.text(originalText), 2000);
            } else {                
                alert(aiohm_booking_mvp_admin.i18n.saveSettingsError + ': ' + (data.data || aiohm_booking_mvp_admin.i18n.unknownError));
            }
        } catch (error) {            
            alert(aiohm_booking_mvp_admin.i18n.errorProcessingResponse);
        } finally {
            submitButton.prop('disabled', false);
        }
    }

    /**
     * Handle form submission error
     */
    function handleFormSubmissionError(submitButton, originalText) {
        alert(CALENDAR_CONFIG.MESSAGES.connectionError);
        resetButtonState(submitButton, originalText);
    }

    /**
     * Initialize global event handlers
     */
    function initializeGlobalEventHandlers() {
        // Close menu when clicking outside
        $(document).on('click', function(event) {
            if (!$(event.target).closest('.aiohm-simple-menu, .aiohm-date-first-part, .aiohm-date-second-part').length) {
                removeExistingMenus();
            }
        });

        // Close menu with Escape key
        $(document).on('keydown', function(event) {
            if (event.key === 'Escape') {
                removeExistingMenus();
            }
        });

        // Add hover indicators to editable cells
        setTimeout(() => {
            $('.aiohm-editable-cell.aiohm-date-free').attr('title', aiohm_booking_mvp_admin.i18n.clickToModify);
        }, 500);

        // Re-align after any menu close/update that might change heights
        $(document).on('click', '.aiohm-set-status-btn, .aiohm-unblock-btn, .aiohm-close-simple', () => {
            setTimeout(alignCalendarLayout, 50);
        });
    }

    /**
     * Align accommodations table rows with date table rows and headers
     */
    function alignCalendarLayout() {
        try {
            alignHeaderHeights();
            alignBodyRowHeights();
        } catch (e) {
            // no-op
        }
    }

    function alignHeaderHeights() {
        const roomHead = $('.aiohm-bookings-calendar-rooms thead tr');
        const dateHead = $('.aiohm-bookings-date-table thead tr');
        if (!roomHead.length || !dateHead.length) return;

        // Reset before measuring
        roomHead.css('height', '');
        dateHead.css('height', '');

        const h = Math.max(roomHead.outerHeight(), dateHead.outerHeight());
        roomHead.css('height', h + 'px');
        dateHead.css('height', h + 'px');
    }

    function alignBodyRowHeights() {
        const roomRows = $('.aiohm-bookings-calendar-rooms tbody tr');
        const dateRows = $('.aiohm-bookings-date-table tbody tr');
        if (!roomRows.length || !dateRows.length) return;

        // Reset heights before measuring
        roomRows.css('height', '');
        dateRows.css('height', '');

        const count = Math.min(roomRows.length, dateRows.length);
        for (let i = 0; i < count; i++) {
            const rr = $(roomRows[i]);
            const dr = $(dateRows[i]);
            const h = Math.max(rr.outerHeight(), dr.outerHeight());
            rr.css('height', h + 'px');
            dr.css('height', h + 'px');
        }
    }

    function debounce(fn, wait) {
        let t;
        return function() {
            clearTimeout(t);
            const args = arguments;
            t = setTimeout(() => fn.apply(null, args), wait);
        };
    }

    // System initialized - Calendar is ready for user interaction
});
