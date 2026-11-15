<?php
/**
 * Editor blocks and the changelog shortcode for shop pages.
 *
 * @package WPLC
 */

namespace WPLC\Account;

use WPLC\Update\ReleaseRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * B-117: building blocks for pages made with the block editor or a page builder.
 *
 * - Block `wplc/licenses`: the customer's license list, the same output as the
 *   `[wplc_licenses]` shortcode (CustomerPortal::shortcode()). Server-rendered;
 *   the editor shows a placeholder, because the list belongs to the visitor.
 * - Shortcode `[wplc_changelog product="…" limit="10"]` and block `wplc/changelog`:
 *   the release notes of the published stable versions of a product, highest first.
 *
 * The editor script needs no build step and contains no translatable strings;
 * the labels come from PHP (wp_add_inline_script()).
 */
final class Blocks {

	/**
	 * Default number of versions in the changelog.
	 */
	const CHANGELOG_DEFAULT_LIMIT = 10;

	/**
	 * Most versions the changelog shows.
	 */
	const CHANGELOG_MAX_LIMIT = 50;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register_blocks' ) );
		add_shortcode( 'wplc_changelog', array( self::class, 'changelog_shortcode' ) );
	}

	/**
	 * Register the editor script and the two dynamic blocks.
	 *
	 * @return void
	 */
	public static function register_blocks(): void {
		if ( ! function_exists( 'register_block_type' ) || ! defined( 'WPLC_FILE' ) ) {
			return;
		}

		wp_register_script(
			'wplc-blocks',
			plugins_url( 'assets/js/wplc-blocks.js', WPLC_FILE ),
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components' ),
			CustomerPortal::asset_version( 'assets/js/wplc-blocks.js' ),
			true
		);
		wp_add_inline_script( 'wplc-blocks', 'window.wplcBlocks = ' . wp_json_encode( self::editor_strings() ) . ';', 'before' );

		register_block_type(
			'wplc/licenses',
			array(
				'api_version' => '3',
				'title' => __( 'My licenses', 'wp-license' ),
				'category' => 'widgets',
				'editor_script_handles' => array( 'wplc-blocks' ),
				'supports' => array(
					'html' => false,
					'multiple' => false,
				),
				'render_callback' => array( self::class, 'render_licenses' ),
			)
		);
		register_block_type(
			'wplc/changelog',
			array(
				'api_version' => '3',
				'title' => __( 'Changelog', 'wp-license' ),
				'category' => 'widgets',
				'editor_script_handles' => array( 'wplc-blocks' ),
				'supports' => array( 'html' => false ),
				'attributes' => array(
					'product' => array(
						'type' => 'string',
						'default' => '',
					),
					'limit' => array(
						'type' => 'number',
						'default' => self::CHANGELOG_DEFAULT_LIMIT,
					),
				),
				'render_callback' => array( self::class, 'render_changelog_block' ),
			)
		);
	}

	/**
	 * Labels for the editor script.
	 *
	 * @return array<string,string>
	 */
	public static function editor_strings(): array {
		return array(
			'licensesTitle' => __( 'My licenses', 'wp-license' ),
			'licensesDescription' => __( 'The signed-in customer\'s licenses with keys, sites, downloads and renewal.', 'wp-license' ),
			'licensesPlaceholder' => __( 'The visitor\'s licenses are shown here. Signed-out visitors are asked to sign in.', 'wp-license' ),
			'changelogTitle' => __( 'Changelog', 'wp-license' ),
			'changelogDescription' => __( 'Release notes of the published versions of a product.', 'wp-license' ),
			'changelogProduct' => __( 'Product key', 'wp-license' ),
			'changelogLimit' => __( 'Number of versions', 'wp-license' ),
			'changelogPlaceholder' => __( 'Enter the product key in the block settings.', 'wp-license' ),
		);
	}

	/**
	 * Block `wplc/licenses`.
	 *
	 * @return string
	 */
	public static function render_licenses(): string {
		return CustomerPortal::shortcode();
	}

	/**
	 * Block `wplc/changelog`.
	 *
	 * @param mixed $attributes Block attributes.
	 * @return string
	 */
	public static function render_changelog_block( $attributes = array() ): string {
		$attributes = is_array( $attributes ) ? $attributes : array();

		return self::changelog_html( (string) ( $attributes['product'] ?? '' ), (int) ( $attributes['limit'] ?? self::CHANGELOG_DEFAULT_LIMIT ) );
	}

	/**
	 * Shortcode `[wplc_changelog product="…" limit="10"]`.
	 *
	 * @param mixed $atts Shortcode attributes.
	 * @return string
	 */
	public static function changelog_shortcode( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'product' => '',
				'limit' => self::CHANGELOG_DEFAULT_LIMIT,
			),
			is_array( $atts ) ? $atts : array(),
			'wplc_changelog'
		);

		return self::changelog_html( (string) $atts['product'], (int) $atts['limit'] );
	}

	/**
	 * Release notes of the published stable versions of a product.
	 *
	 * Drafts, withdrawn versions and the beta channel are never shown. Notes are
	 * stored filtered by wp_kses_post() and filtered again on output.
	 *
	 * @param string $product_key Product key.
	 * @param int    $limit       Number of versions.
	 * @return string HTML; empty for an unknown product or one without published versions.
	 */
	public static function changelog_html( string $product_key, int $limit ): string {
		$product_key = sanitize_text_field( $product_key );
		if ( '' === $product_key ) {
			return '';
		}
		$limit = max( 1, min( self::CHANGELOG_MAX_LIMIT, $limit ) );

		// "Newer than 0.0.0" asks for every version instead of the short fallback list.
		$entries = ReleaseRegistry::changelog( $product_key, ReleaseRegistry::DEFAULT_CHANNEL, '0.0.0', '', $limit );
		if ( ! $entries ) {
			return '';
		}

		$html = '<div class="wplc-changelog">';
		foreach ( $entries as $entry ) {
			$date  = '' !== $entry['date'] ? mysql2date( (string) get_option( 'date_format' ), get_date_from_gmt( $entry['date'] ) ) : '';
			$html .= '<section class="wplc-changelog__version">';
			$html .= '<h3>' . esc_html( $entry['version'] ) . ( '' !== $date ? ' <span class="wplc-changelog__date">' . esc_html( $date ) . '</span>' : '' ) . '</h3>';
			$html .= '' !== trim( $entry['notes'] ) ? wpautop( wp_kses_post( $entry['notes'] ) ) : '';
			$html .= '</section>';
		}

		return $html . '</div>';
	}
}
