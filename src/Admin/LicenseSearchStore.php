<?php
/**
 * Keeps the admin license search out of URLs (T-141).
 *
 * @package WPLC
 */

namespace WPLC\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores search criteria briefly per administrator, addressed by an opaque token.
 *
 * The search form is POSTed; the handler stores the criteria from
 * LicenseSearch::criteria() (a complete key only as its hash) in a transient
 * scoped to the current user and redirects to a URL that carries nothing but
 * the random token. Sort and pagination links keep the token, never the term,
 * so a pasted key does not end up in the address bar, browser history,
 * referers or access logs. A token is useless to other users and expires
 * after TTL seconds without use.
 */
final class LicenseSearchStore {

	/**
	 * Query argument carrying the token.
	 */
	public const QUERY_ARG = 'wplc_search';

	/**
	 * Idle lifetime of a stored search in seconds; every use extends it.
	 */
	public const TTL = 900;

	/**
	 * Transient name prefix.
	 */
	private const PREFIX = 'wplc_lsearch_';

	/**
	 * Criteria loaded in this request, keyed by user and token.
	 *
	 * @var array<string,array{type:string,value:string,label:string}|null>
	 */
	private static $loaded = array();

	/**
	 * Store a search term for a user.
	 *
	 * @param int    $user_id Administrator user ID.
	 * @param string $term    Raw search term.
	 * @return string Token, or '' when there is nothing to search for.
	 */
	public static function save( int $user_id, string $term ): string {
		$criteria = LicenseSearch::criteria( $term );
		if ( $user_id <= 0 || LicenseSearch::TYPE_NONE === $criteria['type'] ) {
			return '';
		}

		$token = bin2hex( random_bytes( 12 ) );
		set_transient( self::transient_name( $user_id, $token ), $criteria, self::TTL );
		self::$loaded[ $user_id . ':' . $token ] = $criteria;

		return $token;
	}

	/**
	 * Load the criteria behind a token of the given user and extend their lifetime.
	 *
	 * @param int    $user_id Administrator user ID.
	 * @param string $token   Token from the URL.
	 * @return array{type:string,value:string,label:string}|null Null when unknown, foreign or expired.
	 */
	public static function load( int $user_id, string $token ): ?array {
		if ( $user_id <= 0 || ! self::is_token( $token ) ) {
			return null;
		}
		$cache_key = $user_id . ':' . $token;
		if ( array_key_exists( $cache_key, self::$loaded ) ) {
			return self::$loaded[ $cache_key ];
		}

		$criteria = LicenseSearch::sanitize_criteria( get_transient( self::transient_name( $user_id, $token ) ) );
		if ( null !== $criteria ) {
			set_transient( self::transient_name( $user_id, $token ), $criteria, self::TTL );
		}
		self::$loaded[ $cache_key ] = $criteria;

		return $criteria;
	}

	/**
	 * Whether a string has the shape of a token.
	 *
	 * @param string $token Candidate.
	 * @return bool
	 */
	public static function is_token( string $token ): bool {
		return 1 === preg_match( '/^[0-9a-f]{24}$/', $token );
	}

	/**
	 * Transient name of one stored search.
	 *
	 * @param int    $user_id User ID.
	 * @param string $token   Token.
	 * @return string
	 */
	public static function transient_name( int $user_id, string $token ): string {
		return self::PREFIX . $user_id . '_' . $token;
	}

	/**
	 * Forget the per-request cache (tests).
	 *
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$loaded = array();
	}
}
