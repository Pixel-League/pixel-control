<?php

namespace PixelControl\Queue;

class InMemoryEventQueue implements EventQueueInterface {
	/** @var QueueItem[] $items */
	private $items = array();

	/**
	 * @see EventQueueInterface::enqueue()
	 */
	public function enqueue(QueueItem $item) {
		$this->items[] = $item;
	}

	/**
	 * @see EventQueueInterface::dequeueReady()
	 */
	public function dequeueReady($timestamp = null) {
		$now = $timestamp === null ? time() : (int) $timestamp;

		foreach ($this->items as $index => $item) {
			if ($item->getNextAttemptAt() > $now) {
				continue;
			}

			array_splice($this->items, $index, 1);
			return $item;
		}

		return null;
	}

	/**
	 * @see EventQueueInterface::requeue()
	 */
	public function requeue(QueueItem $item) {
		$this->items[] = $item;
	}

	/**
	 * @see EventQueueInterface::count()
	 */
	public function count() {
		return count($this->items);
	}
}
