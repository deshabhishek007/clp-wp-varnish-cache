<?php
/**
 * Records purge operations to a transient-backed in-memory log.
 *
 * @package CLP_Varnish_Cache
 */

/**
 * Stores and retrieves the last N purge operations as a transient log.
 */
final class ClpVarnishCacheLogger {

	/**
	 * Transient key for the purge log.
	 *
	 * @var string
	 */
	private const TRANSIENT_KEY = 'clp_varnish_purge_log';

	/**
	 * Maximum number of log entries to retain.
	 *
	 * @var int
	 */
	private const MAX_ENTRIES = 20;

	/**
	 * Appends a purge operation to the log, trimming to MAX_ENTRIES.
	 *
	 * @param PurgeType $type    The type of purge performed.
	 * @param string    $target  Human-readable description of what was purged.
	 * @param bool      $success Whether the purge request succeeded.
	 * @param string    $message Optional error message on failure.
	 */
	public static function log( PurgeType $type, string $target, bool $success, string $message = '' ): void {
		$log = self::get_log();
		array_unshift(
			$log,
			array(
				'time'    => current_time( 'mysql' ),
				'type'    => $type->value,
				'target'  => $target,
				'success' => $success,
				'message' => $message,
			)
		);
		set_transient( self::TRANSIENT_KEY, array_slice( $log, 0, self::MAX_ENTRIES ), DAY_IN_SECONDS );
	}

	/**
	 * Returns all stored log entries, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_log(): array {
		$stored = get_transient( self::TRANSIENT_KEY );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Deletes the purge log transient.
	 */
	public static function clear(): void {
		delete_transient( self::TRANSIENT_KEY );
	}
}
