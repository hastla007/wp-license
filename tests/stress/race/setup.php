<?php
require __DIR__ . '/boot.php';
use WPLC\Catalog\Catalog;
global $wpdb; $t = \WPLC\Infrastructure\Database\Schema::table_names();
foreach ( $t as $tb ) { $wpdb->query( "TRUNCATE TABLE $tb" ); }
$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}woocommerce_order_items" ); $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}woocommerce_order_itemmeta" );
foreach ( array( 'wc_orders','wc_orders_meta','wc_order_addresses','wc_order_operational_data','wc_order_stats','wc_order_product_lookup','actionscheduler_actions','actionscheduler_logs','actionscheduler_claims' ) as $tb ) { $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}$tb" ); }
$sc = $argv[1]; $fx = array( 'scenario' => $sc );
function qa_product( $price = '49.00', $map = array() ) {
	$p = new WC_Product_Simple(); $p->set_name( 'QA product' ); $p->set_status( 'publish' ); $p->set_regular_price( $price ); $p->set_virtual( true ); $p->save();
	$ok = Catalog::save_mapping( $p->get_id(), 0, array_merge( array( 'product_key' => 'test-product', 'plan_key' => 'single', 'billing_interval' => 'year', 'live_site_limit' => 1, 'support_level' => 'standard', 'entitlements' => array( 'feature.export' => true ) ), $map ) );
	if ( ! $ok ) { throw new Exception( 'mapping failed' ); }
	return $p;
}
switch ( $sc ) {
	case 'activate_same': case 'activate_diff': case 'activate_samesite':
		$fx['license'] = qa_seed_license(); $fx['inst'] = wp_generate_uuid4(); break;
	case 'activate_diff3':
		$fx['license'] = qa_seed_license( array(), array( 'sites.live_limit' => 3 ) ); break;
	case 'nonce_replay': case 'deact_vs_check': case 'deact_parallel':
		$fx['license'] = qa_seed_license(); $fx['act'] = qa_seed_activation( $fx['license']['id'] ); break;
	case 'staging_vs_deact': case 'staging_parallel':
		$fx['license'] = qa_seed_license(); $fx['act'] = qa_seed_activation( $fx['license']['id'] ); break;
	case 'dl_token': case 'stream_token':
		$fx['license'] = qa_seed_license();
		$tok = bin2hex( random_bytes( 32 ) );
		$wpdb->insert( $t['download_tokens'], array( 'license_id' => $fx['license']['id'], 'token_hash' => hash( 'sha256', $tok ), 'package_url' => 'https://example.org/pkg.zip', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ), 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		$fx['token'] = $tok; break;
	case 'issue_order':
		$u = wp_insert_user( array( 'user_login' => 'qa' . bin2hex( random_bytes( 4 ) ), 'user_pass' => 'x', 'user_email' => bin2hex( random_bytes( 4 ) ) . '@example.test', 'role' => 'customer' ) );
		$p = qa_product();
		remove_action( 'woocommerce_payment_complete', array( \WPLC\License\LicenseIssuer::class, 'maybe_issue_order' ), 20 );
		remove_action( 'woocommerce_order_status_processing', array( \WPLC\License\LicenseIssuer::class, 'maybe_issue_order' ), 20 );
		remove_action( 'woocommerce_order_status_completed', array( \WPLC\License\LicenseIssuer::class, 'maybe_issue_order' ), 20 );
		$o = wc_create_order( array( 'customer_id' => $u ) ); $o->set_billing_email( get_userdata( $u )->user_email ); $o->add_product( $p, 3 ); $o->calculate_totals(); $o->save(); $o->payment_complete( 'txn_qa' );
		$fx['order'] = $o->get_id(); $fx['paid'] = wc_get_order( $o->get_id() )->is_paid(); break;
	case 'process_parallel': case 'process_vs_manualpay': case 'process_vs_extend':
		require __DIR__ . '/qa-adapter.php'; @unlink( __DIR__ . '/charges.log' );
		$u = wp_insert_user( array( 'user_login' => 'qa' . bin2hex( random_bytes( 4 ) ), 'user_pass' => 'x', 'user_email' => bin2hex( random_bytes( 4 ) ) . '@example.test', 'role' => 'customer' ) );
		$p = qa_product();
		$o = wc_create_order( array( 'customer_id' => $u ) ); $o->set_billing_email( get_userdata( $u )->user_email ); $o->add_product( $p, 1 ); $o->set_billing_country( 'DE' ); $o->calculate_totals(); $o->save(); $o->payment_complete( 'txn_qa' );
		$lic = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['licenses']} WHERE source_order_id = %d", $o->get_id() ), ARRAY_A );
		$tok = new WC_Payment_Token_CC(); $tok->set_token( 'pm_qa' ); $tok->set_gateway_id( 'qa' ); $tok->set_card_type( 'visa' ); $tok->set_last4( '4242' ); $tok->set_expiry_month( '12' ); $tok->set_expiry_year( '2099' ); $tok->set_user_id( $u ); $tok->save();
		$pu = gmdate( 'Y-m-d H:i:s', time() - 120 );
		$wpdb->update( $t['licenses'], array( 'paid_until' => $pu, 'next_payment_at' => $pu, 'renewal_enabled' => 1, 'payment_gateway_id' => 'qa', 'payment_token_id' => $tok->get_id() ), array( 'id' => $lic['id'] ) );
		$wpdb->update( $t['entitlements'], array( 'ends_at' => $pu ), array( 'license_id' => $lic['id'] ) );
		$lic = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['licenses']} WHERE id = %d", $lic['id'] ), ARRAY_A );
		$fx['license_id'] = (int) $lic['id']; $fx['customer'] = $u; $fx['paid_until'] = $pu; $fx['token'] = $tok->get_id();
		$fx['renewal_id'] = \WPLC\Billing\RenewalScheduler::find_or_create_row( $lic, 'scheduled' );
		break;
	case 'manual_start': case 'manual_paid': case 'extend_vs_renew': case 'status_vs_cancel': case 'refund_vs_pay':
		$u = wp_insert_user( array( 'user_login' => 'qa' . bin2hex( random_bytes( 4 ) ), 'user_pass' => 'x', 'user_email' => bin2hex( random_bytes( 4 ) ) . '@example.test', 'role' => 'customer' ) );
		$p = qa_product();
		$o = wc_create_order( array( 'customer_id' => $u ) ); $o->set_billing_email( get_userdata( $u )->user_email ); $o->add_product( $p, 1 ); $o->set_billing_country( 'DE' ); $o->calculate_totals(); $o->save(); $o->payment_complete( 'txn_qa' );
		$lic = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['licenses']} WHERE source_order_id = %d", $o->get_id() ), ARRAY_A );
		if ( ! $lic ) { throw new Exception( 'no license issued' ); }
		// move into the renewal window
		$pu = gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS );
		$wpdb->update( $t['licenses'], array( 'paid_until' => $pu, 'next_payment_at' => $pu ), array( 'id' => $lic['id'] ) );
		$wpdb->update( $t['entitlements'], array( 'ends_at' => $pu ), array( 'license_id' => $lic['id'] ) );
		$fx['license_id'] = (int) $lic['id']; $fx['customer'] = $u; $fx['paid_until'] = $pu;
		if ( 'manual_paid' === $sc || 'refund_vs_pay' === $sc ) {
			$ro = \WPLC\Billing\ManualRenewal::start( (int) $lic['id'], $u );
			if ( is_wp_error( $ro ) ) { throw new Exception( 'start failed ' . $ro->get_error_code() ); }
			foreach ( array( 'woocommerce_payment_complete', 'woocommerce_order_status_processing', 'woocommerce_order_status_completed' ) as $h ) { remove_action( $h, array( \WPLC\Billing\RenewalProcessor::class, 'handle_paid_order' ) ); }
			$ro->payment_complete( 'txn_renew' );
			$fx['renewal_order'] = $ro->get_id(); $fx['renewal_id'] = (int) $ro->get_meta( '_wplc_renewal_id', true );
		}
		break;
}
echo json_encode( $fx );
