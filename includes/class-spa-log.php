<?php
/**
 * Sent-log store: reads and writes the spa_sent_log option.
 *
 * @package SportsPress_Announcer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static helpers for the sent-announcement log.
 *
 * Each entry shape:
 *   id          int     post_id (0 for digest entries)
 *   type        string  'result' | 'digest'
 *   label       string  Human-readable event name or digest title
 *   channel     string  '#channel-name'
 *   platform    string  'discord' | 'slack'
 *   sent_at     int     Unix timestamp
 *   status      string  'sent' | 'failed'
 *   webhook_url string  Stored for retry
 *   payload     array   Stored for retry
 */
class SPA_Log {

	public const OPTION   = 'spa_sent_log';
	public const MAX_ROWS = 100;

	/**
	 * Prepend an entry and cap the log at MAX_ROWS.
	 *
	 * @param array $entry Log entry (see shape above).
	 *
	 * @return void
	 */
	public static function write( array $entry ): void {
		$log   = self::get_all();
		$entry = array_merge(
			array(
				'id'          => 0,
				'type'        => 'result',
				'label'       => '',
				'channel'     => '',
				'platform'    => 'discord',
				'sent_at'     => time(),
				'status'      => 'sent',
				'webhook_url' => '',
				'payload'     => array(),
			),
			$entry
		);

		array_unshift( $log, $entry );

		if ( count( $log ) > self::MAX_ROWS ) {
			$log = array_slice( $log, 0, self::MAX_ROWS );
		}

		update_option( self::OPTION, $log, false );
	}

	/**
	 * Return all log entries, newest first.
	 *
	 * @return array[]
	 */
	public static function get_all(): array {
		$raw = get_option( self::OPTION, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Update fields on an existing log entry by its 0-based index.
	 *
	 * @param int   $index Zero-based position in the log array.
	 * @param array $patch Key-value pairs to merge into the entry.
	 *
	 * @return bool True on success, false if index is out of bounds.
	 */
	public static function update_entry( int $index, array $patch ): bool {
		$log = self::get_all();

		if ( ! isset( $log[ $index ] ) ) {
			return false;
		}

		$log[ $index ] = array_merge( $log[ $index ], $patch );
		update_option( self::OPTION, $log, false );

		return true;
	}

	/**
	 * Count entries matching optional filters.
	 *
	 * @param array $filters Associative array of field => value to filter by.
	 *
	 * @return int
	 */
	public static function count( array $filters = array() ): int {
		return count( self::filter( self::get_all(), $filters ) );
	}

	/**
	 * Return a page of entries.
	 *
	 * @param int    $per_page Entries per page.
	 * @param int    $page     1-based page number.
	 * @param array  $filters  Associative array of field => value.
	 * @param string $search   Optional search string (matches label or channel).
	 *
	 * @return array[]
	 */
	public static function get_page( int $per_page, int $page, array $filters = array(), string $search = '' ): array {
		$all = self::get_all();

		if ( $filters ) {
			$all = self::filter( $all, $filters );
		}

		if ( '' !== $search ) {
			$s   = strtolower( $search );
			$all = array_values(
				array_filter(
					$all,
					static function ( array $entry ) use ( $s ): bool {
						return false !== strpos( strtolower( $entry['label'] ?? '' ), $s )
							|| false !== strpos( strtolower( $entry['channel'] ?? '' ), $s );
					}
				)
			);
		}

		$offset = ( max( 1, $page ) - 1 ) * $per_page;
		return array_slice( $all, $offset, $per_page );
	}

	/**
	 * Filter entries by exact field matches.
	 *
	 * @param array[] $entries All log entries.
	 * @param array   $filters Field => value pairs.
	 *
	 * @return array[]
	 */
	private static function filter( array $entries, array $filters ): array {
		return array_values(
			array_filter(
				$entries,
				static function ( array $entry ) use ( $filters ): bool {
					foreach ( $filters as $key => $value ) {
						if ( ( $entry[ $key ] ?? null ) !== $value ) {
							return false;
						}
					}
					return true;
				}
			)
		);
	}
}
