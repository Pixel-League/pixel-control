<?php

namespace PixelControl\Domain\StateSync;

use ManiaControl\Files\AsyncHttpRequest;
use ManiaControl\Logger;

/**
 * Plugin state synchronization trait.
 *
 * Provides two core operations:
 *  1. Blocking state restore on plugin load  (`syncStateOnLoad`)
 *     -- Uses PHP file_get_contents() with stream context for a synchronous GET.
 *     -- Must complete before command listeners are registered.
 *     -- Gracefully degrades: logs warning and continues with defaults on failure.
 *
 *  2. Non-blocking state push after admin commands  (`pushStateAfterCommand`)
 *     -- Uses ManiaControl AsyncHttpRequest with postData() (fire-and-forget POST).
 *     -- Does not delay command response; errors are only logged.
 *
 * Both operations are guarded by the SETTING_STATE_SYNC_ENABLED setting.
 *
 * This trait depends on state fields defined in AdminCommandTrait and
 * VetoDraftCommandTrait. Since PHP traits share the same $this when composed,
 * those fields are accessible here without explicit wiring.
 */
trait StateSyncTrait {

	// ─── Sync lifecycle ───────────────────────────────────────────────────────────

	/**
	 * Restores state from the server on plugin load.
	 * Must be called after initializeEventPipeline() so settings are available.
	 * Blocks until the HTTP response is received (or times out).
	 */
	public function syncStateOnLoad() {
		if (!$this->isStateSyncEnabled()) {
			Logger::log('[PixelControl] StateSync disabled — skipping restore on load.');
			return;
		}

		$serverLogin = $this->getLocalServerLogin();
		if ($serverLogin === '') {
			Logger::logWarning('[PixelControl] StateSync: server login not available, skipping restore.');
			return;
		}

		Logger::log('[PixelControl] StateSync: fetching persisted state for server ' . $serverLogin . '...');

		$response = $this->fetchStateFromServer($serverLogin);
		if ($response === null) {
			Logger::log('[PixelControl] StateSync: no prior state to restore, using defaults.');
			return;
		}

		if (!isset($response['state']) || $response['state'] === null) {
			Logger::log('[PixelControl] StateSync: server returned null state, using defaults.');
			return;
		}

		$snapshot = $response['state'];
		if (!is_array($snapshot)) {
			Logger::logWarning('[PixelControl] StateSync: state field is not an array, skipping restore.');
			return;
		}

		$this->restoreStateFromSnapshot($snapshot);
	}

	/**
	 * Pushes a state snapshot to the server after a state-mutating command.
	 * Non-blocking (async POST, fire-and-forget).
	 */
	public function pushStateAfterCommand() {
		if (!$this->isStateSyncEnabled()) {
			return;
		}

		$serverLogin = $this->getLocalServerLogin();
		if ($serverLogin === '') {
			return;
		}

		$snapshot = $this->buildStateSnapshot();
		$this->pushStateToServer($serverLogin, $snapshot);
	}

	// ─── Domain layer: snapshot builder + restore ─────────────────────────────────

	/**
	 * Builds a complete state snapshot from all in-memory state fields.
	 * Fields come from AdminCommandTrait and VetoDraftCommandTrait (composed in $this).
	 *
	 * @return array
	 */
	public function buildStateSnapshot() {
		return array(
			'state_version' => '1.0',
			'captured_at'   => time(),
			'admin'         => array(
				'current_best_of'      => isset($this->currentBestOf)       ? (int) $this->currentBestOf       : 3,
				'team_maps_score'      => isset($this->teamMapsScore)        ? (array) $this->teamMapsScore      : array('team_a' => 0, 'team_b' => 0),
				'team_round_score'     => isset($this->teamRoundScore)       ? (array) $this->teamRoundScore     : array('team_a' => 0, 'team_b' => 0),
				'team_policy_enabled'  => isset($this->teamPolicyEnabled)    ? (bool) $this->teamPolicyEnabled   : false,
				'team_switch_lock'     => isset($this->teamSwitchLock)       ? (bool) $this->teamSwitchLock      : false,
				'team_roster'          => isset($this->teamRoster)           ? (array) $this->teamRoster         : array(),
				'whitelist_enabled'    => isset($this->whitelistEnabled)     ? (bool) $this->whitelistEnabled    : false,
				'whitelist'            => isset($this->whitelist)            ? array_values((array) $this->whitelist) : array(),
				'vote_policy'          => isset($this->votePolicy)           ? (string) $this->votePolicy        : 'default',
				'vote_ratios'          => isset($this->voteRatios)           ? (array) $this->voteRatios         : array(),
			),
			'veto_draft'    => array(
				'session'                 => isset($this->vetoDraftSession)        ? $this->vetoDraftSession        : null,
				'matchmaking_ready_armed' => isset($this->matchmakingReadyArmed)   ? (bool) $this->matchmakingReadyArmed : false,
				'votes'                   => isset($this->vetoDraftVotes)          ? (array) $this->vetoDraftVotes  : array(),
			),
		);
	}

