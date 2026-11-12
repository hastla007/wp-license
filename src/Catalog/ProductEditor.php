<?php
/**
 * WooCommerce product editor controls for license plans.
 *
 * @package WPLC
 */

namespace WPLC\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds plan mapping fields to simple products and individual variations.
 */
final class ProductEditor {

	/**
	 * Register WooCommerce product editor hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'woocommerce_product_options_general_product_data', array( self::class, 'render_product_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( self::class, 'save_product_fields' ) );
		add_action( 'woocommerce_variation_options_pricing', array( self::class, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( self::class, 'save_variation_fields' ), 10, 2 );
	}

	/**
	 * Render fields for a non-variation product.
	 *
	 * @return void
	 */
	public static function render_product_fields(): void {
		global $post;

		if ( ! $post ) {
			return;
		}

		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return;
		}

		echo '<div class="options_group">';
		self::render_fields( $product, '' );
		echo '</div>';
	}

	/**
	 * Render fields for one variation.
	 *
	 * @param int     $loop Variation row number.
	 * @param array   $variation_data Variation data.
	 * @param WP_Post $variation Variation post.
	 * @return void
	 */
	public static function render_variation_fields( $loop, $variation_data, $variation ): void {
		$product = wc_get_product( $variation->ID );
		if ( ! $product ) {
			return;
		}

		echo '<div class="form-row form-row-full">';
		self::render_fields( $product, '[' . absint( $loop ) . ']' );
		echo '</div>';
	}

	/**
	 * Render common license plan mapping controls.
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param string       $suffix Variation array suffix.
	 * @return void
	 */
	private static function render_fields( $product, string $suffix ): void {
		$enabled = 'yes' === $product->get_meta( '_wplc_is_license_product', true );
		$values  = array(
			'product_key' => $product->get_meta( '_wplc_product_key', true ),
			'plan_key' => $product->get_meta( '_wplc_plan_key', true ),
			'billing_interval' => $product->get_meta( '_wplc_billing_interval', true ) ?: 'year',
			'live_site_limit' => $product->get_meta( '_wplc_live_site_limit', true ) ?: 1,
			'support_level' => $product->get_meta( '_wplc_support_level', true ) ?: 'standard',
			'entitlements' => $product->get_meta( '_wplc_entitlements_json', true ),
		);

		$name = static function ( string $base ) use ( $suffix ): string {
			return $base . $suffix;
		};

		woocommerce_wp_checkbox(
			array(
				'id' => $name( '_wplc_is_license_product' ),
				'label' => __( 'License product', 'wp-license' ),
				'value' => $enabled ? 'yes' : 'no',
				'cbvalue' => 'yes',
				'desc_tip' => true,
				'description' => __( 'Issue or renew a WP License Captain license when this product is paid.', 'wp-license' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id' => $name( '_wplc_product_key' ),
				'label' => __( 'License product key', 'wp-license' ),
				'value' => $values['product_key'],
				'placeholder' => 'voucher-captain',
				'desc_tip' => true,
				'description' => __( 'Stable product identifier, for example affiliate-captain.', 'wp-license' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id' => $name( '_wplc_plan_key' ),
				'label' => __( 'License plan key', 'wp-license' ),
				'value' => $values['plan_key'],
				'placeholder' => 'business',
			)
		);
		woocommerce_wp_select(
			array(
				'id' => $name( '_wplc_billing_interval' ),
				'label' => __( 'License billing interval', 'wp-license' ),
				'value' => $values['billing_interval'],
				'options' => array(
					'month' => __( 'Monthly', 'wp-license' ),
					'year' => __( 'Yearly', 'wp-license' ),
				),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id' => $name( '_wplc_live_site_limit' ),
				'label' => __( 'Live site limit', 'wp-license' ),
				'type' => 'number',
				'value' => $values['live_site_limit'],
				'custom_attributes' => array(
					'min' => '1',
					'max' => '1000',
					'step' => '1',
				),
			)
		);
		woocommerce_wp_select(
			array(
				'id' => $name( '_wplc_support_level' ),
				'label' => __( 'License support level', 'wp-license' ),
				'value' => $values['support_level'],
				'options' => array(
					'community' => __( 'Community', 'wp-license' ),
					'standard' => __( 'Standard', 'wp-license' ),
					'priority' => __( 'Priority', 'wp-license' ),
				),
			)
		);
		woocommerce_wp_textarea_input(
			array(
				'id' => $name( '_wplc_entitlements_json' ),
				'label' => __( 'Additional entitlements (JSON)', 'wp-license' ),
				'value' => $values['entitlements'],
				'placeholder' => '{"voucher.multi_location":true}',
				'desc_tip' => true,
				'description' => __( 'Optional JSON object. The live-site limit and support level are added automatically.', 'wp-license' ),
			)
		);
	}

	/**
	 * Save fields on a simple or parent product.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public static function save_product_fields( int $product_id ): void {
		if ( ! self::can_save( $product_id ) ) {
			return;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		$data = self::read_posted_fields( $_POST, '' );
		self::persist_mapping( $product, $product_id, 0, $data );
	}

	/**
	 * Save fields on a variation.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $loop Variation row number.
	 * @return void
	 */
	public static function save_variation_fields( int $variation_id, int $loop ): void {
		if ( ! self::can_save( $variation_id ) ) {
			return;
		}

		$product = wc_get_product( $variation_id );
		if ( ! $product || ! $product->get_parent_id() ) {
			return;
		}

		$data = self::read_posted_fields( $_POST, '[' . absint( $loop ) . ']' );
		self::persist_mapping( $product, $product->get_parent_id(), $variation_id, $data );
	}

	/**
	 * Validate edit permissions and WooCommerce's product editor nonce.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	private static function can_save( int $product_id ): bool {
		if ( ! current_user_can( 'edit_post', $product_id ) ) {
			return false;
		}

		return isset( $_POST['woocommerce_meta_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' );
	}

	/**
	 * Read and sanitize submitted mapping fields.
	 *
	 * @param array $source Request data.
	 * @param string $suffix Variation array suffix.
	 * @return array<string,mixed>
	 */
	private static function read_posted_fields( array $source, string $suffix ): array {
		$get = static function ( string $key ) use ( $source, $suffix ) {
			$value = $source[ $key ] ?? null;
			if ( '' !== $suffix ) {
				$index = absint( trim( $suffix, '[]' ) );
				$value = is_array( $value ) ? ( $value[ $index ] ?? null ) : null;
			}
			if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
				return '';
			}

			return sanitize_text_field( wp_unslash( (string) $value ) );
		};

		$raw_entitlements = $source['_wplc_entitlements_json'] ?? '';
		if ( '' !== $suffix ) {
			$index = absint( trim( $suffix, '[]' ) );
			$raw_entitlements = is_array( $raw_entitlements ) ? ( $raw_entitlements[ $index ] ?? '' ) : '';
		}
		$raw_entitlements = is_string( $raw_entitlements ) ? trim( wp_unslash( $raw_entitlements ) ) : '';
		$decoded_entitlements = '' === $raw_entitlements ? (object) array() : json_decode( $raw_entitlements );
		$entitlements_valid = '' === $raw_entitlements
			|| ( JSON_ERROR_NONE === json_last_error() && is_object( $decoded_entitlements ) );
		$entitlements = $entitlements_valid && '' !== $raw_entitlements
			? json_decode( $raw_entitlements, true )
			: array();
		if ( ! is_array( $entitlements ) ) {
			$entitlements = array();
		}

		$clean_entitlements = array();
		foreach ( $entitlements as $key => $value ) {
			if ( is_string( $key ) && preg_match( '/^[a-zA-Z0-9_.-]{1,120}$/', $key ) ) {
				$clean_entitlements[ $key ] = $value;
			}
		}

		return array(
			'enabled' => 'yes' === $get( '_wplc_is_license_product' ),
			'product_key' => sanitize_key( $get( '_wplc_product_key' ) ),
			'plan_key' => sanitize_key( $get( '_wplc_plan_key' ) ),
			'billing_interval' => sanitize_key( $get( '_wplc_billing_interval' ) ),
			'live_site_limit' => absint( $get( '_wplc_live_site_limit' ) ),
			'support_level' => sanitize_key( $get( '_wplc_support_level' ) ),
			'entitlements' => $clean_entitlements,
			'entitlements_valid' => $entitlements_valid,
		);
	}

	/**
	 * Persist product metadata and catalog mapping.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @param int $product_id Parent product ID.
	 * @param int $variation_id Variation ID.
	 * @param array<string,mixed> $data Sanitized values.
	 * @return void
	 */
	private static function persist_mapping( $product, int $product_id, int $variation_id, array $data ): void {
		if ( empty( $data['entitlements_valid'] ) ) {
			self::add_admin_error( __( 'Additional entitlements must be a valid JSON object. No product changes were saved.', 'wp-license' ) );
			return;
		}

		if (
			$data['enabled']
			&& (
				'' === $data['product_key']
				|| '' === $data['plan_key']
				|| ! in_array( $data['billing_interval'], array( 'month', 'year' ), true )
			)
		) {
			$product->update_meta_data( '_wplc_is_license_product', 'no' );
			$product->save();
			Catalog::delete_mapping( $product_id, $variation_id );
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'A license product needs a product key, plan key, and valid billing interval.', 'wp-license' );
					echo '</p></div>';
				}
			);
			return;
		}

		$meta = array(
			'_wplc_is_license_product' => $data['enabled'] ? 'yes' : 'no',
			'_wplc_product_key' => $data['product_key'],
			'_wplc_plan_key' => $data['plan_key'],
			'_wplc_billing_interval' => $data['billing_interval'],
			'_wplc_live_site_limit' => max( 1, min( 1000, $data['live_site_limit'] ) ),
			'_wplc_support_level' => $data['support_level'],
			'_wplc_entitlements_json' => wp_json_encode( $data['entitlements'] ),
		);

		if ( $data['enabled'] ) {
			$mapping_saved = Catalog::save_mapping(
				$product_id,
				$variation_id,
				array_merge(
					$data,
					array( 'display_name' => $product->get_name() )
				)
			);
			if ( ! $mapping_saved ) {
				self::add_admin_error( __( 'The license plan could not be saved to the catalog. No product changes were saved.', 'wp-license' ) );
				return;
			}
		} else {
			Catalog::delete_mapping( $product_id, $variation_id );
		}

		foreach ( $meta as $key => $value ) {
			$product->update_meta_data( $key, $value );
		}
		$product->save();
	}

	/**
	 * Display a product editor validation or persistence error.
	 *
	 * @param string $message Escaped at render time.
	 * @return void
	 */
	private static function add_admin_error( string $message ): void {
		add_action(
			'admin_notices',
			static function () use ( $message ): void {
				echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
			}
		);
	}
}
