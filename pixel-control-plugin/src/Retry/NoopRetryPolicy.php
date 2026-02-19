<?php

namespace PixelControl\Retry;

use PixelControl\Api\DeliveryError;
use PixelControl\Queue\QueueItem;

class NoopRetryPolicy implements RetryPolicyInterface {
	/**
	 * @see RetryPolicyInterface::shouldRetry()
	 */
	public function shouldRetry(QueueItem $item, ?DeliveryError $deliveryError = null) {
		return false;
	}

	/**
	 * @see RetryPolicyInterface::getDelaySeconds()
	 */
	public function getDelaySeconds(QueueItem $item) {
		return 0;
	}
}