	/**
	 * Restores in-memory state fields from a persisted snapshot array.
	 * Validates state_version (only '1.0' is supported — logs warning and returns on unknown version).
	 * Applies type coercion and default fallbacks for each field.
	 *
	 * @param array $snapshot
	 */
	public function restoreStateFromSnapshot(array $snapshot) {
		$version = isset($snapshot['state_version']) ? (string) $snapshot['state_version'] : '';
		if ($version !== '1.0') {
			Logger::logWarning('[PixelControl] StateSync: unknown state_version "' . $version . '", skipping restore.');
			return;
		}

		// Restore admin state.
		$admin = isset($snapshot['admin']) && is_array($snapshot['admin']) ? $snapshot['admin'] : array();

		if (array_key_exists('current_best_of', $admin)) {
			$this->currentBestOf = max(1, (int) $admin['current_best_of']);
		}

		if (array_key_exists('team_maps_score', $admin) && is_array($admin['team_maps_score'])) {
			$this->teamMapsScore = array(
				'team_a' => isset($admin['team_maps_score']['team_a']) ? (int) $admin['team_maps_score']['team_a'] : 0,
				'team_b' => isset($admin['team_maps_score']['team_b']) ? (int) $admin['team_maps_score']['team_b'] : 0,
			);
		}

		if (array_key_exists('team_round_score', $admin) && is_array($admin['team_round_score'])) {
			$this->teamRoundScore = array(
				'team_a' => isset($admin['team_round_score']['team_a']) ? (int) $admin['team_round_score']['team_a'] : 0,
				'team_b' => isset($admin['team_round_score']['team_b']) ? (int) $admin['team_round_score']['team_b'] : 0,
			);
		}

		if (array_key_exists('team_policy_enabled', $admin)) {
			$this->teamPolicyEnabled = (bool) $admin['team_policy_enabled'];
		}

		if (array_key_exists('team_switch_lock', $admin)) {
			$this->teamSwitchLock = (bool) $admin['team_switch_lock'];
		}

		if (array_key_exists('team_roster', $admin) && is_array($admin['team_roster'])) {
			$this->teamRoster = array();
			foreach ($admin['team_roster'] as $login => $team) {
				if (is_string($login) && ($team === 'team_a' || $team === 'team_b')) {
					$this->teamRoster[$login] = $team;
				}
			}
		}

		if (array_key_exists('whitelist_enabled', $admin)) {
			$this->whitelistEnabled = (bool) $admin['whitelist_enabled'];
		}

		if (array_key_exists('whitelist', $admin) && is_array($admin['whitelist'])) {
			$this->whitelist = array();
			foreach ($admin['whitelist'] as $entry) {
				if (is_string($entry) && trim($entry) !== '') {
					$this->whitelist[] = trim($entry);
				}
			}
		}

		if (array_key_exists('vote_policy', $admin) && is_string($admin['vote_policy']) && $admin['vote_policy'] !== '') {
			$this->votePolicy = $admin['vote_policy'];
		}

		if (array_key_exists('vote_ratios', $admin) && is_array($admin['vote_ratios'])) {
			$this->voteRatios = array();
			foreach ($admin['vote_ratios'] as $cmd => $ratio) {
				if (is_string($cmd) && is_numeric($ratio)) {
					$this->voteRatios[$cmd] = (float) $ratio;
				}
			}
		}

		Logger::log('[PixelControl] StateSync: admin state restored (best_of=' . $this->currentBestOf
			. ', whitelist_enabled=' . ($this->whitelistEnabled ? 'true' : 'false')
			. ', whitelist_size=' . count($this->whitelist)
			. ', team_policy=' . ($this->teamPolicyEnabled ? 'true' : 'false')
			. ', team_roster_size=' . count($this->teamRoster)
			. ', vote_policy=' . $this->votePolicy
			. ').'
		);

		// Restore veto-draft state.
		$vetoDraft = isset($snapshot['veto_draft']) && is_array($snapshot['veto_draft']) ? $snapshot['veto_draft'] : array();

		if (array_key_exists('session', $vetoDraft)) {
			$this->vetoDraftSession = is_array($vetoDraft['session']) ? $vetoDraft['session'] : null;
		}

		if (array_key_exists('matchmaking_ready_armed', $vetoDraft)) {
			$this->matchmakingReadyArmed = (bool) $vetoDraft['matchmaking_ready_armed'];
		}

		if (array_key_exists('votes', $vetoDraft) && is_array($vetoDraft['votes'])) {
			$this->vetoDraftVotes = array();
			foreach ($vetoDraft['votes'] as $actorLogin => $mapUid) {
				if (is_string($actorLogin) && is_string($mapUid)) {
					$this->vetoDraftVotes[$actorLogin] = $mapUid;
				}
			}
		}

		Logger::log('[PixelControl] StateSync: veto-draft state restored (session='
			. ($this->vetoDraftSession !== null ? 'set' : 'null')
			. ', ready_armed=' . ($this->matchmakingReadyArmed ? 'true' : 'false')
			. ', votes_count=' . count($this->vetoDraftVotes)
			. ').'
		);
	}

