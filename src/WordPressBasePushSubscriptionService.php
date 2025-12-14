<?php

declare(strict_types=1);

namespace BbApp\PushService\WordPressBase;

use BbApp\PushService\{
	PushSubscriptionService,
	PushSubscriptionValidateOptions,
	PushSubscriptionValidateResult,
	Error\PushSubscriptionValidationError
};

use BbApp\Result\{Result, Success, Failure};
use WP_REST_Request, WP_REST_Response, WP_Error;
use Closure, Throwable;

/**
 * WordPress-specific implementation of push subscription service with REST API.
 */
class WordPressBasePushSubscriptionService extends PushSubscriptionService
{
	protected $route_namespace = 'bb-app/v1';
	protected $subscriptions_route = '/push-subscriptions';

	/**
	 * Migrate guest push tokens to user after password-based authentication.
	 */
	public function determine_current_user($user_id)
	{
		if (
			!empty($user_id) &&
			is_int($user_id) &&
			!empty($_GET['guest_id'])
		) {
			$this->migrate_guest_subscriptions_to_user($user_id, $_GET['guest_id']);
		}

		return $user_id;
	}

	/**
	 * Validates subscription request by checking content existence and permissions.
	 *
	 * @return Result<PushSubscriptionValidateResult,\Throwable>
	 */
	public function validate_subscription(
		PushSubscriptionValidateOptions $options
	): Result {
		if ($options->content_id <= 0) {
			return new Failure(new PushSubscriptionValidationError(
				'invalid_params',
				__('Invalid parameters', 'bb-app'),
				400
			));
		}

		try {
			$object_type = $this->content_source->get_entity_types($options->content_type);
		} catch (Throwable $error) {
			return new Failure(new PushSubscriptionValidationError(
				'unknown_content_type',
				__('Unknown content type', 'bb-app'),
				400
			));
		}

		$entity = $this->content_source->get_content($options->content_type, $options->content_id);

		if (!$entity) {
			return new Failure(new PushSubscriptionValidationError(
				'does_not_exist',
				__('Content does not exist', 'bb-app'),
				404
			));
		}

		$entity_content_type = $this->content_source->get_content_type($entity);

		if ($entity_content_type !== $options->content_type) {
			return new Failure(new PushSubscriptionValidationError(
				'invalid_content_type',
				__('Content type does not match', 'bb-app'),
				400
			));
		}

		if ($this->content_source->current_user_can('view', $options->content_type, $options->content_id) === false) {
			return new Failure(new PushSubscriptionValidationError(
				'no_permission',
				__('No permission to view content', 'bb-app'),
				403
			));
		}

		return new Success(new PushSubscriptionValidateResult(
			$object_type,
			$options->content_id
		));
	}

	/**
	 * Wraps callback with validation logic for REST API responses.
	 */
	protected function validate_response_callback(
		callable $callback
	): Closure {
		return function(WP_REST_Request $request) use ($callback) {
			$result = $this->validate_subscription(new PushSubscriptionValidateOptions(
				(string) $request->get_param('content_type'),
				(int) $request->get_param('content_id')
			));

			if ($result instanceof Success) {
				return call_user_func($callback, $result->unwrap(), $request);
			}

			$error = $result->unwrap();

			if ($error instanceof PushSubscriptionValidationError) {
				return new WP_Error(
					$error->errorCode,
					$error->getMessage(),
					['status' => $error->status]
				);
			}

			return new WP_Error(
				$this->error_to_code($error),
				__('Unknown error', 'bb-app'),
				['status' => 500]
			);
		};
	}

	/**
	 * Registers REST API routes for creating and deleting push subscriptions.
	 */
	public function register(): void
	{
		$args = [
			'content_type' => [
				'enum' => array_keys(
					$this->content_source->get_entity_types()
				),

				'required' => true,
				'type' => 'string'
			],

			'content_id' => [
				'required' => true,
				'type' => 'integer',
				'minimum' => 1
			],

			'guest_id' => [
				'required' => false,
				'type' => 'string',
				'format' => 'uuid'
			]
		];

		register_rest_route($this->route_namespace, $this->subscriptions_route, compact('args') + [
			'callback' => $this->validate_response_callback(function (PushSubscriptionValidateResult $result, WP_REST_Request $request) {
				if (is_user_logged_in()) {
					$subscription = $this->create_user_subscription(
						get_current_user_id(),
						$result->object_type,
						$result->object_id
					);
				} else {
					$guest_id = $request->get_param('guest_id');

					if (empty($guest_id)) {
						return new WP_Error(
							'missing_guest_id',
							__('Guest ID is required', 'bb-app'),
							['status' => 400]
						);
					}

					$subscription = $this->create_guest_subscription(
						$guest_id,
						$result->object_type,
						$result->object_id
					);
				}

				if ($subscription instanceof Failure) {
					$error = $subscription->unwrap();
					return new WP_Error(
						$this->error_to_code($error),
						__('Could not create subscription', 'bb-app'),
						['status' => 500]
					);
				}

				return new WP_REST_Response(['created' => true], 201);
			}),

			'methods' => ['POST'],
			'permission_callback' => '__return_true'
		]);

		register_rest_route($this->route_namespace, $this->subscriptions_route, compact('args') + [
			'callback' => $this->validate_response_callback(function (PushSubscriptionValidateResult $result, WP_REST_Request $request) {
				if (is_user_logged_in()) {
					$subscription = $this->delete_user_subscription(
						get_current_user_id(),
						$result->object_type,
						$result->object_id
					);
				} else {
					$guest_id = (string) $request->get_param('guest_id');

					if (empty($guest_id)) {
						return new WP_Error(
							'missing_guest_id',
							__('Guest ID is required', 'bb-app'),
							['status' => 400]
						);
					}

					$subscription = $this->delete_guest_subscription(
						$guest_id,
						$result->object_type,
						$result->object_id
					);
				}

				if ($subscription instanceof Failure) {
					$error = $subscription->unwrap();

					return new WP_Error(
						$this->error_to_code($error),
						__('Could not delete subscription', 'bb-app'),
						['status' => $this->error_to_status($error)]
					);
				}

				return new WP_REST_Response(['deleted' => true]);
			}),

			'methods' => ['DELETE'],
			'permission_callback' => '__return_true'
		]);
	}

	/**
	 * Initializes the subscription service.
	 */
	public function init(): void {
		add_filter('determine_current_user', [$this, 'determine_current_user'], 30);
	}

	/**
	 * Converts exception class name to snake_case error code.
	 */
	protected function error_to_code(Throwable $error): string
	{
		$class_name = (new \ReflectionClass($error))->getShortName();
		return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $class_name));
	}

	/**
	 * Converts exception to appropriate HTTP status code.
	 */
	protected function error_to_status(Throwable $error): int
	{
		if ($error instanceof PushSubscriptionValidationError) {
			return $error->status;
		}

		$class_name = (new \ReflectionClass($error))->getShortName();

		if (strpos($class_name, 'NotFound') !== false) {
			return 404;
		}

		return 500;
	}
}
