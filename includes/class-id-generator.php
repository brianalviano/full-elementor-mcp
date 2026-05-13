<?php
/**
 * Unique element ID generation.
 *
 * Generates 7-character hex IDs matching Elementor's internal format.
 *
 * @package Full_Elementor_MCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates unique element IDs for Elementor elements.
 *
 * @since 1.0.0
 */
class Full_Full_Elementor_MCP_Id_Generator {

	/**
	 * Tracks IDs already issued during the current request to avoid in-batch
	 * collisions. 7-hex-char space is ~268M but tight loops in `build-page` /
	 * `import-template` / `reassign_ids` make collisions plausible.
	 *
	 * @var array<string,bool>
	 */
	private static $issued = array();

	/**
	 * Generates a 7-character random hex string, retrying on in-request collision.
	 *
	 * Matches Elementor's internal element ID format.
	 *
	 * @since 1.0.0
	 *
	 * @return string 7-character hex ID.
	 */
	public static function generate(): string {
		// Up to 8 retries — astronomically unlikely to ever loop more than once.
		for ( $attempt = 0; $attempt < 8; $attempt++ ) {
			$id = substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
			if ( ! isset( self::$issued[ $id ] ) ) {
				self::$issued[ $id ] = true;
				return $id;
			}
		}
		// Fallback: append attempt counter; keeps 7-char length impossible
		// to guarantee, so allow up to 8 chars in the worst case.
		$id = substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
		self::$issued[ $id ] = true;
		return $id;
	}

	/**
	 * Marks externally-known IDs (e.g. existing page tree) as taken so newly
	 * generated IDs cannot collide with them.
	 *
	 * @param string[] $ids
	 */
	public static function reserve( array $ids ): void {
		foreach ( $ids as $id ) {
			if ( is_string( $id ) && '' !== $id ) {
				self::$issued[ $id ] = true;
			}
		}
	}

	/**
	 * Generates a CSS class identifier suitable for atomic-element local style
	 * classes. Uses the same dedupe machinery as element IDs.
	 *
	 * @param string $element_id The owning element's ID.
	 * @return string Class id, e.g. "e-{element_id}-{7hex}".
	 */
	public static function generate_class_id( string $element_id ): string {
		return 'e-' . $element_id . '-' . self::generate();
	}
}
