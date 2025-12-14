<?php

declare(strict_types=1);

namespace BbApp\PushService\WordPressBase;

use BbApp\PushService\PushTokenService;
use BbApp\PushService\PushTransportAbstract;
use BbApp\Result\Failure;

use WP_REST_Request, WP_REST_Response, WP_Error;
use Throwable;

/**
 * WordPress-specific implementation of push token service with REST API endpoints.
 */
class WordPressBasePushTokenService extends PushTokenService
{
	protected $route_namespace = 'bb-app/v1';
	protected $tokens_route = '/push-tokens';

	/**
	 * Migrate guest push tokens to user after password-based authentication.
	 */
	public function determine_current_user($user_id) {
		if (
			!empty($user_id) &&
			is_int($user_id) &&
			!empty($_GET['guest_id'])
		) {
			$this->migrate_guest_tokens_to_user($user_id, $_GET['guest_id']);
		}

		return $user_id;
	}

	/**
	 * Registers REST API routes for submitting and deleting push tokens.
	 */
	public function register(): void {
		register_rest_route($this->route_namespace, $this->tokens_route, [
			'callback' => function (
				WP_REST_Request $request
			) {
				$result = $this->submit_push_token(
					get_current_user_id(),
					$request->get_param('service'),
					$request->get_param('token'),
					$request->get_param('guest_id') ?: null,
					wp_generate_uuid4()
				);

				if ($result instanceof Failure) {
					$error = $result->unwrap();
					return new WP_Error(
						$this->error_to_code($error),
						__('An error occurred', 'bb-app'),
						['status' => 500]
					);
				}

				return new WP_REST_Response(['uuid' => $result->unwrap()], 201);
			},

			'args' => [
				'service' => [
					'required' => true,
					'type' => 'string',
					'enum' => PushTransportAbstract::get_ids()
				],

				'token' => [
					'required' => true,
					'type' => 'string',
					'minLength' => 32,
					'maxLength' => 255
				],

				'guest_id' => [
					'required' => false,
					'type' => 'string',
					'format' => 'uuid'
				]
			],

			'methods' => ['POST'],
			'permission_callback' => '__return_true'
		]);

		register_rest_route($this->route_namespace, "{$this->tokens_route}/(?P<uuid>[0-9A-Fa-f]{8}\-[0-9A-Fa-f]{4}\-4[0-9A-Fa-f]{3}\-[89ABab][0-9A-Fa-f]{3}\-[0-9A-Fa-f]{12})", [
			'callback' => function (
				WP_REST_Request $request
			) {
				if (is_user_logged_in()) {
					$uuid = $request->get_param('uuid');
					$current_user_id = get_current_user_id();
					$result = $this->delete_token_by_uuid_user_id($uuid, $current_user_id);
				} else {
					$guest_id = $request->get_param('guest_id');

					if (empty($guest_id)) {
						return new WP_Error(
							'missing_guest_id',
							__('Guest ID is required', 'bb-app'),
							['status' => 400]
						);
					}

					$uuid = $request->get_param('uuid');
					$result = $this->delete_token_by_uuid_guest_id($uuid, $guest_id);
				}

				if ($result instanceof Failure) {
					$error = $result->unwrap();

					return new WP_Error(
						$this->error_to_code($error),
						__('Could not delete token', 'bb-app'),
						['status' => 500]
					);
				}

				return new WP_REST_Response(['deleted' => true]);
			},

			'args' => [
				'guest_id' => [
					'required' => false,
					'type' => 'string',
					'format' => 'uuid'
				]
			],

			'methods' => ['DELETE'],
			'permission_callback' => '__return_true'
		]);
	}

	/**
	 * Initializes the token service.
	 */
	public function init(): void {
		add_filter('determine_current_user', [$this, 'determine_current_user'], 30);
	}

	/**
	 * Converts exception class name to snake_case error code.
	 */
	protected function error_to_code(Throwable $error): string {
		$class_name = (new \ReflectionClass($error))->getShortName();
		return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $class_name));
	}
}
