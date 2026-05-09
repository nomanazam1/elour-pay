<?php
defined( 'ABSPATH' ) || exit;

class Elour_Pay_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'elour_pay';
        $this->method_title       = 'Élour Pay';
        $this->method_description = 'Accept payments directly from any Pakistani bank account via OTP — powered by Safepay, branded as Élour Pay.';
        $this->has_fields         = true;
        $this->supports           = [ 'products', 'refunds' ];

        $this->init_form_fields();
        $this->init_settings();

        $this->title        = $this->get_option( 'title' );
        $this->description  = $this->get_option( 'description' );
        $this->enabled      = $this->get_option( 'enabled' );
        $this->sandbox_mode = $this->get_option( 'sandbox_mode' );

        $this->secret_key = $this->sandbox_mode === 'yes'
            ? $this->get_option( 'sandbox_secret_key' )
            : $this->get_option( 'live_secret_key' );

        $this->public_key = $this->sandbox_mode === 'yes'
            ? $this->get_option( 'sandbox_public_key' )
            : $this->get_option( 'live_public_key' );

        $this->api_base = $this->sandbox_mode === 'yes'
            ? 'https://sandbox.api.getsafepay.com'
            : 'https://api.getsafepay.com';

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        add_action( 'woocommerce_api_elour_pay_webhook', [ $this, 'handle_webhook' ] );
    }

    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title'   => 'Enable / Disable',
                'type'    => 'checkbox',
                'label'   => 'Enable Élour Pay',
                'default' => 'yes',
            ],
            'title' => [
                'title'       => 'Payment Method Title',
                'type'        => 'text',
                'default'     => 'Pay via Bank Account',
                'desc_tip'    => true,
                'description' => 'Shown to the customer at checkout.',
            ],
            'description' => [
                'title'   => 'Description',
                'type'    => 'textarea',
                'default' => 'Pay securely from any Pakistani bank account using a one-time OTP.',
            ],
            'sandbox_mode' => [
                'title'       => 'Sandbox Mode',
                'type'        => 'checkbox',
                'label'       => 'Enable sandbox (test) mode',
                'default'     => 'yes',
                'description' => 'Disable when going live.',
                'desc_tip'    => true,
            ],
            'sandbox_keys_title' => [
                'title'       => 'Sandbox API Keys',
                'type'        => 'title',
                'description' => 'From Safepay dashboard → Developer → API Keys (Sandbox).',
            ],
            'sandbox_secret_key' => [
                'title'       => 'Sandbox Secret Key',
                'type'        => 'password',
                'default'     => '',
                'desc_tip'    => true,
                'description' => 'Never share this. Server-side only.',
            ],
            'sandbox_public_key' => [
                'title'   => 'Sandbox Public Key',
                'type'    => 'text',
                'default' => '',
            ],
            'live_keys_title' => [
                'title'       => 'Live API Keys',
                'type'        => 'title',
                'description' => 'Disable sandbox mode above before going live.',
            ],
            'live_secret_key' => [
                'title'   => 'Live Secret Key',
                'type'    => 'password',
                'default' => '',
            ],
            'live_public_key' => [
                'title'   => 'Live Public Key',
                'type'    => 'text',
                'default' => '',
            ],
            'dashboard_title' => [
                'title'       => 'Dashboard Sync',
                'type'        => 'title',
                'description' => 'Connect to your Élour Pay Dashboard to sync transactions in real time.',
            ],
            'api_url' => [
                'title'       => 'Dashboard API URL',
                'type'        => 'text',
                'default'     => '',
                'description' => 'Your Vercel API URL.',
                'desc_tip'    => true,
            ],
            'api_secret' => [
                'title'       => 'Dashboard API Secret',
                'type'        => 'password',
                'default'     => '',
                'description' => 'The API_SECRET from your Vercel environment variables.',
                'desc_tip'    => true,
            ],
            'webhook_title' => [
                'title'       => 'Webhook',
                'type'        => 'title',
                'description' => sprintf(
                    'Add this URL in your Safepay dashboard → Webhooks:<br><code>%s</code>',
                    esc_url( home_url( '/wc-api/elour_pay_webhook' ) )
                ),
            ],
            'webhook_secret' => [
                'title'       => 'Webhook Secret',
                'type'        => 'password',
                'default'     => '',
                'description' => 'Copy from Safepay webhook settings.',
                'desc_tip'    => true,
            ],
        ];
    }

    public function enqueue_scripts() {
        if ( ! is_checkout() ) return;

        wp_enqueue_style(
            'elour-pay',
            ELOUR_PAY_URL . 'assets/css/checkout.css',
            [],
            ELOUR_PAY_VERSION
        );

        wp_enqueue_script(
            'elour-pay',
            ELOUR_PAY_URL . 'assets/js/checkout.js',
            [ 'jquery' ],
            ELOUR_PAY_VERSION,
            true
        );

        wp_localize_script( 'elour-pay', 'ElourPay', [
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'elour_pay_nonce' ),
            'public_key' => $this->public_key,
            'sandbox'    => $this->sandbox_mode === 'yes' ? '1' : '0',
            'currency'   => get_woocommerce_currency_symbol(),
        ] );
    }

    public function payment_fields() {
        $desc = $this->get_option( 'description' );
        if ( $desc ) {
            echo '<p style="font-family:Poppins,sans-serif;font-size:13px;color:#6B6356;margin:8px 0 0;line-height:1.5;">'
                . wp_kses_post( $desc ) . '</p>';
        }
        if ( $this->sandbox_mode === 'yes' ) {
            echo '<p style="font-family:Poppins,sans-serif;font-size:11px;background:#FDF6E3;color:#8A6200;border:1px solid rgba(138,98,0,0.2);border-radius:5px;padding:6px 10px;margin-top:8px;">
                    &#9888; Sandbox mode — no real payments will be taken.
                  </p>';
        }
    }

    public function validate_fields() {
        return true;
    }

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        $order->update_status( 'pending', 'Awaiting Élour Pay OTP verification.' );

        return [
            'result'   => 'success',
            'elour_modal' => true,
            'order_id' => $order_id,
            'redirect' => $order->get_checkout_order_received_url(),
            'nonce'    => wp_create_nonce( 'elour_pay_nonce' ),
        ];
    }

    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order   = wc_get_order( $order_id );
        $tracker = $order->get_meta( '_elour_pay_tracker' );

        if ( ! $tracker ) {
            return new WP_Error( 'no_tracker', 'No Safepay tracker found for this order.' );
        }

        $api    = new Elour_Pay_Safepay_API( $this->secret_key, $this->public_key, $this->api_base );
        $result = $api->refund( $tracker, (float) $amount );

        if ( ! $result['success'] ) {
            return new WP_Error( 'refund_failed', $result['message'] );
        }

        $order->add_order_note( 'Élour Pay refund initiated: PKR ' . $amount );
        return true;
    }

    public function handle_webhook() {
        $payload   = file_get_contents( 'php://input' );
        $signature = $_SERVER['HTTP_X_SFPY_SIGNATURE'] ?? '';
        $secret    = $this->get_option( 'webhook_secret' );

        if ( ! Elour_Pay_Safepay_API::verify_webhook_signature( $payload, $signature, $secret ) ) {
            elour_pay_log( 'Webhook: invalid signature', 'error' );
            wp_die( 'Unauthorized', 'Élour Pay', [ 'response' => 401 ] );
        }

        $event   = json_decode( $payload, true );
        $type    = $event['type'] ?? '';
        $tracker = $event['data']['tracker'] ?? [];
        $orderid = $tracker['order_id'] ?? null;

        elour_pay_log( 'Webhook received: ' . $type );

        if ( in_array( $type, [ 'payment:created', 'payment.success' ], true ) && $orderid ) {
            $order = wc_get_order( (int) $orderid );
            if ( $order && in_array( $order->get_status(), [ 'pending', 'on-hold' ], true ) ) {
                $order->payment_complete( $tracker['reference_number'] ?? '' );
                $order->add_order_note( 'Élour Pay: payment confirmed via Safepay webhook.' );
                elour_pay_log( 'Order #' . $orderid . ' marked complete via webhook.' );
            }
        }

        if ( $type === 'payment:failed' && $orderid ) {
            $order = wc_get_order( (int) $orderid );
            if ( $order ) {
                $order->update_status( 'failed', 'Élour Pay: payment failed via Safepay.' );
            }
        }

        status_header( 200 );
        exit;
    }

    // ── Getters ───────────────────────────────────────────────────────────────
    public function get_secret_key(): string { return (string) $this->secret_key; }
    public function get_public_key(): string { return (string) $this->public_key; }
    public function get_api_base():   string { return (string) $this->api_base; }
    public function get_dashboard_api_url():    string { return (string) $this->get_option( 'api_url' ); }
    public function get_dashboard_api_secret(): string { return (string) $this->get_option( 'api_secret' ); }
}

function elour_pay_log( string $message, string $level = 'info' ): void {
    wc_get_logger()->log( $level, $message, [ 'source' => 'elour-pay' ] );
}

// ── Intercept WC checkout AJAX before it sends its redirect response ──────────
add_action( 'woocommerce_checkout_order_processed', 'elour_pay_intercept_checkout', 10, 3 );

function elour_pay_intercept_checkout( $order_id, $posted_data, $order ) {
    if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) return;
    if ( $order->get_payment_method() !== 'elour_pay' ) return;

    $order->update_status( 'pending', 'Awaiting Élour Pay OTP verification.' );

    wp_send_json( [
        'result'      => 'elour_modal',
        'order_id'    => $order_id,
        'redirect'    => $order->get_checkout_order_received_url(),
        'nonce'       => wp_create_nonce( 'elour_pay_nonce' ),
    ] );
    exit;
}
