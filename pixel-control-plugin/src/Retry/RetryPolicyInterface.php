<?php

namespace PixelControl\Retry;

use PixelControl\Api\DeliveryError;
use PixelControl\Queue\QueueItem;

interface RetryPolicyInterface {
	/**
	 * @param QueueItem           $item
	 * @param DeliveryError|null  $deliveryError
	 * @return bool
	 */
	public function shouldRetry(QueueItem $item, ?DeliveryError $deliveryError = null);

	/**
	 * @param QueueItem $item
	 * @return int
	 */
	public function getDelaySeconds(QueueItem $item);
}
