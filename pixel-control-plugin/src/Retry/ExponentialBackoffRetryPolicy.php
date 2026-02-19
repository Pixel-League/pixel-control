<?php

namespace PixelControl\Retry;

use PixelControl\Api\DeliveryError;
use PixelControl\Queue\QueueItem;

class ExponentialBackoffRetryPolicy implements RetryPolicyInterface {
	/** @var int $maxAttempts */
	private $maxAttempts;
	/** @var int $baseDelaySeconds */
	private $baseDelaySeconds;
	/** @var int $maxDelaySeconds */
	private $maxDelaySeconds;

	/**
	 * @param int $maxAttempts
	 * @param int $baseDelaySeconds
	 * @param int $maxDelaySeconds
	 */
	public function __construct($maxAttempts = 3, $baseDelaySeconds = 1, $maxDelaySeconds = 30) {
		$this->maxAttempts = max(1, (int) $maxAttempts);
		$this->baseDelaySeconds = max(0, (int) $baseDelaySeconds);
		$this->maxDelaySeconds = max(0, (int) $maxDelaySeconds);
	}

	/**
	 * @see RetryPolicyInterface::shouldRetry()
	 */
	public function shouldRetry(QueueItem $item, ?DeliveryError $deliveryError = null) {
		if ($deliveryError && !$deliveryError->isRetryable()) {
			return false;
		}

		return $item->getAttempt() < $this->maxAttempts;
	}

	/**
	 * @see RetryPolicyInterface::getDelaySeconds()
	 */
	public function getDelaySeconds(QueueItem $item) {
		if ($this->baseDelaySeconds <= 0) {
			return 0;
		}

		$attemptIndex = max(0, $item->getAttempt() - 1);
		$exponentialDelay = $this->baseDelaySeconds * (2 ** $attemptIndex);
		$boundedDelay = max($this->baseDelaySeconds, (int) $exponentialDelay);

		if ($this->maxDelaySeconds > 0) {
			return min($boundedDelay, $this->maxDelaySeconds);
		}

		return $boundedDelay;
	}
}
