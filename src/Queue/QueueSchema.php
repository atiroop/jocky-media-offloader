<?php
/**
 * Installs the durable offload queue tables.
 *
 * @package Jocky\MediaOffloader\Queue
 */

declare(strict_types=1);

namespace Jocky\MediaOffloader\Queue;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta and queue state require direct database access; table names use the trusted WordPress prefix.

/** Versioned schema for jobs and per-object state. */
final class QueueSchema {
	public const VERSION = 1;
	public const OPTION  = 'jmo_queue_schema_version';

	/** Install or upgrade the schema when the plugin is loaded. */
	public static function maybe_install(): void {
		if ( ! isset( $GLOBALS['wpdb'] ) || ( (int) get_option( self::OPTION, 0 ) >= self::VERSION && self::ready() ) ) {
			return;
		}
		self::install();
	}

	/** Create both tables using WordPress's schema migration helper. */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate     = $wpdb->get_charset_collate();
		$jobs        = self::jobs_table();
		$objects     = self::objects_table();
		$jobs_sql    = "CREATE TABLE {$jobs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL,
			job_key char(64) NOT NULL,
			manifest_version smallint(5) unsigned NOT NULL DEFAULT 1,
			manifest_hash char(64) NOT NULL DEFAULT '',
			manifest_json longtext NULL,
			status varchar(24) NOT NULL DEFAULT 'pending',
			phase varchar(16) NOT NULL DEFAULT 'upload',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			available_at datetime NOT NULL,
			lease_token char(64) NULL,
			lease_expires_at datetime NULL,
			uploaded_count int(10) unsigned NOT NULL DEFAULT 0,
			verified_count int(10) unsigned NOT NULL DEFAULT 0,
			error_code varchar(64) NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_key (job_key),
			KEY status_available (status,available_at),
			KEY attachment_status (attachment_id,status)
		) {$collate} ENGINE=InnoDB;";
		$objects_sql = "CREATE TABLE {$objects} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL,
			object_index smallint(5) unsigned NOT NULL,
			object_key_hash char(64) NOT NULL,
			object_key text NOT NULL,
			relative_path varchar(512) NOT NULL,
			size_bytes bigint(20) unsigned NOT NULL,
			mime_type varchar(128) NOT NULL,
			sha256 char(64) NOT NULL,
			file_identity varchar(160) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			multipart_upload_id varchar(255) NULL,
			multipart_parts longtext NULL,
			remote_etag varchar(255) NULL,
			error_code varchar(64) NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_object (job_id,object_index),
			UNIQUE KEY object_key_hash (object_key_hash),
			KEY job_status (job_id,status)
		) {$collate} ENGINE=InnoDB;";
		dbDelta( $jobs_sql );
		dbDelta( $objects_sql );
		if ( self::ready() ) {
			update_option( self::OPTION, self::VERSION, false );
		}
	}

	/** Check that both durable queue tables exist. */
	private static function ready(): bool {
		global $wpdb;
		$jobs    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::jobs_table() ) );
		$objects = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::objects_table() ) );
		return self::jobs_table() === $jobs && self::objects_table() === $objects;
	}

	/** Return the trusted job table name. */
	public static function jobs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'jmo_offload_jobs';
	}

	/** Return the trusted object table name. */
	public static function objects_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'jmo_offload_objects';
	}
}
