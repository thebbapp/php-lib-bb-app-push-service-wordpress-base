<?php

declare(strict_types=1);

namespace BbApp\PushService\WordPressBase;

use BbApp\PushService\{
	PushDatabaseSchema,
	PushDatabaseSchemaTables
};

/**
 * WordPress-specific implementation of push notification database schema.
 */
class WordPressBasePushDatabaseSchema extends PushDatabaseSchema
{
	/**
	 * Initializes the database schema with WordPress database prefix and charset.
	 */
	public function __construct()
	{
		global $wpdb;

		parent::__construct(
			new PushDatabaseSchemaTables($wpdb->prefix),
			$wpdb->get_charset_collate()
		);
	}

	/**
	 * Creates database tables for push notifications using WordPress dbDelta.
	 */
	public function install(): void
	{
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta($this->create_table_push_tokens());
		dbDelta($this->create_table_push_subscriptions());
		dbDelta($this->create_table_push_queue());
	}

	/**
	 * Drops all push notification database tables.
	 */
	public function uninstall(): void
	{
		global $wpdb;

		$table_list = implode(', ', (array) $this->tables);
		$wpdb->query("DROP TABLE IF EXISTS {$table_list}");
	}
}
