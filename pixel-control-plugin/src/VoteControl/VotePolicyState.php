<?php

namespace PixelControl\VoteControl;

class VotePolicyState implements VotePolicyStateInterface {
	/** @var string $mode */
	private $mode = VotePolicyCatalog::DEFAULT_MODE;
	/** @var int $updatedAt */
	private $updatedAt = 0;
	/** @var string $updatedBy */
	private $updatedBy = 'system';
	/** @var string $updateSource */
	private $updateSource = VotePolicyCatalog::UPDATE_SOURCE_SETTING;

	public function bootstrap(array $defaults, $updateSource, $updatedBy) {
		$this->mode = VotePolicyCatalog::normalizeMode(
			$this->readValue($defaults, array('mode'), VotePolicyCatalog::DEFAULT_MODE),
			VotePolicyCatalog::DEFAULT_MODE
		);
		$this->markUpdated($updateSource, $updatedBy);

		return $this->buildSuccess(
			'vote_policy_bootstrap_applied',
			'Vote policy defaults initialized.',
			array('changed_fields' => array('mode'))
		);
	}

	public function reset() {
		$this->mode = VotePolicyCatalog::DEFAULT_MODE;
		$this->updatedAt = 0;
		$this->updatedBy = 'system';
		$this->updateSource = VotePolicyCatalog::UPDATE_SOURCE_SETTING;
	}

	public function getSnapshot() {
		$mode = VotePolicyCatalog::normalizeMode($this->mode, VotePolicyCatalog::DEFAULT_MODE);

		return array(
			'mode' => $mode,
			'strict_mode' => VotePolicyCatalog::isStrictMode($mode),
			'updated_at' => $this->updatedAt,
			'updated_by' => $this->updatedBy,
			'update_source' => $this->updateSource,
			'available_modes' => array(
				VotePolicyCatalog::MODE_CANCEL_NON_ADMIN,
				VotePolicyCatalog::MODE_DISABLE_CALLVOTES,
			),
		);
	}

	public function setMode($mode, $updateSource, $updatedBy, array $context = array()) {
		if (trim((string) $mode) === '') {
			return $this->buildFailure('missing_parameters', 'mode is required.', array('field' => 'mode'));
		}

		if (!VotePolicyCatalog::isSupportedMode($mode)) {
			return $this->buildFailure(
				'invalid_parameters',
				'Unsupported mode. Use cancel_non_admin_vote_on_callback or disable_callvotes_and_use_admin_actions.',
				array('field' => 'mode')
			);
		}

		$normalizedMode = VotePolicyCatalog::normalizeMode($mode, VotePolicyCatalog::DEFAULT_MODE);

		$changed = $normalizedMode !== $this->mode;
		$this->mode = $normalizedMode;
		$this->markUpdated($updateSource, $updatedBy);

		return $this->buildSuccess(
			($changed ? 'vote_policy_mode_updated' : 'vote_policy_mode_unchanged'),
			($changed ? 'Vote policy mode updated.' : 'Vote policy mode unchanged.'),
			array(
				'changed_fields' => ($changed ? array('mode') : array()),
				'mode' => $normalizedMode,
			)
		);
	}

	public function getMode() {
		return VotePolicyCatalog::normalizeMode($this->mode, VotePolicyCatalog::DEFAULT_MODE);
	}

	private function readValue(array $values, array $keys, $fallback) {
		foreach ($keys as $key) {
			if (!array_key_exists($key, $values)) {
				continue;
			}

			return $values[$key];
		}

		return $fallback;
	}

	private function markUpdated($updateSource, $updatedBy) {
		$this->updatedAt = time();
		$this->updateSource = VotePolicyCatalog::normalizeUpdateSource($updateSource, $this->updateSource);
		$this->updatedBy = VotePolicyCatalog::normalizeUpdatedBy($updatedBy, $this->updatedBy);
	}

	private function buildSuccess($code, $message, array $details = array()) {
		return array(
			'success' => true,
			'code' => trim((string) $code),
			'message' => trim((string) $message),
			'snapshot' => $this->getSnapshot(),
			'details' => $details,
		);
	}

	private function buildFailure($code, $message, array $details = array()) {
		return array(
			'success' => false,
			'code' => trim((string) $code),
			'message' => trim((string) $message),
			'snapshot' => $this->getSnapshot(),
			'details' => $details,
		);
	}
}
