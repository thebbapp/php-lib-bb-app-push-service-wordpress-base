<?php

declare(strict_types=1);

namespace BbApp\PushService\WordPressBase;

use BbApp\PushService\{PushQueueRepository, PushRepositoryTables};
use BbApp\PushService\Error\{
    PushQueueEnqueueError,
    PushQueueMarkProcessingError,
    PushQueueDeleteError
};
use BbApp\Result\{Result, Success, Failure};

/**
 * WordPress-specific implementation of push queue repository using wpdb.
 */
class WordPressBasePushQueueRepository extends PushQueueRepository
{
	private $tables;

	/**
	 * Initializes the repository with WordPress database tables.
	 */
	public function __construct()
	{
		global $wpdb;
        $this->tables = new PushRepositoryTables($wpdb->prefix);
	}

	/**
	 * Adds a notification to the queue for processing.
	 *
	 * @return Result<void,\Throwable>
	 */
	public function enqueue(array $notification_data): Result
	{
		global $wpdb;

		$data = [
			'notification_data' => wp_json_encode($notification_data),
			'created_at' => gmdate('Y-m-d H:i:s'),
			'status' => 'pending'
		];

		$result = $wpdb->insert(
			$this->tables->queue,
			$data,
			['%s', '%s', '%s']
		);

		if ($result === false) {
			return new Failure(new PushQueueEnqueueError());
		}

		return new Success();
	}

	/**
	 * Retrieves pending notifications from the queue up to specified limit.
	 */
	public function get_pending_notifications(int $limit = 100): array
	{
		global $wpdb;

		$results = $wpdb->get_results($wpdb->prepare(
			"SELECT id, notification_data, created_at
			 FROM {$this->tables->queue}
			 WHERE status = 'pending'
			 ORDER BY created_at ASC
			 LIMIT %d",
			$limit
		));

		if (empty($results)) {
			return [];
		}

		$notifications = [];

		foreach ($results as $row) {
			$data = json_decode($row->notification_data, true);

			if (is_array($data)) {
				$data['queue_id'] = (int) $row->id;
				$data['queued_at'] = $row->created_at;
				$notifications[] = $data;
			}
		}

		return $notifications;
	}

	/**
	 * Marks a queued notification as currently being processed.
	 *
	 * @return Result<void,\Throwable>
	 */
	public function mark_as_processing(int $queue_id): Result
	{
		global $wpdb;

		$result = $wpdb->update(
			$this->tables->queue,
			['status' => 'processing'],
			['id' => $queue_id],
			['%s'],
			['%d']
		);

		if ($result === false) {
			return new Failure(new PushQueueMarkProcessingError());
		}

		return new Success();
	}

	/**
	 * Deletes a notification from the queue by ID.
	 *
	 * @return Result<void,\Throwable>
	 */
	public function delete(int $queue_id): Result
	{
		global $wpdb;

		$result = $wpdb->delete(
			$this->tables->queue,
			['id' => $queue_id],
			['%d']
		);

		if ($result === false) {
			return new Failure(new PushQueueDeleteError());
		}

		return new Success();
	}

	/**
	 * Removes notifications stuck in processing status older than specified minutes.
	 */
	public function cleanup_stale_processing(int $minutes = 5): int
	{
		global $wpdb;

		$result = $wpdb->query($wpdb->prepare(
			"DELETE FROM {$this->tables->queue}
			 WHERE status = 'processing'
			 AND created_at < DATE_SUB(NOW(), INTERVAL %d MINUTE)",
			$minutes
		));

		return (int) $result;
	}
}
