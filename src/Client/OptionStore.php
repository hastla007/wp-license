<?php
/**
 * Storage of the license client: per-site options or network options.
 *
 * @package WPLC
 */

namespace WPLC\Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\OptionStore', false ) ) {

	/**
	 * Reads and writes the non-autoloaded options of one add-on.
	 *
	 * With `$network = true` (multisite, one activation for the whole network)
	 * the values live in the network options and the address of the installation
	 * is `network_home_url()`. Otherwise every site keeps its own values and is
	 * identified by `home_url()`.
	 *
	 * The class is not final so that tests can replace it with an in-memory
	 * store; add-ons should not extend it.
	 */
	class OptionStore {

		/**
		 * Version of the client library this copy belongs to.
		 */
		const VERSION = '2.0.0';

		/**
		 * Whether values are stored for the whole network.
		 *
		 * @var bool
		 */
		private $network;

		/**
		 * Constructor.
		 *
		 * @param bool $network Store per network instead of per site. Ignored outside multisite.
		 */
		public function __construct( bool $network = false ) {
			$this->network = $network && function_exists( 'is_multisite' ) && is_multisite();
		}

		/**
		 * Whether values are stored for the whole network.
		 *
		 * @return bool
		 */
		public function is_network(): bool {
			return $this->network;
		}

		/**
		 * Read a value.
		 *
		 * @param string $name     Option name.
		 * @param mixed  $fallback Value when the option does not exist.
		 * @return mixed
		 */
		public function get( string $name, $fallback = array() ) {
			return $this->network ? get_site_option( $name, $fallback ) : get_option( $name, $fallback );
		}

		/**
		 * Write a value (never autoloaded) and confirm that it was stored.
		 *
		 * @param string $name  Option name.
		 * @param mixed  $value Value.
		 * @return bool Whether the stored value equals the given one afterwards.
		 */
		public function set( string $name, $value ): bool {
			if ( $this->network ) {
				update_site_option( $name, $value );
			} else {
				update_option( $name, $value, false );
			}

			// update_option() also returns false for an unchanged value, so read back instead.
			return maybe_serialize( $this->get( $name, null ) ) === maybe_serialize( $value );
		}

		/**
		 * Delete a value.
		 *
		 * @param string $name Option name.
		 * @return void
		 */
		public function delete( string $name ): void {
			if ( $this->network ) {
				delete_site_option( $name );
			} else {
				delete_option( $name );
			}
		}

		/**
		 * Address this installation is activated for.
		 *
		 * @return string
		 */
		public function site_url(): string {
			return $this->network ? network_home_url() : home_url();
		}
	}
}
