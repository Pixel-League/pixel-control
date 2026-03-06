<?php

namespace PixelControl\Domain\VetoDraft;

use ManiaControl\Communication\CommunicationAnswer;
use ManiaControl\Logger;

/**
 * Minimal VetoDraft command communication listener for Pixel Control P4 endpoints.
 *
 * Registers 5 CommunicationManager listeners for the PixelControl.VetoDraft.* method family:
 *   PixelControl.VetoDraft.Status  (P4.1) -- read current session state
 *   PixelControl.VetoDraft.Ready   (P4.2) -- arm matchmaking ready gate
 *   PixelControl.VetoDraft.Start   (P4.3) -- start matchmaking or tournament draft
 *   PixelControl.VetoDraft.Action  (P4.4) -- record a ban/pick/vote action
 *   PixelControl.VetoDraft.Cancel  (P4.5) -- cancel an active session
 *
 * This trait does NOT restore the full VetoDraft subsystem that was removed during
 * PLAN-ELITE-ENRICHMENT. It is a focused, minimal re-implementation that maintains
 * in-memory session state and proxies actions through ManiaControl managers.
 *
 * Link-auth is validated on Start, Action, and Cancel. Status and Ready do not
 * require auth (they are read-only or arm-only operations).
 *
 * Response shape for Status: { status: { active, mode, session, series_targets }, communication }
 * Response shape for Ready/Start/Action/Cancel: { success, code, message }
 */
trait VetoDraftCommandTrait {

	// ─── Internal session state ───────────────────────────────────────────────────

	/**
	 * Active veto/draft session or null when idle.
	 *
	 * Shape when set:
	 * {
	 *   status: string ('idle'|'running'|'completed'|'cancelled'),
	 *   mode: string ('matchmaking_vote'|'tournament_draft'),
	 *   steps: array,
	 *   current_step: int,
	 *   captains: array|null,
	 *   map_pool: array,
	 *   votes: array,
	 *   started_at: int,
	 * }
	 *
	 * @var array|null $vetoDraftSession
	 */
	private $vetoDraftSession = null;

	/**
	 * Whether the matchmaking ready gate has been armed.
	 * Must be true before a matchmaking_vote session can be started.
	 *
	 * @var bool $matchmakingReadyArmed
	 */
	private $matchmakingReadyArmed = false;

	/**
	 * Map of actor_login => map_uid for matchmaking votes.
	 *
	 * @var array $vetoDraftVotes
	 */
	private $vetoDraftVotes = array();

	// ─── Registration ─────────────────────────────────────────────────────────────

	/**
	 * Registers the 5 PixelControl.VetoDraft.* communication listeners.
	 * Call from load().
	 */
	private function registerVetoDraftCommandListener() {
		if (!$this->maniaControl || !$this->maniaControl->getCommunicationManager()) {
			Logger::logError('[PixelControl] VetoDraftCommandTrait: CommunicationManager not available.');
			return;
		}
		$cm = $this->maniaControl->getCommunicationManager();
		$cm->registerCommunicationListener('PixelControl.VetoDraft.Status', $this, 'handleVetoDraftStatus');
		$cm->registerCommunicationListener('PixelControl.VetoDraft.Ready',  $this, 'handleVetoDraftReady');
		$cm->registerCommunicationListener('PixelControl.VetoDraft.Start',  $this, 'handleVetoDraftStart');
		$cm->registerCommunicationListener('PixelControl.VetoDraft.Action', $this, 'handleVetoDraftAction');
		$cm->registerCommunicationListener('PixelControl.VetoDraft.Cancel', $this, 'handleVetoDraftCancel');
		Logger::log('[PixelControl] VetoDraft command listeners registered (Status, Ready, Start, Action, Cancel).');
	}

	// ─── P4.1 -- Status ───────────────────────────────────────────────────────────

