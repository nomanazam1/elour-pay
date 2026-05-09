<?php
defined( 'ABSPATH' ) || exit;

class Elour_Pay_Ajax {

    private Elour_Pay_Gateway $gateway;
    private Elour_Pay_Safepay_API $api;

    public function __construct( Elour_Pay_Gateway $gateway ) {
        $this->gateway = $gateway;
        $this->api     = new Elour_Pay_Safepay_API(
            $gateway->get_secret_key(),
            $gateway->get_public_key(),
            $gateway->get_api_base()
        );

        add_action( 'wp_ajax_elour_pay_initiate',          [ $this, 'initiate' ] );
        add_action( 'wp_ajax_nopriv_elour_pay_initiate',   [ $this, 'initiate' ] );
        add_action( 'wp_ajax_elour_pay_send_otp',          [ $this, 'send_otp' ] );
        add_action( 'wp_ajax_nopriv_elour_pay_send_otp',   [ $this, 'send_otp' ] );
        add_action( 'wp_ajax_elour_pay_verify_otp',        [ $this, 'verify_otp' ] );
        add_action( 'wp_ajax_nopriv_elour_pay_verify_otp', [ $this, 'verify_otp' ] );
    }

    // ── Step 1: Create Safepay session ────────────────────────────────────────
    public function initiate(): void {
        $this->verify_nonce();

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $order    = wc_get_order( $order_id );

        if ( ! $order ) $this->error( 'Invalid order.' );
        if ( $order->get_status() !== 'pending' ) $this->error( 'This order has already been processed.' );

        $result = $this->api->create_payment_session(
            (float) $order->get_total(),
            $order->get_currency(),
            $order_id
        );

        if ( ! $result['success'] ) {
            elour_pay_log( 'Session failed for order #' . $order_id . ': ' . $result['message'], 'error' );
            $this->error( $result['message'] );
        }

        WC()->session->set( 'elour_pay_tracker_' . $order_id, $result['tracker'] );
        WC()->session->set( 'elour_pay_otp_attempts_' . $order_id, 0 );

        elour_pay_log( 'Session created for order #' . $order_id );
        $this->success( [ 'session_ready' => true, 'order_id' => $order_id ] );
    }

    // ── Step 2: Send OTP ──────────────────────────────────────────────────────
    public function send_otp(): void {
        $this->verify_nonce();

        $order_id       = absint( $_POST['order_id'] ?? 0 );
        $bank_code      = sanitize_text_field( $_POST['bank_code'] ?? '' );
        $account_number = sanitize_text_field( $_POST['account_number'] ?? '' );

        if ( ! $order_id || ! $bank_code || ! $account_number ) $this->error( 'Missing required fields.' );
        if ( strlen( $account_number ) < 8 || strlen( $account_number ) > 34 ) $this->error( 'Please enter a valid account number.' );

        $tracker = WC()->session->get( 'elour_pay_tracker_' . $order_id );
        if ( ! $tracker ) $this->error( 'Payment session expired. Please refresh and try again.' );

        // Store bank code for later sync
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $order->update_meta_data( '_elour_bank', $bank_code );
            $order->save();
        }

        $result = $this->api->initiate_otp_debit( $tracker, $bank_code, $account_number );

        if ( ! $result['success'] ) {
            elour_pay_log( 'OTP failed for order #' . $order_id . ': ' . $result['message'], 'error' );
            $this->error( $result['message'] );
        }

        elour_pay_log( 'OTP sent for order #' . $order_id );
        $this->success( [ 'message' => 'OTP sent to your registered mobile number.' ] );
    }

    // ── Step 3: Verify OTP ────────────────────────────────────────────────────
    public function verify_otp(): void {
        $this->verify_nonce();

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $otp      = sanitize_text_field( $_POST['otp'] ?? '' );

        if ( ! $order_id || ! $otp ) $this->error( 'Missing required fields.' );
        if ( ! preg_match( '/^\d{4,8}$/', $otp ) ) $this->error( 'Invalid OTP format.' );

        // Brute-force protection
        $attempts = (int) WC()->session->get( 'elour_pay_otp_attempts_' . $order_id, 0 );
        if ( $attempts >= 5 ) $this->error( 'Too many incorrect attempts. Please restart the payment.' );
        WC()->session->set( 'elour_pay_otp_attempts_' . $order_id, $attempts + 1 );

        $tracker = WC()->session->get( 'elour_pay_tracker_' . $order_id );
        if ( ! $tracker ) $this->error( 'Payment session expired. Please refresh and try again.' );

        $order = wc_get_order( $order_id );
        if ( ! $order ) $this->error( 'Order not found.' );

        $result = $this->api->verify_otp_and_debit( $tracker, $otp );

        if ( ! $result['success'] ) {
            elour_pay_log( 'OTP verify failed for order #' . $order_id . ': ' . $result['message'], 'error' );
            $this->error( $result['message'] );
        }

        // Save reference, clear session
        $order->update_meta_data( '_elour_pay_tracker',   $tracker );
        $order->update_meta_data( '_elour_pay_reference', $result['reference'] ?? '' );
        $order->save();

        WC()->session->__unset( 'elour_pay_tracker_' . $order_id );
        WC()->session->__unset( 'elour_pay_otp_attempts_' . $order_id );

        $order->update_status( 'on-hold', 'Élour Pay: OTP verified — awaiting Safepay payment confirmation.' );

        // Sync to dashboard
        $sync = new Elour_Pay_Sync();
        $sync->sync_order( $order );
        $sync->sync_transaction( $order, 'on_hold', $tracker, $result['reference'] ?? '' );

        elour_pay_log( 'OTP verified for order #' . $order_id . ' — awaiting webhook.' );

        $this->success( [
            'message'      => 'Payment authorised. Your order is being confirmed.',
            'redirect_url' => $order->get_checkout_order_received_url(),
            'order_id'     => $order_id,
            'reference'    => $result['reference'] ?? '',
        ] );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    private function verify_nonce(): void {
        if ( ! check_ajax_referer( 'elour_pay_nonce', 'nonce', false ) ) {
            $this->error( 'Security check failed. Please refresh the page.' );
        }
    }

    private function success( array $data ): void { wp_send_json_success( $data ); }

    private function error( string $message ): void {
        wp_send_json_error( [ 'message' => $message ] );
        exit;
    }
}
