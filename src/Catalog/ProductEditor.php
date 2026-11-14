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
 * Adds plan mapping fields to simple products and individual variations and
 * keeps the license catalog in step with product changes made elsewhere.
 *
 * Writing the license catalog needs its own capability (see can_manage_catalog());
 * being allowed to edit products is not enough.
 */
final class ProductEditor {

	/**
	 * Hidden field that proves the license fields were part of the submitted form.
	 * Without it a save must not touch the mapping: an unticked checkbox and a
	 * form that never contained the checkbox look the same in the request.
	 */
	const PRESENT_FIELD = '_wplc_fields_present';

	/**
	 * Same marker for one variation row (array, indexed by the row number).
	 */
	const VARIATION_PRESENT_FIELD = '_wplc_variation_fields_present';

	/**
	 * True while this class saves a product itself, so the product-save hook does not run again.
	 *
	 * @var bool
	 */
	private static $saving = false;

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
		add_action( 'admin_notices', array( self::class, 'render_block_editor_notice' ) );

		// Product changes outside the classic editor: quick edit, bulk edit, REST, import, duplicate.
		foreach ( array( 'woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_new_product_variation', 'woocommerce_update_product_variation' ) as $hook ) {
			add_action( $hook, array( self::class, 'sync_after_product_save' ), 20, 1 );
		}
		add_action( 'wp_trash_post', array( self::class, 'on_trash' ) );
		add_action( 'untrashed_post', array( self::class, 'on_untrash' ) );
		add_action( 'before_delete_post', array( self::class, 'on_delete' ) );

