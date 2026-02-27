<?php

namespace PixelControl\Domain\Admin;

use ManiaControl\Admin\AuthenticationManager;
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

		if ($this->isServerLinkCommandAction($actionName)) {
			$result = $this->executeServerLinkCommand($actionName, $parameters, $player);
			if ($result->isSuccess()) {
				$this->maniaControl->getChat()->sendSuccess($result->getMessage(), $player);
				return;
			}

			if ($result->getCode() === 'admin_command_unauthorized') {
				$this->maniaControl->getAuthenticationManager()->sendNotAllowed($player);
				return;
			}

			$this->maniaControl->getChat()->sendError($result->getMessage(), $player);
			return;
		}

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
		$authorization = $this->resolveLinkedCommunicationAuthorization($requestPayload);
		if (!$authorization['authorized']) {
			return new CommunicationAnswer($authorization['response'], true);
		}

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
				'security_mode' => 'link_bearer',
			)
		);

		return new CommunicationAnswer($result->toArray(), !$result->isSuccess());
	}


	public function handleAdminControlCommunicationList($data) {
		$requestPayload = $this->normalizeCommunicationPayload($data);
		$authorization = $this->resolveLinkedCommunicationAuthorization($requestPayload);
		if (!$authorization['authorized']) {
			return new CommunicationAnswer($authorization['response'], true);
		}

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
					'authentication_mode' => 'link_bearer',
					'actor_login_required' => false,
					'permission_model' => 'linked_server_actorless',
					'required_fields' => array('server_login', 'auth.mode', 'auth.token'),
				),
			),
			'link' => $this->buildLinkStatusPayload(),
			'whitelist' => $this->buildWhitelistCapabilitySnapshot(),
			'vote_policy' => $this->getVotePolicySnapshot(),
			'team_control' => $this->buildTeamControlCapabilitySnapshot(),
			'series_targets' => $this->getSeriesControlSnapshot(),
			'actions' => AdminActionCatalog::getActionDefinitions(),
		);

		return new CommunicationAnswer($payload, false);
	}


	private function isServerLinkCommandAction($actionName) {
		$normalizedAction = strtolower(trim((string) $actionName));
		return ($normalizedAction === 'server.link.set' || $normalizedAction === 'server.link.status');
	}


	private function executeServerLinkCommand($actionName, array $parameters, Player $player) {
		if (!$this->isServerLinkCommandAuthorized($player)) {
			return AdminActionResult::failure(
				(string) $actionName,
				'admin_command_unauthorized',
				'Server link configuration requires super admin or master admin rights.'
			);
		}

		$normalizedAction = strtolower(trim((string) $actionName));
		if ($normalizedAction === 'server.link.status') {
			return $this->executeServerLinkStatusCommand();
		}

		if ($normalizedAction === 'server.link.set') {
			return $this->executeServerLinkSetCommand($parameters);
		}

		return AdminActionResult::failure(
			$normalizedAction,
			'invalid_parameters',
			'Unknown server link command. Use //'.$this->adminControlCommandName.' server.link.status.'
		);
	}


	private function isServerLinkCommandAuthorized(Player $player) {
		return AuthenticationManager::checkRight($player, AuthenticationManager::AUTH_LEVEL_SUPERADMIN);
	}


	private function executeServerLinkSetCommand(array $parameters) {
		$baseUrl = isset($parameters['base_url']) ? trim((string) $parameters['base_url']) : '';
		$linkToken = isset($parameters['link_token']) ? trim((string) $parameters['link_token']) : '';

		if ($baseUrl === '' || $linkToken === '') {
			return AdminActionResult::failure(
				'server.link.set',
				'missing_parameters',
				'Missing parameters. Usage: //'.$this->adminControlCommandName.' server.link.set base_url=<url> link_token=<token>.'
			);
		}

		$normalizedBaseUrl = $this->normalizeServerLinkBaseUrl($baseUrl);
		if ($normalizedBaseUrl === '') {
			return AdminActionResult::failure(
				'server.link.set',
				'invalid_parameters',
				'Invalid base_url. Only absolute http/https URLs are accepted.'
			);
		}

		$settingManager = $this->maniaControl->getSettingManager();
		$baseUrlWritten = $settingManager->setSetting($this, self::SETTING_LINK_SERVER_URL, $normalizedBaseUrl);
		$tokenWritten = $settingManager->setSetting($this, self::SETTING_LINK_TOKEN, $linkToken);

		if (!$baseUrlWritten || !$tokenWritten) {
			return AdminActionResult::failure(
				'server.link.set',
				'setting_write_failed',
				'Unable to persist server link settings.'
			);
		}

		$this->applyRuntimeLinkTransportOverrides($normalizedBaseUrl, $linkToken);

		$linkSnapshot = $this->resolveLinkConfigurationSnapshot(false);
		return AdminActionResult::success(
			'server.link.set',
			'Server link updated. base_url=' . $linkSnapshot['base_url']
			. ', token_fingerprint=' . $linkSnapshot['token_fingerprint_masked']
			. ', token_source=' . $linkSnapshot['token_source']
			. '.'
		);
	}


	private function executeServerLinkStatusCommand() {
		$linkSnapshot = $this->resolveLinkConfigurationSnapshot(false);
		$status = $linkSnapshot['linked'] ? 'linked' : 'not_linked';

		return AdminActionResult::success(
			'server.link.status',
			'Server link status: ' . $status
			. ', base_url=' . ($linkSnapshot['base_url'] !== '' ? $linkSnapshot['base_url'] : '<unset>')
			. ', token_fingerprint=' . $linkSnapshot['token_fingerprint_masked']
			. ', base_url_source=' . $linkSnapshot['base_url_source']
			. ', token_source=' . $linkSnapshot['token_source']
			. '.'
		);
	}


	private function normalizeServerLinkBaseUrl($baseUrl) {
		$trimmedBaseUrl = trim((string) $baseUrl);
		if ($trimmedBaseUrl === '') {
			return '';
		}

		$validatedUrl = filter_var($trimmedBaseUrl, FILTER_VALIDATE_URL);
		if (!is_string($validatedUrl) || $validatedUrl === '') {
			return '';
		}

		$parsedUrl = parse_url($validatedUrl);
		if (!is_array($parsedUrl) || !isset($parsedUrl['scheme'])) {
			return '';
		}

		$scheme = strtolower(trim((string) $parsedUrl['scheme']));
		if ($scheme !== 'http' && $scheme !== 'https') {
			return '';
		}

		return rtrim($validatedUrl, '/');
	}


	private function applyRuntimeLinkTransportOverrides($baseUrl, $linkToken) {
		if (!$this->apiClient) {
			return;
		}

		if ($baseUrl !== '' && method_exists($this->apiClient, 'setBaseUrl')) {
			$this->apiClient->setBaseUrl($baseUrl);
		}

		if ($linkToken !== '' && method_exists($this->apiClient, 'setAuthMode') && method_exists($this->apiClient, 'setAuthValue')) {
			$this->apiClient->setAuthMode('bearer');
			$this->apiClient->setAuthValue($linkToken);
		}
	}


	private function buildLinkStatusPayload() {
		$linkSnapshot = $this->resolveLinkConfigurationSnapshot(false);

		return array(
			'linked' => $linkSnapshot['linked'],
			'base_url' => $linkSnapshot['base_url'],
			'base_url_source' => $linkSnapshot['base_url_source'],
			'token_source' => $linkSnapshot['token_source'],
			'token_fingerprint' => $linkSnapshot['token_fingerprint_masked'],
		);
	}


	private function resolveLinkConfigurationSnapshot($includeSecretToken) {
		$baseUrl = $this->resolveRuntimeStringSetting(self::SETTING_LINK_SERVER_URL, 'PIXEL_CONTROL_LINK_SERVER_URL', '');
		$linkToken = $this->resolveRuntimeStringSetting(self::SETTING_LINK_TOKEN, 'PIXEL_CONTROL_LINK_TOKEN', '');
		$tokenFingerprint = $this->buildLinkTokenFingerprint($linkToken);
		$baseUrlSource = $this->hasRuntimeEnvValue('PIXEL_CONTROL_LINK_SERVER_URL') ? 'env' : 'setting';
		$tokenSource = $this->hasRuntimeEnvValue('PIXEL_CONTROL_LINK_TOKEN') ? 'env' : 'setting';

		$snapshot = array(
			'linked' => ($baseUrl !== '' && $linkToken !== ''),
			'base_url' => $baseUrl,
			'base_url_source' => $baseUrlSource,
			'token_source' => $tokenSource,
			'token_fingerprint' => $tokenFingerprint,
			'token_fingerprint_masked' => $this->maskLinkTokenFingerprint($tokenFingerprint),
		);

		if ($includeSecretToken) {
			$snapshot['link_token'] = $linkToken;
		}

		return $snapshot;
	}


	private function buildLinkTokenFingerprint($linkToken) {
		$normalizedToken = trim((string) $linkToken);
		if ($normalizedToken === '') {
			return '';
		}

		return hash('sha256', $normalizedToken);
	}


	private function maskLinkTokenFingerprint($tokenFingerprint) {
		$normalizedFingerprint = trim((string) $tokenFingerprint);
		if ($normalizedFingerprint === '') {
			return 'not_set';
		}

		if (strlen($normalizedFingerprint) <= 12) {
			return $normalizedFingerprint;
		}

		return substr($normalizedFingerprint, 0, 8) . '...' . substr($normalizedFingerprint, -4);
	}


	private function resolveLinkedCommunicationAuthorization(array $requestPayload) {
		$linkSnapshot = $this->resolveLinkConfigurationSnapshot(true);
		if (!$linkSnapshot['linked']) {
			return array(
				'authorized' => false,
				'response' => $this->buildLinkedCommunicationRejection(
					'admin_command_unauthorized',
					'Server link is not configured. Configure link credentials via //'.$this->adminControlCommandName.' server.link.set.',
					array('reason' => 'server_not_linked')
				),
			);
		}

		$expectedServerLogin = $this->resolveLocalServerLoginForLinkAuth();
		if ($expectedServerLogin === '') {
			return array(
				'authorized' => false,
				'response' => $this->buildLinkedCommunicationRejection(
					'admin_command_unauthorized',
					'Local server identity is unavailable for link authorization.',
					array('reason' => 'local_server_login_unavailable')
				),
			);
		}

		$providedServerLogin = '';
		if (isset($requestPayload['server_login'])) {
			$providedServerLogin = strtolower(trim((string) $requestPayload['server_login']));
		}

		$authMode = '';
		$authToken = '';
		if (isset($requestPayload['auth']) && is_array($requestPayload['auth'])) {
			if (isset($requestPayload['auth']['mode'])) {
				$authMode = strtolower(trim((string) $requestPayload['auth']['mode']));
			}
			if (isset($requestPayload['auth']['token'])) {
				$authToken = trim((string) $requestPayload['auth']['token']);
			}
		}

		if ($providedServerLogin === '' || $authMode === '' || $authToken === '') {
			return array(
				'authorized' => false,
				'response' => $this->buildLinkedCommunicationRejection(
					'link_auth_missing',
					'Missing link authentication payload fields (server_login, auth.mode, auth.token).'
				),
			);
		}

		if ($authMode !== 'link_bearer') {
			return array(
				'authorized' => false,
				'response' => $this->buildLinkedCommunicationRejection(
					'link_auth_invalid',
					'Invalid auth.mode. Expected link_bearer.'
				),
			);
		}

		if ($providedServerLogin !== $expectedServerLogin) {
			return array(
				'authorized' => false,
				'response' => $this->buildLinkedCommunicationRejection(
					'link_server_mismatch',
					'Provided server_login does not match local plugin server identity.',
					array(
						'provided_server_login' => $providedServerLogin,
						'expected_server_login' => $expectedServerLogin,
					)
				),
			);
		}

		$expectedLinkToken = isset($linkSnapshot['link_token']) ? (string) $linkSnapshot['link_token'] : '';
		if ($expectedLinkToken === '' || !hash_equals($expectedLinkToken, $authToken)) {
			return array(
				'authorized' => false,
				'response' => $this->buildLinkedCommunicationRejection(
					'link_auth_invalid',
					'Provided link bearer token is invalid.'
				),
			);
		}

		return array('authorized' => true, 'response' => array());
	}


	private function buildLinkedCommunicationRejection($code, $message, array $details = array()) {
		return array(
			'action_name' => 'server_scoped_admin',
			'success' => false,
			'code' => trim((string) $code),
			'message' => trim((string) $message),
			'details' => $details,
		);
	}


	private function resolveLocalServerLoginForLinkAuth() {
		if (!$this->maniaControl || !$this->maniaControl->getServer()) {
			return '';
		}

		$serverLogin = '';
		$server = $this->maniaControl->getServer();
		if (isset($server->login)) {
			$serverLogin = trim((string) $server->login);
		}

		return strtolower($serverLogin);
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
		$this->maniaControl->getChat()->sendInformation(
			'Server link commands (super/master admin only): server.link.set base_url=<url> link_token=<token>, server.link.status',
			$player
		);

		$familyLines = $this->buildAdminActionHelpFamilyLines($actionDefinitions);
		foreach ($familyLines as $familyLine) {
			$this->maniaControl->getChat()->sendInformation($familyLine, $player);
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


	private function buildAdminActionHelpFamilyLines(array $actionDefinitions) {
		$families = array();
		$actionNames = array_keys($actionDefinitions);
		sort($actionNames);

		foreach ($actionNames as $actionName) {
			$actionDefinition = (isset($actionDefinitions[$actionName]) && is_array($actionDefinitions[$actionName]))
				? $actionDefinitions[$actionName]
				: array();
			$familyKey = $this->buildAdminActionHelpFamilyKey($actionName);
			if (!isset($families[$familyKey])) {
				$families[$familyKey] = array();
			}

			$families[$familyKey][] = array(
				'label' => $this->buildAdminActionHelpActionLabel($actionName),
				'required_parameters' => $this->extractAdminActionHelpParameters($actionDefinition, 'required_parameters'),
				'optional_parameters' => $this->extractAdminActionHelpParameters($actionDefinition, 'optional_parameters'),
			);
		}

		ksort($families);

		$familyLines = array();
		foreach ($families as $familyKey => $familyActions) {
			usort($familyActions, function ($left, $right) {
				$leftLabel = isset($left['label']) ? trim((string) $left['label']) : '';
				$rightLabel = isset($right['label']) ? trim((string) $right['label']) : '';
				return strcmp($leftLabel, $rightLabel);
			});

			$familyLines[] = $this->formatAdminActionFamilyHelpLine($familyKey, $familyActions);
		}

		return $familyLines;
	}


	private function buildAdminActionHelpFamilyKey($actionName) {
		$normalizedActionName = strtolower(trim((string) $actionName));
		if ($normalizedActionName === '') {
			return 'unknown';
		}

		$segments = explode('.', $normalizedActionName);
		if (count($segments) <= 1) {
			return $normalizedActionName;
		}

		array_pop($segments);
		$familyKey = implode('.', $segments);
		if ($familyKey !== '') {
			return $familyKey;
		}

		return $normalizedActionName;
	}


	private function buildAdminActionHelpActionLabel($actionName) {
		$normalizedActionName = strtolower(trim((string) $actionName));
		if ($normalizedActionName === '') {
			return 'unknown';
		}

		$segments = explode('.', $normalizedActionName);
		$actionLabel = array_pop($segments);
		if (is_string($actionLabel) && trim($actionLabel) !== '') {
			return trim($actionLabel);
		}

		return $normalizedActionName;
	}


	private function formatAdminActionFamilyHelpLine($familyKey, array $familyActions) {
		$actionSegments = array();
		foreach ($familyActions as $familyAction) {
			$actionSegments[] = $this->formatAdminActionHelpActionLabel(
				(isset($familyAction['label']) ? (string) $familyAction['label'] : ''),
				(isset($familyAction['required_parameters']) && is_array($familyAction['required_parameters']))
					? $familyAction['required_parameters']
					: array(),
				(isset($familyAction['optional_parameters']) && is_array($familyAction['optional_parameters']))
					? $familyAction['optional_parameters']
					: array()
			);
		}

		$normalizedFamilyKey = trim((string) $familyKey);
		if ($normalizedFamilyKey === '') {
			$normalizedFamilyKey = 'unknown';
		}

		return '- ' . $normalizedFamilyKey . ': ' . implode(' | ', $actionSegments);
	}


	private function formatAdminActionHelpActionLabel($actionLabel, array $requiredParameters, array $optionalParameters) {
		$normalizedActionLabel = trim((string) $actionLabel);
		if ($normalizedActionLabel === '') {
			$normalizedActionLabel = 'unknown';
		}

		$parameterHint = $this->formatAdminActionHelpParameterHint($requiredParameters, $optionalParameters);
		if ($parameterHint === '') {
			return $normalizedActionLabel;
		}

		return $normalizedActionLabel . $parameterHint;
	}


	private function formatAdminActionHelpParameterHint(array $requiredParameters, array $optionalParameters) {
		$segments = array();
		if (!empty($requiredParameters)) {
			$segments[] = 'req:' . implode(',', $requiredParameters);
		}
		if (!empty($optionalParameters)) {
			$segments[] = 'opt:' . implode(',', $optionalParameters);
		}

		if (empty($segments)) {
			return '';
		}

		return '(' . implode(';', $segments) . ')';
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
					'authentication_mode' => 'link_bearer',
					'actor_login_required' => false,
					'permission_model' => 'linked_server_actorless',
					'required_fields' => array('server_login', 'auth.mode', 'auth.token'),
				),
			),
			'link' => $this->buildLinkStatusPayload(),
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
