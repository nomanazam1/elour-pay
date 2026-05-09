<?php
defined( 'ABSPATH' ) || exit;

class Elour_Pay_Sync {

    private string $api_url;
    private string $api_secret;

    public function __construct() {
        $gateways         = WC()->payment_gateways()->payment_gateways();
        $gateway          = $gateways['elour_pay'] ?? null;
        $this->api_url    = $gateway ? $gateway->get_dashboard_api_url()    : '';
        $this->api_secret = $gateway ? $gateway->get_dashboard_api_secret() : '';
    }

    public function sync_transaction( WC_Order $order, string $status, string $tracker = '', string $ref = '' ): void {
        if ( empty( $this->api_url ) || empty( $this->api_secret ) ) {
            elour_pay_log( 'Sync skipped — API URL or secret not configured.', 'warning' );
            return;
        }

        $this->post( '/api/sync/transaction', [
            'order_id'        => $order->get_id(),
            'wc_order_number' => $order->get_order_number(),
            'safepay_tracker' => $tracker,
            'safepay_ref'     => $ref,
            'amount'          => $order->get_total(),
            'currency'        => $order->get_currency() ?: 'PKR',
            'status'          => $status,
            'bank_code'       => $order->get_meta( '_elour_bank' ) ?: '',
            'customer_name'   => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'customer_email'  => $order->get_billing_email(),
            'customer_phone'  => $order->get_billing_phone(),
        ] );
    }

    public function sync_order( WC_Order $order ): void {
        if ( empty( $this->api_url ) || empty( $this->api_secret ) ) return;

        $items = [];
        foreach ( $order->get_items() as $item ) {
            $items[] = [
                'name'  => $item->get_name(),
                'qty'   => $item->get_quantity(),
                'price' => $item->get_total(),
            ];
        }

        $this->post( '/api/sync/order', [
            'wc_order_id'     => $order->get_id(),
            'wc_order_number' => $order->get_order_number(),
            'status'          => $order->get_status(),
            'total'           => $order->get_total(),
            'currency'        => $order->get_currency() ?: 'PKR',
            'customer_name'   => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'customer_email'  => $order->get_billing_email(),
            'customer_phone'  => $order->get_billing_phone(),
            'customer_city'   => $order->get_billing_city(),
            'items'           => $items,
            'payment_method'  => 'elour_pay',
        ] );
    }

    private function post( string $endpoint, array $payload ): void {
        $url = rtrim( $this->api_url, '/' ) . $endpoint;

        $response = wp_remote_post( $url, [
            'timeout' => 10,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-secret' => $this->api_secret,
            ],
            'body' => wp_json_encode( $payload ),
        ] );

        if ( is_wp_error( $response ) ) {
            elour_pay_log( 'Sync failed (' . $endpoint . '): ' . $response->get_error_message(), 'error' );
            return;
        }

        $code = wp_remote_retrieve_response_code( $response );
        elour_pay_log( 'Sync ' . ( $code === 200 ? 'OK' : 'HTTP ' . $code ) . ': ' . $endpoint );
    }
}
