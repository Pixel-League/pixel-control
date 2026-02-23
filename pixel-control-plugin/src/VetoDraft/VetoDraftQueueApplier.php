<?php

namespace PixelControl\VetoDraft;

class VetoDraftQueueApplier {
	public function applySeriesMapOrder(MapRuntimeAdapterInterface $runtimeAdapter, array $orderedMapUids, $launchImmediately) {
		$normalizedMapUids = $this->normalizeUniqueMapUids($orderedMapUids);
		if (empty($normalizedMapUids)) {
			return array(
				'success' => false,
				'code' => 'series_map_order_empty',
				'message' => 'Map order is empty.',
			);
		}

		$openerMapUid = trim((string) $normalizedMapUids[0]);
		$openerMapUidLower = strtolower($openerMapUid);
		$currentMapUid = trim((string) $runtimeAdapter->getCurrentMapUid());
		$currentMapUidLower = strtolower($currentMapUid);
		$openerMatchesCurrent = ($openerMapUidLower !== '' && $currentMapUidLower !== '' && $openerMapUidLower === $currentMapUidLower);
		$applyBranch = $openerMatchesCurrent ? 'opener_already_current' : 'opener_differs';
		$queuedMapUids = $openerMatchesCurrent ? array_slice($normalizedMapUids, 1) : $normalizedMapUids;

		if (!$runtimeAdapter->clearQueue()) {
			return array(
				'success' => false,
				'code' => 'map_queue_unavailable',
				'message' => 'Map queue manager is unavailable.',
				'details' => array(
					'apply_branch' => $applyBranch,
					'map_order' => $normalizedMapUids,
					'opener_map_uid' => $openerMapUid,
					'current_map_uid' => $currentMapUid,
				),
			);
		}

		$queuedMapUidsApplied = array();
		$failedMapUids = array();
		foreach ($queuedMapUids as $mapUid) {
			$added = $runtimeAdapter->enqueueMap($mapUid);
			if ($added) {
				$queuedMapUidsApplied[] = $mapUid;
				continue;
			}

			$failedMapUids[] = $mapUid;
		}

		if (!empty($failedMapUids)) {
			return array(
				'success' => false,
				'code' => 'map_queue_add_failed',
				'message' => 'Failed to queue one or more maps.',
				'details' => array(
					'apply_branch' => $applyBranch,
					'map_order' => $normalizedMapUids,
					'opener_map_uid' => $openerMapUid,
					'current_map_uid' => $currentMapUid,
					'queued_map_uids' => $queuedMapUidsApplied,
					'failed_map_uids' => $failedMapUids,
				),
			);
		}

		$skipResult = array(
			'enabled' => true,
			'applied' => false,
			'code' => 'skip_not_required',
			'message' => 'Current map already equals opener; skip not required.',
		);

		if (!$openerMatchesCurrent) {
			$skipApplied = $runtimeAdapter->skipCurrentMap();
			$skipResult = array(
				'enabled' => true,
				'applied' => $skipApplied,
				'code' => $skipApplied ? 'skip_applied' : 'skip_failed',
				'message' => $skipApplied
					? 'Skipped current map to jump to opener from queued order.'
					: 'Failed to skip current map for opener jump.',
			);

			if (!$skipApplied) {
				return array(
					'success' => false,
					'code' => 'map_skip_failed',
					'message' => 'Map queue was updated but opener skip failed.',
					'details' => array(
						'apply_branch' => $applyBranch,
						'map_order' => $normalizedMapUids,
						'opener_map_uid' => $openerMapUid,
						'current_map_uid' => $currentMapUid,
						'queued_map_uids' => $queuedMapUidsApplied,
						'skip' => $skipResult,
					),
				);
			}
		}

		return array(
			'success' => true,
			'code' => 'map_order_applied',
			'message' => 'Map order applied to queue.',
			'details' => array(
				'apply_branch' => $applyBranch,
				'map_order' => $normalizedMapUids,
				'opener_map_uid' => $openerMapUid,
				'current_map_uid' => $currentMapUid,
				'queued_map_uids' => $queuedMapUidsApplied,
				'queued_count' => count($queuedMapUidsApplied),
				'skip' => $skipResult,
				'launch_immediately_requested' => (bool) $launchImmediately,
			),
		);
	}

	private function normalizeUniqueMapUids(array $orderedMapUids) {
		$normalizedMapUids = array();
		$seenMapUids = array();

		foreach ($orderedMapUids as $mapUid) {
			$normalizedMapUid = trim((string) $mapUid);
			if ($normalizedMapUid === '') {
				continue;
			}

			$lookupKey = strtolower($normalizedMapUid);
			if (isset($seenMapUids[$lookupKey])) {
				continue;
			}

			$seenMapUids[$lookupKey] = true;
			$normalizedMapUids[] = $normalizedMapUid;
		}

		return $normalizedMapUids;
	}
}