	/**
	 * Handles PixelControl.VetoDraft.Status.
	 * No link-auth required.
	 *
	 * @param mixed $data
	 * @return CommunicationAnswer
	 */
	public function handleVetoDraftStatus($data) {
		$active = $this->vetoDraftSession !== null
			&& $this->vetoDraftSession['status'] === 'running';

		if (!$active) {
			$statusPayload = array(
				'active'  => false,
				'mode'    => null,
				'session' => array('status' => 'idle'),
			);
		} else {
			$session = $this->vetoDraftSession;
			$totalSteps = count($session['steps']);
			$currentStep = $session['current_step'];

			$statusPayload = array(
				'active'  => true,
				'mode'    => $session['mode'],
				'session' => array(
					'status'       => $session['status'],
					'mode'         => $session['mode'],
					'started_at'   => $session['started_at'],
					'current_step' => $currentStep,
					'total_steps'  => $totalSteps,
					'steps'        => $session['steps'],
					'captains'     => $session['captains'],
					'map_pool'     => $session['map_pool'],
					'votes'        => $session['votes'],
				),
				'series_targets' => array(
					'best_of' => isset($session['best_of']) ? $session['best_of'] : null,
				),
			);
		}

		$result = array(
			'status'        => $statusPayload,
			'communication' => array(
				'status' => 'PixelControl.VetoDraft.Status',
				'ready'  => 'PixelControl.VetoDraft.Ready',
				'start'  => 'PixelControl.VetoDraft.Start',
				'action' => 'PixelControl.VetoDraft.Action',
				'cancel' => 'PixelControl.VetoDraft.Cancel',
			),
		);

		return new CommunicationAnswer($result, false);
	}

	// ─── P4.2 -- Ready ────────────────────────────────────────────────────────────

	/**
	 * Handles PixelControl.VetoDraft.Ready.
	 * Arms the matchmaking ready gate. No link-auth required.
	 *
	 * @param mixed $data
	 * @return CommunicationAnswer
	 */
	public function handleVetoDraftReady($data) {
		if ($this->matchmakingReadyArmed) {
			return new CommunicationAnswer(array(
				'success' => false,
				'code'    => 'matchmaking_ready_already_armed',
				'message' => 'Matchmaking ready gate is already armed.',
			), false);
		}

		$this->matchmakingReadyArmed = true;
		$this->pushStateAfterCommand();

		return new CommunicationAnswer(array(
			'success' => true,
			'code'    => 'matchmaking_ready_armed',
			'message' => 'Matchmaking ready gate armed. A matchmaking_vote session may now be started.',
		), false);
	}

	// ─── P4.3 -- Start ────────────────────────────────────────────────────────────

	/**
	 * Handles PixelControl.VetoDraft.Start.
	 * Validates link-auth. Validates mode and session prerequisites.
	 *
	 * @param mixed $data
	 * @return CommunicationAnswer
	 */
	public function handleVetoDraftStart($data) {
		// Validate link-auth.
		$authError = $this->validateLinkAuth($data);
		if ($authError !== null) {
			return new CommunicationAnswer($authError, false);
		}

		// Reject if a session is already running.
		if ($this->vetoDraftSession !== null && $this->vetoDraftSession['status'] === 'running') {
			return new CommunicationAnswer(array(
				'success' => false,
				'code'    => 'session_active',
				'message' => 'A veto/draft session is already running. Cancel it first.',
			), false);
		}

		$parameters = isset($data->parameters) ? $data->parameters : null;
		$mode = $this->vdRequireStringParam($parameters, 'mode');

		if ($mode === null || !in_array($mode, array('matchmaking_vote', 'tournament_draft'), true)) {
			return new CommunicationAnswer(array(
				'success' => false,
				'code'    => 'invalid_mode',
				'message' => 'Missing or invalid mode. Must be "matchmaking_vote" or "tournament_draft".',
			), false);
		}

		// Fetch map pool from ManiaControl.
		$mapPool = $this->fetchMapPool();
		$mapCount = count($mapPool);

		if ($mode === 'matchmaking_vote') {
			$answer = $this->startMatchmakingSession($parameters, $mapPool, $mapCount);
		} else {
			$answer = $this->startTournamentSession($parameters, $mapPool, $mapCount);
		}

		// Push state snapshot after a successful session start.
		if (isset($answer->data['success']) && $answer->data['success'] === true) {
			$this->pushStateAfterCommand();
		}

		return $answer;
	}

	/**
	 * Starts a matchmaking_vote session.
	 *
	 * @param mixed $parameters
	 * @param array $mapPool
	 * @param int   $mapCount
	 * @return CommunicationAnswer
	 */
	private function startMatchmakingSession($parameters, $mapPool, $mapCount) {
		if (!$this->matchmakingReadyArmed) {
			return new CommunicationAnswer(array(
				'success' => false,
				'code'    => 'matchmaking_ready_required',
				'message' => 'The matchmaking ready gate must be armed (call Ready) before starting a matchmaking vote.',
			), false);
		}

		if ($mapCount < 1) {
			return new CommunicationAnswer(array(
				'success' => false,
				'code'    => 'map_pool_empty',
				'message' => 'The server map pool is empty. Add maps before starting a veto session.',
			), false);
		}

		// Reset votes and create session.
		$this->vetoDraftVotes = array();
		$this->matchmakingReadyArmed = false;

		$this->vetoDraftSession = array(
			'status'      => 'running',
			'mode'        => 'matchmaking_vote',
			'steps'       => array(),
			'current_step'=> 0,
			'captains'    => null,
			'map_pool'    => $mapPool,
			'votes'       => array(),
			'started_at'  => time(),
			'best_of'     => null,
		);

		Logger::log('[PixelControl] VetoDraft matchmaking_vote session started.');

		return new CommunicationAnswer(array(
			'success' => true,
			'code'    => 'session_started',
			'message' => 'Matchmaking vote session started.',
			'details' => array(
				'mode'      => 'matchmaking_vote',
				'map_count' => $mapCount,
			),
		), false);
	}

