<?php

namespace PixelControl\Domain\Admin;

use ManiaControl\Communication\CommunicationAnswer;
use ManiaControl\Players\Player;
use PixelControl\Admin\AdminActionCatalog;
use PixelControl\Admin\AdminActionResult;

trait AdminControlIngressTrait {

	public function handleAdminControlCommand(array $chatCallback, Player $player) {
		if (!$this->adminControlEnabled) {
			$this->maniaControl->getChat()->sendError('Pixel admin control surface is disabled.', $player);
			return;
		}

		$commandRequest = $this->parseAdminControlCommandRequest($chatCallback);
		$actionName = $commandRequest['action_name'];
		$parameters = $commandRequest['parameters'];

		if ($actionName === '' || $actionName === 'help' || $actionName === 'list') {
			$this->sendAdminControlHelp($player);
			return;
		}

		$result = $this->executeDelegatedAdminAction(
			$actionName,
			$parameters,
			(isset($player->login) ? (string) $player->login : ''),
			'chat_command',
			$player
		);

		if ($result->isSuccess()) {
			$this->maniaControl->getChat()->sendSuccess($result->getMessage(), $player);
			return;
		}

		if ($result->getCode() === 'permission_denied') {
			$this->maniaControl->getAuthenticationManager()->sendNotAllowed($player);
			return;
		}

		$this->maniaControl->getChat()->sendError($result->getMessage(), $player);
	}


	public function handleAdminControlCommunicationExecute($data) {
		if (!$this->adminControlEnabled) {
			$result = AdminActionResult::failure('unknown', 'feature_disabled', 'Pixel admin control surface is disabled.');
			return new CommunicationAnswer($result->toArray(), true);
		}

		$requestPayload = $this->normalizeCommunicationPayload($data);
		$actionName = isset($requestPayload['action']) ? (string) $requestPayload['action'] : '';
		if ($actionName === '') {
			$actionName = isset($requestPayload['action_name']) ? (string) $requestPayload['action_name'] : '';
		}

		$parameters = array();
		if (isset($requestPayload['parameters']) && is_array($requestPayload['parameters'])) {
			$parameters = $requestPayload['parameters'];
		}

		$actorLogin = '';
		if (isset($requestPayload['actor_login'])) {
			$actorLogin = trim((string) $requestPayload['actor_login']);
		}

		$result = $this->executeDelegatedAdminAction(
			$actionName,
			$parameters,
			$actorLogin,
			'communication',
			null,
			array(
				'allow_actorless' => true,
				'skip_permission_checks' => true,
				'security_mode' => 'payload_untrusted',
			)
		);

		return new CommunicationAnswer($result->toArray(), !$result->isSuccess());
	}


	public function handleAdminControlCommunicationList($data) {
		$payload = array(
			'enabled' => $this->adminControlEnabled,
			'command' => $this->adminControlCommandName,
			'communication' => array(
				'exec' => AdminActionCatalog::COMMUNICATION_EXECUTE_ACTION,
				'list' => AdminActionCatalog::COMMUNICATION_LIST_ACTIONS,
			),
			'security' => array(
				'chat_command' => array(
					'actor_login_required' => true,
					'permission_model' => 'maniacontrol_plugin_rights',
				),
				'communication' => array(
					'authentication_mode' => 'none_temporary',
					'actor_login_required' => false,
					'permission_model' => 'trusted_payload_no_actor',
				),
			),
			'whitelist' => $this->buildWhitelistCapabilitySnapshot(),
			'vote_policy' => $this->getVotePolicySnapshot(),
			'team_control' => $this->buildTeamControlCapabilitySnapshot(),
			'series_targets' => $this->getSeriesControlSnapshot(),
			'actions' => AdminActionCatalog::getActionDefinitions(),
		);

		return new CommunicationAnswer($payload, false);
	}


	private function parseAdminControlCommandRequest(array $chatCallback) {
		$commandText = '';
		if (isset($chatCallback[1]) && is_array($chatCallback[1]) && isset($chatCallback[1][2])) {
			$commandText = trim((string) $chatCallback[1][2]);
		}

		if ($commandText === '') {
			return array('action_name' => '', 'parameters' => array());
		}

		$tokens = preg_split('/\s+/', $commandText);
		if (empty($tokens)) {
			return array('action_name' => '', 'parameters' => array());
		}

		array_shift($tokens);
		if (empty($tokens)) {
			return array('action_name' => '', 'parameters' => array());
		}

		$actionName = AdminActionCatalog::normalizeActionName(array_shift($tokens));
		$parameters = $this->parseArgumentTokens($tokens);

		return array(
			'action_name' => $actionName,
			'parameters' => $parameters,
		);
	}


