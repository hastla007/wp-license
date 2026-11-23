<?php
/**
 * Reads and validates a catalog file (`wplc-catalog.json`, docs/CATALOG-FILE.md).
 *
 * @package WPLC
 */

namespace WPLC\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A catalog file describes one license product of a vendor: its product key, its entitlement
 * prefix, its name and the entitlements its add-on checks, each with labels per locale and a
 * status. The add-on ships the file; the server imports it (T-150, owner decisions G2 and E8).
 *
 * This class is pure: no WordPress function is needed to validate a file.
 */
final class CatalogFile {

	/**
	 * The only format version this server reads.
	 */
	public const FORMAT = 1;

	/**
	 * File name an add-on ships in its root folder.
	 */
	public const FILE_NAME = 'wplc-catalog.json';

	/**
	 * Largest accepted file in bytes.
	 */
	public const MAX_BYTES = 262144;

	/**
	 * Most entitlements one product may define.
	 */
	public const MAX_ENTITLEMENTS = 500;

	/**
	 * Longest label or name in characters.
	 */
	public const MAX_LABEL_LENGTH = 190;

	/**
	 * Longest description in characters.
	 */
	public const MAX_DESCRIPTION_LENGTH = 1000;

	/**
	 * Most installation steps of a product (SET-LIC-24).
	 */
	public const MAX_INSTALL_STEPS = 10;

	/**
	 * Longest installation step and longest documentation address in characters (SET-LIC-24).
	 */
	public const MAX_STEP_LENGTH = 500;

	/**
	 * Most plan names of a product (SET-LIC-24).
	 */
	public const MAX_PLANS = 50;

	public const STATUS_AVAILABLE = 'available';
	public const STATUS_PLANNED   = 'planned';

	/**
	 * Product key: the pattern the release registry and the license catalog use.
	 */
	public const PRODUCT_KEY_PATTERN = '/^[a-z0-9][a-z0-9-]{1,99}$/D';

	/**
	 * Entitlement prefix: the part of every key before the first dot, also the group in the product editor.
	 */
	public const PREFIX_PATTERN = '/^[a-z][a-z0-9_-]{0,39}$/D';

	/**
	 * Locale of a label: `en` (required, the fallback) or a WordPress locale such as `de_DE` or `de_DE_formal`.
	 */
	public const LOCALE_PATTERN = '/^(?:en|[a-z]{2,3}_[A-Z]{2}(?:_[a-z0-9]+)?)$/D';

	/**
	 * Parse and validate the content of a catalog file.
	 *
	 * @param string $json File content.
	 * @return array{ok:bool,errors:array<int,array{code:string,path:string}>,catalog:array<string,mixed>|null}
	 *         `catalog` is the normalized product (see normalize()) when `ok` is true.
	 */
	public static function parse( string $json ): array {
		if ( strlen( $json ) > self::MAX_BYTES ) {
			return self::failure( array( self::error( 'file_too_large', '' ) ) );
		}
		// A UTF-8 byte order mark is tolerated (files saved by Windows editors).
		if ( 0 === strpos( $json, "\xEF\xBB\xBF" ) ) {
			$json = substr( $json, 3 );
		}
		$data = json_decode( $json, true, 16 );
		if ( ! is_array( $data ) || self::is_list( $data ) ) {
			return self::failure( array( self::error( 'not_a_json_object', '' ) ) );
		}

		return self::validate( $data );
	}

