<?php

namespace PixelControl\Callbacks;

use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Callbacks\CallbackManager;
use ManiaControl\Callbacks\Callbacks;
use ManiaControl\ManiaControl;
use ManiaControl\Players\PlayerManager;

class CallbackRegistry {
	/** @var string[] $lifecycleCallbacks */
	private static $lifecycleCallbacks = array(
		CallbackManager::CB_MP_BEGINMATCH,
		CallbackManager::CB_MP_ENDMATCH,
		CallbackManager::CB_MP_BEGINMAP,
		CallbackManager::CB_MP_ENDMAP,
		CallbackManager::CB_MP_BEGINROUND,
		CallbackManager::CB_MP_ENDROUND,
	);

	/** @var string[] $lifecycleScriptCallbacks */
	private static $lifecycleScriptCallbacks = array(
		Callbacks::MP_WARMUP_START,
		Callbacks::MP_WARMUP_END,
		Callbacks::MP_WARMUP_STATUS,
		Callbacks::MP_STARTMATCHSTART,
		Callbacks::MP_STARTMATCHEND,
		Callbacks::MP_ENDMATCHSTART,
		Callbacks::MP_ENDMATCHEND,
		Callbacks::MP_LOADINGMAPSTART,
		Callbacks::MP_LOADINGMAPEND,
		Callbacks::MP_UNLOADINGMAPSTART,
		Callbacks::MP_UNLOADINGMAPEND,
		Callbacks::MP_STARTROUNDSTART,
		Callbacks::MP_STARTROUNDEND,
		Callbacks::MP_ENDROUNDSTART,
		Callbacks::MP_ENDROUNDEND,
	);

	/** @var string[] $playerCallbacks */
	private static $playerCallbacks = array(
		PlayerManager::CB_PLAYERCONNECT,
		PlayerManager::CB_PLAYERDISCONNECT,
		PlayerManager::CB_PLAYERINFOCHANGED,
		PlayerManager::CB_PLAYERINFOSCHANGED,
	);

	/** @var string[] $combatCallbacks */
	private static $combatCallbacks = array(
		Callbacks::SM_ONSHOOT,
		Callbacks::SM_ONHIT,
		Callbacks::SM_ONNEARMISS,
		Callbacks::SM_ONARMOREMPTY,
		Callbacks::SM_ONCAPTURE,
		Callbacks::SM_SCORES,
	);

	/** @var array $modeCallbacks */
	private static $modeCallbacks = array(
		'elite' => array(
			Callbacks::SM_ELITE_STARTTURN,
			Callbacks::SM_ELITE_ENDTURN,
		),
		'joust' => array(
			Callbacks::SM_JOUST_ONRELOAD,
			Callbacks::SM_JOUST_SELECTEDPLAYERS,
			Callbacks::SM_JOUST_ROUNDRESULT,
		),
		'royal' => array(
			Callbacks::SM_ROYAL_POINTS,
			Callbacks::SM_ROYAL_PLAYERSPAWN,
			Callbacks::SM_ROYAL_ROUNDWINNER,
		),
	);

	public function register(ManiaControl $maniaControl, CallbackListener $listener) {
		$callbackManager = $maniaControl->getCallbackManager();

		foreach (self::$lifecycleCallbacks as $callbackName) {
			$callbackManager->registerCallbackListener($callbackName, $listener, 'handleLifecycleCallback');
		}

		foreach (self::$lifecycleScriptCallbacks as $callbackName) {
			$callbackManager->registerScriptCallbackListener($callbackName, $listener, 'handleLifecycleCallback');
		}

		foreach (self::$playerCallbacks as $callbackName) {
			$callbackManager->registerCallbackListener($callbackName, $listener, 'handlePlayerCallback');
		}

		foreach (self::$combatCallbacks as $callbackName) {
			$callbackManager->registerCallbackListener($callbackName, $listener, 'handleCombatCallback');
		}

		foreach (self::$modeCallbacks as $callbackNames) {
			foreach ($callbackNames as $callbackName) {
				$callbackManager->registerCallbackListener($callbackName, $listener, 'handleModeCallback');
			}
		}
	}

	/**
	 * @return string[]
	 */
	public function getLifecycleCallbacks() {
		return self::$lifecycleCallbacks;
	}

	/**
	 * @return string[]
	 */
	public function getLifecycleScriptCallbacks() {
		return self::$lifecycleScriptCallbacks;
	}

	/**
	 * @return string[]
	 */
	public function getPlayerCallbacks() {
		return self::$playerCallbacks;
	}

	/**
	 * @return string[]
	 */
	public function getCombatCallbacks() {
		return self::$combatCallbacks;
	}

	/**
	 * @return array
	 */
	public function getModeCallbacks() {
		return self::$modeCallbacks;
	}
}
