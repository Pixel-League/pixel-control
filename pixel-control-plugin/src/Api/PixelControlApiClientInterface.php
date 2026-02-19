<?php

namespace PixelControl\Api;

interface PixelControlApiClientInterface {
	/**
	 * Queue an asynchronous send attempt for a single envelope.
	 *
	 * @param EventEnvelope $envelope
	 * @param int           $attempt
	 * @param callable|null $resultCallback Receives `(bool $delivered, ?DeliveryError $deliveryError)`.
	 * @return bool
	 */
	public function sendEvent(EventEnvelope $envelope, $attempt = 1, ?callable $resultCallback = null);

	/**
	 * @return int
	 */
	public function getTimeoutSeconds();

	/**
	 * @param int $timeoutSeconds
	 */
	public function setTimeoutSeconds($timeoutSeconds);

	/**
	 * @return int
	 */
	public function getMaxRetryAttempts();

	/**
	 * @param int $maxRetryAttempts
	 */
	public function setMaxRetryAttempts($maxRetryAttempts);

	/**
	 * @return int
	 */
	public function getRetryBackoffMs();

	/**
	 * @param int $retryBackoffMs
	 */
	public function setRetryBackoffMs($retryBackoffMs);

	/**
	 * @return string
	 */
	public function getAuthMode();

	/**
	 * @param string $authMode
	 */
	public function setAuthMode($authMode);

	/**
	 * @return string
	 */
	public function getAuthValue();

	/**
	 * @param string $authValue
	 */
	public function setAuthValue($authValue);

	/**
	 * @return string
	 */
	public function getAuthHeader();

	/**
	 * @param string $authHeader
	 */
	public function setAuthHeader($authHeader);
}