	/**
	 * Validate decoded file content.
	 *
	 * @param array<mixed,mixed> $data Decoded JSON object.
	 * @return array{ok:bool,errors:array<int,array{code:string,path:string}>,catalog:array<string,mixed>|null}
	 */
	public static function validate( array $data ): array {
		$errors = array();

		if ( ! isset( $data['format'] ) || ! is_int( $data['format'] ) ) {
			$errors[] = self::error( 'format_missing', 'format' );
		} elseif ( self::FORMAT !== $data['format'] ) {
			// A newer format is not guessed at: the server must be updated first.
			return self::failure( array( self::error( 'format_unsupported', 'format' ) ) );
		}

		$product_key = isset( $data['product_key'] ) && is_string( $data['product_key'] ) ? $data['product_key'] : '';
		if ( 1 !== preg_match( self::PRODUCT_KEY_PATTERN, $product_key ) ) {
			$errors[] = self::error( 'product_key_invalid', 'product_key' );
		}

		$name = self::texts( $data['name'] ?? null, 'name', self::MAX_LABEL_LENGTH, $errors );

		$status = $data['status'] ?? self::STATUS_AVAILABLE;
		if ( ! in_array( $status, array( self::STATUS_AVAILABLE, self::STATUS_PLANNED ), true ) ) {
			$errors[] = self::error( 'status_invalid', 'status' );
			$status   = self::STATUS_AVAILABLE;
		}

		$raw = $data['entitlements'] ?? null;
		if ( ! is_array( $raw ) || ! self::is_list( $raw ) ) {
			$errors[] = self::error( 'entitlements_missing', 'entitlements' );
			$raw      = array();
		} elseif ( count( $raw ) > self::MAX_ENTITLEMENTS ) {
			$errors[] = self::error( 'too_many_entitlements', 'entitlements' );
			$raw      = array();
		}

		// The prefix may be left out when the keys show it.
		$prefix = $data['prefix'] ?? null;
		if ( null === $prefix ) {
			$first  = isset( $raw[0]['key'] ) && is_string( $raw[0]['key'] ) ? $raw[0]['key'] : '';
			$dot    = strpos( $first, '.' );
			$prefix = false === $dot ? '' : substr( $first, 0, $dot );
			if ( '' === $prefix ) {
				$errors[] = self::error( 'prefix_missing', 'prefix' );
			}
		}
		if ( ! is_string( $prefix ) || ( '' !== $prefix && 1 !== preg_match( self::PREFIX_PATTERN, $prefix ) ) ) {
			$errors[] = self::error( 'prefix_invalid', 'prefix' );
			$prefix   = '';
		}

		$entitlements = array();
		$seen         = array();
		foreach ( $raw as $index => $entry ) {
			$path = 'entitlements[' . $index . ']';
			if ( ! is_array( $entry ) || self::is_list( $entry ) ) {
				$errors[] = self::error( 'entitlement_not_an_object', $path );
				continue;
			}
			$key = isset( $entry['key'] ) && is_string( $entry['key'] ) ? $entry['key'] : '';
			if ( 1 !== preg_match( EntitlementCatalog::KEY_PATTERN, $key ) ) {
				$errors[] = self::error( 'key_invalid', $path . '.key' );
				continue;
			}
			if ( '' !== $prefix && 0 !== strpos( $key, $prefix . '.' ) ) {
				$errors[] = self::error( 'key_without_prefix', $path . '.key' );
			}
			if ( isset( $seen[ $key ] ) ) {
				$errors[] = self::error( 'key_duplicate', $path . '.key' );
				continue;
			}
			$seen[ $key ] = true;

			$entry_status = $entry['status'] ?? self::STATUS_AVAILABLE;
			if ( ! in_array( $entry_status, array( self::STATUS_AVAILABLE, self::STATUS_PLANNED ), true ) ) {
				$errors[]     = self::error( 'status_invalid', $path . '.status' );
				$entry_status = self::STATUS_AVAILABLE;
			}
			$label       = self::texts( $entry['label'] ?? null, $path . '.label', self::MAX_LABEL_LENGTH, $errors );
			$description = array_key_exists( 'description', $entry )
				? self::texts( $entry['description'], $path . '.description', self::MAX_DESCRIPTION_LENGTH, $errors )
				: array();

			$entitlements[] = array(
				'key'         => $key,
				'label'       => $label,
				'description' => $description,
				'status'      => $entry_status,
			);
		}

		// Texts for customers (SET-LIC-24), all optional: documentation address, installation steps, plan names.
		$customer_texts = self::customer_texts( $data, $errors );

		if ( $errors ) {
			return self::failure( $errors );
		}

		return array(
			'ok'      => true,
			'errors'  => array(),
			'catalog' => array(
				'format'       => self::FORMAT,
				'product_key'  => $product_key,
				'prefix'       => $prefix,
				'name'         => $name,
				'status'       => $status,
				'entitlements' => $entitlements,
			) + $customer_texts,
		);
	}

