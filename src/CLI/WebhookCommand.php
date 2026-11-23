<?php
/**
 * Outgoing webhooks on the command line.
 *
 * @package WPLC
 */

namespace WPLC\CLI;

use WPLC\Webhooks\Admin;
use WPLC\Webhooks\Dispatcher;
use WPLC\Webhooks\Endpoints;
use WPLC\Webhooks\EventCatalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp wplc webhook`: list, add and delete the endpoints of the outgoing webhooks and send a test
 * event. The same checks as the admin screen (https only, no private or local address, at least one
 * event); every change is written to the audit log. The secret is printed once, by `add`.
 */
final class WebhookCommand {

	/**
	 * List the endpoints. Never prints a secret.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json or csv. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc webhook list
	 *
	 * @subcommand list
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function list_( array $args, array $assoc_args ): void {
		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), self::rows(), array( 'id', 'name', 'url', 'events', 'active', 'failures', 'last_success', 'last_failure' ) );
	}

	/**
	 * Add an endpoint and print its secret once.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : Target URL (https://, port 443, public address).
	 *
	 * --events=<events>
	 * : Comma-separated event names, or "all". Events: license.issued, license.status_changed, license.plan_changed, license.site_limit_reached, activation.created, activation.removed, release.published, release.withdrawn.
	 *
	 * [--name=<name>]
	 * : Label of the endpoint.
	 *
	 * [--inactive]
	 * : Add it switched off.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc webhook add https://hooks.example.com/licenses --events=license.issued,license.status_changed --name="CRM"
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function add( array $args, array $assoc_args ): void {
		$result = self::run_add( (string) ( $args[0] ?? '' ), (string) ( $assoc_args['events'] ?? '' ), (string) ( $assoc_args['name'] ?? '' ), empty( $assoc_args['inactive'] ) );
		if ( ! $result['ok'] ) {
			\WP_CLI::error( $result['message'] );
		}
		\WP_CLI::line( 'Secret (shown only now): ' . $result['secret'] );
		\WP_CLI::success( $result['message'] );
	}

	/**
	 * Delete an endpoint and its delivery log.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Endpoint ID (see "wp wplc webhook list").
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc webhook delete 3 --yes
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function delete( array $args, array $assoc_args ): void {
		$id = absint( $args[0] ?? 0 );
		\WP_CLI::confirm( 'Delete webhook endpoint ' . $id . ' and its delivery log?', $assoc_args );
		if ( ! Endpoints::delete( $id, self::user_id() ) ) {
			\WP_CLI::error( 'The endpoint was not found.' );
		}
		\WP_CLI::success( 'The endpoint and its delivery log were deleted.' );
	}

	/**
	 * Send a test event (webhook.test) to an endpoint now and print the result.
	 *
	 * The test event is sent once, also to an endpoint that is switched off, and is not retried.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Endpoint ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc webhook test 3
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function test( array $args, array $assoc_args ): void {
		$delivery = Dispatcher::send_test( absint( $args[0] ?? 0 ) );
		if ( null === $delivery ) {
			\WP_CLI::error( 'The endpoint was not found.' );
		}
		\WP_CLI::line( sprintf( 'Event ID %s, HTTP status %d, %d ms.', (string) $delivery['event_id'], (int) $delivery['response_code'], (int) $delivery['duration_ms'] ) );
		if ( 'delivered' !== $delivery['status'] ) {
			\WP_CLI::error( 'The test event could not be delivered: ' . (string) $delivery['error'] );
		}
		\WP_CLI::success( 'The test event was delivered.' );
	}

	/**
	 * Add an endpoint. Kept free of WP_CLI calls so it can be tested.
	 *
	 * @param string $url    Target URL.
	 * @param string $events Comma-separated event names or "all".
	 * @param string $name   Label.
	 * @param bool   $active Switched on.
	 * @return array{ok:bool,id:int,secret:string,message:string}
	 */
	public static function run_add( string $url, string $events, string $name, bool $active ): array {
		$selection = 'all' === trim( $events ) ? EventCatalog::names() : EventCatalog::sanitize_selection( $events );
		$result    = Endpoints::create( $name, $url, $selection, $active, self::user_id() );

		return array(
			'ok'      => $result['ok'],
			'id'      => $result['id'],
			'secret'  => $result['secret'],
			'message' => $result['ok'] ? sprintf( 'Webhook endpoint %d added.', $result['id'] ) : Admin::message( $result['error'] ),
		);
	}

	/**
	 * Rows of the list.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows(): array {
		$rows = array();
		foreach ( Endpoints::all() as $endpoint ) {
			$rows[] = array(
				'id'           => $endpoint['id'],
				'name'         => $endpoint['name'],
				'url'          => $endpoint['url'],
				'events'       => implode( ',', $endpoint['events'] ),
				'active'       => $endpoint['active'] ? 'yes' : ( Endpoints::REASON_FAILURES === $endpoint['disabled_reason'] ? 'no (failures)' : 'no' ),
				'failures'     => $endpoint['consecutive_failures'],
				'last_success' => $endpoint['last_success_at'],
				'last_failure' => $endpoint['last_failure_at'],
			);
		}

		return $rows;
	}

	/**
	 * Acting user (WP-CLI --user), 0 when none.
	 *
	 * @return int
	 */
	private static function user_id(): int {
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}
}
