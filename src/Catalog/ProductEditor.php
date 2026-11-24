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
		// The customer account looks up the plans of its license list at once (P6-I1).
		PlanOverrides::register_hooks();
	}

	/**
	 * Whether the current user may read and write the license catalog.
	 *
	 * @return bool
	 */
	private static function can_manage_catalog(): bool {
		// One capability map for the whole plugin (filter `wplc_capability`, B-48).
		return \WPLC\Admin\Capabilities::can( 'manage_catalog' );
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
	 * Visible help text of a field, linked to the field with aria-describedby (P5-I3).
	 *
	 * @param string $id   Field ID.
	 * @param string $text Plain help text.
	 * @return array{desc_tip:bool,description:string,custom_attributes:array<string,string>}
	 */
	private static function help( string $id, string $text ): array {
		$help_id = self::help_id( $id );

		return array(
			'desc_tip'          => false,
			'description'       => '<span id="' . esc_attr( $help_id ) . '">' . esc_html( $text ) . '</span>',
			'custom_attributes' => array( 'aria-describedby' => $help_id ),
		);
	}

	/**
	 * ID of the help text of a field.
	 *
	 * @param string $id Field ID (may contain the variation index in brackets).
	 * @return string
	 */
	private static function help_id( string $id ): string {
		return 'wplc-help-' . trim( (string) preg_replace( '/[^A-Za-z0-9_-]+/', '-', $id ), '-_' );
	}

	/**
	 * A field definition with its visible help merged in.
	 *
	 * @param array<string,mixed> $field Field arguments for woocommerce_wp_*().
	 * @param string              $text  Plain help text.
	 * @return array<string,mixed>
	 */
	private static function with_help( array $field, string $text ): array {
		$help                       = self::help( (string) $field['id'], $text );
		$field['desc_tip']          = $help['desc_tip'];
		$field['description']       = $help['description'];
		$field['custom_attributes'] = array_merge( isset( $field['custom_attributes'] ) && is_array( $field['custom_attributes'] ) ? $field['custom_attributes'] : array(), $help['custom_attributes'] );

		return $field;
	}

	/**
	 * Transient that keeps the submitted values of a form that failed validation (P5-I2).
	 *
	 * @param int $product_id   Parent product ID.
	 * @param int $variation_id Variation ID, or zero.
	 * @return string
	 */
	private static function draft_key( int $product_id, int $variation_id ): string {
		return 'wplc_plan_form_' . (int) get_current_user_id() . '_' . $product_id . '_' . $variation_id;
	}

	/**
	 * Keep the submitted values for the next render of the form, for this user only, ten minutes.
	 *
	 * @param int                 $product_id   Parent product ID.
	 * @param int                 $variation_id Variation ID, or zero.
	 * @param array<string,mixed> $data         Submitted values (read_posted_fields()).
	 * @param string              $field        Override column that failed, or ''.
	 * @return void
	 */
	private static function keep_draft( int $product_id, int $variation_id, array $data, string $field = '' ): void {
		$data['error_field'] = $field;
		set_transient( self::draft_key( $product_id, $variation_id ), $data, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * The kept values of a failed save, once.
	 *
	 * @param int $product_id   Parent product ID.
	 * @param int $variation_id Variation ID, or zero.
	 * @return array<string,mixed>|null
	 */
	private static function take_draft( int $product_id, int $variation_id ): ?array {
		$key   = self::draft_key( $product_id, $variation_id );
		$draft = get_transient( $key );
		if ( ! is_array( $draft ) ) {
			return null;
		}
		delete_transient( $key );

		return $draft;
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

		// P5-I2: after a failed save the form shows what was submitted, not the stored plan.
		$draft = self::take_draft( $product_id, $variation_id );
		if ( null !== $draft ) {
			$enabled = ! empty( $draft['enabled'] );
			$flagged = false;
			$encoded = wp_json_encode( is_array( $draft['entitlements'] ?? null ) ? $draft['entitlements'] : array() );
			$values  = array(
				'product_key' => (string) ( $draft['product_key'] ?? '' ),
				'plan_key' => (string) ( $draft['plan_key'] ?? '' ),
				'billing_interval' => (string) ( $draft['billing_interval'] ?? 'year' ),
				'live_site_limit' => (string) ( $draft['live_site_limit_raw'] ?? ( $draft['live_site_limit'] ?? 1 ) ),
				'support_level' => (string) ( $draft['support_level'] ?? 'standard' ),
				'entitlements' => false === $encoded ? '' : $encoded,
			);
		}

		$name = static function ( string $base ) use ( $suffix ): string {
			return self::field_name( $base, $suffix );
		};

		echo '<input type="hidden" name="' . esc_attr( '' === $suffix ? self::PRESENT_FIELD : self::VARIATION_PRESENT_FIELD . $suffix ) . '" value="1">';

		if ( $flagged ) {
			echo '<p class="form-field wplc-catalog-warning"><strong>' . esc_html__( 'This product is marked as a license product but has no plan in the license catalog (for example after a copy or an import). Customers who buy it do not receive a license. Tick "License product" and save to create the plan.', 'wp-license' ) . '</strong></p>';
		}

		woocommerce_wp_checkbox(
			self::with_help(
				array(
					'id' => $name( '_wplc_is_license_product' ),
					'label' => __( 'License product', 'wp-license' ),
					'value' => $enabled ? 'yes' : 'no',
					'cbvalue' => 'yes',
				),
				__( 'Issue or renew a WP License Captain license when this product is paid.', 'wp-license' )
			)
		);
		woocommerce_wp_text_input(
			self::with_help(
				array(
					'id' => $name( '_wplc_product_key' ),
					'label' => __( 'License product key', 'wp-license' ),
					'value' => $values['product_key'],
					'placeholder' => 'acme-pro',
					'custom_attributes' => array(
						'list' => 'wplc-known-product-keys',
						'pattern' => '[a-z0-9][a-z0-9\-]{1,99}',
						'maxlength' => '100',
					),
				),
				__( 'Stable product identifier. Must match the product key the premium add-on sends, for example acme-pro; the suggestions come from the imported catalog files. Lowercase letters, digits and hyphens only.', 'wp-license' )
			)
		);
		static $datalist_printed = false;
		if ( ! $datalist_printed ) {
			$datalist_printed = true;
			echo '<datalist id="wplc-known-product-keys">';
			foreach ( array_keys( EntitlementCatalog::known_product_keys() ) as $known_key ) {
				echo '<option value="' . esc_attr( $known_key ) . '"></option>';
			}
			echo '</datalist>';
		}
		if ( '' === $suffix ) {
			$stored_name = null !== $draft && '' !== (string) ( $draft['display_name'] ?? '' ) ? (string) $draft['display_name'] : Catalog::product_display_name( (string) $values['product_key'] );
			woocommerce_wp_text_input(
				self::with_help(
					array(
						'id' => '_wplc_product_display_name',
						'label' => __( 'License product name', 'wp-license' ),
						'value' => $stored_name,
						'placeholder' => $product->get_name(),
						'custom_attributes' => array( 'maxlength' => '190' ),
					),
					__( 'Name of the license product as customers see it in their account and in emails. It is shared by all plans with the same license product key. Leave empty to keep the current name.', 'wp-license' )
				)
			);
		}
		woocommerce_wp_text_input(
			self::with_help(
				array(
					'id' => $name( '_wplc_plan_key' ),
					'label' => __( 'License plan key', 'wp-license' ),
					'value' => $values['plan_key'],
					'placeholder' => 'business',
					'custom_attributes' => array(
						'pattern' => '[a-z0-9][a-z0-9\-]{0,99}',
						'maxlength' => '100',
					),
				),
				__( 'Identifier of this plan within the license product, for example single or agency; each plan (product or variation) of one license product needs its own. Licenses store it, so keep it unchanged once the plan is sold. Lowercase letters, digits and hyphens only.', 'wp-license' )
			)
		);
		woocommerce_wp_select(
			self::with_help(
				array(
					'id' => $name( '_wplc_billing_interval' ),
					'label' => __( 'License billing interval', 'wp-license' ),
					'value' => $values['billing_interval'],
					'options' => array(
						'month' => __( 'Monthly', 'wp-license' ),
						'year' => __( 'Yearly', 'wp-license' ),
					),
				),
				__( 'How often a license of this plan is renewed and billed: every month or every year.', 'wp-license' )
			)
		);
		woocommerce_wp_text_input(
			self::with_help(
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
				),
				__( 'Live (production) sites one license of this plan may activate at the same time, 1 to 1000. Staging and development sites have their own limits below.', 'wp-license' )
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
		self::render_override_fields( $plan, $suffix, $draft );

		$stored = json_decode( (string) $values['entitlements'], true );
		$split  = EntitlementCatalog::split( is_array( $stored ) ? $stored : array() );
		self::render_entitlement_checklist( $split['checked'], (string) $values['product_key'], $suffix );

		$advanced_json = $split['advanced'] ? wp_json_encode( $split['advanced'] ) : '';
		if ( null !== $draft && isset( $draft['entitlements_raw'] ) && is_string( $draft['entitlements_raw'] ) ) {
			// The text as typed, also when it was not valid JSON.
			$advanced_json = $draft['entitlements_raw'];
		}
		woocommerce_wp_textarea_input(
			self::with_help(
				array(
					'id' => $name( '_wplc_entitlements_json' ),
					'label' => __( 'Advanced: additional entitlements (JSON)', 'wp-license' ),
					'value' => false === $advanced_json ? '' : $advanced_json,
					'placeholder' => '{"acme.project_limit":3}',
				),
				__( 'Optional JSON object for numeric limits and entitlement keys that are not in the list above. The live-site limit and support level are added automatically.', 'wp-license' )
			)
		);
	}

	/**
	 * Labels and help texts of the overrides per plan (SET-LIC-21).
	 *
	 * @return array<string,array{0:string,1:string}> Column => label, help.
	 */
	public static function override_texts(): array {
		return array(
			'staging_site_limit'     => array( __( 'Staging sites per live site', 'wp-license' ), __( 'Staging copies each live site of this plan may activate (0 to 10; 0 = none).', 'wp-license' ) ),
			'development_site_limit' => array( __( 'Development sites', 'wp-license' ), __( 'Development installations on local addresses a license of this plan may hold at the same time, exactly this number (0 to 100; 0 = none).', 'wp-license' ) ),
			'offline_cache_days'     => array( __( 'Offline period (days)', 'wp-license' ), __( 'Days a customer site of this plan may rely on the last signed answer (2 to 90). Never beyond the end of access.', 'wp-license' ) ),
			'max_quantity_per_line'  => array( __( 'Largest quantity per order line', 'wp-license' ), __( 'Largest quantity of this plan in one cart line (0 to 100000; 0 = no limit).', 'wp-license' ) ),
			'co_user_limit'          => array( __( 'Co-users per license', 'wp-license' ), __( 'Co-users the owner of a license of this plan can invite (0 to 100; 0 = none).', 'wp-license' ) ),
			'beta_allowed'           => array( __( 'Beta versions', 'wp-license' ), __( 'Whether customer sites of this plan may receive beta versions when the customer turns them on in the add-on. Without the right they get stable versions only.', 'wp-license' ) ),
		);
	}

	/**
	 * Render the overrides per plan: empty = the global setting (SET-LIC-21).
	 *
	 * A rule the current user may not change (it replaces a manufacturer rule that needs
	 * `wplc_manage_policy`, P4-M1) is shown read-only with the reason and is not part of the form.
	 *
	 * @param array<string,mixed>|null $plan   Stored plan, null for a product without one.
	 * @param string                   $suffix Variation array suffix.
	 * @param array<string,mixed>|null $draft  Values submitted with a failed save (P5-I2).
	 * @return void
	 */
	private static function render_override_fields( ?array $plan, string $suffix, ?array $draft = null ): void {
		echo '<p class="form-field wplc-plan-overrides-intro"><strong>' . esc_html__( 'Rules of this plan', 'wp-license' ) . '</strong> ' . esc_html__( 'Leave a field empty to use the global setting (License settings). The filters of the global values still decide last.', 'wp-license' ) . '</p>';
		$submitted = null !== $draft && is_array( $draft['overrides'] ?? null ) ? $draft['overrides'] : array();
		$failed    = null !== $draft ? (string) ( $draft['error_field'] ?? '' ) : '';
		foreach ( self::override_texts() as $field => $text ) {
			list( $min, $max, $setting ) = PlanOverrides::FIELDS[ $field ];
			$own                         = PlanOverrides::of_plan( $plan, $field );
			$switch                      = in_array( $field, PlanOverrides::SWITCHES, true );
			$global                      = $switch
				? ( \WPLC\Settings\Registry::bool( $setting ) ? __( 'allowed', 'wp-license' ) : __( 'not allowed', 'wp-license' ) )
				: number_format_i18n( \WPLC\Settings\Registry::int( $setting ) );
			$id                          = self::field_name( '_wplc_' . $field, $suffix );

			if ( ! PlanOverrides::may_change( $field ) ) {
				self::render_locked_override( $id, $text, $own, $switch, $global, PlanOverrides::capability( $field ) );
				continue;
			}

			$value = null === $own ? '' : (string) $own;
			if ( array_key_exists( $field, $submitted ) && is_scalar( $submitted[ $field ] ) ) {
				$value = (string) $submitted[ $field ];
			}
			$invalid = $failed === $field ? array( 'aria-invalid' => 'true' ) : array();

			if ( $switch ) {
				// SET-LIC-20: a yes/no rule of the plan; empty = the global setting.
				woocommerce_wp_select(
					self::with_help(
						array(
							'id'                => $id,
							'label'             => $text[0],
							'value'             => $value,
							'options'           => array(
								/* translators: %s: value of the global setting, "allowed" or "not allowed". */
								''  => sprintf( __( 'Global setting (%s)', 'wp-license' ), $global ),
								'1' => __( 'Allowed', 'wp-license' ),
								'0' => __( 'Not allowed', 'wp-license' ),
							),
							'custom_attributes' => $invalid,
						),
						$text[1]
					)
				);
				continue;
			}
			/* translators: %s: value of the global setting. */
			$global_text = sprintf( __( 'Global: %s', 'wp-license' ), $global );
			woocommerce_wp_text_input(
				self::with_help(
					array(
						'id'                => $id,
						'label'             => $text[0],
						'type'              => 'number',
						'value'             => $value,
						'placeholder'       => $global_text,
						'custom_attributes' => array(
							'min'  => (string) $min,
							'max'  => (string) $max,
							'step' => '1',
						) + $invalid,
					),
					// P5-I3: the global value is visible text, not only the placeholder.
					$text[1] . ' ' . $global_text
				)
			);
		}
	}

	/**
	 * Render one override the current user may not change: value, help and reason, without a
	 * form field name (P4-M1).
	 *
	 * @param string                   $id         Field ID of the editable field.
	 * @param array{0:string,1:string} $text       Label and help.
	 * @param int|null                 $own        Stored value, null for the global setting.
	 * @param bool                     $switch     Whether the rule is a yes/no choice.
	 * @param string                   $global     Value of the global setting, for display.
	 * @param string                   $capability Capability slug needed.
	 * @return void
	 */
	private static function render_locked_override( string $id, array $text, ?int $own, bool $switch, string $global, string $capability ): void {
		if ( null === $own ) {
			/* translators: %s: value of the global setting. */
			$shown = sprintf( __( 'Global setting (%s)', 'wp-license' ), $global );
		} elseif ( $switch ) {
			$shown = 1 === $own ? __( 'Allowed', 'wp-license' ) : __( 'Not allowed', 'wp-license' );
		} else {
			$shown = number_format_i18n( $own );
		}
		$locked_id = 'wplc-locked-' . trim( (string) preg_replace( '/[^A-Za-z0-9_-]+/', '-', $id ), '-_' );
		$help_id   = self::help_id( $locked_id );
		$reason    = sprintf(
			/* translators: %s: capability name, for example wplc_manage_policy. */
			__( 'Read only: this rule replaces a manufacturer rule of the license settings, so only users with the capability %s can change it.', 'wp-license' ),
			\WPLC\Admin\Capabilities::cap( $capability )
		);

		echo '<p class="form-field wplc-plan-override-locked"><label for="' . esc_attr( $locked_id ) . '">' . esc_html( $text[0] ) . '</label>';
		echo '<input type="text" class="short" id="' . esc_attr( $locked_id ) . '" value="' . esc_attr( $shown ) . '" readonly aria-describedby="' . esc_attr( $help_id ) . '"> ';
		echo '<span class="description" id="' . esc_attr( $help_id ) . '">' . esc_html( $text[1] . ' ' . $reason ) . '</span></p>';
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

		// The script resolves a typed key with the same map (catalog only, no substring match: PK-01).
		$key_groups = wp_json_encode( (object) EntitlementCatalog::product_key_groups() );
		$keyed      = '' !== $product_key;
		$visible    = 0;

		echo '<fieldset class="form-field wplc-entitlements" data-wplc-product-groups="' . esc_attr( false === $key_groups ? '{}' : $key_groups ) . '">';
		echo '<legend>' . esc_html__( 'Entitlements', 'wp-license' ) . '</legend>';
		foreach ( $catalog as $group => $entitlements ) {
			if ( ! $entitlements ) {
				continue;
			}
			$group_checked = array_intersect( array_column( $entitlements, 'key' ), $checked );
			// With a product key only its own group is offered; a key the catalog does not know gets none.
			// Never hide a group that carries saved values; the script applies the same rule while typing.
			$hidden = $keyed && ! in_array( $group, $matching, true ) && ! $group_checked;
			if ( ! $hidden ) {
				++$visible;
			}
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
		echo '<p class="description" data-wplc-entitlement-none' . ( $keyed && 0 === $visible ? '' : ' hidden' ) . '>' . esc_html__( 'The catalog offers no entitlements for this license product key. They come from the catalog file of the product: upload a release whose package contains it, or import it under License catalog files.', 'wp-license' ) . '</p>';
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
		wp_enqueue_style( 'wplc-admin', plugins_url( 'assets/css/wplc-admin.css', WPLC_FILE ), array(), \WPLC\Account\CustomerPortal::asset_version( 'assets/css/wplc-admin.css' ) );
		wp_enqueue_script( 'wplc-product-editor', plugins_url( 'assets/js/wplc-product-editor.js', WPLC_FILE ), array( 'jquery' ), \WPLC\Account\CustomerPortal::asset_version( 'assets/js/wplc-product-editor.js' ), true );
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

		// SET-LIC-21: an override is only changed when its field was part of the form.
		$overrides = array();
		foreach ( array_keys( PlanOverrides::FIELDS ) as $field ) {
			$posted = $source[ self::field_base( '_wplc_' . $field, $suffix ) ] ?? null;
			if ( '' !== $suffix ) {
				$posted = is_array( $posted ) ? ( $posted[ absint( trim( $suffix, '[]' ) ) ] ?? null ) : null;
			}
			if ( null !== $posted ) {
				$overrides[ $field ] = $get( '_wplc_' . $field );
			}
		}

		return array(
			'overrides' => $overrides,
			'live_site_limit_raw' => $get( '_wplc_live_site_limit' ),
			'entitlements_raw' => $raw_entitlements,
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
			self::keep_draft( $product_id, $variation_id, $data );
			self::add_admin_error( $label, __( 'The advanced entitlements field must contain a valid JSON object. The license settings were not saved.', 'wp-license' ) );
			return;
		}

		if ( ! $data['enabled'] ) {
			Catalog::delete_mapping( $product_id, $variation_id );
			self::write_meta( $product, $data, false );
			return;
		}

		$error       = '';
		$error_field = '';
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
				$error       = Catalog::last_error();
				$error_field = Catalog::last_error_field();
			}
		}

		if ( '' !== $error ) {
			// P5-I2: the form shows the submitted values again; the message names the wrong field only.
			self::keep_draft( $product_id, $variation_id, $data, $error_field );
			self::add_admin_error(
				$label,
				Catalog::error_message( $error, $error_field ) . ' ' . __( 'The license settings were not saved.', 'wp-license' )
			);
			return;
		}

		// P5-M17: the live-site limit is kept within 1 to 1000; say so instead of changing it silently.
		$saved_limit = max( 1, min( 1000, (int) $data['live_site_limit'] ) );
		if ( (string) ( $data['live_site_limit_raw'] ?? $saved_limit ) !== (string) $saved_limit ) {
			self::add_admin_error(
				$label,
				sprintf(
					/* translators: %s: the number that was saved. */
					__( 'The live site limit must be a whole number from 1 to 1000; %s was saved.', 'wp-license' ),
					number_format_i18n( $saved_limit )
				)
			);
		}
		// P4-M1: rules the user may not change were sent anyway (an old form, another tool): say they stayed.
		$kept = Catalog::kept_overrides();
		if ( $kept ) {
			self::add_admin_error(
				$label,
				sprintf(
					/* translators: 1: capability name, 2: list of rule names. */
					__( 'These rules of the plan were not changed, because only users with the capability %1$s may change them: %2$s.', 'wp-license' ),
					\WPLC\Admin\Capabilities::cap( 'manage_policy' ),
					implode( ', ', array_map( array( PlanOverrides::class, 'label' ), $kept ) )
				)
			);
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
	 * Mirror the values of a plan that was saved outside the product editor (WP-CLI) in the product
	 * meta, so the editor shows what the plan holds.
	 *
	 * @param \WC_Product         $product Product.
	 * @param array<string,mixed> $data    product_key, plan_key, billing_interval, live_site_limit, support_level, entitlements.
	 * @return void
	 */
	public static function mirror_meta( $product, array $data ): void {
		self::write_meta( $product, $data, true );
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