	/**
	 * The optional texts for customers of a file (SET-LIC-24). Only the fields the file has are returned,
	 * so a product without them keeps the normalized form (and the fingerprint) it had before.
	 *
	 * - `docs_url`: text per locale, an http(s) address of the documentation;
	 * - `install_steps`: list of 1 to 10 texts per locale (the steps in the account and the license email);
	 * - `plans`: object plan key => { "name": text per locale } (the name of the plan shown to customers).
	 *
	 * @param array<mixed,mixed>                          $data   Decoded file.
	 * @param array<int,array{code:string,path:string}> $errors Collected errors.
	 * @return array<string,mixed>
	 */
	private static function customer_texts( array $data, array &$errors ): array {
		$out = array();
		if ( array_key_exists( 'docs_url', $data ) ) {
			$urls = self::texts( $data['docs_url'], 'docs_url', self::MAX_STEP_LENGTH, $errors );
			foreach ( $urls as $locale => $url ) {
				if ( 1 !== preg_match( '#^https?://[^\s<>"\'`]+$#iD', $url ) ) {
					$errors[] = self::error( 'url_invalid', 'docs_url.' . $locale );
				}
			}
			$out['docs_url'] = $urls;
		}
		if ( array_key_exists( 'install_steps', $data ) ) {
			$raw = $data['install_steps'];
			if ( ! is_array( $raw ) || ! self::is_list( $raw ) || array() === $raw || count( $raw ) > self::MAX_INSTALL_STEPS ) {
				$errors[] = self::error( 'install_steps_invalid', 'install_steps' );
			} else {
				$steps = array();
				foreach ( $raw as $index => $step ) {
					$steps[] = self::texts( $step, 'install_steps[' . $index . ']', self::MAX_STEP_LENGTH, $errors );
				}
				$out['install_steps'] = $steps;
			}
		}
		if ( array_key_exists( 'plans', $data ) ) {
			$raw = $data['plans'];
			if ( ! is_array( $raw ) || ( self::is_list( $raw ) && array() !== $raw ) || count( $raw ) > self::MAX_PLANS ) {
				$errors[] = self::error( 'plans_invalid', 'plans' );
			} else {
				$plans = array();
				foreach ( $raw as $plan_key => $plan ) {
					$path = 'plans.' . $plan_key;
					if ( ! is_string( $plan_key ) || 1 !== preg_match( Catalog::PLAN_KEY_PATTERN, $plan_key ) ) {
						$errors[] = self::error( 'plan_key_invalid', $path );
						continue;
					}
					if ( ! is_array( $plan ) || self::is_list( $plan ) || ! array_key_exists( 'name', $plan ) ) {
						$errors[] = self::error( 'text_missing', $path . '.name' );
						continue;
					}
					$plans[ $plan_key ] = array( 'name' => self::texts( $plan['name'], $path . '.name', self::MAX_LABEL_LENGTH, $errors ) );
				}
				ksort( $plans );
				if ( $plans ) {
					$out['plans'] = $plans;
				}
			}
		}

		return $out;
	}

	/**
	 * A text per locale. A plain string is read as the English text.
	 *
	 * @param mixed                                       $value  Value from the file.
	 * @param string                                      $path   Path for error messages.
	 * @param int                                         $max    Longest text in characters.
	 * @param array<int,array{code:string,path:string}> $errors Collected errors.
	 * @return array<string,string> Locale => text; always contains `en` when valid.
	 */
	private static function texts( $value, string $path, int $max, array &$errors ): array {
		if ( is_string( $value ) ) {
			$value = array( 'en' => $value );
		}
		if ( ! is_array( $value ) || self::is_list( $value ) && array() !== $value ) {
			$errors[] = self::error( 'text_missing', $path );
			return array();
		}
		$texts = array();
		foreach ( $value as $locale => $text ) {
			if ( ! is_string( $locale ) || 1 !== preg_match( self::LOCALE_PATTERN, $locale ) ) {
				$errors[] = self::error( 'locale_invalid', $path );
				continue;
			}
			if ( ! is_string( $text ) ) {
				$errors[] = self::error( 'text_invalid', $path . '.' . $locale );
				continue;
			}
			$text = trim( $text );
			// Labels are plain text: no markup, no control characters, one line.
			if ( '' === $text || $text !== strip_tags( $text ) || 1 === preg_match( '/[\x00-\x1F\x7F]/', $text ) ) {
				$errors[] = self::error( 'text_invalid', $path . '.' . $locale );
				continue;
			}
			if ( self::length( $text ) > $max ) {
				$errors[] = self::error( 'text_too_long', $path . '.' . $locale );
				continue;
			}
			$texts[ $locale ] = $text;
		}
		// An English text that is present but invalid was reported above.
		if ( ! array_key_exists( 'en', $value ) ) {
			$errors[] = self::error( 'text_missing', $path . '.en' );
		}

		return $texts;
	}

