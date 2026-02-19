<?php

namespace PixelControl\Queue;

interface EventQueueInterface {
	/**
	 * @param QueueItem $item
	 */
	public function enqueue(QueueItem $item);

	/**
	 * @param int|null $timestamp
	 * @return QueueItem|null
	 */
	public function dequeueReady($timestamp = null);

	/**
	 * @param QueueItem $item
	 */
	public function requeue(QueueItem $item);

	/**
	 * @return int
	 */
	public function count();
}
