<?php
/**
 * Create or update a project product (a license product that is not for sale) from the command line.
 *
 * @package WPLC
 */

namespace WPLC\CLI;

use WPLC\Catalog\Catalog;
use WPLC\Catalog\CatalogFile;
use WPLC\Catalog\CatalogStore;
use WPLC\Catalog\ProductEditor;
use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp wplc project-product`: the private WooCommerce product and the plan of a plugin built for one client.
 */
final class ProjectProductCommand {

	/**
	 * Create or update a project product with one plan.
	 *
	 * A project product is licensed and updated like every other license
	 * product but is not sold in the shop: its WooCommerce product is private,
	 * virtual and costs nothing, and its licenses are issued by hand
	 * (wp wplc license issue). The command does what the product editor does
	 * for "License product", product key, plan, live sites and entitlements
	 * (docs/manual/04-license-products.md).
	 *
	 * The first run creates the WooCommerce product; a later run with the same
	 * product key and plan key updates that product and keeps the plan ID.
	 * Import the catalog file of the plugin first (wp wplc catalog import) when
	 * the plan carries entitlements.
	 *
	 * ## OPTIONS
	 *
	 * --product=<product-key>
	 * : Product key of the plugin (its folder name), e.g. acme-connector.
	 *
	 * [--name=<name>]
	 * : Name the client sees in the account and in emails. Needed when the product is created.
	 *
	 * [--plan=<plan-key>]
	 * : Plan key. Default: project.
	 *
	 * [--sites=<number>]
	 * : Number of live sites, 1 to 1000. Default: 1.
	 *
	 * [--entitlements=<keys>]
	 * : Comma-separated entitlement keys of the product's catalog file, "all" (default: every available key of the catalog file) or "none".
	 *
	 * [--support=<level>]
	 * : community, standard (default) or priority.
	 *
	 * [--format=<format>]
	 * : "text" (default) or "json" (product_id, plan_id, product_key, plan_key, created, entitlements).
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc catalog import acme-connector/wplc-catalog.json
	 *     wp wplc project-product save --product=acme-connector --name="Acme Connector" --sites=2
	 *     wp wplc license issue --plan-id=$(wp wplc project-product save --product=acme-connector --format=json | jq .plan_id) --customer=client@example.com --expires=2027-12-31 --yes
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function save( array $args, array $assoc_args ): void {
		$result = self::run_save( $assoc_args );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		if ( 'json' === ( $assoc_args['format'] ?? 'text' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $result, JSON_UNESCAPED_SLASHES ) );
			return;
		}
		\WP_CLI::success(
			sprintf(
				$result['created']
					/* translators: 1: product key, 2: WooCommerce product ID, 3: plan key, 4: plan ID. */
					? __( 'Project product %1$s created (WooCommerce product %2$d), plan %3$s has the ID %4$d.', 'wp-license' )
					/* translators: 1: product key, 2: WooCommerce product ID, 3: plan key, 4: plan ID. */
					: __( 'Project product %1$s updated (WooCommerce product %2$d), plan %3$s has the ID %4$d.', 'wp-license' ),
				$result['product_key'],
				$result['product_id'],
				$result['plan_key'],
				$result['plan_id']
			)
		);
	}

	/**
	 * Create or update the product and its plan. Kept free of WP_CLI calls so it can be tested.
	 *
	 * @param array<string,mixed> $assoc_args Options of `wp wplc project-product save`.
	 * @return array{product_id:int,plan_id:int,product_key:string,plan_key:string,created:bool,entitlements:string[]}|\WP_Error
	 */
	public static function run_save( array $assoc_args ) {
		global $wpdb;

		$product_key = strtolower( trim( (string) ( $assoc_args['product'] ?? '' ) ) );
		$plan_key    = strtolower( trim( (string) ( $assoc_args['plan'] ?? 'project' ) ) );
		$name        = trim( sanitize_text_field( (string) ( $assoc_args['name'] ?? '' ) ) );
		$sites       = (string) ( $assoc_args['sites'] ?? '1' );
		$support     = strtolower( trim( (string) ( $assoc_args['support'] ?? 'standard' ) ) );

		if ( ! Catalog::is_valid_product_key( $product_key ) ) {
			return new \WP_Error( 'invalid_product_key', Catalog::error_message( 'invalid_product_key' ) );
		}
		if ( ! Catalog::is_valid_plan_key( $plan_key ) ) {
			return new \WP_Error( 'invalid_plan_key', Catalog::error_message( 'invalid_plan_key' ) );
		}
		if ( 1 !== preg_match( '/^[0-9]{1,4}$/', $sites ) || (int) $sites < 1 || (int) $sites > 1000 ) {
			return new \WP_Error( 'invalid_sites', __( '--sites must be a number from 1 to 1000.', 'wp-license' ) );
		}
		if ( ! in_array( $support, array( 'community', 'standard', 'priority' ), true ) ) {
			return new \WP_Error( 'invalid_support', __( '--support must be community, standard or priority.', 'wp-license' ) );
		}
		$entitlements = self::entitlements( $product_key, (string) ( $assoc_args['entitlements'] ?? 'all' ) );
		if ( is_wp_error( $entitlements ) ) {
			return $entitlements;
		}

		// The plan of this product key and plan key, if there is one: its WooCommerce product is updated.
		$tables = Schema::table_names();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from Schema::table_names().
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, woocommerce_product_id, woocommerce_variation_id FROM {$tables['plans']} WHERE product_key = %s AND plan_key = %s ORDER BY id ASC", $product_key, $plan_key ), ARRAY_A );
		if ( count( $rows ) > 1 ) {
			/* translators: 1: plan key, 2: product key. */
			return new \WP_Error( 'ambiguous_plan', sprintf( __( 'Several WooCommerce products carry the plan %1$s of %2$s. Edit them in the product editor.', 'wp-license' ), $plan_key, $product_key ) );
		}

		$created = ! $rows;
		if ( $created ) {
			if ( '' === $name ) {
				return new \WP_Error( 'name_missing', __( 'Give --name: the product does not exist yet.', 'wp-license' ) );
			}
			$product = new \WC_Product_Simple();
			$product->set_name( $name );
			$product->set_status( 'private' );
			$product->set_catalog_visibility( 'hidden' );
			$product->set_virtual( true );
			$product->set_regular_price( '0' );
			$product->save();
		} else {
			$product = (int) $rows[0]['woocommerce_variation_id'] ? null : wc_get_product( (int) $rows[0]['woocommerce_product_id'] );
			if ( ! $product instanceof \WC_Product || 'private' !== $product->get_status() ) {
				/* translators: 1: plan key, 2: product key. */
				return new \WP_Error( 'not_a_project_product', sprintf( __( 'The plan %1$s of %2$s belongs to a product that is not private. This command only changes project products; edit the other ones in the product editor.', 'wp-license' ), $plan_key, $product_key ) );
			}
			if ( '' !== $name && $name !== $product->get_name() ) {
				$product->set_name( $name );
				$product->save();
			}
		}
		$product_id = (int) $product->get_id();
		if ( $product_id <= 0 ) {
			return new \WP_Error( 'product_not_saved', __( 'WooCommerce did not save the product.', 'wp-license' ) );
		}

		$data = array(
			'product_key'      => $product_key,
			'plan_key'         => $plan_key,
			'billing_interval' => 'year',
			'live_site_limit'  => (int) $sites,
			'support_level'    => $support,
			'entitlements'     => array_fill_keys( $entitlements, true ),
		);
		if ( '' !== $name ) {
			$data['display_name']        = $name;
			$data['update_display_name'] = true;
		}
		if ( ! Catalog::save_mapping( $product_id, 0, $data ) ) {
			$code = Catalog::last_error();
			if ( $created ) {
				// Leave no product without a plan behind: the command can simply run again.
				$product->delete( true );
			}
			return new \WP_Error( '' !== $code ? $code : 'plan_not_saved', Catalog::error_message( $code ) );
		}
		ProductEditor::mirror_meta( $product, $data );

		$plan = Catalog::find_exact_mapping( $product_id, 0, true );

		return array(
			'product_id'   => $product_id,
			'plan_id'      => (int) ( $plan['id'] ?? 0 ),
			'product_key'  => $product_key,
			'plan_key'     => $plan_key,
			'created'      => $created,
			'entitlements' => $entitlements,
		);
	}

	/**
	 * Entitlement keys of the plan, checked against the catalog entry of the product.
	 *
	 * @param string $product_key Product key.
	 * @param string $option      Value of --entitlements.
	 * @return string[]|\WP_Error
	 */
	private static function entitlements( string $product_key, string $option ) {
		$option  = trim( $option );
		$catalog = CatalogStore::get( $product_key );
		$known   = array();
		foreach ( (array) ( $catalog['entitlements'] ?? array() ) as $entitlement ) {
			$known[ (string) $entitlement['key'] ] = (string) ( $entitlement['status'] ?? CatalogFile::STATUS_AVAILABLE );
		}
		if ( 'none' === $option ) {
			return array();
		}
		if ( '' === $option || 'all' === $option ) {
			return array_keys( array_filter( $known, static fn( string $status ): bool => CatalogFile::STATUS_AVAILABLE === $status ) );
		}
		$keys = array_values( array_unique( array_filter( array_map( 'trim', explode( ',', $option ) ), static fn( string $key ): bool => '' !== $key ) ) );
		if ( null === $catalog ) {
			/* translators: %s: product key. */
			return new \WP_Error( 'catalog_missing', sprintf( __( 'The catalog has no entry for %s. Import the catalog file of the plugin first (wp wplc catalog import).', 'wp-license' ), $product_key ) );
		}
		$unknown = array_values( array_diff( $keys, array_keys( $known ) ) );
		if ( $unknown ) {
			/* translators: 1: product key, 2: comma-separated entitlement keys. */
			return new \WP_Error( 'unknown_entitlements', sprintf( __( 'The catalog file of %1$s does not define: %2$s', 'wp-license' ), $product_key, implode( ', ', $unknown ) ) );
		}

		return $keys;
	}
}
