<?php

namespace PixelControl\Api;

use ManiaControl\Files\AsyncHttpRequest;
use ManiaControl\Logger;
use ManiaControl\ManiaControl;

class AsyncPixelControlApiClient implements PixelControlApiClientInterface {
	const DEFAULT_EVENT_PATH = '/plugin/events';

	/** @var ManiaControl $maniaControl */
	private $maniaControl;
	/** @var string $baseUrl */
	private $baseUrl;
	/** @var string $eventPath */
	private $eventPath;
	/** @var int $timeoutSeconds */
	private $timeoutSeconds = 5;
	/** @var int $maxRetryAttempts */
	private $maxRetryAttempts = 3;
	/** @var int $retryBackoffMs */
	private $retryBackoffMs = 250;
	/** @var int $lastFailureLogAt */
	private $lastFailureLogAt = 0;
	/** @var int $failureLogCooldownSeconds */
	private $failureLogCooldownSeconds = 30;
	/** @var string $authMode */
	private $authMode = 'none';
	/** @var string $authValue */
	private $authValue = '';
	/** @var string $authHeader */
	private $authHeader = 'X-Pixel-Control-Api-Key';

	/**
	 * @param ManiaControl $maniaControl
	 * @param string       $baseUrl
	 * @param string       $eventPath
	 */
	public function __construct(ManiaControl $maniaControl, $baseUrl, $eventPath = self::DEFAULT_EVENT_PATH) {
		$this->maniaControl = $maniaControl;
		$this->baseUrl = rtrim($baseUrl, '/');
		$this->eventPath = '/' . ltrim($eventPath, '/');
	}

	/**
	 * @see PixelControlApiClientInterface::sendEvent()
	 * Callback signature: `(bool $delivered, ?DeliveryError $deliveryError)`.
	 */
	public function sendEvent(EventEnvelope $envelope, $attempt = 1, ?callable $resultCallback = null) {
		$eventName = $envelope->getEventName();
		$eventId = $envelope->getEventId();
		$idempotencyKey = $envelope->getIdempotencyKey();
		$normalizedAttempt = max(1, (int) $attempt);

		$payload = json_encode(array(
			'envelope' => $envelope->toArray(),
			'transport' => array(
				'attempt' => $normalizedAttempt,
				'max_attempts' => $this->maxRetryAttempts,
				'retry_backoff_ms' => $this->retryBackoffMs,
				'auth_mode' => $this->authMode,
			),
		));

		if (!is_string($payload)) {
			$deliveryError = new DeliveryError('encoding_failed', 'json encode failed', false, 0);
			$this->logDeliveryFailure($eventName, $eventId, $idempotencyKey, $normalizedAttempt, $deliveryError);
			$this->invokeResultCallback($resultCallback, false, $deliveryError);
			return false;
		}

		try {
			$request = new AsyncHttpRequest($this->maniaControl, $this->buildEventsUrl());
			$headers = $this->buildRequestHeaders($envelope);
			if (!empty($headers)) {
				$request->setHeaders($headers);
			}

			$request->setContentType(AsyncHttpRequest::CONTENT_TYPE_JSON);
			$request->setTimeout($this->timeoutSeconds);
			$request->setContent($payload);
			$request->setCallable(function ($responseBody, $errorMessage) use ($normalizedAttempt, $eventName, $eventId, $idempotencyKey, $resultCallback) {
				if ($errorMessage) {
					$deliveryError = new DeliveryError('transport_error', (string) $errorMessage, true, 0);
					$this->logDeliveryFailure($eventName, $eventId, $idempotencyKey, $normalizedAttempt, $deliveryError);
					$this->invokeResultCallback($resultCallback, false, $deliveryError);
					return;
				}

				if ($responseBody === null) {
					$deliveryError = new DeliveryError('empty_response', 'empty response body', true, 0);
					$this->logDeliveryFailure($eventName, $eventId, $idempotencyKey, $normalizedAttempt, $deliveryError);
					$this->invokeResultCallback($resultCallback, false, $deliveryError);
					return;
				}

				$decodedBody = json_decode($responseBody, true);
				if (!is_array($decodedBody)) {
					$deliveryError = new DeliveryError('invalid_ack_payload', 'non-json acknowledgment payload', false, 0);
					$this->logDeliveryFailure($eventName, $eventId, $idempotencyKey, $normalizedAttempt, $deliveryError);
					$this->invokeResultCallback($resultCallback, false, $deliveryError);
					return;
				}

				$deliveryError = $this->resolveDeliveryErrorFromResponse($decodedBody);
				if ($deliveryError) {
					$this->logDeliveryFailure($eventName, $eventId, $idempotencyKey, $normalizedAttempt, $deliveryError);
					$this->invokeResultCallback($resultCallback, false, $deliveryError);
					return;
				}

				$this->invokeResultCallback($resultCallback, true, null);
			});
			$request->postData();
		} catch (\Throwable $throwable) {
			$reason = trim((string) $throwable->getMessage());
			if ($reason === '') {
				$reason = 'transport exception';
			}

			$deliveryError = new DeliveryError('transport_exception', $reason, true, 0);
			$this->logDeliveryFailure($eventName, $eventId, $idempotencyKey, $normalizedAttempt, $deliveryError);
			$this->invokeResultCallback($resultCallback, false, $deliveryError);
			return false;
		}

		return true;
	}

