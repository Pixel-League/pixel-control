<?php

namespace PixelControl\Admin;

class AdminActionResult {
	/** @var string $actionName */
	private $actionName;
	/** @var bool $success */
	private $success;
	/** @var string $code */
	private $code;
	/** @var string $message */
	private $message;
	/** @var array $details */
	private $details;

	public function __construct($actionName, $success, $code, $message, array $details = array()) {
		$this->actionName = (string) $actionName;
		$this->success = (bool) $success;
		$this->code = trim((string) $code);
		$this->message = trim((string) $message);
		$this->details = $details;

		if ($this->code === '') {
			$this->code = $this->success ? 'ok' : 'error';
		}

		if ($this->message === '') {
			$this->message = $this->success ? 'Action executed.' : 'Action failed.';
		}
	}

	public static function success($actionName, $message, array $details = array()) {
		return new self($actionName, true, 'ok', $message, $details);
	}

	public static function failure($actionName, $code, $message, array $details = array()) {
		return new self($actionName, false, $code, $message, $details);
	}

	public function isSuccess() {
		return $this->success;
	}

	public function getActionName() {
		return $this->actionName;
	}

	public function getCode() {
		return $this->code;
	}

	public function getMessage() {
		return $this->message;
	}

	public function getDetails() {
		return $this->details;
	}

	public function toArray() {
		return array(
			'action_name' => $this->actionName,
			'success' => $this->success,
			'code' => $this->code,
			'message' => $this->message,
			'details' => $this->details,
		);
	}
}
