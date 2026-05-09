/**
 * Élour Pay — Checkout Modal v2
 * Three screens: Bank Details → OTP → Receipt
 * All payment calls server-side via AJAX.
 */
(function ($) {
    'use strict';

    const state = {
        orderId:     null,
        redirectUrl: null,
        otpTimer:    null,
        otpSeconds:  120,
        bank:        '',
        account:     '',
    };

    // ── Inject modal HTML ────────────────────────────────────────────────────
    function injectModal() {
        if ($('#elour-pay-overlay').length) return;
        $('body').append(`
        <div id="elour-pay-overlay">
          <div id="elour-pay-modal" role="dialog" aria-modal="true" aria-label="Élour Pay Secure Checkout">

            <div class="elour-modal-header">
              <div class="elour-modal-brand">ÉLOUR <span>PAY</span></div>
              <button class="elour-modal-close" aria-label="Close">&times;</button>
            </div>

            <div class="elour-steps">
              <div class="elour-step active" data-step="1">
                <div class="elour-step-dot">1</div>
                <div class="elour-step-label">Bank</div>
              </div>
              <div class="elour-step" data-step="2">
                <div class="elour-step-dot">2</div>
                <div class="elour-step-label">Verify</div>
              </div>
              <div class="elour-step" data-step="3">
                <div class="elour-step-dot">&#10003;</div>
                <div class="elour-step-label">Done</div>
              </div>
            </div>

            <div class="elour-modal-body">

              <!-- Screen 1: Bank + Account -->
              <div id="elour-screen-1" class="elour-screen active">
                <p class="elour-screen-title">Select Your Bank</p>
                <p class="elour-screen-subtitle">Enter your details to receive a one-time verification code.</p>

                <div class="elour-amount-strip">
                  <span class="elour-amount-label">Order Total</span>
                  <span class="elour-amount-value" id="elour-order-amount">—</span>
                </div>

                <div class="elour-error" id="elour-err-1"></div>

                <div class="elour-field">
                  <label for="elour-bank-select">Bank</label>
                  <div class="elour-select-wrap">
                    <select id="elour-bank-select">
                      <option value="">— Choose your bank —</option>
                      <option value="HBL">HBL — Habib Bank Limited</option>
                      <option value="UBL">UBL — United Bank Limited</option>
                      <option value="MCB">MCB — Muslim Commercial Bank</option>
                      <option value="ABL">ABL — Allied Bank Limited</option>
                      <option value="MEEZAN">Meezan Bank</option>
                      <option value="ALFALAH">Bank Alfalah</option>
                      <option value="FAYSAL">Faysal Bank</option>
                      <option value="ASKARI">Askari Bank</option>
                      <option value="HABIB_METRO">Habib Metropolitan Bank</option>
                      <option value="SILK">Silkbank</option>
                      <option value="SCB">Standard Chartered Pakistan</option>
                      <option value="BOP">Bank of Punjab</option>
                      <option value="BOK">Bank of Khyber</option>
                      <option value="SINDH">Bank of Sindh</option>
                      <option value="SUMMIT">Summit Bank</option>
                    </select>
                  </div>
                </div>

                <div class="elour-reveal" id="elour-account-reveal">
                  <div class="elour-field">
                    <label for="elour-account-input">Account / IBAN Number</label>
                    <input type="text" id="elour-account-input" class="elour-input"
                      placeholder="Enter your account or IBAN number"
                      autocomplete="off" inputmode="numeric" />
                  </div>
                </div>

                <button class="elour-btn" id="elour-btn-1">Send OTP</button>

                <div class="elour-secure-badge">
                  <svg viewBox="0 0 24 24" fill="none" stroke="#6B6356" stroke-width="1.8" stroke-linecap="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                  <span>Secured by Élour Pay &middot; Bank-grade encryption</span>
                </div>
              </div>

              <!-- Screen 2: OTP -->
              <div id="elour-screen-2" class="elour-screen">
                <p class="elour-screen-title">Verify Payment</p>
                <p class="elour-screen-subtitle">Enter the 6-digit OTP sent to your registered mobile number.</p>

                <div class="elour-error" id="elour-err-2"></div>

                <div class="elour-field">
                  <label>One-Time Password</label>
                  <div class="elour-otp-row">
                    <input class="elour-otp-box" maxlength="1" inputmode="numeric" pattern="[0-9]" id="elour-otp-0">
                    <input class="elour-otp-box" maxlength="1" inputmode="numeric" pattern="[0-9]" id="elour-otp-1">
                    <input class="elour-otp-box" maxlength="1" inputmode="numeric" pattern="[0-9]" id="elour-otp-2">
                    <input class="elour-otp-box" maxlength="1" inputmode="numeric" pattern="[0-9]" id="elour-otp-3">
                    <input class="elour-otp-box" maxlength="1" inputmode="numeric" pattern="[0-9]" id="elour-otp-4">
                    <input class="elour-otp-box" maxlength="1" inputmode="numeric" pattern="[0-9]" id="elour-otp-5">
                  </div>
                </div>

                <div class="elour-timer-row">
                  <span class="elour-countdown">Expires in <strong id="elour-timer-val">2:00</strong></span>
                  <button class="elour-resend-btn" id="elour-resend-btn">Resend OTP</button>
                </div>

                <button class="elour-btn" id="elour-btn-2">Confirm Payment</button>

                <div class="elour-secure-badge">
                  <svg viewBox="0 0 24 24" fill="none" stroke="#6B6356" stroke-width="1.8" stroke-linecap="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                  <span>Secured by Élour Pay &middot; Bank-grade encryption</span>
                </div>
              </div>

              <!-- Screen 3: Receipt -->
              <div id="elour-screen-success" class="elour-screen" style="text-align:center;">
                <div class="elour-success-icon">
                  <svg class="elour-success-check" viewBox="0 0 24 24">
                    <polyline points="20 6 9 17 4 12"/>
                  </svg>
                </div>
                <p class="elour-screen-title" style="margin-bottom:5px;">Payment Confirmed</p>
                <p class="elour-screen-subtitle" style="margin-bottom:0;">Your order has been placed successfully.</p>

                <div class="elour-receipt">
                  <div class="elour-receipt-row">
                    <span class="elour-receipt-label">Amount Paid</span>
                    <span class="elour-receipt-val gold" id="elour-receipt-amount">—</span>
                  </div>
                  <div class="elour-receipt-row">
                    <span class="elour-receipt-label">Merchant</span>
                    <span class="elour-receipt-val">Élour Personal Care</span>
                  </div>
                  <div class="elour-receipt-row">
                    <span class="elour-receipt-label">Bank</span>
                    <span class="elour-receipt-val" id="elour-receipt-bank">—</span>
                  </div>
                  <div class="elour-receipt-row">
                    <span class="elour-receipt-label">Transaction ID</span>
                    <span class="elour-receipt-val mono" id="elour-receipt-txn">—</span>
                  </div>
                  <div class="elour-receipt-row">
                    <span class="elour-receipt-label">Date</span>
                    <span class="elour-receipt-val" id="elour-receipt-date">—</span>
                  </div>
                  <div class="elour-receipt-row">
                    <span class="elour-receipt-label">Status</span>
                    <span class="elour-receipt-val" style="color:#2A6E47;font-weight:600;">&#10003; Confirmed</span>
                  </div>
                </div>

                <button class="elour-btn" id="elour-btn-done">View My Order</button>

                <div class="elour-secure-badge" style="margin-top:14px;">
                  <svg viewBox="0 0 24 24" fill="none" stroke="#6B6356" stroke-width="1.8" stroke-linecap="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                  <span>Securely processed by Élour Pay</span>
                </div>
              </div>

            </div>
          </div>
        </div>`);
    }

    // ── Modal open/close ─────────────────────────────────────────────────────
    function openModal(amount) {
        if (amount) $('#elour-order-amount').text(amount);
        goToScreen(1);
        resetOTP();
        $('#elour-pay-overlay').addClass('active');
        $('body').css('overflow', 'hidden');
    }

    function closeModal() {
        $('#elour-pay-overlay').removeClass('active');
        $('body').css('overflow', '');
        stopTimer();
    }

    // ── Screen navigation ────────────────────────────────────────────────────
    function goToScreen(n) {
        $('.elour-screen').removeClass('active');
        setTimeout(() => {
            const id = n === 3 ? '#elour-screen-success' : '#elour-screen-' + n;
            $(id).addClass('active');
        }, 20);

        $('.elour-step').each(function () {
            const s = parseInt($(this).data('step'));
            $(this).removeClass('active completed');
            if      (s < n)  $(this).addClass('completed');
            else if (s === n) $(this).addClass('active');
        });

        clearErrors();
    }

    // ── Errors ───────────────────────────────────────────────────────────────
    function showErr(screen, msg) {
        $('#elour-err-' + screen).text(msg).addClass('visible');
    }
    function clearErrors() {
        $('.elour-error').removeClass('visible').text('');
    }

    // ── Button states ────────────────────────────────────────────────────────
    function btnLoad($b, txt) { $b.prop('disabled', true).addClass('loading').text(txt || 'Processing…'); }
    function btnReset($b, txt) { $b.prop('disabled', false).removeClass('loading').text(txt); }

    // ── OTP timer ────────────────────────────────────────────────────────────
    function startTimer() {
        state.otpSeconds = 120;
        stopTimer();
        updateTimer();
        state.otpTimer = setInterval(() => {
            state.otpSeconds--;
            updateTimer();
            if (state.otpSeconds <= 0) {
                stopTimer();
                $('#elour-resend-btn').addClass('visible');
                $('#elour-btn-2').prop('disabled', true);
                showErr(2, 'OTP expired. Please request a new one.');
            }
        }, 1000);
    }
    function stopTimer() { clearInterval(state.otpTimer); state.otpTimer = null; }
    function updateTimer() {
        const m = Math.floor(state.otpSeconds / 60);
        const s = state.otpSeconds % 60;
        $('#elour-timer-val').text(m + ':' + String(s).padStart(2, '0'));
    }

    // ── OTP helpers ──────────────────────────────────────────────────────────
    function resetOTP() {
        for (let i = 0; i < 6; i++) {
            $('#elour-otp-' + i).val('').removeClass('filled');
        }
        $('#elour-resend-btn').removeClass('visible');
        $('#elour-btn-2').prop('disabled', false);
        stopTimer();
    }

    function getOTP() {
        let val = '';
        for (let i = 0; i < 6; i++) val += $('#elour-otp-' + i).val();
        return val;
    }

    // ── AJAX: initiate + send OTP ────────────────────────────────────────────
    function sendOTP(bank, account) {
        const $btn = $('#elour-btn-1');
        btnLoad($btn, 'Sending OTP…'); clearErrors();

        $.post(ElourPay.ajax_url, {
            action: 'elour_pay_initiate', nonce: ElourPay.nonce, order_id: state.orderId
        }).done(r => {
            if (!r.success) { showErr(1, r.data.message || 'Could not start session.'); btnReset($btn, 'Send OTP'); return; }

            $.post(ElourPay.ajax_url, {
                action: 'elour_pay_send_otp', nonce: ElourPay.nonce,
                order_id: state.orderId, bank_code: bank, account_number: account
            }).done(r2 => {
                if (!r2.success) { showErr(1, r2.data.message || 'Could not send OTP.'); btnReset($btn, 'Send OTP'); return; }
                btnReset($btn, 'Send OTP');
                state.bank = bank;
                const bankLabel = $('#elour-bank-select option:selected').text().split('—')[0].trim();
                $('#elour-receipt-bank').text(bankLabel);
                goToScreen(2);
                startTimer();
                setTimeout(() => $('#elour-otp-0').focus(), 300);
            }).fail(() => { showErr(1, 'Network error. Please try again.'); btnReset($btn, 'Send OTP'); });
        }).fail(() => { showErr(1, 'Network error. Please try again.'); btnReset($btn, 'Send OTP'); });
    }

    // ── AJAX: verify OTP ─────────────────────────────────────────────────────
    function verifyOTP(otp) {
        const $btn = $('#elour-btn-2');
        btnLoad($btn, 'Confirming…'); clearErrors();

        $.post(ElourPay.ajax_url, {
            action: 'elour_pay_verify_otp', nonce: ElourPay.nonce,
            order_id: state.orderId, otp: otp
        }).done(r => {
            if (!r.success) { showErr(2, r.data.message || 'Incorrect OTP. Please try again.'); btnReset($btn, 'Confirm Payment'); return; }

            stopTimer();
            state.redirectUrl = r.data.redirect_url;

            // Populate receipt
            const now = new Date();
            $('#elour-receipt-txn').text(r.data.reference || 'EL-' + state.orderId);
            $('#elour-receipt-date').text(now.toLocaleDateString('en-PK', { day: 'numeric', month: 'long', year: 'numeric' }));
            // Amount from displayed strip
            $('#elour-receipt-amount').text($('#elour-order-amount').text());

            goToScreen(3);
        }).fail(() => { showErr(2, 'Network error. Please try again.'); btnReset($btn, 'Confirm Payment'); });
    }

    // ── WooCommerce checkout intercept ───────────────────────────────────────
    function initCheckoutIntercept() {
        $.ajaxPrefilter(function (options, originalOptions, jqXHR) {
            if (!options.url || options.url.indexOf('wc-ajax=checkout') === -1) return;
            if ($('input[name="payment_method"]:checked').val() !== 'elour_pay') return;

            const _success = options.success;
            options.success = function (data) {
                const res = (typeof data === 'string') ? JSON.parse(data) : data;

                if (res && (res.result === 'elour_modal' || res.elour_modal === true)) {
                    state.orderId     = res.order_id;
                    state.redirectUrl = res.redirect;
                    if (res.nonce) ElourPay.nonce = res.nonce;

                    // Get formatted order total for receipt
                    const total = $('.order-total .woocommerce-Price-amount').first().text() ||
                                  $('.wc-block-formatted-money-amount').last().text() || '';

                    $('#place_order').prop('disabled', false).removeClass('loading').val('Place Order');
                    openModal(total);
                    return;
                }

                if (_success) _success.apply(this, arguments);
            };
        });
    }

    // ── Event bindings ───────────────────────────────────────────────────────
    function bindEvents() {

        // Close
        $(document.body).on('click', '.elour-modal-close', closeModal);
        $(document.body).on('click', '#elour-pay-overlay', e => {
            if ($(e.target).is('#elour-pay-overlay')) closeModal();
        });
        $(document).on('keydown', e => { if (e.key === 'Escape') closeModal(); });

        // Bank select → reveal IBAN field
        $(document.body).on('change', '#elour-bank-select', function () {
            if ($(this).val()) {
                $('#elour-account-reveal').addClass('shown');
                setTimeout(() => $('#elour-account-input').focus(), 650);
            } else {
                $('#elour-account-reveal').removeClass('shown');
            }
            clearErrors();
        });

        // Screen 1: Send OTP
        $(document.body).on('click', '#elour-btn-1', function () {
            const bank    = $('#elour-bank-select').val();
            const account = $('#elour-account-input').val().trim();
            if (!bank)              { showErr(1, 'Please select your bank.'); return; }
            if (account.length < 8) { showErr(1, 'Please enter a valid account or IBAN number.'); return; }
            sendOTP(bank, account);
        });

        // OTP boxes: auto-advance + backspace
        $(document.body).on('input', '.elour-otp-box', function () {
            const val = $(this).val().replace(/[^0-9]/g, '');
            $(this).val(val);
            const idx = parseInt($(this).attr('id').replace('elour-otp-', ''));
            if (val) {
                $(this).addClass('filled');
                if (idx < 5) $('#elour-otp-' + (idx + 1)).focus();
            } else {
                $(this).removeClass('filled');
            }
            clearErrors();
        });

        $(document.body).on('keydown', '.elour-otp-box', function (e) {
            const idx = parseInt($(this).attr('id').replace('elour-otp-', ''));
            if (e.key === 'Backspace' && !$(this).val() && idx > 0) {
                $('#elour-otp-' + (idx - 1)).focus();
            }
            if (e.key === 'Enter') $('#elour-btn-2').trigger('click');
        });

        // Screen 2: Confirm payment
        $(document.body).on('click', '#elour-btn-2', function () {
            const otp = getOTP();
            if (!/^\d{6}$/.test(otp)) { showErr(2, 'Please enter the complete 6-digit OTP.'); return; }
            verifyOTP(otp);
        });

        // Screen 2: Resend
        $(document.body).on('click', '#elour-resend-btn', function () {
            resetOTP(); clearErrors();
            sendOTP(state.bank, $('#elour-account-input').val().trim());
        });

        // Screen 3: Done
        $(document.body).on('click', '#elour-btn-done', function () {
            if (state.redirectUrl) window.location.href = state.redirectUrl;
        });

        // Re-inject after WC refresh
        $(document.body).on('updated_checkout', () => {
            if (!$('#elour-pay-overlay').length) injectModal();
        });
    }

    // ── Init ─────────────────────────────────────────────────────────────────
    $(document).ready(function () {
        if (typeof ElourPay === 'undefined') return;
        injectModal();
        bindEvents();
        initCheckoutIntercept();
    });

})(jQuery);