	/**
	 * @param array $decodedBody
	 * @return DeliveryError|null
	 */
	private function resolveDeliveryErrorFromResponse(array $decodedBody) {
		if (array_key_exists('error', $decodedBody)) {
			if (is_array($decodedBody['error'])) {
				return DeliveryError::fromArray($decodedBody['error'], 'server_error', 'server rejected envelope', false);
			}

			if (is_string($decodedBody['error']) && trim($decodedBody['error']) !== '') {
				return new DeliveryError('server_error', trim($decodedBody['error']), false, 0);
			}
		}

		$ackStatus = null;
		if (isset($decodedBody['ack']) && is_array($decodedBody['ack']) && isset($decodedBody['ack']['status'])) {
			$ackStatus = strtolower(trim((string) $decodedBody['ack']['status']));
		}

		if ($ackStatus === null && isset($decodedBody['status']) && is_string($decodedBody['status'])) {
			$ackStatus = strtolower(trim((string) $decodedBody['status']));
		}

		if ($ackStatus === null || $ackStatus === '') {
			return null;
		}

		switch ($ackStatus) {
			case 'accepted':
			case 'ok':
			case 'success':
				return null;
			case 'rejected':
			case 'error':
			case 'failed':
				if (isset($decodedBody['ack']) && is_array($decodedBody['ack'])) {
					return DeliveryError::fromArray($decodedBody['ack'], 'ack_rejected', 'server rejected envelope', false);
				}

				return new DeliveryError('ack_rejected', 'server rejected envelope', false, 0);
			default:
				return null;
		}
	}

	/**
	 * @param callable|null $resultCallback
	 * @param bool              $delivered
	 * @param DeliveryError|null $deliveryError
	 */
	private function invokeResultCallback($resultCallback, $delivered, ?DeliveryError $deliveryError = null) {
		if (!is_callable($resultCallback)) {
			return;
		}

		try {
			call_user_func($resultCallback, (bool) $delivered, $deliveryError);
		} catch (\Throwable $throwable) {
			Logger::logWarning('[PixelControl] Delivery callback execution failed: ' . trim((string) $throwable->getMessage()));
		}
	}

	/**
	 * @param string $eventName
	 * @param string $eventId
	 * @param string $idempotencyKey
	 * @param int           $attempt
	 * @param DeliveryError $deliveryError
	 */
	private function logDeliveryFailure($eventName, $eventId, $idempotencyKey, $attempt, DeliveryError $deliveryError) {
		$now = time();
		if (($now - $this->lastFailureLogAt) < $this->failureLogCooldownSeconds) {
			return;
		}

		$this->lastFailureLogAt = $now;
		Logger::logWarning(
			'[PixelControl] Event delivery failed: event=' . $eventName
			. ', event_id=' . $eventId
			. ', key=' . $idempotencyKey
			. ', attempt=' . $attempt
			. ', code=' . $deliveryError->getCode()
			. ', retryable=' . ($deliveryError->isRetryable() ? 'yes' : 'no')
			. ', reason=' . $deliveryError->getMessage()
		);
	}

	/**
	 * @return int
	 */
	public function getTimeoutSeconds() {
		return $this->timeoutSeconds;
	}

	/**
	 * @param int $timeoutSeconds
	 */
	public function setTimeoutSeconds($timeoutSeconds) {
		$this->timeoutSeconds = max(1, (int) $timeoutSeconds);
	}

