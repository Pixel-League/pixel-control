<?php

namespace PixelControl\Api;

class DeliveryError {
	/** @var string $code */
	private $code;
	/** @var string $message */
	private $message;
	/** @var bool $retryable */
	private $retryable;
	/** @var int $retryAfterSeconds */
	private $retryAfterSeconds;

	/**
	 * @param string $code
	 * @param string $message
	 * @param bool   $retryable
	 * @param int    $retryAfterSeconds
	 */
	public function __construct($code, $message, $retryable = true, $retryAfterSeconds = 0) {
		$this->code = trim((string) $code);
		if ($this->code === '') {
			$this->code = 'delivery_error';
		}

		$this->message = trim((string) $message);
		if ($this->message === '') {
			$this->message = 'delivery failed';
		}

		$this->retryable = (bool) $retryable;
		$this->retryAfterSeconds = max(0, (int) $retryAfterSeconds);
	}

	/**
	 * @return string
	 */
	public function getCode() {
		return $this->code;
	}

	/**
	 * @return string
	 */
	public function getMessage() {
		return $this->message;
	}

	/**
	 * @return bool
	 */
	public function isRetryable() {
		return $this->retryable;
	}

	/**
	 * @return int
	 */
	public function getRetryAfterSeconds() {
		return $this->retryAfterSeconds;
	}

	/**
	 * @return array
	 */
	public function toArray() {
		return array(
			'code' => $this->code,
			'message' => $this->message,
			'retryable' => $this->retryable,
			'retry_after_seconds' => $this->retryAfterSeconds,
		);
	}

	/**
	 * @param array  $errorData
	 * @param string $fallbackCode
	 * @param string $fallbackMessage
	 * @param bool   $fallbackRetryable
	 * @return DeliveryError
	 */
	public static function fromArray(array $errorData, $fallbackCode = 'delivery_error', $fallbackMessage = 'delivery failed', $fallbackRetryable = true) {
		$code = isset($errorData['code']) ? (string) $errorData['code'] : $fallbackCode;
		$message = isset($errorData['message']) ? (string) $errorData['message'] : $fallbackMessage;

		$retryable = $fallbackRetryable;
		if (array_key_exists('retryable', $errorData)) {
			$retryable = (bool) $errorData['retryable'];
		}

		$retryAfterSeconds = 0;
		if (isset($errorData['retry_after_seconds']) && is_numeric($errorData['retry_after_seconds'])) {
			$retryAfterSeconds = max(0, (int) $errorData['retry_after_seconds']);
		} else if (isset($errorData['retry_after_ms']) && is_numeric($errorData['retry_after_ms'])) {
			$retryAfterSeconds = max(0, (int) ceil(((int) $errorData['retry_after_ms']) / 1000));
		}

		return new self($code, $message, $retryable, $retryAfterSeconds);
	}
}