	// ─── Transport layer ──────────────────────────────────────────────────────────

	/**
	 * Performs a blocking synchronous GET to retrieve the server state.
	 * Uses PHP stream context (file_get_contents). Times out after 5 seconds.
	 *
	 * @param  string     $serverLogin
	 * @return array|null Decoded JSON response array, or null on failure.
	 */
	private function fetchStateFromServer($serverLogin) {
		$url = $this->buildStateEndpointUrl($serverLogin);
		if ($url === '') {
			return null;
		}

		$headers = $this->buildStateSyncAuthHeaders($serverLogin);
		$headerLines = implode("\r\n", $headers);

		$context = stream_context_create(array(
			'http' => array(
				'method'           => 'GET',
				'header'           => $headerLines,
				'timeout'          => 5,
				'ignore_errors'    => true,
			),
		));

		$rawResponse = @file_get_contents($url, false, $context);
		if ($rawResponse === false) {
			Logger::logWarning('[PixelControl] StateSync: GET ' . $url . ' failed (server unreachable).');
			return null;
		}

		$decoded = json_decode($rawResponse, true);
		if (!is_array($decoded)) {
			Logger::logWarning('[PixelControl] StateSync: GET response is not valid JSON.');
			return null;
		}

		return $decoded;
	}

	/**
	 * Performs a non-blocking async POST to persist the state snapshot.
	 * Fire-and-forget — errors are only logged, not propagated.
	 *
	 * @param string $serverLogin
	 * @param array  $snapshot
	 */
	private function pushStateToServer($serverLogin, array $snapshot) {
		if (!$this->maniaControl) {
			return;
		}

		$url = $this->buildStateEndpointUrl($serverLogin);
		if ($url === '') {
			return;
		}

		$payload = json_encode($snapshot);
		if (!is_string($payload)) {
			Logger::logWarning('[PixelControl] StateSync: failed to JSON-encode state snapshot.');
			return;
		}

		try {
			$request = new AsyncHttpRequest($this->maniaControl, $url);
			$headers = $this->buildStateSyncAuthHeaders($serverLogin);
			if (!empty($headers)) {
				$request->setHeaders($headers);
			}
			$request->setContentType(AsyncHttpRequest::CONTENT_TYPE_JSON);
			$request->setTimeout(5);
			$request->setContent($payload);
			$request->setCallable(function ($responseBody, $errorMessage) {
				if ($errorMessage) {
					Logger::logWarning('[PixelControl] StateSync push failed: ' . (string) $errorMessage);
				}
			});
			$request->postData();
		} catch (\Throwable $throwable) {
			Logger::logWarning('[PixelControl] StateSync push exception: ' . $throwable->getMessage());
		}
	}

