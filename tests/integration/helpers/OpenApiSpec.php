<?php
/**
 * Minimal reader for docs/openapi-v1.json used by the REST conformance tests.
 *
 * @package WPLC
 */

/**
 * Answers "is this status declared for this operation" and "which fields are required".
 */
final class WPLC_OpenApi_Spec {
	/**
	 * Decoded document.
	 *
	 * @var array<string,mixed>
	 */
	private $document;

	public function __construct() {
		$json           = (string) file_get_contents( dirname( __DIR__, 3 ) . '/docs/openapi-v1.json' );
		$this->document = json_decode( $json, true );
		if ( ! is_array( $this->document ) ) {
			throw new RuntimeException( 'docs/openapi-v1.json is not valid JSON.' );
		}
	}

	/**
	 * All documented operations as [ method, path ] pairs.
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	public function operations(): array {
		$operations = array();
		foreach ( $this->document['paths'] as $path => $methods ) {
			foreach ( array_keys( $methods ) as $method ) {
				$operations[] = array( strtoupper( (string) $method ), (string) $path );
			}
		}

		return $operations;
	}

	/**
	 * Status codes declared for an operation.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   OpenAPI path template, e.g. /activations/{activation_id}.
	 * @return int[]
	 */
	public function declared_statuses( string $method, string $path ): array {
		$operation = $this->document['paths'][ $path ][ strtolower( $method ) ] ?? null;
		if ( ! is_array( $operation ) ) {
			throw new RuntimeException( 'Operation not documented: ' . $method . ' ' . $path );
		}

		return array_map( 'intval', array_keys( $operation['responses'] ) );
	}

	/**
	 * Whether the declared response for a status is the shared ApiError body.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path template.
	 * @param int    $status Status code.
	 * @return bool
	 */
	public function is_api_error( string $method, string $path, int $status ): bool {
		$response = $this->document['paths'][ $path ][ strtolower( $method ) ]['responses'][ (string) $status ] ?? array();

		return '#/components/responses/ApiError' === ( $response['$ref'] ?? '' );
	}

	/**
	 * Required property names of a component schema.
	 *
	 * @param string $schema Schema name.
	 * @return string[]
	 */
	public function required( string $schema ): array {
		return (array) ( $this->document['components']['schemas'][ $schema ]['required'] ?? array() );
	}

	/**
	 * Fields the SignedEnvelope description promises for entitlement responses.
	 *
	 * @return string[]
	 */
	public function entitlement_fields(): array {
		$description = (string) $this->document['components']['schemas']['SignedEnvelope']['properties']['data']['description'];
		if ( ! preg_match( '/include (.+?)\.$/', $description, $match ) ) {
			throw new RuntimeException( 'The SignedEnvelope data description no longer lists the entitlement fields.' );
		}
		$fields = preg_split( '/,\s*|\s+and\s+/', $match[1] );

		return array_values( array_filter( array_map( 'trim', (array) $fields ) ) );
	}
}