	/**
	 * Starts a tournament_draft session.
	 *
	 * @param mixed $parameters
	 * @param array $mapPool
	 * @param int   $mapCount
	 * @return CommunicationAnswer
	 */
	private function startTournamentSession($parameters, $mapPool, $mapCount) {
		$captainA = $this->vdRequireStringParam($parameters, 'captain_a');
		$captainB = $this->vdRequireStringParam($parameters, 'captain_b');

		if ($captainA === null || $captainA === '') {
			return new CommunicationAnswer(array(
				'success' => false,
				'code'    => 'invalid_captain',
				'message' => 'Missing or empty captain_a parameter.',
			), false);
		}

		if ($captainB === null || $captainB === '') {
			return new CommunicationAnswer(array(
				'success' => false,
				'code'    => 'invalid_captain',
				'message' => 'Missing or empty captain_b parameter.',
			), false);
		}

		if ($captainA === $captainB) {
			return new CommunicationAnswer(array(
				'success' => false,
				'code'    => 'captain_conflict',
				'message' => 'captain_a and captain_b must be distinct logins.',
			), false);
		}

		// Determine best_of and required maps.
		$bestOf = 3;
		$rawBestOf = null;
		if ($parameters !== null) {
			if (is_object($parameters) && isset($parameters->best_of)) {
				$rawBestOf = (int) $parameters->best_of;
			} elseif (is_array($parameters) && isset($parameters['best_of'])) {
				$rawBestOf = (int) $parameters['best_of'];
			}
		}
		if ($rawBestOf !== null && $rawBestOf >= 1) {
			$bestOf = $rawBestOf;
		}

		// For a best_of_N draft: need at least N maps (bans reduce the pool, picks select from it).
		if ($mapCount < $bestOf) {
			return new CommunicationAnswer(array(
				'success' => false,
				'code'    => 'map_pool_insufficient',
				'message' => "Map pool has only {$mapCount} maps but best_of_{$bestOf} requires at least {$bestOf}.",
			), false);
		}

		// Build step sequence: alternate bans, then picks.
		$steps = $this->buildTournamentSteps($bestOf);

		$this->vetoDraftSession = array(
			'status'       => 'running',
			'mode'         => 'tournament_draft',
			'steps'        => $steps,
			'current_step' => 0,
			'captains'     => array(
				'team_a' => $captainA,
				'team_b' => $captainB,
			),
			'map_pool'     => $mapPool,
			'votes'        => array(),
			'started_at'   => time(),
			'best_of'      => $bestOf,
		);

		Logger::log('[PixelControl] VetoDraft tournament_draft session started (best_of=' . $bestOf . ').');

		return new CommunicationAnswer(array(
			'success' => true,
			'code'    => 'session_started',
			'message' => 'Tournament draft session started.',
			'details' => array(
				'mode'         => 'tournament_draft',
				'captain_a'    => $captainA,
				'captain_b'    => $captainB,
				'best_of'      => $bestOf,
				'total_steps'  => count($steps),
				'map_count'    => $mapCount,
			),
		), false);
	}

	/**
	 * Builds an alternating ban/pick step sequence for a tournament draft.
	 * Pattern: ban_a, ban_b, pick_a, pick_b, ... (depending on best_of)
	 *
	 * @param int $bestOf
	 * @return array
	 */
	private function buildTournamentSteps($bestOf) {
		$steps = array();
		// For simplicity: alternate bans first, then picks.
		// ban_a, ban_b (one round of bans), then pick_a, pick_b, ... for remaining picks.
		$pickCount = $bestOf;
		$banCount = 2; // always 2 bans (one per team) for standard competitive format.

		for ($i = 0; $i < $banCount; $i++) {
			$steps[] = array(
				'type' => 'ban',
				'team' => ($i % 2 === 0) ? 'team_a' : 'team_b',
			);
		}

		for ($i = 0; $i < $pickCount; $i++) {
			$steps[] = array(
				'type' => 'pick',
				'team' => ($i % 2 === 0) ? 'team_a' : 'team_b',
			);
		}

		return $steps;
	}

