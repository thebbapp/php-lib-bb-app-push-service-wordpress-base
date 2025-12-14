<?php

declare(strict_types=1);

namespace BbApp\PushService\WordPressBase;

use BbApp\PushService\PushQueueService;

/**
 * WordPress-specific implementation of push queue service using WP-Cron.
 */
class WordPressBasePushQueueService extends PushQueueService
{
	const CRON_HOOK = 'bb_app_process_push_notification_queue';
	const CRON_INTERVAL = 'bb_app_every_minute';

	/**
	 * Adds custom cron interval for processing queue every minute.
	 */
	public function cron_schedules($schedules): array
	{
		$schedules[static::CRON_INTERVAL] = [
			'interval' => 60,
			'display'  => __('Every Minute', 'bb-app')
		];

		return $schedules;
	}

	/**
	 * Initializes WP-Cron scheduled event for processing queue.
	 */
	public function init(): void
	{
		add_filter('cron_schedules', [$this, 'cron_schedules']);
		add_action(static::CRON_HOOK, [$this, 'process_queue']);

		if (!wp_next_scheduled(static::CRON_HOOK)) {
			wp_schedule_event(time(), static::CRON_INTERVAL, static::CRON_HOOK);
		}
	}

	/**
	 * Removes scheduled cron event when deactivating.
	 */
	public function dealloc(): void
	{
		$timestamp = wp_next_scheduled(static::CRON_HOOK);

		if ($timestamp) {
			wp_unschedule_event($timestamp, static::CRON_HOOK);
		}
	}
}
