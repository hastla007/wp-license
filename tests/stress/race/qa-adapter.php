<?php
class QA_Adapter implements \WPLC\Gateway\RecurringGatewayAdapter {
	public function gateway_id(): string { return 'qa'; }
	public function supports_checkout(): bool { return true; }
	public function supports_saved_token( int $token_id, int $customer_id ): bool { return true; }
	public function charge( $order, int $token_id, string $idempotency_key ) {
		file_put_contents( __DIR__ . '/charges.log', getmypid() . ' order ' . $order->get_id() . ' key ' . substr( $idempotency_key, -6 ) . "\n", FILE_APPEND | LOCK_EX );
		usleep( (int) ( getenv( 'QA_CHARGE_MS' ) ?: 700 ) * 1000 );
		return array( 'success' => true, 'transaction_id' => 'ch_' . getmypid() );
	}
}
add_filter( 'wplc_recurring_gateway_adapters', function ( $a ) { $a[] = new QA_Adapter(); return $a; } );
// The scenarios charge licenses that became due seconds ago; the advance notice rule (B-41) is not what they test.
add_filter( 'wplc_renewal_requires_advance_notice', '__return_false' );
