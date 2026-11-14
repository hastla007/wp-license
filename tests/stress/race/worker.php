<?php
putenv( 'QA_WORKER=1' );
require __DIR__ . '/boot.php';
global $wpdb; $wpdb->hide_errors(); $wpdb->suppress_errors( true ); $t = \WPLC\Infrastructure\Database\Schema::table_names();
$fx = json_decode( file_get_contents( $argv[1] ), true ); $T = (float) $argv[2]; $i = (int) $argv[3]; $sc = $fx['scenario'];
$out = array( 'i' => $i );
$GLOBALS['qa_issued'] = 0; add_action( 'wplc/license_issued', function () { $GLOBALS['qa_issued']++; }, 10, 0 );
register_shutdown_function( function () use ( &$out ) { $out['errors'] = $GLOBALS['qa_errors']; echo json_encode( $out ), "\n"; } );
require __DIR__ . '/qa-adapter.php';
qa_wait( $T );
switch ( $sc ) {
	case 'activate_same':
		$r = qa_dispatch( qa_req( 'POST', '/wplc/v1/activations', array( 'license_key' => $fx['license']['key'], 'product_key' => 'test-product', 'installation_id' => $fx['inst'], 'installation_secret' => str_repeat( 'a', 40 ), 'site_url' => 'https://same.example.com' ) ) );
		$out += array( 'status' => $r['status'], 'code' => $r['code'], 'act' => $r['data']['data']['activation_id'] ?? null ); break;
	case 'activate_diff': case 'activate_diff3':
		$r = qa_dispatch( qa_req( 'POST', '/wplc/v1/activations', array( 'license_key' => $fx['license']['key'], 'product_key' => 'test-product', 'installation_id' => wp_generate_uuid4(), 'installation_secret' => str_repeat( 'a', 40 ), 'site_url' => "https://site$i.example.com" ) ) );
		$out += array( 'status' => $r['status'], 'code' => $r['code'] ); break;
	case 'activate_samesite':
		$r = qa_dispatch( qa_req( 'POST', '/wplc/v1/activations', array( 'license_key' => $fx['license']['key'], 'product_key' => 'test-product', 'installation_id' => wp_generate_uuid4(), 'installation_secret' => str_repeat( 'a', 40 ), 'site_url' => "https://same.example.com" ) ) );
		$out += array( 'status' => $r['status'], 'code' => $r['code'] ); break;
	case 'nonce_replay':
		$r = qa_dispatch( qa_req( 'POST', '/wplc/v1/entitlements/check', array(), $fx['act'], 'fixed-nonce-0123456789' ) );
		$out += array( 'status' => $r['status'], 'code' => $r['code'] ); break;
	case 'deact_vs_check':
		if ( 0 === $i ) { $r = qa_dispatch( qa_req( 'DELETE', '/wplc/v1/activations/' . $fx['act']['id'], null, $fx['act'] ) ); }
		else { $r = qa_dispatch( qa_req( 'POST', '/wplc/v1/entitlements/check', array(), $fx['act'] ) ); }
		$out += array( 'status' => $r['status'], 'code' => $r['code'] ); break;
	case 'deact_parallel':
		$r = qa_dispatch( qa_req( 'DELETE', '/wplc/v1/activations/' . $fx['act']['id'], null, $fx['act'] ) );
		$out += array( 'status' => $r['status'], 'code' => $r['code'] ); break;
	case 'staging_vs_deact':
		if ( 0 === $i ) { $out['deact'] = \WPLC\License\ActivationManager::deactivate( $fx['act']['id'], 'qa' ); }
		else { $r = qa_dispatch( qa_req( 'POST', '/wplc/v1/activations', array( 'license_key' => $fx['license']['key'], 'product_key' => 'test-product', 'installation_id' => wp_generate_uuid4(), 'installation_secret' => str_repeat( 'a', 40 ), 'site_url' => "https://staging$i.example.com", 'environment' => 'staging', 'staging_parent_installation_id' => $fx['act']['uuid'] ) ) );
			$out += array( 'status' => $r['status'], 'code' => $r['code'] ); }
		break;
	case 'staging_parallel':
		$r = qa_dispatch( qa_req( 'POST', '/wplc/v1/activations', array( 'license_key' => $fx['license']['key'], 'product_key' => 'test-product', 'installation_id' => wp_generate_uuid4(), 'installation_secret' => str_repeat( 'a', 40 ), 'site_url' => "https://staging$i.example.com", 'environment' => 'staging', 'staging_parent_installation_id' => $fx['act']['uuid'] ) ) );
		$out += array( 'status' => $r['status'], 'code' => $r['code'] ); break;
	case 'dl_token':
		add_filter( 'wplc_update_package_hosts', function ( $h ) { $h[] = 'example.org'; return $h; } );
		add_filter( 'wp_redirect', function ( $loc ) use ( &$out ) { $out['redirect'] = $loc; return false; }, PHP_INT_MAX );
		add_filter( 'status_header', function ( $h, $code ) use ( &$out ) { $out['http'] = $code; return $h; }, 10, 2 );
		$r = new WP_REST_Request( 'GET', '/wplc/v1/updates/download/' . $fx['token'] ); $r->set_url_params( array( 'token' => $fx['token'] ) );
		ob_start(); \WPLC\Api\V1\UpdateService::download( $r ); break;
	case 'issue_order':
		\WPLC\License\LicenseIssuer::maybe_issue_order( (int) $fx['order'] ); $out['issued_events'] = $GLOBALS['qa_issued']; break;
	case 'process_parallel':
		\WPLC\Billing\RenewalProcessor::process( (int) $fx['renewal_id'] ); break;
	case 'process_vs_manualpay':
		if ( 0 === $i ) { \WPLC\Billing\RenewalProcessor::process( (int) $fx['renewal_id'] ); }
		else { usleep( 150000 * $i ); $o = \WPLC\Billing\ManualRenewal::start( (int) $fx['license_id'], (int) $fx['customer'] ); $out['res'] = is_wp_error( $o ) ? $o->get_error_code() : 'order:' . $o->get_id() . ' status ' . $o->get_status() . ' manual=' . $o->get_meta( '_wplc_manual_renewal', true );
			if ( ! is_wp_error( $o ) && 1 === $i ) { $out['paid'] = $o->payment_complete( 'txn_customer' ); } }
		break;
	case 'process_vs_extend':
		if ( 0 === $i ) { \WPLC\Billing\RenewalProcessor::process( (int) $fx['renewal_id'] ); }
		else { usleep( 200000 ); $out['res'] = \WPLC\License\LicenseLifecycle::admin_extend( (int) $fx['license_id'], gmdate( 'Y-m-d H:i:s', time() + 800 * DAY_IN_SECONDS ), 'goodwill', 1 ); }
		break;
	case 'manual_start':
		$o = \WPLC\Billing\ManualRenewal::start( (int) $fx['license_id'], (int) $fx['customer'] );
		$out['res'] = is_wp_error( $o ) ? $o->get_error_code() : 'order:' . $o->get_id(); break;
	case 'manual_paid':
		\WPLC\Billing\RenewalProcessor::reconcile_paid_renewal_order( (int) $fx['renewal_order'] ); break;
	case 'refund_vs_pay':
		if ( 0 === $i % 2 ) { \WPLC\Billing\RenewalProcessor::reconcile_paid_renewal_order( (int) $fx['renewal_order'] ); }
		else { \WPLC\License\LicenseLifecycle::handle_full_refund( (int) $fx['renewal_order'] ); }
		break;
	case 'status_vs_cancel':
		if ( 0 === $i % 2 ) { $out['res'] = \WPLC\License\LicenseLifecycle::admin_set_status( (int) $fx['license_id'], 'suspended', 'qa', 1 ); }
		else { $out['res'] = \WPLC\License\LicenseLifecycle::schedule_cancellation( (int) $fx['license_id'], 'qa', (int) $fx['customer'] ); }
		break;
	case 'extend_vs_renew':
		if ( 0 === $i % 2 ) { $out['res'] = \WPLC\License\LicenseLifecycle::admin_extend( (int) $fx['license_id'], gmdate( 'Y-m-d H:i:s', time() + 800 * DAY_IN_SECONDS ), 'qa', 1 ); }
		else { \WPLC\License\LicenseLifecycle::mark_renewed( (int) $fx['license_id'], gmdate( 'Y-m-d H:i:s', time() + 370 * DAY_IN_SECONDS ), 0 ); }
		break;
}