	private function parseArgumentTokens(array $tokens) {
		$arguments = array();
		$positionals = array();

		foreach ($tokens as $token) {
			$trimmedToken = trim((string) $token);
			if ($trimmedToken === '') {
				continue;
			}

			if (strpos($trimmedToken, '=') === false) {
				$positionals[] = $trimmedToken;
				continue;
			}

			$parts = explode('=', $trimmedToken, 2);
			$key = $this->normalizeIdentifier($parts[0], '');
			if ($key === '') {
				continue;
			}

			$arguments[$key] = isset($parts[1]) ? trim((string) $parts[1]) : '';
		}

		if (!empty($positionals)) {
			$arguments['_positionals'] = $positionals;
		}

		return $arguments;
	}


	private function normalizeActionParameters($actionName, array $parameters) {
		$normalizedParameters = $parameters;
		$positionals = array();
		if (isset($normalizedParameters['_positionals']) && is_array($normalizedParameters['_positionals'])) {
			$positionals = array_values($normalizedParameters['_positionals']);
		}
		unset($normalizedParameters['_positionals']);

		switch ($actionName) {
			case AdminActionCatalog::ACTION_MAP_JUMP:
			case AdminActionCatalog::ACTION_MAP_QUEUE:
				if (!isset($normalizedParameters['map_uid']) && !empty($positionals)) {
					$normalizedParameters['map_uid'] = $positionals[0];
				}
			break;
			case AdminActionCatalog::ACTION_MAP_ADD:
				if (!isset($normalizedParameters['mx_id']) && !empty($positionals)) {
					$normalizedParameters['mx_id'] = $positionals[0];
				}
			break;
			case AdminActionCatalog::ACTION_MAP_REMOVE:
				if (!isset($normalizedParameters['map_uid']) && !empty($positionals)) {
					$normalizedParameters['map_uid'] = $positionals[0];
				}
			break;
			case AdminActionCatalog::ACTION_WARMUP_EXTEND:
				if (!isset($normalizedParameters['seconds']) && !empty($positionals)) {
					$normalizedParameters['seconds'] = $positionals[0];
				}
			break;
			case AdminActionCatalog::ACTION_VOTE_SET_RATIO:
				if (!isset($normalizedParameters['command']) && !empty($positionals)) {
					$normalizedParameters['command'] = $positionals[0];
				}
				if (!isset($normalizedParameters['ratio']) && count($positionals) > 1) {
					$normalizedParameters['ratio'] = $positionals[1];
				}
			break;
			case AdminActionCatalog::ACTION_PLAYER_FORCE_TEAM:
				if (!isset($normalizedParameters['target_login']) && !empty($positionals)) {
					$normalizedParameters['target_login'] = $positionals[0];
				}
				if (!isset($normalizedParameters['team']) && count($positionals) > 1) {
					$normalizedParameters['team'] = $positionals[1];
				}
			break;
			case AdminActionCatalog::ACTION_PLAYER_FORCE_PLAY:
			case AdminActionCatalog::ACTION_PLAYER_FORCE_SPEC:
			case AdminActionCatalog::ACTION_AUTH_REVOKE:
				if (!isset($normalizedParameters['target_login']) && !empty($positionals)) {
					$normalizedParameters['target_login'] = $positionals[0];
				}
			break;
			case AdminActionCatalog::ACTION_AUTH_GRANT:
				if (!isset($normalizedParameters['target_login']) && !empty($positionals)) {
					$normalizedParameters['target_login'] = $positionals[0];
				}
				if (!isset($normalizedParameters['auth_level']) && count($positionals) > 1) {
					$normalizedParameters['auth_level'] = $positionals[1];
				}
			break;
			case AdminActionCatalog::ACTION_VOTE_CUSTOM_START:
				if (!isset($normalizedParameters['vote_index']) && !empty($positionals)) {
					$normalizedParameters['vote_index'] = $positionals[0];
				}
			break;
			case AdminActionCatalog::ACTION_WHITELIST_ADD:
			case AdminActionCatalog::ACTION_WHITELIST_REMOVE:
				if (!isset($normalizedParameters['target_login']) && !empty($positionals)) {
					$normalizedParameters['target_login'] = $positionals[0];
				}
			break;
			case AdminActionCatalog::ACTION_VOTE_POLICY_SET:
				if (!isset($normalizedParameters['mode']) && !empty($positionals)) {
					$normalizedParameters['mode'] = $positionals[0];
				}
			break;
			case AdminActionCatalog::ACTION_TEAM_POLICY_SET:
				if (!isset($normalizedParameters['enabled']) && !empty($positionals)) {
					$normalizedParameters['enabled'] = $positionals[0];
				}
				if (!isset($normalizedParameters['switch_lock']) && count($positionals) > 1) {
					$normalizedParameters['switch_lock'] = $positionals[1];
				}
			break;
			case AdminActionCatalog::ACTION_TEAM_ROSTER_ASSIGN:
				if (!isset($normalizedParameters['target_login']) && !empty($positionals)) {
					$normalizedParameters['target_login'] = $positionals[0];
				}
				if (!isset($normalizedParameters['team']) && count($positionals) > 1) {
					$normalizedParameters['team'] = $positionals[1];
				}

				if (isset($normalizedParameters['target_team']) && !isset($normalizedParameters['team'])) {
					$normalizedParameters['team'] = $normalizedParameters['target_team'];
				}
			break;
			case AdminActionCatalog::ACTION_TEAM_ROSTER_UNASSIGN:
				if (!isset($normalizedParameters['target_login']) && !empty($positionals)) {
					$normalizedParameters['target_login'] = $positionals[0];
				}
			break;
			case AdminActionCatalog::ACTION_MATCH_BO_SET:
				if (!isset($normalizedParameters['best_of']) && !empty($positionals)) {
					$normalizedParameters['best_of'] = $positionals[0];
				}
				if (isset($normalizedParameters['bo']) && !isset($normalizedParameters['best_of'])) {
					$normalizedParameters['best_of'] = $normalizedParameters['bo'];
				}
			break;
			case AdminActionCatalog::ACTION_MATCH_MAPS_SET:
				if (!isset($normalizedParameters['target_team']) && !empty($positionals)) {
					$normalizedParameters['target_team'] = $positionals[0];
				}
				if (!isset($normalizedParameters['maps_score']) && count($positionals) > 1) {
					$normalizedParameters['maps_score'] = $positionals[1];
				}

				if (isset($normalizedParameters['team']) && !isset($normalizedParameters['target_team'])) {
					$normalizedParameters['target_team'] = $normalizedParameters['team'];
				}
				if (isset($normalizedParameters['target']) && !isset($normalizedParameters['target_team'])) {
					$normalizedParameters['target_team'] = $normalizedParameters['target'];
				}
				if (isset($normalizedParameters['score']) && !isset($normalizedParameters['maps_score'])) {
					$normalizedParameters['maps_score'] = $normalizedParameters['score'];
				}
			break;
			case AdminActionCatalog::ACTION_MATCH_SCORE_SET:
				if (!isset($normalizedParameters['target_team']) && !empty($positionals)) {
					$normalizedParameters['target_team'] = $positionals[0];
				}
				if (!isset($normalizedParameters['score']) && count($positionals) > 1) {
					$normalizedParameters['score'] = $positionals[1];
				}

				if (isset($normalizedParameters['team']) && !isset($normalizedParameters['target_team'])) {
					$normalizedParameters['target_team'] = $normalizedParameters['team'];
				}
				if (isset($normalizedParameters['target']) && !isset($normalizedParameters['target_team'])) {
					$normalizedParameters['target_team'] = $normalizedParameters['target'];
				}
			break;
		}

		return $normalizedParameters;
	}


