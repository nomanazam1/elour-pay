<?php
defined( 'ABSPATH' ) || exit;

class Elour_Pay_Safepay_API {

    private string $secret_key;
    private string $public_key;
    private string $api_base;

    public function __construct( string $secret_key, string $public_key, string $api_base ) {
        $this->secret_key = $secret_key;
        $this->public_key = $public_key;
        $this->api_base   = rtrim( $api_base, '/' );
    }

    public function create_payment_session( float $amount, string $currency, string $order_id ): array {
        $response = $this->post( '/order/v1/init', [
            'client'      => $this->secret_key,
            'amount'      => (float) round( $amount, 2 ),
            'currency'    => $currency ?: 'PKR',
            'environment' => strpos( $this->api_base, 'sandbox' ) !== false ? 'sandbox' : 'production',
            'order_id'    => (string) $order_id,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => $response->get_error_message() ];
        }

        $tracker = $response['data']['tracker']['token'] ?? null;
        if ( ! $tracker ) {
            return [ 'success' => false, 'message' => 'Could not create payment session. Please try again.' ];
        }

        return [ 'success' => true, 'tracker' => $tracker ];
    }

    public function initiate_otp_debit( string $tracker, string $bank_code, string $account_number ): array {
        $response = $this->post( '/v1/payments/debit/otp/initiate/', [
            'tracker'        => $tracker,
            'bank_code'      => $bank_code,
            'account_number' => $account_number,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => $response->get_error_message() ];
        }

        $success = $response['data']['success'] ?? false;
        if ( ! $success ) {
            $msg = $response['data']['message'] ?? 'Could not send OTP. Please check your account number.';
            return [ 'success' => false, 'message' => $msg ];
        }

        return [ 'success' => true, 'message' => 'OTP sent to your registered mobile number.' ];
    }

    public function verify_otp_and_debit( string $tracker, string $otp ): array {
        $response = $this->post( '/v1/payments/debit/otp/verify/', [
            'tracker' => $tracker,
            'otp'     => $otp,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => $response->get_error_message() ];
        }

        $state = $response['data']['tracker']['state'] ?? '';
        if ( $state === 'PAID' ) {
            return [
                'success'   => true,
                'reference' => $response['data']['tracker']['reference_number'] ?? '',
                'message'   => 'Payment successful.',
            ];
        }

        $msg = $response['data']['message'] ?? 'OTP verification failed. Please try again.';
        return [ 'success' => false, 'message' => $msg ];
    }

    public function refund( string $tracker, float $amount ): array {
        $response = $this->post( '/v1/payments/refund/', [
            'tracker' => $tracker,
            'amount'  => (int) round( $amount * 100 ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => $response->get_error_message() ];
        }

        $success = $response['data']['success'] ?? false;
        return [
            'success' => $success,
            'message' => $success ? 'Refund initiated.' : ( $response['data']['message'] ?? 'Refund failed.' ),
        ];
    }

    public static function verify_webhook_signature( string $payload, string $signature, string $secret ): bool {
        if ( empty( $secret ) || empty( $signature ) ) return false;
        $expected = hash_hmac( 'sha256', $payload, $secret );
        return hash_equals( $expected, $signature );
    }

    private function post( string $endpoint, array $body ): array|WP_Error {
        $url = $this->api_base . $endpoint;

        $response = wp_remote_post( $url, [
            'method'  => 'POST',
            'timeout' => 30,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->secret_key,
                'Accept'        => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            elour_pay_log( 'Safepay API error: ' . $response->get_error_message(), 'error' );
            return new WP_Error( 'api_error', 'Payment service unavailable. Please try again.' );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        elour_pay_log( 'Safepay ' . $endpoint . ' → HTTP ' . $code );

        if ( $code >= 500 ) {
            return new WP_Error( 'server_error', 'Payment service temporarily unavailable.' );
        }

        return $data ?? [];
    }
}
