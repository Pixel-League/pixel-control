<?php

namespace PixelControl\Queue;

use PixelControl\Api\EventEnvelope;

class QueueItem {
	/** @var string $id */
	private $id;
	/** @var EventEnvelope $envelope */
	private $envelope;
	/** @var int $attempt */
	private $attempt;
	/** @var int $queuedAt */
	private $queuedAt;
	/** @var int $nextAttemptAt */
	private $nextAttemptAt;

	/**
	 * @param string        $id
	 * @param EventEnvelope $envelope
	 * @param int           $attempt
	 * @param int|null      $queuedAt
	 * @param int|null      $nextAttemptAt
	 */
	public function __construct($id, EventEnvelope $envelope, $attempt = 1, $queuedAt = null, $nextAttemptAt = null) {
		$this->id = (string) $id;
		$this->envelope = $envelope;
		$this->attempt = max(1, (int) $attempt);
		$this->queuedAt = $queuedAt === null ? time() : (int) $queuedAt;
		$this->nextAttemptAt = $nextAttemptAt === null ? $this->queuedAt : (int) $nextAttemptAt;
	}

	/**
	 * @return string
	 */
	public function getId() {
		return $this->id;
	}

	/**
	 * @return EventEnvelope
	 */
	public function getEnvelope() {
		return $this->envelope;
	}

	/**
	 * @return int
	 */
	public function getAttempt() {
		return $this->attempt;
	}

	/**
	 * @return int
	 */
	public function getQueuedAt() {
		return $this->queuedAt;
	}

	/**
	 * @return int
	 */
	public function getNextAttemptAt() {
		return $this->nextAttemptAt;
	}

	/**
	 * @param int $delaySeconds
	 */
	public function markRetry($delaySeconds) {
		$this->attempt++;
		$this->nextAttemptAt = time() + max(0, (int) $delaySeconds);
	}
}
