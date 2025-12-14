<?php

declare(strict_types=1);

namespace BbApp\PushService\WordPressBase;

use BbApp\PushService\{PushTokenRepository, PushRepositoryTables};
use BbApp\PushService\Error\{
    PushDatabaseInsertError,
    PushTokenServiceDeleteError,
    PushTokenServiceMigrateGuestTokenToUserError
};
use BbApp\Result\{Result, Success, Failure};
use Exception;

/**
 * WordPress-specific implementation of push token repository using wpdb.
 */
class WordPressBasePushTokenTokenRepository extends PushTokenRepository
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
	 * Retrieves push tokens for specified subscription targets excluding author.
	 */
	public function get_tokens_for_targets(
		array $targets,
		int $user_id = 0,
		string $guest_id = ''
	): array {
		global $wpdb;

		if (empty($targets)) {
			return [];
		}

		$placeholders = [];
		$params = [];

		foreach ($targets as $tuple) {
			list($type, $id) = $tuple;
			$placeholders[] = '(s.object_type = %s AND s.object_id = %d)';
			$params[] = $type;
			$params[] = (int) $id;
		}

		$where = implode(' OR ', $placeholders);

		$author_exclusion = '';

		if ($user_id > 0) {
			$author_exclusion = ' AND (t.user_id IS NULL OR t.user_id != %d)';
			$params[] = $user_id;
		}

		$guest_exclusion = '';

		if (!empty($guest_id)) {
			$guest_exclusion = ' AND (t.guest_id IS NULL OR t.guest_id != %s)';
			$params[] = $guest_id;
		}

		$query = $wpdb->prepare(
			"SELECT DISTINCT t.id, t.service, t.token, t.user_id
			 FROM {$this->tables->tokens} t
			 INNER JOIN {$this->tables->subscriptions} s ON (
				(s.user_id IS NOT NULL AND s.user_id = t.user_id)
				OR (s.guest_id IS NOT NULL AND s.guest_id = t.guest_id)
			 )
			 WHERE ({$where}){$author_exclusion}{$guest_exclusion}",
			...$params
		);

		return (array) $wpdb->get_results($query, ARRAY_A);
	}

	/**
	 * Updates the last active timestamp for specified token IDs.
	 */
	public function update_last_active_for_token_ids(array $ids): void
	{
		if (empty($ids)) {
			return;
		}

		global $wpdb;

		$in_placeholders = implode(', ', array_fill(0, count($ids), '%d'));

		$query = $wpdb->prepare(
			"UPDATE {$this->tables->tokens} SET last_active_date_gmt = %s
				WHERE id IN ({$in_placeholders})",
			gmdate('Y-m-d H:i:s'),
			...$ids
		);

		$wpdb->query($query);
	}

	/**
	 * Deletes push tokens by their database IDs.
	 */
	public function delete_tokens_by_ids(array $ids): void
	{
		if (empty($ids)) {
			return;
		}

		global $wpdb;

		foreach ($ids as $id) {
			$id = (int) $id;

			if ($id > 0) {
				$wpdb->delete(
					$this->tables->tokens,
					compact('id'),
					['%d']
				);
			}
		}
	}

	/**
	 * Retrieves an existing token record by service and token value.
	 */
	public function get_existing_token(
		string $service,
		string $token
	): ?object {
		global $wpdb;

		$result = $wpdb->get_row($wpdb->prepare(
			"SELECT id, uuid, user_id, guest_id FROM {$this->tables->tokens}
				WHERE service = %s AND token = %s",
			$service,
			$token
		));

		return $result !== null ? $result : null;
	}

	/**
	 * Updates token last active time and binds to user or guest.
	 */
	public function update_last_active_and_bind_user(
		int $id,
		?int $user_id,
		?string $guest_id,
		string $last_active_date_gmt
	): void {
		global $wpdb;

		$data = compact('last_active_date_gmt');
		$formats = ['%s'];

		if ($user_id !== null && $user_id > 0) {
			$data['user_id'] = $user_id;
			$formats[] = '%d';
		}

		if (!empty($guest_id)) {
			$data['guest_id'] = $guest_id;
			$formats[] = '%s';
		}

		$wpdb->update(
			$this->tables->tokens,
			$data,
			compact('id'),
			$formats,
			['%d']
		);
	}

	/**
	 * Counts total push tokens registered for a specific user.
	 */
	public function count_tokens_for_user(int $user_id): int
	{
		global $wpdb;

		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->tables->tokens}
				WHERE user_id = %d",
			$user_id
		));
	}

	/**
	 * Deletes the oldest token for a user to enforce token limits.
	 */
	public function delete_oldest_token_for_user(int $user_id): void
	{
		global $wpdb;

		$id = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT id FROM {$this->tables->tokens}
				WHERE user_id = %d ORDER BY id ASC LIMIT 1",
			$user_id
		));

		if ($id > 0) {
			$wpdb->delete($this->tables->tokens, compact('id'), ['%d']);
		}
	}

	/**
	 * Inserts a new push token into the database.
	 *
	 * @return Result<void,\Throwable>
	 */
	public function insert_token(
		string $uuid,
		int $user_id,
		?string $guest_id,
		string $service,
		string $token,
		string $last_active_date_gmt
	): Result {
		global $wpdb;

		$data = compact(
			'uuid',
			'service',
			'token',
			'last_active_date_gmt'
		);

		$formats = ['%s', '%s', '%s', '%s'];

		if ($user_id > 0) {
			$data['user_id'] = $user_id;
			$formats[] = '%d';
		}

		if (!empty($guest_id)) {
			$data['guest_id'] = $guest_id;
			$formats[] = '%s';
		}

		$inserted = $wpdb->insert($this->tables->tokens, $data, $formats);

		if ($inserted === false) {
			return new Failure(new PushDatabaseInsertError());
		}

		return new Success();
	}

	/**
	 * Migrates all guest tokens to a user account upon login.
	 *
	 * @return Result<void,\Throwable>
	 */
	public function migrate_guest_tokens_to_user(
		int $user_id,
		string $guest_id
	): Result {
		global $wpdb;

		$wpdb->query('START TRANSACTION');

		try {
			$wpdb->query($wpdb->prepare(
				"UPDATE IGNORE {$this->tables->tokens} SET user_id = %d, guest_id = NULL
				    WHERE guest_id = %s",
				$user_id,
				$guest_id
			));

			$wpdb->query('COMMIT');
			return new Success();
		} catch (Exception $e) {
			$wpdb->query('ROLLBACK');
			return new Failure(new PushTokenServiceMigrateGuestTokenToUserError());
		}
	}

	/**
	 * Deletes a push token by UUID and user ID.
	 *
	 * @return Result<void,\Throwable>
	 */
	public function delete_token_by_uuid_user_id(
		string $uuid,
		int $user_id
	): Result {
		global $wpdb;

		$deleted = $wpdb->delete(
			$this->tables->tokens,
			compact('uuid', 'user_id'),
			['%s', '%d']
		);

		if ($deleted === false) {
			return new Failure(new PushTokenServiceDeleteError());
		}

		return new Success();
	}

	/**
	 * Deletes a push token by UUID and guest ID.
	 *
	 * @return Result<void,\Throwable>
	 */
	public function delete_token_by_uuid_guest_id(string $uuid, string $guest_id): Result
	{
		global $wpdb;

		$deleted = $wpdb->delete(
			$this->tables->tokens,
			compact('uuid', 'guest_id'),
			['%s', '%s']
		);

		if ($deleted === false) {
			return new Failure(new PushTokenServiceDeleteError());
		}

		return new Success();
	}

	/**
	 * Deletes all tokens associated with a guest ID.
	 */
	public function delete_tokens_by_guest_id(string $guest_id): int
	{
		global $wpdb;

		$deleted = $wpdb->delete(
			$this->tables->tokens,
			compact('guest_id'),
			['%s']
		);

		return $deleted !== false ? $deleted : 0;
	}
}
