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
		// After WooCommerce's own save (priority 10), so the plan stores the price entered in the same request.
		add_action( 'woocommerce_process_product_meta', array( self::class, 'save_product_fields' ), 20 );
		add_action( 'woocommerce_variation_options_pricing', array( self::class, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( self::class, 'save_variation_fields' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
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
	 * Request key of a license field, without the variation index.
	 *
	 * Variation fields carry their own prefix. The edit form of a variable product
	 * contains the parent's fields and, once the Variations tab was opened, the
	 * fields of every variation; with equal names PHP would replace the parent's
	 * value (`_wplc_product_key`) by the variations' array (`_wplc_product_key[0]`).
	 *
	 * @param string $base Field name of a simple product, e.g. `_wplc_product_key`.
	 * @param string $suffix Empty for a product, `[index]` for a variation.
	 * @return string
	 */
	private static function field_base( string $base, string $suffix ): string {
		return '' === $suffix ? $base : '_wplc_variation_' . substr( $base, strlen( '_wplc_' ) );
	}

	/**
	 * Name (and ID) of a license form field.
	 *
	 * @param string $base Field name of a simple product.
	 * @param string $suffix Empty for a product, `[index]` for a variation.
	 * @return string
	 */
	private static function field_name( string $base, string $suffix ): string {
		return self::field_base( $base, $suffix ) . $suffix;
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
			return self::field_name( $base, $suffix );
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
		$stored = json_decode( (string) $values['entitlements'], true );
		$split  = EntitlementCatalog::split( is_array( $stored ) ? $stored : array() );
		self::render_entitlement_checklist( $split['checked'], (string) $values['product_key'], $suffix );

		$advanced_json = $split['advanced'] ? wp_json_encode( $split['advanced'] ) : '';
		woocommerce_wp_textarea_input(
			array(
				'id' => $name( '_wplc_entitlements_json' ),
				'label' => __( 'Advanced: additional entitlements (JSON)', 'wp-license' ),
				'value' => false === $advanced_json ? '' : $advanced_json,
				'placeholder' => '{"affiliate.program_limit":3}',
				'desc_tip' => true,
				'description' => __( 'Optional JSON object for numeric limits and entitlement keys that are not in the list above. The live-site limit and support level are added automatically.', 'wp-license' ),
			)
		);
	}

	/**
	 * Render the checklist of known entitlements, grouped by product.
	 *
	 * @param string[] $checked Keys that are currently granted.
	 * @param string   $product_key License product key of this product.
	 * @param string   $suffix Variation array suffix.
	 * @return void
	 */
	private static function render_entitlement_checklist( array $checked, string $product_key, string $suffix ): void {
		$catalog = EntitlementCatalog::all();
		if ( ! $catalog ) {
			return;
		}

		$matching  = EntitlementCatalog::groups_for_product_key( $product_key );
		$id_suffix = '' === $suffix ? 'p' : 'v' . absint( trim( $suffix, '[]' ) );
		// Separate names: "x[]" on the parent and "x[0][]" on a variation would collide in one request.
		$field_name = '' === $suffix ? '_wplc_entitlement_keys[]' : '_wplc_variation_entitlement_keys' . $suffix . '[]';

		echo '<fieldset class="form-field wplc-entitlements">';
		echo '<legend>' . esc_html__( 'Entitlements', 'wp-license' ) . '</legend>';
		foreach ( $catalog as $group => $entitlements ) {
			if ( ! $entitlements ) {
				continue;
			}
			$group_checked = array_intersect( array_column( $entitlements, 'key' ), $checked );
			// Never hide a group that carries saved values; the script applies the same rule while typing.
			$hidden = $matching && ! in_array( $group, $matching, true ) && ! $group_checked;
			echo '<div class="wplc-entitlement-group" data-wplc-entitlement-group="' . esc_attr( $group ) . '"' . ( $hidden ? ' hidden' : '' ) . '>';
			echo '<strong>' . esc_html( EntitlementCatalog::group_label( $group ) ) . '</strong><ul>';
			foreach ( $entitlements as $entitlement ) {
				$id = 'wplc_ent_' . $id_suffix . '_' . preg_replace( '/[^a-z0-9]+/i', '_', $entitlement['key'] );
				echo '<li><label for="' . esc_attr( $id ) . '">';
				echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $field_name ) . '" value="' . esc_attr( $entitlement['key'] ) . '"' . checked( in_array( $entitlement['key'], $checked, true ), true, false ) . '> ';
				echo esc_html( $entitlement['label'] ) . ' <code>' . esc_html( $entitlement['key'] ) . '</code>';
				echo '</label></li>';
			}
			echo '</ul></div>';
		}
		echo '</fieldset>';
	}

	/**
	 * Load the checklist styles and script on the product editor.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->id ) {
			return;
		}
		wp_enqueue_style( 'wplc-admin', plugins_url( 'assets/css/wplc-admin.css', WPLC_FILE ), array(), WPLC_VERSION );
		wp_enqueue_script( 'wplc-product-editor', plugins_url( 'assets/js/wplc-product-editor.js', WPLC_FILE ), array( 'jquery' ), WPLC_VERSION, true );
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
			$value = $source[ self::field_base( $key, $suffix ) ] ?? null;
			if ( '' !== $suffix ) {
				$index = absint( trim( $suffix, '[]' ) );
				$value = is_array( $value ) ? ( $value[ $index ] ?? null ) : null;
			}
			if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
				return '';
			}

			return sanitize_text_field( wp_unslash( (string) $value ) );
		};

		$raw_entitlements = $source[ self::field_base( '_wplc_entitlements_json', $suffix ) ] ?? '';
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

		if ( '' === $suffix ) {
			$checked = $source['_wplc_entitlement_keys'] ?? array();
		} else {
			$index = absint( trim( $suffix, '[]' ) );
			$checked = $source['_wplc_variation_entitlement_keys'] ?? array();
			$checked = is_array( $checked ) ? ( $checked[ $index ] ?? array() ) : array();
		}
		$checked = is_array( $checked ) ? array_map( 'sanitize_text_field', array_filter( wp_unslash( $checked ), 'is_string' ) ) : array();

		// Ticked catalog entries and the advanced JSON object are stored as one entitlement map.
		$clean_entitlements = EntitlementCatalog::merge( $checked, $entitlements );

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
			self::add_admin_error( __( 'The advanced entitlements field must contain a valid JSON object. No product changes were saved.', 'wp-license' ) );
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