		TermDisplay::register_hooks();
	}

	/**
	 * Whether the current user may read and write the license catalog.
	 *
	 * @return bool
	 */
	private static function can_manage_catalog(): bool {
		/**
		 * Filters the capability needed for an area of WP License Captain.
		 *
		 * @since 0.2.0
		 *
		 * @param string $capability Default capability.
		 * @param string $area       Area, here `manage_catalog`.
		 */
		return current_user_can( (string) apply_filters( 'wplc_capability', 'manage_woocommerce', 'manage_catalog' ) );
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

		// External and grouped products are not sold themselves and cannot carry a license.
		echo '<div class="options_group hide_if_external hide_if_grouped wplc-license-fields">';
		self::render_fields( $product, '' );
		echo '</div>';
	}

	/**
	 * Render fields for one variation.
	 *
	 * @param int      $loop Variation row number.
	 * @param array    $variation_data Variation data.
	 * @param \WP_Post $variation Variation post.
	 * @return void
	 */
	public static function render_variation_fields( $loop, $variation_data, $variation ): void {
		$product = wc_get_product( $variation->ID );
		if ( ! $product ) {
			return;
		}

		echo '<div class="form-row form-row-full wplc-license-fields">';
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
	 * Product and variation ID of a product object, as the catalog stores them.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return array{0:int,1:int} Parent product ID and variation ID (zero for a product).
	 */
	private static function catalog_ids( $product ): array {
		if ( $product->is_type( 'variation' ) && $product->get_parent_id() ) {
			return array( (int) $product->get_parent_id(), (int) $product->get_id() );
		}
		return array( (int) $product->get_id(), 0 );
	}

	/**
	 * Render common license plan mapping controls.
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param string      $suffix Variation array suffix.
	 * @return void
	 */
	private static function render_fields( $product, string $suffix ): void {
		list( $product_id, $variation_id ) = self::catalog_ids( $product );
		// The catalog table decides whether this is a license product; the meta only keeps the form values.
		$plan    = Catalog::find_exact_mapping( $product_id, $variation_id );
		$enabled = null !== $plan;
		$flagged = ! $enabled && 'yes' === $product->get_meta( '_wplc_is_license_product', true );

		if ( ! self::can_manage_catalog() ) {
			if ( $enabled ) {
				echo '<p class="form-field"><strong>' . esc_html__( 'License product', 'wp-license' ) . ':</strong> ';
				echo esc_html(
					sprintf(
						/* translators: 1: license product key, 2: license plan key. */
						__( 'This product issues licenses for %1$s (plan %2$s). Only users who may manage the license catalog can change this.', 'wp-license' ),
						(string) $plan['product_key'],
						(string) $plan['plan_key']
					)
				);
				echo '</p>';
			}
			return;
		}

		$values = array(
			'product_key' => $plan ? $plan['product_key'] : $product->get_meta( '_wplc_product_key', true ),
			'plan_key' => $plan ? $plan['plan_key'] : $product->get_meta( '_wplc_plan_key', true ),
			'billing_interval' => $plan ? $plan['billing_interval'] : ( $product->get_meta( '_wplc_billing_interval', true ) ? $product->get_meta( '_wplc_billing_interval', true ) : 'year' ),
			'live_site_limit' => $plan ? $plan['live_site_limit'] : ( $product->get_meta( '_wplc_live_site_limit', true ) ? $product->get_meta( '_wplc_live_site_limit', true ) : 1 ),
			'support_level' => $plan ? $plan['support_level'] : ( $product->get_meta( '_wplc_support_level', true ) ? $product->get_meta( '_wplc_support_level', true ) : 'standard' ),
			'entitlements' => $plan ? $plan['entitlements_json'] : $product->get_meta( '_wplc_entitlements_json', true ),
		);

		$name = static function ( string $base ) use ( $suffix ): string {
			return self::field_name( $base, $suffix );
		};

		echo '<input type="hidden" name="' . esc_attr( '' === $suffix ? self::PRESENT_FIELD : self::VARIATION_PRESENT_FIELD . $suffix ) . '" value="1">';

		if ( $flagged ) {
			echo '<p class="form-field wplc-catalog-warning"><strong>' . esc_html__( 'This product is marked as a license product but has no plan in the license catalog (for example after a copy or an import). Customers who buy it do not receive a license. Tick "License product" and save to create the plan.', 'wp-license' ) . '</strong></p>';
		}

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
				'description' => __( 'Stable product identifier that the add-on sends, for example affiliate-captain. Lowercase letters, digits and hyphens only.', 'wp-license' ),
				'custom_attributes' => array(
					'pattern' => '[a-z0-9][a-z0-9\-]{1,99}',
					'maxlength' => '100',
				),
			)
		);
		if ( '' === $suffix ) {
			$stored_name = Catalog::product_display_name( (string) $values['product_key'] );
			woocommerce_wp_text_input(
				array(
					'id' => '_wplc_product_display_name',
					'label' => __( 'License product name', 'wp-license' ),
					'value' => $stored_name,
					'placeholder' => $product->get_name(),
					'desc_tip' => true,
					'description' => __( 'Name of the license product as customers see it in their account and in emails. It is shared by all plans with the same license product key. Leave empty to keep the current name.', 'wp-license' ),
					'custom_attributes' => array( 'maxlength' => '190' ),
				)
			);
		}
		woocommerce_wp_text_input(
			array(
				'id' => $name( '_wplc_plan_key' ),
				'label' => __( 'License plan key', 'wp-license' ),
				'value' => $values['plan_key'],
				'placeholder' => 'business',
				'desc_tip' => true,
				'description' => __( 'Identifier of the plan, for example single or agency. Lowercase letters, digits and hyphens only.', 'wp-license' ),
				'custom_attributes' => array(
					'pattern' => '[a-z0-9][a-z0-9\-]{0,99}',
					'maxlength' => '100',
				),
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
	 * Whether WooCommerce's block-based product editor is switched on.
	 *
	 * @return bool
	 */
	public static function block_product_editor_enabled(): bool {
		$enabled = false;
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			try {
				$enabled = \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'product_block_editor' );
			} catch ( \Throwable $error ) {
				$enabled = false;
			}
		}

		/**
		 * Filters whether the block-based product editor counts as switched on.
		 *
		 * @since 0.2.0
		 *
		 * @param bool $enabled Detected through WooCommerce's feature flag `product_block_editor`.
		 */
		return (bool) apply_filters( 'wplc_block_product_editor_enabled', $enabled );
	}

	/**
	 * The block-based product editor does not show the license fields: say so
	 * instead of leaving the shop manager searching for them.
	 *
	 * @return void
	 */
	public static function render_block_editor_notice(): void {
		if ( ! self::block_product_editor_enabled() || ! self::can_manage_catalog() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? (string) $screen->id : '';
		if ( ! in_array( $id, array( 'product', 'edit-product', 'woocommerce_page_wc-admin', 'woocommerce_page_wplc-catalog-csv' ), true ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'WP License Captain: the license fields (product key, plan, entitlements) are only available in the classic product editor. The new block-based product editor is switched on in this shop and does not show them. Switch it off under WooCommerce → Settings → Advanced → Features, or maintain the plans with the license catalog CSV.', 'wp-license' );
		echo '</p></div>';
	}

	/**
	 * Save fields on a simple or parent product.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public static function save_product_fields( int $product_id ): void {
		if ( ! self::can_save( $product_id, false ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in can_save().
		if ( empty( $_POST[ self::PRESENT_FIELD ] ) ) {
			return;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in can_save().
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
		if ( ! self::can_save( $variation_id, true ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in can_save(); only tested for presence.
		$present = isset( $_POST[ self::VARIATION_PRESENT_FIELD ] ) && is_array( $_POST[ self::VARIATION_PRESENT_FIELD ] ) ? $_POST[ self::VARIATION_PRESENT_FIELD ] : array();
		if ( empty( $present[ absint( $loop ) ] ) ) {
			return;
		}

		$product = wc_get_product( $variation_id );
		if ( ! $product || ! $product->get_parent_id() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in can_save().
		$data = self::read_posted_fields( $_POST, '[' . absint( $loop ) . ']' );
		self::persist_mapping( $product, $product->get_parent_id(), $variation_id, $data );
	}

	/**
	 * Validate permissions and the nonce of the request that saves the product.
	 *
	 * The product form sends WooCommerce's meta box nonce. "Save changes" on the
	 * Variations tab is an AJAX request (`woocommerce_save_variations`) that only
	 * sends the variation rows and its own nonce in `security`.
	 *
	 * @param int  $product_id Product or variation ID.
	 * @param bool $is_variation Whether a variation row is saved.
	 * @return bool
	 */
	private static function can_save( int $product_id, bool $is_variation ): bool {
		if ( ! current_user_can( 'edit_post', $product_id ) || ! self::can_manage_catalog() ) {
			return false;
		}

		if (
			isset( $_POST['woocommerce_meta_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' )
		) {
			return true;
		}

		return $is_variation
			&& isset( $_POST['security'] )
			&& (bool) wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['security'] ) ), 'save-variations' );
	}

	/**
	 * Read submitted mapping fields. Keys are validated later, never rewritten.
	 *
	 * @param array  $source Request data.
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
			$index            = absint( trim( $suffix, '[]' ) );
			$raw_entitlements = is_array( $raw_entitlements ) ? ( $raw_entitlements[ $index ] ?? '' ) : '';
		}
		$raw_entitlements     = is_string( $raw_entitlements ) ? trim( wp_unslash( $raw_entitlements ) ) : '';
		$decoded_entitlements = '' === $raw_entitlements ? (object) array() : json_decode( $raw_entitlements );
		$entitlements_valid   = '' === $raw_entitlements
			|| ( JSON_ERROR_NONE === json_last_error() && is_object( $decoded_entitlements ) );
		$entitlements         = $entitlements_valid && '' !== $raw_entitlements
			? json_decode( $raw_entitlements, true )
			: array();
		if ( ! is_array( $entitlements ) ) {
			$entitlements = array();
		}

		if ( '' === $suffix ) {
			$checked = $source['_wplc_entitlement_keys'] ?? array();
		} else {
			$index   = absint( trim( $suffix, '[]' ) );
			$checked = $source['_wplc_variation_entitlement_keys'] ?? array();
			$checked = is_array( $checked ) ? ( $checked[ $index ] ?? array() ) : array();
		}
		$checked = is_array( $checked ) ? array_map( 'sanitize_text_field', array_filter( wp_unslash( $checked ), 'is_string' ) ) : array();

		// Ticked catalog entries and the advanced JSON object are stored as one entitlement map.
		$clean_entitlements = EntitlementCatalog::merge( $checked, $entitlements );

		$display_name = '';
		if ( '' === $suffix && isset( $source['_wplc_product_display_name'] ) && is_string( $source['_wplc_product_display_name'] ) ) {
			$display_name = sanitize_text_field( wp_unslash( $source['_wplc_product_display_name'] ) );
		}

		return array(
			'enabled' => 'yes' === $get( '_wplc_is_license_product' ),
			'product_key' => Catalog::normalize_key( $get( '_wplc_product_key' ) ),
			'plan_key' => Catalog::normalize_key( $get( '_wplc_plan_key' ) ),
			'billing_interval' => Catalog::normalize_key( $get( '_wplc_billing_interval' ) ),
			'live_site_limit' => absint( $get( '_wplc_live_site_limit' ) ),
			'support_level' => Catalog::normalize_key( $get( '_wplc_support_level' ) ),
			'entitlements' => $clean_entitlements,
			'entitlements_valid' => $entitlements_valid,
			'display_name' => $display_name,
		);
	}

	/**
	 * Persist the catalog mapping and mirror the values in the product meta.
	 *
	 * On any validation error nothing is changed: an existing plan stays as it is
	 * and the reason is shown to the editor.
	 *
	 * @param \WC_Product         $product Product or variation.
	 * @param int                 $product_id Parent product ID.
	 * @param int                 $variation_id Variation ID.
	 * @param array<string,mixed> $data Submitted values.
	 * @return void
	 */
	private static function persist_mapping( $product, int $product_id, int $variation_id, array $data ): void {
		$label = $variation_id
			/* translators: %d: variation ID. */
			? sprintf( __( 'Variation #%d', 'wp-license' ), $variation_id )
			: $product->get_name();

		if ( empty( $data['entitlements_valid'] ) ) {
			self::add_admin_error( $label, __( 'The advanced entitlements field must contain a valid JSON object. The license settings were not saved.', 'wp-license' ) );
			return;
		}

		if ( ! $data['enabled'] ) {
			Catalog::delete_mapping( $product_id, $variation_id );
			self::write_meta( $product, $data, false );
			return;
		}

		$error = '';
		if ( ! Catalog::is_valid_product_key( (string) $data['product_key'] ) ) {
			$error = 'invalid_product_key';
		} elseif ( ! Catalog::is_valid_plan_key( (string) $data['plan_key'] ) ) {
			$error = 'invalid_plan_key';
		} elseif ( ! in_array( $data['billing_interval'], array( 'month', 'year' ), true ) ) {
			$error = 'invalid_interval';
		}

		if ( '' === $error ) {
			$mapping  = $data;
			$existing = Catalog::find_exact_mapping( $product_id, $variation_id, true );
			$stored   = Catalog::product_display_name( (string) $data['product_key'] );
			// The name field shows the name of the key the form was loaded with. When the key was
			// changed to another existing license product, that text must not rename the other product.
			$same_key = $existing && (string) $existing['product_key'] === (string) $data['product_key'];
			if ( '' !== (string) $data['display_name'] && $stored !== (string) $data['display_name'] && ( $same_key || '' === $stored ) ) {
				$mapping['update_display_name'] = true;
			} else {
				unset( $mapping['display_name'] );
			}
			if ( ! Catalog::save_mapping( $product_id, $variation_id, $mapping ) ) {
				$error = Catalog::last_error();
			}
		}

		if ( '' !== $error ) {
			self::add_admin_error(
				$label,
				Catalog::error_message( $error ) . ' ' . __( 'The license settings were not saved.', 'wp-license' )
			);
			return;
		}

		self::write_meta( $product, $data, true );
	}

	/**
	 * Mirror the form values in the product meta.
	 *
	 * @param \WC_Product         $product Product or variation.
	 * @param array<string,mixed> $data Submitted values.
	 * @param bool                $enabled Whether the product is a license product now.
	 * @return void
	 */
	private static function write_meta( $product, array $data, bool $enabled ): void {
		$meta = array(
			'_wplc_is_license_product' => $enabled ? 'yes' : 'no',
			'_wplc_product_key' => $data['product_key'],
			'_wplc_plan_key' => $data['plan_key'],
			'_wplc_billing_interval' => $data['billing_interval'],
			'_wplc_live_site_limit' => max( 1, min( 1000, (int) $data['live_site_limit'] ) ),
			'_wplc_support_level' => $data['support_level'],
			'_wplc_entitlements_json' => wp_json_encode( $data['entitlements'] ),
		);

		foreach ( $meta as $key => $value ) {
			$product->update_meta_data( $key, $value );
		}

		self::$saving = true;
		try {
			$product->save();
		} finally {
			self::$saving = false;
		}
	}

	/**
	 * Keep the catalog in step with a product that was saved anywhere.
	 *
	 * - A mapped product: its plan takes over the current regular price (quick
	 *   edit, bulk edit, REST and import do not pass through the product editor).
	 * - A product whose meta says "license product" but that has no plan (a
	 *   duplicate, an imported product): a user who may manage the catalog gets
	 *   the plan created from the copied values; for everyone else the mark is
	 *   removed, so nobody sells a "license product" that issues no license and
	 *   nobody without the capability creates a plan through copied meta.
	 *
	 * @param mixed $product_id Product or variation ID.
	 * @return void
	 */
	public static function sync_after_product_save( $product_id ): void {
		if ( self::$saving ) {
			return;
		}
		$product = wc_get_product( absint( $product_id ) );
		if ( ! $product ) {
			return;
		}

		list( $parent_id, $variation_id ) = self::catalog_ids( $product );
		if ( Catalog::find_exact_mapping( $parent_id, $variation_id, true ) ) {
			Catalog::refresh_price( $parent_id, $variation_id );
			return;
		}

		if ( 'yes' !== $product->get_meta( '_wplc_is_license_product', true ) ) {
			return;
		}

		$created = false;
		if ( self::can_manage_catalog() ) {
			$entitlements = json_decode( (string) $product->get_meta( '_wplc_entitlements_json', true ), true );
			$created      = Catalog::save_mapping(
				$parent_id,
				$variation_id,
				array(
					'product_key' => $product->get_meta( '_wplc_product_key', true ),
					'plan_key' => $product->get_meta( '_wplc_plan_key', true ),
					'billing_interval' => $product->get_meta( '_wplc_billing_interval', true ),
					'live_site_limit' => $product->get_meta( '_wplc_live_site_limit', true ),
					'support_level' => $product->get_meta( '_wplc_support_level', true ),
					'entitlements' => is_array( $entitlements ) ? $entitlements : array(),
				)
			);
		}

		if ( ! $created ) {
			// Written directly: saving the product object here would run this hook again.
			update_post_meta( (int) $product->get_id(), '_wplc_is_license_product', 'no' );
		}
	}

	/**
	 * A trashed product cannot be bought: deactivate its plans.
	 *
	 * @param mixed $post_id Post ID.
	 * @return void
	 */
	public static function on_trash( $post_id ): void {
		if ( self::is_product_post( (int) $post_id ) ) {
			Catalog::set_active_for_post( (int) $post_id, false );
		}
	}

	/**
	 * A restored product sells licenses again.
	 *
	 * @param mixed $post_id Post ID.
	 * @return void
	 */
	public static function on_untrash( $post_id ): void {
		if ( self::is_product_post( (int) $post_id ) ) {
			Catalog::set_active_for_post( (int) $post_id, true );
		}
	}

	/**
	 * A deleted product or variation loses its plans.
	 *
	 * @param mixed $post_id Post ID.
	 * @return void
	 */
	public static function on_delete( $post_id ): void {
		if ( self::is_product_post( (int) $post_id ) ) {
			Catalog::delete_mappings_of_post( (int) $post_id );
		}
	}

	/**
	 * Whether a post is a product or a variation.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function is_product_post( int $post_id ): bool {
		return $post_id > 0 && in_array( get_post_type( $post_id ), array( 'product', 'product_variation' ), true );
	}

	/**
	 * Show a validation or persistence error after the editor reloads.
	 *
	 * WooCommerce stores meta box errors across the redirect of the product form
	 * and prints them in the response of the AJAX variation save; an
	 * `admin_notices` callback added during the save request is never rendered.
	 *
	 * @param string $subject Product name or variation label.
	 * @param string $message Plain text message.
	 * @return void
	 */
	private static function add_admin_error( string $subject, string $message ): void {
		$text = sprintf(
			/* translators: 1: product name or variation, 2: error message. */
			__( 'License settings of "%1$s": %2$s', 'wp-license' ),
			$subject,
			$message
		);

		if ( class_exists( '\\WC_Admin_Meta_Boxes' ) ) {
			\WC_Admin_Meta_Boxes::add_error( esc_html( $text ) );
			return;
		}

		add_action(
			'admin_notices',
			static function () use ( $text ): void {
				echo '<div class="notice notice-error"><p>' . esc_html( $text ) . '</p></div>';
			}
		);
	}
}
