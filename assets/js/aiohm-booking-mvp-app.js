/**
 * AIOHM Booking Frontend Application
 * Modern ES6+ implementation with enhanced error handling and performance optimization
 */
 'use strict';

  // Configuration and Constants
  const CONFIG = {
    SELECTORS: {
      bookingForm: '#aiohm-booking-mvp-form',
      calendarGrid: '#calendarGrid',
      quantityInputs: 'input[type="number"]',
      submitButton: 'button[type="submit"]'
    },
    API: {
      baseUrl: '/wp-json/aiohm-booking-mvp/v1',
      timeout: 30000
    },
    DELAYS: {
      redirect: 1500,
      messageHide: 5000
    }
  };

  // Utility Functions
  const Utils = {
    qs(selector, root = document) {
      return root.querySelector(selector);
    },

    qsa(selector, root = document) {
      return Array.from(root.querySelectorAll(selector));
    },

    debounce(func, wait) {
      let timeout;
      return function executedFunction(...args) {
        const later = () => {
          clearTimeout(timeout);
          func.apply(this, args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
      };
    },

    throttle(func, limit) {
      let inThrottle;
      return function() {
        const args = arguments;
        const context = this;
        if (!inThrottle) {
          func.apply(context, args);
          inThrottle = true;
          setTimeout(() => inThrottle = false, limit);
        }
      };
    }
  };

  // Error Handler Class
  class ErrorHandler {
    static logError(error, context = '') {
      // Errors are handled silently in production

      // Send to analytics if available
      if (window.gtag) {
        gtag('event', 'exception', {
          description: `${context}: ${error.message}`,
          fatal: false
        });
      }
    }

    static createUserFriendlyMessage(error) {
      if (typeof error === 'string') return error;
      if (error?.message) return error.message;
      if (error?.data) return error.data;      
      return AIOHM_BOOKING.i18n.unexpectedError || 'An unexpected error occurred. Please try again.';
    }
  }


  // Enhanced Message Handler
  function showMessage(element, message, type = 'error') {
    try {
      if (!element) {
        throw new Error('No element provided for message display');
      }

      // Remove existing messages
      const existing = element.querySelector('.message');
      if (existing) {
        existing.remove();
      }

      // Create new message element
      const div = document.createElement('div');
      div.className = `message ${type}`;
      div.textContent = message;

      // Add ARIA attributes for accessibility
      div.setAttribute('role', type === 'error' ? 'alert' : 'status');
      div.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');

      // Insert at the beginning
      element.insertBefore(div, element.firstChild);

      // Auto-hide success messages
      if (type === 'success') {
        setTimeout(() => {
          if (div.parentNode) {
            div.remove();
          }
        }, CONFIG.DELAYS.messageHide);
      }

      // Smooth entrance animation
      div.style.opacity = '0';
      div.style.transform = 'translateY(-10px)';
      div.style.transition = 'all 0.3s ease';

      requestAnimationFrame(() => {
        div.style.opacity = '1';
        div.style.transform = 'translateY(0)';
      });

    } catch (error) {
      ErrorHandler.logError(error, 'Message display failed');
      // Fallback to alert
      alert(message);
    }
  }

  // Enhanced API Client with retry logic and better error handling
  class ApiClient {
    constructor() {
      this.baseUrl = window.AIOHM_BOOKING?.rest || CONFIG.API.baseUrl;
      this.nonce = window.AIOHM_BOOKING?.nonce || '';
      this.timeout = CONFIG.API.timeout;
    }

    async request(endpoint, options = {}) {
      const url = `${this.baseUrl}${endpoint}`;
      const defaultOptions = {
        method: 'GET',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': this.nonce
        },
        timeout: this.timeout
      };

      const mergedOptions = { ...defaultOptions, ...options };

      try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), this.timeout);

        const response = await fetch(url, {
          ...mergedOptions,
          signal: controller.signal
        });

        clearTimeout(timeoutId);

        if (!response.ok) {
          const errorData = await response.json().catch(() => ({}));
          throw new Error(errorData.message || `HTTP ${response.status}: ${response.statusText}`);
        }

        return await response.json();

      } catch (error) {
        if (error.name === 'AbortError') {
          throw new Error(AIOHM_BOOKING.i18n.requestTimeout || 'Request timed out. Please check your connection and try again.');
        }
        throw error;
      }
    }

    async hold(payload) {
      return this.request('/hold', {
        method: 'POST',
        body: JSON.stringify(payload)
      });
    }
  }

  // Create singleton API client
  const apiClient = new ApiClient();

  // Legacy function for backward compatibility
  async function hold(payload) {
    return apiClient.hold(payload);
  }

  // Enhanced Form Validation
  const FormValidator = {
    validateEmail(email) {
      // More comprehensive email validation
      const emailRegex = /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/;
      return emailRegex.test(email.trim());
    },

    validateRequired(value) {
      return value && value.toString().trim().length > 0;
    },

    validatePositiveNumber(value) {
      const num = parseInt(value);
      return !isNaN(num) && num > 0;
    },

    validateDateRange(checkin, checkout) {
      if (!checkin || !checkout) return false;
      const checkinDate = new Date(checkin);
      const checkoutDate = new Date(checkout);
      return checkinDate < checkoutDate;
    }
  };


  // Enhanced form validation with better error messaging
  function validateForm(form) {
    const errors = [];

    try {
      // Basic field validation
      const name = form.name?.value?.trim() || '';
      const email = form.email?.value?.trim() || '';

      if (!FormValidator.validateRequired(name)) {
        errors.push(AIOHM_BOOKING.i18n.enterFullName);
      }

      if (!FormValidator.validateRequired(email)) {
        errors.push(AIOHM_BOOKING.i18n.enterEmail);
      } else if (!FormValidator.validateEmail(email)) {
        errors.push(AIOHM_BOOKING.i18n.invalidEmail);
      }

      // Age validation (only if field is visible and enabled)
      const ageField = form.querySelector('input[name="age"]');
      if (ageField && ageField.offsetParent !== null && ageField.hasAttribute('required')) {
        const age = parseInt(form.age?.value || '0', 10);
        const minAge = parseInt(ageField.getAttribute('min') || '0', 10);        
        
        // Only validate age if a minimum age is set and the field has a value
        if (age === 0 && minAge > 0) {
          errors.push(AIOHM_BOOKING.i18n.enterAge);
        } else if (age > 0 && minAge > 0 && age < minAge) {
          errors.push(AIOHM_BOOKING.i18n.ageMin.replace('%d', minAge));
        } else if (age > 99) {
          errors.push(AIOHM_BOOKING.i18n.ageMax);
        }
      }

      // Room-specific validation (always rooms mode now)
      const mode = 'rooms';

      if (mode === 'rooms') {
        // Count selected accommodations
        const selectedAccommodations = form.querySelectorAll('.accommodation-checkbox:checked').length;
        const privateAll = form.private_all?.checked || false;

        if (selectedAccommodations === 0 && !privateAll) {
          errors.push(AIOHM_BOOKING.i18n.selectAccommodation);
        }

        const guestsQty = parseInt(form.guests_qty?.value || '0', 10);
        if (!guestsQty || guestsQty < 1) {
            errors.push(AIOHM_BOOKING.i18n.atLeastOneGuest);
        } else if (guestsQty > 20) {
            errors.push(AIOHM_BOOKING.i18n.maxGuests);
        }

        // Date validation for room bookings
        const checkinDate = form.checkin_date?.value || Utils.qs('#checkinDisplay')?.value;
        const checkoutDate = form.checkout_date?.value || Utils.qs('#checkoutHidden')?.value;

        if (checkinDate && checkoutDate && !FormValidator.validateDateRange(checkinDate, checkoutDate)) {
          errors.push(AIOHM_BOOKING.i18n.checkoutAfterCheckin);
        }
      }

      return errors;

    } catch (error) {
      ErrorHandler.logError(error, 'Form validation failed');      
      return [AIOHM_BOOKING.i18n.formValidationError];
    }
  }

  // Auto-calculate and display pricing preview
  function updatePricing(form) {
    const priceDisplay = form.querySelector('.pricing-summary');
    if (!priceDisplay) return;

    const checkinEl = document.getElementById('checkinDisplay');
    const durationInput = document.getElementById('stay_duration');
    const duration = parseInt(durationInput?.value) || 1;
    
    let totalRegular = 0;
    let totalEarly = 0;
    let selectedEarlyBirds = [];

    // First check all accommodation checkboxes
    const allAccommodationCheckboxes = Array.from(form.querySelectorAll('.accommodation-checkbox'));
    
    // Get selected accommodation options
    let selectedOptions = Array.from(form.querySelectorAll('.accommodation-checkbox:checked'));
    
    // Check if private_all is selected but no individual rooms are checked
    const privateAllCheckbox = form.querySelector('#private_all_checkbox, [name="private_all"]');
    const isPrivateAll = privateAllCheckbox && privateAllCheckbox.checked;
    
    // If private_all is checked but no accommodation checkboxes are selected, use all accommodations
    if (isPrivateAll && selectedOptions.length === 0 && allAccommodationCheckboxes.length > 0) {
        selectedOptions = allAccommodationCheckboxes;
    }

    if (checkinEl && checkinEl.value && (selectedOptions.length > 0 || isPrivateAll)) {
        const checkinDate = new Date(checkinEl.value);
        // Loop through each day of the stay to calculate daily price
        for (let i = 0; i < duration; i++) {
            const currentDate = new Date(checkinDate);
            currentDate.setDate(checkinDate.getDate() + i);
            const dateString = formatDate(currentDate);

            let dailyBaseSum = 0;
            let dailyEbSum = 0;

            selectedOptions.forEach((option, index) => {
                const base = parseFloat(option.dataset.price || 0) || 0;
                const eb = parseFloat(option.dataset.earlybird || 0) || 0;
                
                // Custom daily prices should only override if there's no individual room price
                // Use room's individual price first, fall back to custom daily price, then base price
                let finalPrice = base;
                let useEarlyBird = false;
                
                if (base > 0) {
                    // Room has individual price - use it
                    finalPrice = base;
                    if (eb > 0) {
                        useEarlyBird = true;
                    }
                } else {
                    // Room has no individual price - check for custom daily price
                    const customPrice = dailyPrices[dateString] !== undefined ? parseFloat(dailyPrices[dateString]) : null;
                    if (customPrice !== null) {
                        finalPrice = customPrice;
                    }
                }

                dailyBaseSum += finalPrice;
                if (useEarlyBird) {
                    dailyEbSum += eb;
                    selectedEarlyBirds.push(eb);
                } else {
                    dailyEbSum += finalPrice;
                }
            });
            totalRegular += dailyBaseSum;
            totalEarly += dailyEbSum;
        }
    } else {
        // Fallback to old logic if no date is selected yet
        let baseSum = 0;
        
        // If private_all is checked but selectedOptions is empty, use all accommodations
        if (isPrivateAll && selectedOptions.length === 0 && allAccommodationCheckboxes.length > 0) {
            allAccommodationCheckboxes.forEach(option => {
                const price = parseFloat(option.dataset.price || 0) || 0;
                baseSum += price;
            });
        } else {
            selectedOptions.forEach(option => { 
              const price = parseFloat(option.dataset.price || 0) || 0;
              baseSum += price; 
            });
        }
        
        totalRegular = baseSum * duration;
        totalEarly = totalRegular; // No early bird without a date
    }

    const depositPercent = parseFloat(priceDisplay.dataset.depositPercent || 0);
    const depositRegular = totalRegular * (depositPercent / 100);
    let depositEarly = totalEarly * (depositPercent / 100);

    const currency = priceDisplay.dataset.currency || 'USD';


    const totalEl = priceDisplay.querySelector('.total-amount');
    const depositEl = priceDisplay.querySelector('.deposit-amount');

    if (totalEl) totalEl.textContent = `${currency} ${totalRegular.toFixed(2)}`;
    if (depositEl) depositEl.textContent = `${currency} ${depositRegular.toFixed(2)}`;

    // Early bird eligibility by days before check-in
    const earlyDays = parseInt(priceDisplay.dataset.earlybirdDays || '30', 10);
    let eligibleByDate = false;
    if (checkinEl && checkinEl.value) {
      const checkinDate = new Date(checkinEl.value);
      if (!isNaN(checkinDate)) {
        const today = new Date();
        today.setHours(0,0,0,0);
        checkinDate.setHours(0,0,0,0);
        const diffMs = checkinDate.getTime() - today.getTime();
        const diffDays = Math.floor(diffMs / (1000*60*60*24));
        eligibleByDate = diffDays >= earlyDays;
      }
    }

    // Early bird totals and deposit handling
    const ebRow = priceDisplay.querySelector('.earlybird-row');
    const ebValue = priceDisplay.querySelector('.earlybird-amount');
    const savingRow = priceDisplay.querySelector('.saving-row');
    const savingVal = priceDisplay.querySelector('.saving-amount');

    // Update deposit labels to include percent
    const depLabel = priceDisplay.querySelector('.deposit-row .price-label');
    if (depLabel) depLabel.textContent = AIOHM_BOOKING.i18n.depositRequired.replace('%s', depositPercent);
    // Deposit label already updated for main deposit row only

    const hasEarly = selectedEarlyBirds.length > 0 && eligibleByDate;
    if (hasEarly) {
      // Early bird total
      if (ebRow && ebValue) {
        ebValue.textContent = `${currency} ${totalEarly.toFixed(2)}`;
        ebRow.style.display = '';
      }
      // Savings
      if (savingRow && savingVal) {
        const saving = Math.max(0, totalRegular - totalEarly);
        savingVal.textContent = `${currency} ${saving.toFixed(2)}`;
        savingRow.style.display = '';
      }
      // Strike through regular total; show deposit for early total in main row
      if (totalEl) totalEl.classList.add('strikethrough');
      if (depositEl) {
        depositEl.classList.remove('strikethrough');
        depositEl.textContent = `${currency} ${depositEarly.toFixed(2)}`;
      }
    } else {
      if (ebRow) ebRow.style.display = 'none';
      if (savingRow) savingRow.style.display = 'none';
      if (totalEl) totalEl.classList.remove('strikethrough');
      if (depositEl) depositEl.classList.remove('strikethrough');
      if (depositEl) depositEl.textContent = `${currency} ${depositRegular.toFixed(2)}`;
    }
  }

  // Enhanced form handling with better UX
  function initializeForm(form) {
    const submitButton = form.querySelector('button[type="submit"]');
    const originalButtonText = submitButton.textContent;

    form.addEventListener('submit', async function(e) {
      e.preventDefault();

      // Clear previous messages
      const existingMessage = form.querySelector('.message');
      if (existingMessage) existingMessage.remove();

      // Validate form
      const errors = validateForm(form);
      if (errors.length > 0) {
        showMessage(form, errors.join('. '), 'error');
        return;
      }

      // Disable submit button and show loading
      submitButton.disabled = true;
      submitButton.textContent = AIOHM_BOOKING.i18n.creatingHold;

      try {
        const formData = new FormData(form);
        const data = Object.fromEntries(formData.entries());

        // Normalize dates for API expectations
        const checkinEl = document.getElementById('checkinDisplay') || form.querySelector('input[name="checkin_date"]');
        const checkoutEl = document.getElementById('checkoutHidden') || form.querySelector('input[name="checkout_date"]');
        if (checkinEl && checkinEl.value) data.check_in_date = checkinEl.value;
        if (checkoutEl && checkoutEl.value) data.check_out_date = checkoutEl.value;

        // Collect selected accommodations as room IDs (1-based)
        const selectedOptions = Array.from(form.querySelectorAll('.accommodation-checkbox:checked'));
        if (selectedOptions.length > 0) {
          const roomIds = selectedOptions.map(opt => parseInt(opt.value, 10) + 1).filter(n => !isNaN(n));
          data.room_ids = roomIds;
          data.rooms_qty = roomIds.length.toString();
        }

        // Convert checkboxes to boolean
        if (data.private_all) data.private_all = true;

        const result = await hold(data);

        if (result && result.order_id) {
          showMessage(form, AIOHM_BOOKING.i18n.holdCreated.replace('%d', result.order_id), 'success');

          // Trigger calendar sync event
          const syncEvent = new CustomEvent('aiohm_booking_updated', {
            detail: {
              order_id: result.order_id,
              check_in_date: data.check_in_date,
              check_out_date: data.check_out_date,
              mode: data.mode,
              rooms_qty: data.rooms_qty,
              private_all: data.private_all
            }
          });
          document.dispatchEvent(syncEvent);

          // Redirect to checkout after short delay
          setTimeout(() => {
            const checkoutUrl = window.AIOHM_BOOKING.checkout_url || '';
            const buyerEmail = result.buyer_email || data.email || '';
            
            // Order processing successful
            
            if (checkoutUrl) {
                const url = new URL(checkoutUrl);
                url.searchParams.set('order_id', result.order_id);
                if (buyerEmail) url.searchParams.set('email', buyerEmail);
                // Redirecting to checkout page
                window.location = url.toString();
            } else {
                const url = new URL(window.location.href);
                url.searchParams.set('order_id', result.order_id);
                if (buyerEmail) url.searchParams.set('email', buyerEmail);
                // Redirecting to checkout page
                window.location = url.toString().split('#')[0] + '#checkout';
            }
          }, 1500);

        } else {
          throw new Error(AIOHM_BOOKING.i18n.invalidResponse);
        }

      } catch (error) {
        ErrorHandler.logError(error, 'Hold creation failed');
        
        // Provide user-friendly error messages
        let userMessage;        
        if (error.message.includes('timeout')) {
          userMessage = AIOHM_BOOKING.i18n.requestTimeout;
        } else if (error.message.includes('nonce')) {
          userMessage = AIOHM_BOOKING.i18n.nonceExpired;
        } else if (error.message.includes('database')) {
          userMessage = AIOHM_BOOKING.i18n.systemUnavailable;
        } else if (error.message.includes('private event')) {
          // Special handling for private event errors - show option to switch to private booking
          userMessage = error.message + '\n\nWould you like to book the entire property instead?';
          if (confirm(userMessage)) {
            // Enable private all checkbox if available
            const privateAllCheckbox = form.querySelector('#private_all_checkbox, [name="private_all"]');
            if (privateAllCheckbox) {
              privateAllCheckbox.checked = true;
              privateAllCheckbox.dispatchEvent(new Event('change'));
            }
          }
          return; // Don't show regular error message
        } else {
          userMessage = error.message || AIOHM_BOOKING.i18n.holdFailed;
        }
        
        showMessage(form, userMessage, 'error');

        // Re-enable submit button
        submitButton.disabled = false;
        submitButton.textContent = originalButtonText;
      }
    });

    // Add real-time quantity validation
    const quantityInputs = form.querySelectorAll('input[type="number"]');
    quantityInputs.forEach(input => {
      input.addEventListener('input', () => {
        const value = parseInt(input.value);
        if (value < 0) input.value = 0;
        updatePricing(form);
      });
    });

    // Add event listeners to accommodation dropdown - use event delegation for infinite wheel
    const accommodationContainer = form.querySelector('.accommodation-list, .accommodation-wheel-container');
    
    if (accommodationContainer) {
        accommodationContainer.addEventListener('change', (e) => {
            if (e.target.matches('.accommodation-checkbox')) {
                updatePricing(form);
                // Force tooltip update by hiding it
                const tooltip = document.querySelector('.calendar-price-tooltip');
                if (tooltip) {
                    tooltip.style.display = 'none';
                }
            }
        });
    } else {
        // Fallback to direct event listeners
        const accommodationCheckboxes = form.querySelectorAll('.accommodation-checkbox');
        if (accommodationCheckboxes.length > 0) {
            accommodationCheckboxes.forEach((checkbox) => {
                checkbox.addEventListener('change', (e) => {
                    updatePricing(form);
                    // Force tooltip update by hiding it
                    const tooltip = document.querySelector('.calendar-price-tooltip');
                    if (tooltip) {
                        tooltip.style.display = 'none';
                    }
                });
            });
        }
    }

    // Initial pricing and early bird update
    updatePricing(form);

    // Add event listener for pets checkbox
    const petsCheckbox = form.querySelector('#bringing_pets_checkbox');
    if (petsCheckbox) {
      const petDetailsField = form.querySelector('.pet-details-field');
      if (petDetailsField) {
        petsCheckbox.addEventListener('change', function() {
          petDetailsField.style.display = this.checked ? 'block' : 'none';
        });
      }
    }
  }

  // Quantity selector functionality for modern UI
  function initQuantitySelectors() {
    document.querySelectorAll('.qty-btn').forEach(button => {
      button.addEventListener('click', function(e) {
        e.preventDefault();

        const targetId = this.getAttribute('data-target');
        const input = document.getElementById(targetId);
        if (!input) return;

        const isPlus = this.classList.contains('qty-plus');
        const isMinus = this.classList.contains('qty-minus');
        let currentValue = parseInt(input.value) || 0;
        const maxValue = parseInt(input.getAttribute('max')) || 999;
        const minValue = parseInt(input.getAttribute('min')) || 0;

        if (isPlus && currentValue < maxValue) {
          input.value = currentValue + 1;
        } else if (isMinus && currentValue > minValue) {
          input.value = currentValue - 1;
        }

        // Trigger change event for any listeners
        input.dispatchEvent(new Event('change'));

        // Update pricing if function exists
        const form = input.closest('form');
        if (form) updatePricing(form);
      });
    });
  }

  // Handle private property checkbox logic
  function initPrivatePropertyToggle() {
    const privateAllCheckbox = document.getElementById('private_all_checkbox');
    if (!privateAllCheckbox) return;

    const privatePropertyOption = privateAllCheckbox.closest('.private-property-option');

    privateAllCheckbox.addEventListener('change', function() {
        const accommodationCheckboxes = document.querySelectorAll('.accommodation-checkbox');
        
        // Toggle visual state
        if (privatePropertyOption) {
            if (this.checked) {
                privatePropertyOption.classList.add('selected');
            } else {
                privatePropertyOption.classList.remove('selected');
            }
        }
        
        // Handle accommodation selection logic
        if (accommodationCheckboxes.length > 0) {
            accommodationCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        }
        
        // Directly update pricing after changing selections
        const form = this.closest('form');
        if (form) {
            updatePricing(form);
        }
    });

    // Also handle clicks on the entire container for better UX
    if (privatePropertyOption) {
        privatePropertyOption.addEventListener('click', function(e) {
            // Only trigger if clicking on the container itself, not the checkbox
            if (e.target === this || e.target.classList.contains('private-property-label')) {
                privateAllCheckbox.click();
            }
        });
    }
  }

  // Simple accommodation selection
  function initAccommodationSelection() {
    const container = document.querySelector('.accommodation-wheel-container');
    if (!container) return;

    const list = container.querySelector('.accommodation-list');
    const items = Array.from(list.querySelectorAll('.accommodation-item'));

    // Mark all items as original (no cloning needed)
    items.forEach((item, index) => {
        item.classList.add('is-original');
        const checkbox = item.querySelector('input[type="checkbox"]');
        if (checkbox) {
            checkbox.dataset.originalIndex = index;
            checkbox.classList.add('is-original-checkbox');
        }
    });

    // Container styling is now handled by CSS

    // Initially disable room selection until dates are chosen
    disableRoomSelection();
  }

  // Disable room selection until dates are chosen
  function disableRoomSelection() {
    const container = document.querySelector('.accommodation-wheel-container');
    if (container) {
      container.classList.add('disabled');
      
      // Disable all checkboxes
      const checkboxes = container.querySelectorAll('input[type="checkbox"]');
      checkboxes.forEach(checkbox => {
        checkbox.disabled = true;
        checkbox.checked = false;
      });
    }
  }

  // Enable room selection after dates are chosen
  function enableRoomSelection() {
    const container = document.querySelector('.accommodation-wheel-container');
    if (container) {
      container.classList.remove('disabled');
      
      // Enable checkboxes (but let room availability logic handle specific availability)
      const checkboxes = container.querySelectorAll('input[type="checkbox"]');
      checkboxes.forEach(checkbox => {
        if (!checkbox.classList.contains('private-all-checkbox')) {
          checkbox.disabled = false;
        }
      });
    }
  }

  // Date formatting utility function
  function formatDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  }

  // Global dailyPrices object for calendar pricing
  let dailyPrices = {};
  let privateEvents = {};

  // Visual Calendar Implementation
  function initVisualCalendar() {
    const calendarGrid = document.getElementById('calendarGrid');
    const currentMonthElement = document.getElementById('currentMonth');
    const prevButton = document.getElementById('prevMonth');
    const nextButton = document.getElementById('nextMonth');

    if (!calendarGrid || !currentMonthElement || !prevButton || !nextButton) {
      return; // Exit if no calendar in this form
    }

    let currentDate = new Date();
    let selectedCheckin = null;

    // Store occupied dates from server
    let occupiedDates = [];

    const monthNames = AIOHM_BOOKING.i18n.monthNames;
    const dayNames = AIOHM_BOOKING.i18n.dayNames;

    // Create a single tooltip element for the calendar
    const priceTooltip = document.createElement('div');
    priceTooltip.className = 'calendar-price-tooltip';
    priceTooltip.style.display = 'none';
    calendarGrid.parentNode.appendChild(priceTooltip);
    
    
    // Moved to outer scope to be accessible by updatePricing
    // function formatDate(date) { ... }
    // It's already defined in the global scope of the IIFE, so it's fine.


    function isDateOccupied(date) {
      return occupiedDates.some(occDate =>
        occDate.toDateString() === date.toDateString()
      );
    }

    function isDateInPast(date) {
      const today = new Date();
      today.setHours(0, 0, 0, 0);
      return date < today;
    }

    function updateSelectedDates() {
      if (!selectedCheckin) return;

      const duration = parseInt(document.getElementById('stay_duration').value) || 1;
      const checkoutDate = new Date(selectedCheckin);
      checkoutDate.setDate(selectedCheckin.getDate() + duration);

      // Update display fields
      document.getElementById('checkinDisplay').value = formatDate(selectedCheckin);
      document.getElementById('checkoutHidden').value = formatDate(checkoutDate);

      // Update check-in text display
      const checkinDisplayText = document.getElementById('checkinDisplayText');
      checkinDisplayText.textContent = selectedCheckin.toLocaleDateString('en-US', {
        weekday: 'short',
        year: 'numeric',
        month: 'short',        
        day: 'numeric'
      });

      // Update check-out text display
      const checkoutDisplay = document.getElementById('checkoutDisplay');
      checkoutDisplay.textContent = checkoutDate.toLocaleDateString('en-US', {
        weekday: 'short',
        year: 'numeric',
        month: 'short',        
        day: 'numeric'
      });

      // Enable room selection now that dates are chosen
      enableRoomSelection();
      
      // Update calendar visual selection
      updateCalendarSelection();
      updatePricing(calendarGrid.closest('form'));
      
      // Update room availability based on selected dates
      updateRoomAvailability();
    }

    function updateCalendarSelection() {
      if (!selectedCheckin) return;

      const duration = parseInt(document.getElementById('stay_duration').value) || 1;
      const allDays = calendarGrid.querySelectorAll('.calendar-day');

      allDays.forEach(day => {
        day.classList.remove('selected', 'selected-range');

        if (day.dataset.date) {
          const dayDate = new Date(day.dataset.date);

          // Check if this is the selected checkin date
          if (dayDate.toDateString() === selectedCheckin.toDateString()) {
            day.classList.add('selected');
          }
          // Check if this date is in the selected range
          else if (dayDate > selectedCheckin) {
            const daysDiff = Math.floor((dayDate - selectedCheckin) / (1000 * 60 * 60 * 24));
            if (daysDiff < duration) {
              day.classList.add('selected-range');
            }
          }
        }
      });
    }

    // Fetch availability data from server
    function fetchAvailabilityData(fromDate, toDate) {
      // Skip API call if AIOHM_BOOKING is not available (fallback to empty array)
      if (typeof AIOHM_BOOKING === 'undefined' || !AIOHM_BOOKING.rest) {
        // AIOHM_BOOKING not available, using empty availability data
        occupiedDates = [];
        return typeof Promise !== 'undefined' ? Promise.resolve() : null;
      }
      
      // Use fetch if available, otherwise use XMLHttpRequest
      if (typeof fetch !== 'undefined') {
        return fetch(AIOHM_BOOKING.rest + '/availability?from=' + fromDate + '&to=' + toDate)
          .then(response => {
            if (response.ok) {
              return response.json();
            } else {
              // Failed to fetch availability data
              return { occupied_dates: [] };
            }
          })
          .then(data => {
            // Convert date strings to Date objects
            occupiedDates = data.occupied_dates.map(dateStr => new Date(dateStr + 'T00:00:00'));
            dailyPrices = data.daily_prices || {};
            privateEvents = data.private_events || {};
          })
          .catch(error => {
            // Error fetching availability data
            occupiedDates = [];
          });
      } else {
        // Fallback for older browsers
        // Fetch API not available, using empty availability data
        occupiedDates = [];
        dailyPrices = {};
        privateEvents = {};
        return typeof Promise !== 'undefined' ? Promise.resolve() : null;
      }
    }

    function renderCalendar() {
      const year = currentDate.getFullYear();
      const month = currentDate.getMonth();

      // Fetch availability for current month (plus some buffer)
      const firstDay = new Date(year, month, 1);
      const lastDay = new Date(year, month + 1, 0);
      const fromDate = formatDate(firstDay);
      const toDate = formatDate(lastDay);
      
      // Fetch availability data and then render
      if (typeof Promise !== 'undefined') {
        fetchAvailabilityData(fromDate, toDate).then(() => {
          renderCalendarGrid();
        }).catch(() => {
          renderCalendarGrid(); // Render anyway even if fetch fails
        });
      } else {
        // Fallback for very old browsers - just render immediately
        occupiedDates = [];
        renderCalendarGrid();
      }
    }
    
    function renderCalendarGrid() {
      const year = currentDate.getFullYear();
      const month = currentDate.getMonth();

      // Update month/year display
      currentMonthElement.textContent = monthNames[month] + ' ' + year;

      // Clear calendar
      calendarGrid.innerHTML = '';

      // Add day headers
      dayNames.forEach(day => {
        const dayHeader = document.createElement('div');
        dayHeader.className = 'calendar-day-header';
        dayHeader.textContent = day;
        calendarGrid.appendChild(dayHeader);
      });

      // Get first day of month and number of days
      const firstDay = new Date(year, month, 1);
      const lastDay = new Date(year, month + 1, 0);
      const firstDayWeekday = firstDay.getDay();
      const daysInMonth = lastDay.getDate();

      // Add empty cells for days before month starts
      for (let i = 0; i < firstDayWeekday; i++) {
        const emptyDay = document.createElement('div');
        emptyDay.className = 'calendar-day other-month';
        calendarGrid.appendChild(emptyDay);
      }

      // Add days of current month
      for (let day = 1; day <= daysInMonth; day++) {
        const date = new Date(year, month, day);
        const dateString = formatDate(date);
        const dayElement = document.createElement('div');
        dayElement.className = 'calendar-day';
        dayElement.textContent = day;
        dayElement.dataset.date = dateString;

        if (dailyPrices[dateString] !== undefined) {
          dayElement.dataset.price = dailyPrices[dateString];
        }

        // Apply appropriate classes
        if (isDateInPast(date)) {
          dayElement.classList.add('past');
        } else if (isDateOccupied(date)) {
          dayElement.classList.add('occupied');
        } else {
          dayElement.classList.add('available');
          
          // Add private event classes if applicable
          if (privateEvents[dateString]) {
            const eventMode = privateEvents[dateString].mode;
            if (eventMode === 'special_pricing') {
              dayElement.classList.add('special-pricing');
            } else if (eventMode === 'private_only') {
              dayElement.classList.add('private-only');
            }
          }

          // Make focusable and add keyboard support
          dayElement.setAttribute('tabindex', '0');
          dayElement.setAttribute('role', 'button');
          dayElement.setAttribute('aria-label', AIOHM_BOOKING.i18n.selectDate.replace('%s', date.toLocaleDateString()));

          // Add click event for available dates
          const selectDate = function() {
            selectedCheckin = date;
            updateSelectedDates();
          };
          
          dayElement.addEventListener('click', selectDate);
          
          // Add keyboard support
          dayElement.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
              e.preventDefault();
              selectDate();
            }
          });
        }

        calendarGrid.appendChild(dayElement);
      }

      // Update selection display if we have a selected date
      if (selectedCheckin) {
        updateCalendarSelection();
      }
    }

    // Add hover listeners for price tooltip
    calendarGrid.addEventListener('mouseover', e => {
      const dayElement = e.target.closest('.calendar-day.available');
      if (!dayElement) return;

      const form = calendarGrid.closest('form');
      const selectedOptions = Array.from(form.querySelectorAll('.accommodation-checkbox:checked'));
      const currency = document.querySelector('.pricing-summary')?.dataset.currency || 'USD';
      
      let totalDayPrice = 0;
      const dateString = dayElement.dataset.date;
      
      if (selectedOptions.length === 0) {
        // Show "from" price if no rooms selected
        const basePrice = dayElement.dataset.price;
        if (basePrice !== undefined) {          
          priceTooltip.textContent = AIOHM_BOOKING.i18n.fromPrice.replace('%1$s', currency).replace('%2$s', parseFloat(basePrice).toFixed(2));
        } else {
          return; // No price to show
        }
      } else {
        // Calculate total for selected rooms
        selectedOptions.forEach(option => {
          const basePrice = parseFloat(option.dataset.price || 0) || 0;
          const customPrice = dailyPrices[dateString] !== undefined ? parseFloat(dailyPrices[dateString]) : null;
          
          if (customPrice !== null) {
            totalDayPrice += customPrice;
          } else {
            totalDayPrice += basePrice;
          }
        });
                
        priceTooltip.textContent = AIOHM_BOOKING.i18n.pricePerNight.replace('%1$s', currency).replace('%2$s', totalDayPrice.toFixed(2));
      }
      
      priceTooltip.style.display = 'block';

      const rect = dayElement.getBoundingClientRect();
      const containerRect = calendarGrid.parentNode.getBoundingClientRect();

      priceTooltip.style.left = `${rect.left - containerRect.left + rect.width / 2 - priceTooltip.offsetWidth / 2}px`;
      priceTooltip.style.top = `${rect.top - containerRect.top - priceTooltip.offsetHeight - 5}px`; // 5px gap
    });

    calendarGrid.addEventListener('mouseout', e => {
      const dayElement = e.target.closest('.calendar-day.available');
      if (!dayElement) return;
      priceTooltip.style.display = 'none';
    });

    // Navigation event listeners with keyboard support
    const goToPrevMonth = function() {
      currentDate.setMonth(currentDate.getMonth() - 1);
      renderCalendar();
    };
    
    const goToNextMonth = function() {
      currentDate.setMonth(currentDate.getMonth() + 1);
      renderCalendar();
    };
    
    prevButton.addEventListener('click', goToPrevMonth);
    prevButton.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        goToPrevMonth();
      }
    });

    nextButton.addEventListener('click', goToNextMonth);
    nextButton.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        goToNextMonth();
      }
    });

    // Duration change event listener
    const durationInput = document.getElementById('stay_duration');
    if (durationInput) {
      durationInput.addEventListener('change', function() {
        if (selectedCheckin) {
          updateSelectedDates();
        }
      });
    }

    // Listen for booking updates to refresh calendar state
    document.addEventListener('aiohm_booking_updated', function(e) {
      // Booking update received, refreshing calendar
      renderCalendar();
    });

    // Initial render on page load
    renderCalendar();

    // Set up polling to refresh availability every 60 seconds.
    // This ensures that if a booking is cancelled in the admin, the change
    // will be reflected on the frontend for all users.
    // Only poll when page is visible for performance
    const pollingInterval = 60000; // 60 seconds, can be adjusted
    setInterval(() => {
      if (!document.hidden) {
        renderCalendar();
      }
    }, pollingInterval);
  }

  // Legacy date input validation for simple forms
  function initSimpleDateValidation() {
    const checkinInput = document.querySelector('input[name="checkin_date"]:not(#checkinDisplay)');
    if (!checkinInput || document.getElementById('calendarGrid')) return; // Skip if visual calendar exists

    // Legacy validation code here if needed
  }

  // Room availability management
  function updateRoomAvailability() {
    const checkinDate = document.getElementById('checkinDisplay')?.value;
    const checkoutDate = document.getElementById('checkoutHidden')?.value;
    
    if (!checkinDate || !checkoutDate) {
      // Reset all rooms to available if no dates selected
      resetRoomAvailability();
      return;
    }
    
    // Get all rooms in both original and cloned items
    const accommodationItems = document.querySelectorAll('.accommodation-item');
    const privateEventBanner = document.getElementById('private-event-banner');
    
    // Check if selected dates have any private events
    const checkinFormatted = formatDate(new Date(checkinDate));
    const checkoutFormatted = formatDate(new Date(checkoutDate));
    let hasPrivateEvent = false;
    let privateEventInfo = null;
    
    // Check each day in the date range for private events
    const startDate = new Date(checkinDate);
    const endDate = new Date(checkoutDate);
    const currentDate = new Date(startDate);
    
    while (currentDate < endDate) {
      const dateString = formatDate(currentDate);
      if (privateEvents[dateString] && privateEvents[dateString].mode === 'private_only') {
        hasPrivateEvent = true;
        privateEventInfo = privateEvents[dateString];
        break;
      }
      currentDate.setDate(currentDate.getDate() + 1);
    }
    
    // Handle private events
    if (hasPrivateEvent && privateEventInfo) {
      // Show private event banner
      showPrivateEventBanner(privateEventInfo.name);
      
      // Disable all individual room selections
      accommodationItems.forEach(item => {
        item.classList.add('unavailable-private-event');
        const checkbox = item.querySelector('input[type="checkbox"]');
        if (checkbox && !checkbox.classList.contains('private-all-checkbox')) {
          checkbox.disabled = true;
          checkbox.checked = false;
        }
      });
      
      // Enable only "entire property" option
      const privateAllCheckbox = document.querySelector('.private-all-checkbox');
      if (privateAllCheckbox) {
        privateAllCheckbox.disabled = false;
      }
    } else {
      // Hide private event banner
      hidePrivateEventBanner();
      
      // Check for special pricing events and update room prices
      updateRoomPricesForSpecialEvents(checkinDate, checkoutDate, accommodationItems);
      
      // Check individual room availability based on bookings and admin blocks
      checkIndividualRoomAvailability(checkinDate, checkoutDate, accommodationItems);
    }
  }
  
  function resetRoomAvailability() {
    const accommodationItems = document.querySelectorAll('.accommodation-item');
    hidePrivateEventBanner();
    
    accommodationItems.forEach(item => {
      item.classList.remove('unavailable-private-event', 'unavailable-booked', 'has-special-pricing');
      const checkbox = item.querySelector('input[type="checkbox"]');
      const priceElement = item.querySelector('.accommodation-price');
      
      if (checkbox) {
        checkbox.disabled = false;
        
        // Restore original price if it was changed
        if (checkbox.dataset.originalPrice) {
          checkbox.dataset.price = checkbox.dataset.originalPrice;
          
          if (priceElement) {
            const currency = priceElement.textContent.split(' ')[0];
            priceElement.textContent = `${currency} ${parseFloat(checkbox.dataset.originalPrice).toFixed(2)}`;
            priceElement.classList.remove('special-price');
          }
          
          delete checkbox.dataset.originalPrice;
        }
      }
      
      // Remove special pricing indicators
      const indicator = item.querySelector('.special-pricing-indicator');
      if (indicator) {
        indicator.remove();
      }
    });
    
    // Disable room selection when no dates are selected
    disableRoomSelection();
  }
  
  function showPrivateEventBanner(eventName) {
    let banner = document.getElementById('private-event-banner');
    if (!banner) {
      banner = document.createElement('div');
      banner.id = 'private-event-banner';
      banner.className = 'private-event-banner';
      
      const accommodationSection = document.querySelector('.accommodation-wheel-container, .accommodation-section');
      if (accommodationSection) {
        accommodationSection.insertBefore(banner, accommodationSection.firstChild);
      }
    }
    
    banner.innerHTML = `
      <div class="banner-content">
        <strong>🎉 ${eventName}</strong>
        <p>Private event period - Only entire property booking available</p>
      </div>
    `;
    banner.style.display = 'block';
  }
  
  function hidePrivateEventBanner() {
    const banner = document.getElementById('private-event-banner');
    if (banner) {
      banner.style.display = 'none';
    }
  }
  
  function updateRoomPricesForSpecialEvents(checkinDate, checkoutDate, accommodationItems) {
    // Check if any dates in the range have special pricing events
    const startDate = new Date(checkinDate);
    const endDate = new Date(checkoutDate);
    const currentDate = new Date(startDate);
    
    let hasSpecialPricing = false;
    let specialPrice = null;
    let eventName = null;
    
    // Look for special pricing events in the date range
    while (currentDate < endDate) {
      const dateString = formatDate(currentDate);
      if (privateEvents[dateString] && privateEvents[dateString].mode === 'special_pricing') {
        hasSpecialPricing = true;
        specialPrice = privateEvents[dateString].price;
        eventName = privateEvents[dateString].name;
        break;
      }
      currentDate.setDate(currentDate.getDate() + 1);
    }
    
    // Update room prices if special pricing is found
    accommodationItems.forEach(item => {
      const checkbox = item.querySelector('input[type="checkbox"]');
      const priceElement = item.querySelector('.accommodation-price');
      
      if (!checkbox || !priceElement) return;
      
      if (hasSpecialPricing && specialPrice) {
        // Store original price if not already stored
        if (!checkbox.dataset.originalPrice) {
          checkbox.dataset.originalPrice = checkbox.dataset.price;
        }
        
        // Update to special price
        checkbox.dataset.price = specialPrice;
        
        // Update display price with currency
        const currency = priceElement.textContent.split(' ')[0]; // Get currency symbol
        priceElement.textContent = `${currency} ${parseFloat(specialPrice).toFixed(2)}`;
        priceElement.classList.add('special-price');
        
        // Add visual indicator
        item.classList.add('has-special-pricing');
        
        // Add special pricing indicator if not already present
        let indicator = item.querySelector('.special-pricing-indicator');
        if (!indicator) {
          indicator = document.createElement('span');
          indicator.className = 'special-pricing-indicator';
          indicator.textContent = '🎉 Special Price';
          item.querySelector('.accommodation-item-content').appendChild(indicator);
        }
        indicator.textContent = eventName ? `🎉 ${eventName}` : '🎉 Special Price';
      } else {
        // Restore original price
        if (checkbox.dataset.originalPrice) {
          checkbox.dataset.price = checkbox.dataset.originalPrice;
          
          // Update display price
          const currency = priceElement.textContent.split(' ')[0];
          priceElement.textContent = `${currency} ${parseFloat(checkbox.dataset.originalPrice).toFixed(2)}`;
          priceElement.classList.remove('special-price');
          
          // Remove visual indicators
          item.classList.remove('has-special-pricing');
          const indicator = item.querySelector('.special-pricing-indicator');
          if (indicator) {
            indicator.remove();
          }
        }
      }
    });
    
    // Update pricing calculation if form exists
    const form = document.querySelector('form');
    if (form) {
      updatePricing(form);
    }
  }
  
  function checkIndividualRoomAvailability(checkinDate, checkoutDate, accommodationItems) {
    // Skip if no AIOHM_BOOKING API available
    if (typeof AIOHM_BOOKING === 'undefined' || !AIOHM_BOOKING.rest) {
      // Enable all rooms if API not available
      accommodationItems.forEach(item => {
        item.classList.remove('unavailable-private-event', 'unavailable-booked');
        const checkbox = item.querySelector('input[type="checkbox"]');
        if (checkbox && !checkbox.classList.contains('private-all-checkbox')) {
          checkbox.disabled = false;
        }
      });
      return;
    }
    
    const fromDate = formatDate(new Date(checkinDate));
    const toDate = formatDate(new Date(checkoutDate));
    
    // Fetch detailed availability for room-level checking
    fetch(AIOHM_BOOKING.rest + '/availability?from=' + fromDate + '&to=' + toDate + '&detailed=1')
      .then(response => {
        if (response.ok) {
          return response.json();
        } else {
          throw new Error('Failed to fetch room availability');
        }
      })
      .then(data => {
        // Update each room based on availability data
        accommodationItems.forEach(item => {
          const checkbox = item.querySelector('input[type="checkbox"]');
          if (!checkbox || checkbox.classList.contains('private-all-checkbox')) return;
          
          const roomId = getRoomIdFromCheckbox(checkbox);
          if (!roomId) return;
          
          // Check if this room is blocked for any day in the date range
          const isRoomBlocked = isRoomBlockedInDateRange(roomId, fromDate, toDate, data.blocked_rooms || {});
          
          if (isRoomBlocked) {
            item.classList.add('unavailable-booked');
            item.classList.remove('unavailable-private-event');
            checkbox.disabled = true;
            checkbox.checked = false;
          } else {
            item.classList.remove('unavailable-private-event', 'unavailable-booked');
            checkbox.disabled = false;
          }
        });
      })
      .catch(error => {
        // On error, enable all rooms as fallback
        accommodationItems.forEach(item => {
          item.classList.remove('unavailable-private-event', 'unavailable-booked');
          const checkbox = item.querySelector('input[type="checkbox"]');
          if (checkbox && !checkbox.classList.contains('private-all-checkbox')) {
            checkbox.disabled = false;
          }
        });
      });
  }
  
  function getRoomIdFromCheckbox(checkbox) {
    // Extract room ID from checkbox value or data attribute
    // Convert from 0-based frontend index to 1-based admin calendar room ID
    const frontendIndex = parseInt(checkbox.value) || parseInt(checkbox.dataset.roomId) || null;
    return frontendIndex !== null ? frontendIndex + 1 : null;
  }
  
  function isRoomBlockedInDateRange(roomId, fromDate, toDate, blockedRooms) {
    // Check if the specific room is blocked for any date in the range
    const startDate = new Date(fromDate);
    const endDate = new Date(toDate);
    const currentDate = new Date(startDate);
    
    while (currentDate < endDate) {
      const dateString = formatDate(currentDate);
      if (blockedRooms[roomId] && blockedRooms[roomId][dateString]) {
        return true;
      }
      currentDate.setDate(currentDate.getDate() + 1);
    }
    
    return false;
  }

  // Enhanced initialization with error boundaries
  function init() {
    try {
      // Initialize forms with error handling
      const forms = Utils.qsa(CONFIG.SELECTORS.bookingForm);

      if (forms.length > 0) {
        forms.forEach(form => {
          try {
            initializeForm(form);
          } catch (error) {
            ErrorHandler.logError(error, `Form initialization failed for form: ${form.id}`);
          }
        });
      } else {
        // No booking forms found on this page, but attempting to initialize global components
      }

      // Initialize UI components with individual error handling
      const components = [
        { name: 'Quantity Selectors', init: initQuantitySelectors },
        { name: 'Private Property Toggle', init: initPrivatePropertyToggle },
        { name: 'Visual Calendar', init: initVisualCalendar },
        { name: 'Simple Date Validation', init: initSimpleDateValidation },
        { name: 'Accommodation Selection', init: initAccommodationSelection },
        { name: 'Room Availability Management', init: function() { updateRoomAvailability(); } }
      ];

      components.forEach(({ name, init }) => {
        try {
          init();
        } catch (error) {
          ErrorHandler.logError(error, `${name} initialization failed`);
        }
      });

      // Frontend application initialized successfully

    } catch (error) {
      ErrorHandler.logError(error, 'Critical initialization failure');
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Enhanced Public API with error handling
  window.AIOHM_BOOKING_UI = {
    // Core API methods
    hold,
    showMessage,
    validateForm,
    initializeForm,

    // Utility methods
    Utils,
    FormValidator,
    ErrorHandler,

    // API client
    apiClient,

    // Configuration
    CONFIG,

    // Version info
    version: '2.0.0',

    // Health check method
    healthCheck() {
      return {
        initialized: true,
        formsFound: Utils.qsa(CONFIG.SELECTORS.bookingForm).length,
        apiEndpoint: window.AIOHM_BOOKING?.rest || CONFIG.API.baseUrl,
        hasNonce: !!(window.AIOHM_BOOKING?.nonce)
      };
    }
  };

  // Production build - debug mode removed
  // Performance monitoring (if available)
  if ('performance' in window && performance.mark) {
    performance.mark('aiohm-booking-mvp-init-complete');
  }

  init();