	/**
	 * @return int
	 */
	public function getMaxRetryAttempts() {
		return $this->maxRetryAttempts;
	}

	/**
	 * @param int $maxRetryAttempts
	 */
	public function setMaxRetryAttempts($maxRetryAttempts) {
		$this->maxRetryAttempts = max(1, (int) $maxRetryAttempts);
	}

	/**
	 * @return int
	 */
	public function getRetryBackoffMs() {
		return $this->retryBackoffMs;
	}

	/**
	 * @param int $retryBackoffMs
	 */
	public function setRetryBackoffMs($retryBackoffMs) {
		$this->retryBackoffMs = max(0, (int) $retryBackoffMs);
	}

	/**
	 * @return string
	 */
	public function getAuthMode() {
		return $this->authMode;
	}

	/**
	 * @param string $authMode
	 */
	public function setAuthMode($authMode) {
		$normalized = strtolower(trim((string) $authMode));
		if ($normalized === 'bearer' || $normalized === 'api_key') {
			$this->authMode = $normalized;
			return;
		}

		$this->authMode = 'none';
	}

	/**
	 * @return string
	 */
	public function getAuthValue() {
		return $this->authValue;
	}

	/**
	 * @param string $authValue
	 */
	public function setAuthValue($authValue) {
		$this->authValue = trim((string) $authValue);
	}

	/**
	 * @return string
	 */
	public function getAuthHeader() {
		return $this->authHeader;
	}

	/**
	 * @param string $authHeader
	 */
	public function setAuthHeader($authHeader) {
		$trimmedHeader = trim((string) $authHeader);
		if ($trimmedHeader === '') {
			return;
		}

		$this->authHeader = $trimmedHeader;
	}

	/**
	 * @return string
	 */
	public function getBaseUrl() {
		return $this->baseUrl;
	}

	/**
	 * @param string $baseUrl
	 */
	public function setBaseUrl($baseUrl) {
		$trimmedBaseUrl = trim((string) $baseUrl);
		if ($trimmedBaseUrl === '') {
			return;
		}

		$this->baseUrl = rtrim($trimmedBaseUrl, '/');
	}

	/**
	 * @return string
	 */
	private function buildEventsUrl() {
		return $this->baseUrl . $this->eventPath;
	}

	/**
	 * @return array
	 */
	private function buildAuthHeaders() {
		if ($this->authMode === 'none' || $this->authValue === '') {
			return array();
		}

		if ($this->authMode === 'bearer') {
			return array('Authorization: Bearer ' . $this->authValue);
		}

		if ($this->authMode === 'api_key') {
			return array($this->authHeader . ': ' . $this->authValue);
		}

		return array();
	}

	/**
	 * @param EventEnvelope $envelope
	 * @return array
	 */
	private function buildRequestHeaders(EventEnvelope $envelope) {
		$headers = $this->buildAuthHeaders();

		$serverLogin = $this->resolveServerLoginHeaderValue();
		if ($serverLogin !== '') {
			$headers[] = 'X-Pixel-Server-Login: ' . $serverLogin;
		}

		$pluginVersion = $this->resolvePluginVersionHeaderValue($envelope);
		if ($pluginVersion !== '') {
			$headers[] = 'X-Pixel-Plugin-Version: ' . $pluginVersion;
		}

		return $headers;
	}

	/**
	 * @return string
	 */
	private function resolveServerLoginHeaderValue() {
		try {
			$server = $this->maniaControl ? $this->maniaControl->getServer() : null;
			if ($server && isset($server->login)) {
				$serverLogin = trim((string) $server->login);
				if ($serverLogin !== '') {
					return $serverLogin;
				}
			}
		} catch (\Throwable $throwable) {
		}

		$envServerLogin = getenv('PIXEL_SM_DEDICATED_LOGIN');
		if (!is_string($envServerLogin)) {
			return '';
		}

		return trim($envServerLogin);
	}

	/**
	 * @param EventEnvelope $envelope
	 * @return string
	 */
	private function resolvePluginVersionHeaderValue(EventEnvelope $envelope) {
		$envelopeData = $envelope->toArray();
		$metadata = isset($envelopeData['metadata']) && is_array($envelopeData['metadata'])
			? $envelopeData['metadata']
			: array();

		if (!isset($metadata['plugin_version'])) {
			return '';
		}

		return trim((string) $metadata['plugin_version']);
	}
}