	private function resolveActionActor($actorLogin, $requestActor = null) {
		if ($requestActor instanceof Player) {
			return $requestActor;
		}

		$normalizedActorLogin = trim((string) $actorLogin);
		if ($normalizedActorLogin === '') {
			return null;
		}

		return $this->maniaControl->getPlayerManager()->getPlayer($normalizedActorLogin);
	}


	private function normalizeCommunicationPayload($data) {
		if (is_array($data)) {
			return $data;
		}

		if (is_object($data)) {
			$encoded = json_encode($data);
			if (is_string($encoded)) {
				$decoded = json_decode($encoded, true);
				if (is_array($decoded)) {
					return $decoded;
				}
			}
		}

		return array();
	}


	private function sendAdminControlHelp(Player $player) {
		$actionDefinitions = AdminActionCatalog::getActionDefinitions();
		$actionNames = array_keys($actionDefinitions);
		sort($actionNames);

		$this->maniaControl->getChat()->sendInformation(
			'Pixel delegated admin actions (' . count($actionNames) . ').',
			$player
		);
		$this->maniaControl->getChat()->sendInformation(
			'Usage: //' . $this->adminControlCommandName . ' <action> key=value ...',
			$player
		);

		foreach ($actionNames as $actionName) {
			$definition = (isset($actionDefinitions[$actionName]) && is_array($actionDefinitions[$actionName]))
				? $actionDefinitions[$actionName]
				: array();
			$requiredParameters = $this->extractAdminActionHelpParameters($definition, 'required_parameters');
			$optionalParameters = $this->extractAdminActionHelpParameters($definition, 'optional_parameters');

			$this->maniaControl->getChat()->sendInformation(
				$this->formatAdminActionHelpLine($actionName, $requiredParameters, $optionalParameters),
				$player
			);
		}
	}