	// ─── Helper: URL + headers ────────────────────────────────────────────────────

	/**
	 * Builds the full state endpoint URL for the given server login.
	 * Pattern: {baseUrl}/servers/{serverLogin}/state
	 * Reads configuration directly from env vars and ManiaControl settings,
	 * without relying on CoreDomainTrait helper methods.
	 *
	 * @param  string $serverLogin
	 * @return string Empty string if base URL is not configured.
	 */
	private function buildStateEndpointUrl($serverLogin) {
		if (!$this->maniaControl) {
			return '';
		}

		$baseUrl = $this->stateSyncReadStringSetting(self::SETTING_API_BASE_URL, 'PIXEL_CONTROL_API_BASE_URL', '');

		// Use link server URL if configured (same precedence as event pipeline).
		$linkServerUrl = $this->stateSyncReadStringSetting(self::SETTING_LINK_SERVER_URL, 'PIXEL_CONTROL_LINK_SERVER_URL', '');
		if ($linkServerUrl !== '') {
			$baseUrl = $linkServerUrl;
		}

		if ($baseUrl === '') {
			return '';
		}

		$baseUrl = rtrim($baseUrl, '/');
		$login = rawurlencode($serverLogin);

		return $baseUrl . '/servers/' . $login . '/state';
	}

	/**
	 * Builds the auth headers for state sync requests.
	 * Includes the link bearer token (Authorization) and server login (X-Pixel-Server-Login).
	 *
	 * @param  string $serverLogin
	 * @return array  Array of "Header: Value" strings.
	 */
	private function buildStateSyncAuthHeaders($serverLogin) {
		$headers = array('Content-Type: application/json');

		$linkToken = $this->stateSyncReadStringSetting(self::SETTING_LINK_TOKEN, 'PIXEL_CONTROL_LINK_TOKEN', '');

		if ($linkToken !== '') {
			$headers[] = 'Authorization: Bearer ' . $linkToken;
		}

		if ($serverLogin !== '') {
			$headers[] = 'X-Pixel-Server-Login: ' . $serverLogin;
		}

		return $headers;
	}

	// ─── Helper: enabled check ────────────────────────────────────────────────────

	/**
	 * Returns whether state sync is currently enabled.
	 * Reads from env var first, then ManiaControl settings.
	 *
	 * @return bool
	 */
	private function isStateSyncEnabled() {
		if (!$this->maniaControl) {
			return false;
		}

		$envValue = getenv('PIXEL_CONTROL_STATE_SYNC_ENABLED');
		if ($envValue !== false) {
			$normalized = strtolower(trim((string) $envValue));
			return in_array($normalized, array('1', 'true', 'yes', 'on'), true);
		}

		$settingValue = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_STATE_SYNC_ENABLED);
		if (is_bool($settingValue)) {
			return $settingValue;
		}

		if (is_numeric($settingValue)) {
			return ((int) $settingValue) !== 0;
		}

		if (is_string($settingValue)) {
			$normalized = strtolower(trim($settingValue));
			if ($normalized !== '') {
				return in_array($normalized, array('1', 'true', 'yes', 'on'), true);
			}
		}

		return true; // default enabled
	}

	// ─── Internal setting helpers (self-contained, no CoreDomainTrait dependency) ──

	/**
	 * Reads a string setting: checks env var first, then ManiaControl settings, then fallback.
	 *
	 * @param  string $settingName
	 * @param  string $envVarName
	 * @param  string $fallback
	 * @return string
	 */
	private function stateSyncReadStringSetting($settingName, $envVarName, $fallback) {
		$envValue = getenv($envVarName);
		if ($envValue !== false) {
			$trimmed = trim((string) $envValue);
			if ($trimmed !== '') {
				return $trimmed;
			}
		}

		if (!$this->maniaControl) {
			return $fallback;
		}

		$settingValue = (string) $this->maniaControl->getSettingManager()->getSettingValue($this, $settingName);
		$settingValue = trim($settingValue);
		if ($settingValue !== '') {
			return $settingValue;
		}

		return $fallback;
	}
}
