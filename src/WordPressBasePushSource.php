<?php

declare(strict_types=1);

namespace BbApp\PushService\WordPressBase;

use BbApp\PushService\{
	PushSource,
	PushQueueRepository,
	PushRepositoryTables,
	PushSubscriptionRepository
};

use BbApp\ContentSource\ContentSourceAbstract;
use UnexpectedValueException;
use WP_REST_Request, WP_Post, WP_Comment;

/**
 * Base WordPress implementation of push notification source handling.
 */
abstract class WordPressBasePushSource extends PushSource
{
	protected $tables;

	/**
	 * Initializes the repository with WordPress database tables.
	 */
	public function __construct(
		PushQueueRepository $push_queue,
		PushSubscriptionRepository $push_subscription,
		ContentSourceAbstract $content_source
	) {
		parent::__construct($push_queue, $push_subscription, $content_source);

		global $wpdb;

		$this->tables = new PushRepositoryTables($wpdb->prefix);
	}

	/**
	 * Strips HTML tags from message content for push notifications.
	 */
	public function get_message_content(string $content): string {
		return wp_strip_all_tags($content, true);
	}

	/**
	 * Registers a REST API field indicating if content has push subscriptions.
	 */
	public function register_has_push_subscription_field(string $content_type): void {
		$schema = [
			'description' => __('Whether this item has a push-service subscription or not', 'bb-app'),
			'type' => 'boolean',
			'context' => ['view', 'embed']
		];

		$object_type = $this->content_source->get_entity_types($content_type);

		$get_id = function (
			$item
		) use
		(
			$object_type
		) {
			return (int) ($item['id'] ?? 0);
		};

		register_rest_field($object_type, 'has_push_subscription', compact('schema') + [
				'get_callback' => function (
					$item,
					$field_name,
					WP_REST_Request $request
				) use
				(
					$object_type,
					$get_id
				) {
					$query = $request->get_query_params();
					$id = $get_id($item);

					if ($id <= 0) {
						return false;
					}

					global $wpdb;

					$current_user_id = get_current_user_id();

					if ($current_user_id > 0) {
						$count = (int) $wpdb->get_var($wpdb->prepare(
							"SELECT COUNT(*) FROM {$this->tables->subscriptions}
							WHERE user_id = %d AND object_type = %s AND object_id = %d",
							$current_user_id,
							$object_type,
							$id
						));

						if ($count > 0) {
							return true;
						}
					}

					$guest_id = (string) ($query['guest_id'] ?? null);

					if (!empty($guest_id)) {
						$count = (int) $wpdb->get_var($wpdb->prepare(
							"SELECT COUNT(*) FROM {$this->tables->subscriptions}
							WHERE guest_id = %s AND object_type = %s AND object_id = %d",
							$guest_id,
							$object_type,
							$id
						));

						if ($count > 0) {
							return true;
						}
					}

					return false;
				}
			]);
	}

	/**
	 * Registers REST API fields for push subscription status.
	 */
	public function register(): void {
		add_action('rest_request_before_callbacks', function (
			$server,
			$handler,
			WP_REST_Request $request
		) {
			$this->register_has_push_subscription_field('section');
			$this->register_has_push_subscription_field('post');
			$this->register_has_push_subscription_field('comment');
		}, 10, 3);
	}

	/**
	 * Validates if content is eligible for push notifications.
	 */
	protected function is_valid_content_for_notification($content): bool {
		if ($content instanceof WP_Post) {
			if ($content->post_type !== $this->content_source->get_entity_types('post')) {
				return false;
			}

			if ($content->post_status !== 'publish') {
				return false;
			}

			return true;
		}

		if ($content instanceof WP_Comment) {
			if ($content->comment_approved !== '1') {
				return false;
			}

			return true;
		}

		return false;
	}

	/**
	 * Retrieves the current user ID.
	 */
	protected function get_user_id(): int {
		return get_current_user_id();
	}

	/**
	 * Retrieves the current guest ID from session.
	 */
	protected function get_guest_id(): ?string {
		return bb_app_current_guest_id();
	}

	/**
	 * Extracts the object ID from WordPress post or comment.
	 */
	protected function get_object_id($content): int {
		if ($content instanceof WP_Post) {
			return $content->ID;
		}

		if ($content instanceof WP_Comment) {
			return (int) $content->comment_ID;
		}

		return 0;
	}

	/**
	 * Prepares the push notification envelope with title, message, and URL.
	 */
	public function prepare_message_envelope(
		string $content_type,
		array $data,
		bool $subtitles
	): array {
		$subtitle = null;
		$imageUrl = null;

		switch ($content_type) {
			case 'post':
				$message = sprintf('%s submitted a new post "%s"', $data['username'], $data['title']);

				if (!empty($data['section__title'])) {
					if ($subtitles) {
						$title = __('New post', 'flavor');
						$subtitle = $data['section__title'];
					} else {
						$title = sprintf(__('New post in "%s"', 'flavor'), $data['section__title']);
					}
				} else {
					$title = __('New post', 'flavor');
				}

				$url = "/posts/{$data['id']}";
				break;
			case 'comment':
				$message = sprintf('%s submitted a new comment', $data['username']);

				if (!empty($data['post__title'])) {
					if ($subtitles) {
						$title = __('New comment', 'flavor');
						$subtitle = $data['post__title'];
					} else {
						$title = sprintf(__('New comment on "%s"', 'flavor'), $data['post__title']);
					}
				} else {
					$title = __('New comment', 'flavor');
				}

				$url = "/comments/{$data['id']}";
				break;
			default:
				throw new UnexpectedValueException();
		}

		return compact('title', 'subtitle', 'message', 'url', 'imageUrl');
	}
}