	/**
	 * Fetches current map pool from ManiaControl's MapManager.
	 * Returns an array of map UID strings.
	 *
	 * @return array
	 */
	private function fetchMapPool() {
		if (!$this->maniaControl || !$this->maniaControl->getMapManager()) {
			return array();
		}
		$maps = $this->maniaControl->getMapManager()->getMaps();
		if (!is_array($maps)) {
			return array();
		}
		$pool = array();
		foreach ($maps as $map) {
			if ($map && isset($map->uid)) {
				$pool[] = $map->uid;
			}
		}
		return $pool;
	}

	// ─── P4.4 -- Action ───────────────────────────────────────────────────────────

	/**
	 * Handles PixelControl.VetoDraft.Action.
	 * Validates link-auth. Validates active session. Records ban/pick/vote.
	 *
	 * @param mixed $data
	 * @return CommunicationAnswer
	 */
	public function handleVetoDraftAction($data) {
		// Validate link-auth.
		$authError = $this->validateLinkAuth($data);
		if ($authError !== null) {
			return new CommunicationAnswer($authError, false);
		}

		if ($this->vetoDraftSession === null || $this->vetoDraftSession['status'] !== 'running') {
			return new CommunicationAnswer(array(
				'success' => false,
				'code'    => 'session_not_running',
				'message' => 'No veto/draft session is currently running.',
			), false);
		}

		$parameters = isset($data->parameters) ? $data->parameters : null;
		$actorLogin = $this->vdRequireStringParam($parameters, 'actor_login');
		$mapUid     = $this->vdRequireStringParam($parameters, 'map');

		if ($actorLogin === null) {
			return new CommunicationAnswer(array(
				'success' => false,
				'code'    => 'invalid_parameter',
				'message' => 'Missing or empty actor_login parameter.',
			), false);
		}

		if ($mapUid === null) {
			return new CommunicationAnswer(array(
				'success' => false,
				'code'    => 'invalid_parameter',
				'message' => 'Missing or empty map parameter.',
			), false);
		}

		$session = &$this->vetoDraftSession;

		if ($session['mode'] === 'matchmaking_vote') {
			$answer = $this->applyMatchmakingVote($actorLogin, $mapUid, $parameters, $session);
		} else {
			$answer = $this->applyTournamentAction($actorLogin, $mapUid, $session);
		}

		// Push state snapshot after a successful action.
		if (isset($answer->data['success']) && $answer->data['success'] === true) {
			$this->pushStateAfterCommand();
		}

		return $answer;
	}

	/**
	 * Records a matchmaking vote for the given actor.
	 *
	 * @param string $actorLogin
	 * @param string $mapUid
	 * @param mixed  $parameters
	 * @param array  &$session
	 * @return CommunicationAnswer
	 */
	private function applyMatchmakingVote($actorLogin, $mapUid, $parameters, &$session) {
		// Check if map is in the pool.
		if (!in_array($mapUid, $session['map_pool'], true)) {
			return new CommunicationAnswer(array(
				'success' => false,
				'code'    => 'map_not_in_pool',
				'message' => "Map '{$mapUid}' is not in the current map pool.",
			), false);
		}

		// Check allow_override.
		$allowOverride = false;
		if ($parameters !== null) {
			if (is_object($parameters) && isset($parameters->allow_override)) {
				$allowOverride = (bool) $parameters->allow_override;
			} elseif (is_array($parameters) && isset($parameters['allow_override'])) {
				$allowOverride = (bool) $parameters['allow_override'];
			}
		}

		if (isset($this->vetoDraftVotes[$actorLogin]) && !$allowOverride) {
			return new CommunicationAnswer(array(
				'success' => false,
				'code'    => 'vote_already_cast',
				'message' => "Actor '{$actorLogin}' has already voted. Use allow_override=true to change vote.",
			), false);
		}

		$this->vetoDraftVotes[$actorLogin] = $mapUid;
		$session['votes'] = $this->vetoDraftVotes;

		return new CommunicationAnswer(array(
			'success' => true,
			'code'    => 'vote_recorded',
			'message' => "Vote recorded: {$actorLogin} voted for {$mapUid}.",
			'details' => array(
				'actor_login'  => $actorLogin,
				'map'          => $mapUid,
				'total_votes'  => count($this->vetoDraftVotes),
			),
		), false);
	}

