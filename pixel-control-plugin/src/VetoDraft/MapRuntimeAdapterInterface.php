<?php

namespace PixelControl\VetoDraft;

interface MapRuntimeAdapterInterface {
	/**
	 * Clear current queued map order.
	 *
	 * @return bool
	 */
	public function clearQueue();

	/**
	 * Append a map uid to queue.
	 *
	 * @param string $mapUid
	 * @return bool
	 */
	public function enqueueMap($mapUid);

	/**
	 * Launch first map in queue immediately.
	 *
	 * @param string $firstMapUid
	 * @return array
	 */
	public function launchFirstMap($firstMapUid);

	/**
	 * Resolve current map UID.
	 *
	 * @return string
	 */
	public function getCurrentMapUid();

	/**
	 * Skip current map immediately (equivalent to map.skip).
	 *
	 * @return bool
	 */
	public function skipCurrentMap();

	/**
	 * Skip directly to provided map UID.
	 *
	 * @param string $mapUid
	 * @return bool
	 */
	public function skipToMap($mapUid);
}
