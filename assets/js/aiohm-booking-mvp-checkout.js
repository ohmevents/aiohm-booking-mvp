// Enhanced Stripe payment handler
async function handleStripePayment(orderId) {
    const btn = document.querySelector('.stripe-btn');
    const originalText = btn.innerHTML;

    // Show loading state safely
    while (btn.firstChild) {
        btn.removeChild(btn.firstChild);
    }
    var textSpan = document.createElement('span');
    textSpan.className = 'btn-text';
    textSpan.textContent = 'Processing...';
    var loadingSpan = document.createElement('span');
    loadingSpan.className = 'btn-loading';
    loadingSpan.textContent = '⏳';
    btn.appendChild(textSpan);
    btn.appendChild(loadingSpan);
    btn.disabled = true;

    try {
        const response = await fetch(AIOHM_CHECKOUT_DATA.stripe_session_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': AIOHM_CHECKOUT_DATA.nonce
            },
            body: JSON.stringify({order_id: orderId})
        });

        const data = await response.json();

        if (data.redirect_url) {
            window.location = data.redirect_url;
        } else if (data.checkout_url) {
            window.location = data.checkout_url;
        } else {
            throw new Error(data.message || 'Unable to create checkout session');
        }

    } catch (error) {
        alert(AIOHM_CHECKOUT_DATA.i18n.checkout_error);

        // Restore button safely
        while (btn.firstChild) {
            btn.removeChild(btn.firstChild);
        }
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
}

// PayPal integration (if enabled)
if (AIOHM_CHECKOUT_DATA.paypal_ready) {
    // Initialize PayPal when page loads
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof paypal !== 'undefined') {
            initializePayPal();
        } else {
            // Load PayPal SDK if not already loaded
            const script = document.createElement('script');
            script.src = `https://www.paypal.com/sdk/js?client-id=${AIOHM_CHECKOUT_DATA.paypal_client_id}&currency=${AIOHM_CHECKOUT_DATA.currency}&components=buttons&intent=capture`;
            script.onload = initializePayPal;
            script.onerror = function(){ try { showPayPalCspWarning(); } catch(e){} };
            document.head.appendChild(script);
            setTimeout(function(){ if (typeof paypal === 'undefined') { try { showPayPalCspWarning(); } catch(e){} } }, 3500);
        }
    });

    function initializePayPal() {
        paypal.Buttons({
            style: {
                layout: 'horizontal',
                color: 'blue',
                shape: 'rect',
                label: 'pay',
                height: 45
            },
            createOrder: function(data, actions) {
                return actions.order.create({
                    purchase_units: [{
                        amount: {
                            value: AIOHM_CHECKOUT_DATA.deposit_amount
                        },
                        description: `Booking Order #${AIOHM_CHECKOUT_DATA.order_id}`
                    }]
                });
            },
            onApprove: function(data, actions) {
                return actions.order.capture().then(function(details) {
                    // Send capture info to your server
                    fetch(AIOHM_CHECKOUT_DATA.paypal_capture_url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': AIOHM_CHECKOUT_DATA.nonce
                        },
                        body: JSON.stringify({
                            order_id: AIOHM_CHECKOUT_DATA.order_id,
                            paypal_order_id: data.orderID,
                            capture_details: details
                        })
                    }).then(function(response) {
                        if (response.ok) {
                            window.location = `${AIOHM_CHECKOUT_DATA.thankyou_url}?order_id=${AIOHM_CHECKOUT_DATA.order_id}`;
                        }
                    });
                });
            },
            onError: function(err) {
                alert(AIOHM_CHECKOUT_DATA.i18n.payment_error);
            }
        }).render('#paypal-button-container');
    }
    function showPayPalCspWarning(){
      var el = document.getElementById('paypal-csp-warning');
      if (!el) return;
      el.style.display = '';
      // Clear existing content safely
      while (el.firstChild) {
        el.removeChild(el.firstChild);
      }
      
      // Create warning box using safe DOM methods
      var box = document.createElement('div');
      box.className = 'paypal-csp-box';
      
      var title = document.createElement('strong');
      title.textContent = 'PayPal was blocked by Content-Security-Policy.';
      box.appendChild(title);
      
      box.appendChild(document.createElement('br'));
      
      var description = document.createTextNode('Allow these domains in your CSP to enable PayPal:');
      box.appendChild(description);
      
      var list = document.createElement('div');
      list.className = 'paypal-csp-list';
      
      var domains = [
        { label: 'script-src/script-src-elem', value: 'https://www.paypal.com https://www.paypalobjects.com' },
        { label: 'connect-src', value: 'https://api-m.paypal.com https://api-m.sandbox.paypal.com' },
        { label: 'frame-src', value: 'https://www.paypal.com' },
        { label: 'img-src', value: 'https://www.paypalobjects.com data:' }
      ];
      
      domains.forEach(function(domain) {
        var item = document.createElement('div');
        var label = document.createElement('strong');
        label.textContent = domain.label;
        item.appendChild(label);
        item.appendChild(document.createTextNode(': ' + domain.value));
        list.appendChild(item);
      });
      
      box.appendChild(list);
      
      var note = document.createElement('small');
      note.className = 'paypal-csp-note';
      note.textContent = 'After updating CSP, reload this page to see the button.';
      box.appendChild(note);
      
      el.appendChild(box);
    }
}

// Event listener for Stripe payment buttons
document.addEventListener('DOMContentLoaded', function() {
    // Handle Stripe payment button clicks
    const stripeButtons = document.querySelectorAll('.stripe-btn');
    stripeButtons.forEach(button => {
        button.addEventListener('click', function() {
            const orderId = this.getAttribute('data-order-id');
            if (orderId && typeof handleStripePayment === 'function') {
                handleStripePayment(orderId);
            }
        });
    });
});