	/**
	 * Applies a tournament ban or pick action.
	 *
	 * @param string $actorLogin
	 * @param string $mapUid
	 * @param array  &$session
	 * @return CommunicationAnswer
	 */
	private function applyTournamentAction($actorLogin, $mapUid, &$session) {
		$currentStepIdx = $session['current_step'];
		$steps = $session['steps'];

		if ($currentStepIdx >= count($steps)) {
			return new CommunicationAnswer(array(
				'success' => false,
				'code'    => 'session_complete',
				'message' => 'All draft steps have been completed.',
			), false);
		}

		$currentStep = $steps[$currentStepIdx];
		$expectedTeam = $currentStep['team'];
		$captains = $session['captains'];
		$expectedCaptain = isset($captains[$expectedTeam]) ? $captains[$expectedTeam] : null;

		if ($expectedCaptain === null || $actorLogin !== $expectedCaptain) {
			return new CommunicationAnswer(array(
				'success' => false,
				'code'    => 'wrong_actor',
				'message' => "It is {$expectedTeam}'s turn. Expected captain: " . ($expectedCaptain ?: 'unknown') . ".",
			), false);
		}

		// Check map is in pool.
		if (!in_array($mapUid, $session['map_pool'], true)) {
			return new CommunicationAnswer(array(
				'success' => false,
				'code'    => 'map_not_in_pool',
				'message' => "Map '{$mapUid}' is not in the current map pool.",
			), false);
		}

		// Record the action.
		$actionType = $currentStep['type'];
		$session['votes'][] = array(
			'step'   => $currentStepIdx,
			'type'   => $actionType,
			'team'   => $expectedTeam,
			'actor'  => $actorLogin,
			'map'    => $mapUid,
		);

		// Remove map from pool (for bans and picks alike -- once chosen it's off the board).
		$session['map_pool'] = array_values(array_filter($session['map_pool'], function ($uid) use ($mapUid) {
			return $uid !== $mapUid;
		}));

		// Advance step.
		$session['current_step'] = $currentStepIdx + 1;

		// Check if session is complete.
		$sessionComplete = $session['current_step'] >= count($steps);
		if ($sessionComplete) {
			$session['status'] = 'completed';
		}

		return new CommunicationAnswer(array(
			'success' => true,
			'code'    => $actionType . '_applied',
			'message' => ucfirst($actionType) . " applied: {$actorLogin} ({$expectedTeam}) chose {$mapUid}.",
			'details' => array(
				'step'        => $currentStepIdx,
				'type'        => $actionType,
				'team'        => $expectedTeam,
				'actor'       => $actorLogin,
				'map'         => $mapUid,
				'next_step'   => $session['current_step'],
				'completed'   => $sessionComplete,
			),
		), false);
	}

	// ─── P4.5 -- Cancel ───────────────────────────────────────────────────────────

	/**
	 * Handles PixelControl.VetoDraft.Cancel.
	 * Validates link-auth. Cancels the active session.
	 *
	 * @param mixed $data
	 * @return CommunicationAnswer
	 */
	public function handleVetoDraftCancel($data) {
		// Validate link-auth.
		$authError = $this->validateLinkAuth($data);
		if ($authError !== null) {
			return new CommunicationAnswer($authError, false);
		}

		if ($this->vetoDraftSession === null || $this->vetoDraftSession['status'] !== 'running') {
			return new CommunicationAnswer(array(
				'success' => false,
				'code'    => 'session_not_running',
				'message' => 'No veto/draft session is currently running.',
			), false);
		}

		$mode = $this->vetoDraftSession['mode'];
		$this->vetoDraftSession['status'] = 'cancelled';
		$this->vetoDraftSession = null;
		$this->vetoDraftVotes = array();
		$this->matchmakingReadyArmed = false;

		Logger::log('[PixelControl] VetoDraft session cancelled (mode=' . $mode . ').');
		$this->pushStateAfterCommand();

		return new CommunicationAnswer(array(
			'success' => true,
			'code'    => 'session_cancelled',
			'message' => 'The veto/draft session has been cancelled.',
			'details' => array('mode' => $mode),
		), false);
	}

	// ─── Private parameter helpers (VetoDraft-specific prefix to avoid conflicts) ─

	/**
	 * Extracts a non-empty string parameter from $parameters object or array.
	 *
	 * @param mixed  $parameters
	 * @param string $key
	 * @return string|null
	 */
	private function vdRequireStringParam($parameters, $key) {
		if ($parameters === null) {
			return null;
		}
		if (is_object($parameters) && isset($parameters->$key)) {
			$val = (string) $parameters->$key;
			return $val !== '' ? $val : null;
		}
		if (is_array($parameters) && isset($parameters[$key])) {
			$val = (string) $parameters[$key];
			return $val !== '' ? $val : null;
		}
		return null;
	}
}
