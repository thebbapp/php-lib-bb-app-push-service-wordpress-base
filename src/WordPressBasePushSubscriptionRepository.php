<?php

declare(strict_types=1);

namespace BbApp\PushService\WordPressBase;

use BbApp\PushService\{PushRepositoryTables, PushSubscriptionRepository};
use BbApp\PushService\Error\{
    PushSubscriptionDatabaseInsertError,
    PushSubscriptionDatabaseDeleteError,
    PushSubscriptionMigrationError
};
use BbApp\Result\{Result, Success, Failure};
use Exception;

/**
 * WordPress-specific implementation of push subscription repository using wpdb.
 */
class WordPressBasePushSubscriptionRepository extends PushSubscriptionRepository
{
	protected $tables;

	/**
	 * Initializes the repository with WordPress database tables.
	 */
	public function __construct()
	{
        global $wpdb;
        $this->tables = new PushRepositoryTables($wpdb->prefix);
	}

	/**
	 * Counts unique subscribers for specified content targets.
	 */
	public function count_subscribers_for_targets(array $targets): int
	{
		global $wpdb;

		if (empty($targets)) {
			return 0;
		}

		$placeholders = [];
		$params = [];

		foreach ($targets as $tuple) {
			list($type, $id) = $tuple;

			$placeholders[] = '(object_type = %s AND object_id = %d)';
			$params[] = $type;
			$params[] = (int) $id;
		}

		$where = implode(' OR ', $placeholders);

		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(DISTINCT t.id)
			 FROM {$this->tables->subscriptions} s
			 INNER JOIN {$this->tables->tokens} t ON (
				(s.user_id IS NOT NULL AND s.user_id = t.user_id)
				OR (s.guest_id IS NOT NULL AND s.guest_id = t.guest_id)
			 )
			 WHERE ({$where})",
			...$params
		));
	}

	/**
	 * Checks if a user has an active subscription to specific content.
	 */
	public function user_has_subscription(
		int $user_id,
		string $object_type,
		int $object_id
	): bool {
		global $wpdb;

		$count = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->tables->subscriptions}
                WHERE user_id = %d AND object_type = %s AND object_id = %d",
			$user_id,
			$object_type,
			$object_id
		));
		return $count > 0;
	}

	/**
	 * Creates a new push subscription for a user to specific content.
	 *
	 * @return Result<void,\Throwable>
	 */
	public function create_user_subscription(
		int $user_id,
		string $object_type,
		int $object_id
	): Result {
		global $wpdb;

		$inserted = $wpdb->insert(
			$this->tables->subscriptions,
            compact('user_id', 'object_type', 'object_id'),
			['%d', '%s', '%d']
		);

		if ($inserted === false) {
			return new Failure(new PushSubscriptionDatabaseInsertError());
		}

		return new Success();
	}

	/**
	 * Checks if a guest has an active subscription to specific content.
	 */
	public function guest_has_subscription(
		string $guest_id,
		string $object_type,
		int $object_id
	): bool {
		global $wpdb;

		$count = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->tables->subscriptions}
				WHERE guest_id = %s AND object_type = %s AND object_id = %d",
			$guest_id,
			$object_type,
			$object_id
		));

		return $count > 0;
	}

	/**
	 * Creates a new push subscription for a guest to specific content.
	 *
	 * @return Result<void,\Throwable>
	 */
	public function create_guest_subscription(
		string $guest_id,
		string $object_type,
		int $object_id
	): Result {
		global $wpdb;

		$inserted = $wpdb->insert(
			$this->tables->subscriptions,
            compact('guest_id', 'object_type', 'object_id'),
			['%s', '%s', '%d']
		);

		if ($inserted === false) {
			return new Failure(new PushSubscriptionDatabaseInsertError());
		}

		return new Success();
	}

	/**
	 * Deletes a user's subscription to specific content.
	 *
	 * @return Result<void,\Throwable>
	 */
	public function delete_user_subscription(
		int $user_id,
		string $object_type,
		int $object_id
	): Result {
		global $wpdb;

		$deleted = $wpdb->delete(
			$this->tables->subscriptions,
            compact('user_id', 'object_type', 'object_id'),
			['%d', '%s', '%d']
		);

		if ($deleted === false) {
			return new Failure(new PushSubscriptionDatabaseDeleteError());
		}

		return new Success();
	}

	/**
	 * Deletes a guest's subscription to specific content.
	 *
	 * @return Result<void,\Throwable>
	 */
	public function delete_guest_subscription(
		string $guest_id,
		string $object_type,
		int $object_id
	): Result {
		global $wpdb;

		$deleted = $wpdb->delete(
			$this->tables->subscriptions,
            compact('guest_id', 'object_type', 'object_id'),
			['%s', '%s', '%d']
		);

		if ($deleted === false) {
			return new Failure(new PushSubscriptionDatabaseDeleteError());
		}

		return new Success();
	}

	/**
	 * Migrates all guest subscriptions to a user account upon login.
	 *
	 * @return Result<void,\Throwable>
	 */
	public function migrate_guest_subscriptions_to_user(
		int $user_id,
		string $guest_id
	): Result {
		global $wpdb;

		$wpdb->query('START TRANSACTION');

		try {
			$wpdb->query($wpdb->prepare(
				"UPDATE IGNORE {$this->tables->subscriptions} SET user_id = %d, guest_id = NULL
				    WHERE guest_id = %s",
				$user_id,
				$guest_id
			));

			$wpdb->query('COMMIT');
			return new Success();
		} catch (Exception $e) {
			$wpdb->query('ROLLBACK');
			return new Failure(new PushSubscriptionMigrationError());
		}
	}
}