	/**
	 * The file content for a normalized product, as an add-on would ship it.
	 *
	 * @param array<string,mixed> $catalog Normalized product from parse() or the store.
	 * @return string JSON with a trailing newline.
	 */
	public static function encode( array $catalog ): string {
		$entitlements = array();
		foreach ( (array) ( $catalog['entitlements'] ?? array() ) as $entitlement ) {
			$entry = array(
				'key'   => (string) $entitlement['key'],
				'label' => (array) $entitlement['label'],
			);
			if ( ! empty( $entitlement['description'] ) ) {
				$entry['description'] = (array) $entitlement['description'];
			}
			$entry['status'] = (string) $entitlement['status'];
			$entitlements[]  = $entry;
		}
		$name = (array) ( $catalog['name'] ?? array() );
		$file = array(
			'format'       => self::FORMAT,
			'product_key'  => (string) ( $catalog['product_key'] ?? '' ),
			'prefix'       => (string) ( $catalog['prefix'] ?? '' ),
			'name'         => array( 'en' ) === array_keys( $name ) ? (string) $name['en'] : $name,
			'status'       => (string) ( $catalog['status'] ?? self::STATUS_AVAILABLE ),
			'entitlements' => $entitlements,
		);
		// SET-LIC-24: only when present, so the fingerprint of a product without them stays the same.
		foreach ( array( 'docs_url', 'install_steps', 'plans' ) as $field ) {
			if ( ! empty( $catalog[ $field ] ) && is_array( $catalog[ $field ] ) ) {
				$file[ $field ] = $catalog[ $field ];
			}
		}

		return (string) json_encode( $file, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
	}

	/**
	 * Fingerprint of a normalized product: equal for two files with the same meaning.
	 *
	 * @param array<string,mixed> $catalog Normalized product.
	 * @return string SHA-256, hexadecimal.
	 */
	public static function fingerprint( array $catalog ): string {
		return hash( 'sha256', self::encode( $catalog ) );
	}

	/**
	 * Plain English description of an error code, for the command line and logs.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public static function describe( string $code ): string {
		$messages = array(
			'file_too_large'            => 'The file is larger than 256 KB.',
			'not_a_json_object'         => 'The file is not a JSON object.',
			'format_missing'            => 'The field "format" is missing or not a whole number.',
			'format_unsupported'        => 'This server reads catalog files of format 1 only.',
			'product_key_invalid'       => 'The product key must consist of lowercase letters, digits and hyphens (2 to 100 characters).',
			'prefix_missing'            => 'The field "prefix" is required when the file lists no entitlement.',
			'prefix_invalid'            => 'The prefix must start with a lowercase letter and contain lowercase letters, digits, hyphens or underscores only.',
			'status_invalid'            => 'The status must be "available" or "planned".',
			'entitlements_missing'      => 'The field "entitlements" must be a list (it may be empty).',
			'too_many_entitlements'     => 'The file lists more than 500 entitlements.',
			'entitlement_not_an_object' => 'An entitlement must be an object.',
			'key_invalid'               => 'The entitlement key is missing or contains characters other than letters, digits, dots, hyphens and underscores.',
			'key_without_prefix'        => 'The entitlement key does not start with the prefix of the product and a dot.',
			'key_duplicate'             => 'The entitlement key is listed twice.',
			'text_missing'              => 'A name or label needs at least the English text ("en").',
			'text_invalid'              => 'A text is empty, not a string, or contains markup or control characters.',
			'text_too_long'             => 'A text is too long (name, label and plan name: 190 characters, installation step and documentation address: 500, description: 1000).',
			'locale_invalid'            => 'A locale must be "en" or a WordPress locale such as "de_DE" or "de_DE_formal".',
			'url_invalid'               => 'The documentation address must start with https:// or http:// and contain no spaces.',
			'install_steps_invalid'     => 'The field "install_steps" must be a list of 1 to 10 texts.',
			'plans_invalid'             => 'The field "plans" must be an object with at most 50 plan keys.',
			'plan_key_invalid'          => 'A plan key must consist of lowercase letters, digits and hyphens (1 to 100 characters).',
		);

		return $messages[ $code ] ?? $code;
	}

	/**
	 * @param string $code Error code.
	 * @param string $path Path of the field inside the file.
	 * @return array{code:string,path:string}
	 */
	private static function error( string $code, string $path ): array {
		return array(
			'code' => $code,
			'path' => $path,
		);
	}

	/**
	 * @param array<int,array{code:string,path:string}> $errors Errors.
	 * @return array{ok:bool,errors:array<int,array{code:string,path:string}>,catalog:null}
	 */
	private static function failure( array $errors ): array {
		return array(
			'ok'      => false,
			'errors'  => $errors,
			'catalog' => null,
		);
	}

	/**
	 * Whether an array is a JSON list.
	 *
	 * @param array<mixed,mixed> $value Array.
	 * @return bool
	 */
	private static function is_list( array $value ): bool {
		return array_is_list( $value );
	}

	/**
	 * @param string $text Text.
	 * @return int Characters.
	 */
	private static function length( string $text ): int {
		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}
}
