<?php

namespace PixelControl\Api;

class EventEnvelope {
	/** @var string $eventName */
	private $eventName;
	/** @var string $schemaVersion */
	private $schemaVersion;
	/** @var string $eventId */
	private $eventId;
	/** @var string $eventCategory */
	private $eventCategory;
	/** @var string $sourceCallback */
	private $sourceCallback;
	/** @var int $sourceSequence */
	private $sourceSequence;
	/** @var int $sourceTime */
	private $sourceTime;
	/** @var string $idempotencyKey */
	private $idempotencyKey;
	/** @var array $payload */
	private $payload;
	/** @var array $metadata */
	private $metadata;

	/**
	 * @param string $eventName
	 * @param string $schemaVersion
	 * @param string $eventId
	 * @param string $eventCategory
	 * @param string $sourceCallback
	 * @param int    $sourceSequence
	 * @param int    $sourceTime
	 * @param string $idempotencyKey
	 * @param array  $payload
	 * @param array  $metadata
	 */
	public function __construct(
		$eventName,
		$schemaVersion,
		$eventId,
		$eventCategory,
		$sourceCallback,
		$sourceSequence,
		$sourceTime,
		$idempotencyKey,
		array $payload = array(),
		array $metadata = array()
	) {
		$this->eventName = $eventName;
		$this->schemaVersion = $schemaVersion;
		$this->eventId = $eventId;
		$this->eventCategory = $eventCategory;
		$this->sourceCallback = $sourceCallback;
		$this->sourceSequence = (int) $sourceSequence;
		$this->sourceTime = (int) $sourceTime;
		$this->idempotencyKey = $idempotencyKey;
		$this->payload = $payload;
		$this->metadata = $metadata;
	}

	/**
	 * @return string
	 */
	public function getEventCategory() {
		return $this->eventCategory;
	}

	/**
	 * @return string
	 */
	public function getEventName() {
		return $this->eventName;
	}

	/**
	 * @return string
	 */
	public function getSchemaVersion() {
		return $this->schemaVersion;
	}

	/**
	 * @return string
	 */
	public function getEventId() {
		return $this->eventId;
	}

	/**
	 * @return string
	 */
	public function getIdempotencyKey() {
		return $this->idempotencyKey;
	}

	/**
	 * @return array
	 */
	public function toArray() {
		return array(
			'event_name' => $this->eventName,
			'schema_version' => $this->schemaVersion,
			'event_id' => $this->eventId,
			'event_category' => $this->eventCategory,
			'source_callback' => $this->sourceCallback,
			'source_sequence' => $this->sourceSequence,
			'source_time' => $this->sourceTime,
			'idempotency_key' => $this->idempotencyKey,
			'payload' => $this->payload,
			'metadata' => $this->metadata,
		);
	}

	/**
	 * @return string
	 */
	public function toJson() {
		return json_encode($this->toArray());
	}
}