	private function extractAdminActionHelpParameters(array $actionDefinition, $fieldName) {
		if (!isset($actionDefinition[$fieldName]) || !is_array($actionDefinition[$fieldName])) {
			return array();
		}

		$parameters = array();
		foreach ($actionDefinition[$fieldName] as $parameterName) {
			$normalizedParameterName = trim((string) $parameterName);
			if ($normalizedParameterName === '') {
				continue;
			}

			$parameters[] = $normalizedParameterName;
		}

		return $parameters;
	}


	private function formatAdminActionHelpLine($actionName, array $requiredParameters, array $optionalParameters) {
		$segments = array('- ' . $actionName);
		$requiredPart = $this->formatAdminActionHelpParameterGroup($requiredParameters, 'required');
		$optionalPart = $this->formatAdminActionHelpParameterGroup($optionalParameters, 'optional');
		if ($requiredPart !== '') {
			$segments[] = $requiredPart;
		}
		if ($optionalPart !== '') {
			$segments[] = $optionalPart;
		}

		return implode(' | ', $segments);
	}


	private function formatAdminActionHelpParameterGroup(array $parameters, $label) {
		if (empty($parameters)) {
			return '';
		}

		return $label . ': ' . implode(', ', $parameters);
	}


	private function observePauseStateFromLifecycle($variant, array $callbackArguments) {
		if ($variant === 'pause.start') {
			$this->adminControlPauseActive = true;
			$this->adminControlPauseObservedAt = time();
			return;
		}

		if ($variant === 'pause.end') {
			$this->adminControlPauseActive = false;
			$this->adminControlPauseObservedAt = time();
			return;
		}

		if ($variant !== 'pause.status') {
			return;
		}

		$scriptPayload = $this->extractScriptCallbackPayload($callbackArguments);
		$active = $this->extractBooleanPayloadValue($scriptPayload, array('active'));
		if ($active === null) {
			return;
		}

		$this->adminControlPauseActive = $active;
		$this->adminControlPauseObservedAt = time();
	}


	private function rememberPauseStateAfterAction($actionName, array $parameters) {
		if ($actionName === AdminActionCatalog::ACTION_PAUSE_START) {
			$this->adminControlPauseActive = true;
			$this->adminControlPauseObservedAt = time();
			return;
		}

		if ($actionName === AdminActionCatalog::ACTION_PAUSE_END) {
			$this->adminControlPauseActive = false;
			$this->adminControlPauseObservedAt = time();
			return;
		}
	}


	private function buildAdminControlCapabilitiesPayload() {
		$actionDefinitions = AdminActionCatalog::getActionDefinitions();
		$actionNames = array_keys($actionDefinitions);
		sort($actionNames);

		return array(
			'available' => true,
			'enabled' => $this->adminControlEnabled,
			'command' => $this->adminControlCommandName,
			'pause_state_ttl_seconds' => $this->adminControlPauseStateMaxAgeSeconds,
			'communication' => array(
				'execute_action' => AdminActionCatalog::COMMUNICATION_EXECUTE_ACTION,
				'list_actions' => AdminActionCatalog::COMMUNICATION_LIST_ACTIONS,
			),
			'security' => array(
				'chat_command' => array(
					'actor_login_required' => true,
					'permission_model' => 'maniacontrol_plugin_rights',
				),
				'communication' => array(
					'authentication_mode' => 'none_temporary',
					'actor_login_required' => false,
					'permission_model' => 'trusted_payload_no_actor',
				),
			),
			'actions' => $actionNames,
			'whitelist' => $this->buildWhitelistCapabilitySnapshot(),
			'vote_policy' => $this->getVotePolicySnapshot(),
			'team_control' => $this->buildTeamControlCapabilitySnapshot(),
			'series_targets' => $this->getSeriesControlSnapshot(),
			'ownership_boundary' => array(
				'telemetry_transport' => 'pixel_plugin',
				'admin_execution' => 'native_maniacontrol',
			),
		);
	}

}
