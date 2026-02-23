<?php

namespace PixelControl\TeamControl;

interface TeamRosterStateInterface {
	public function bootstrap(array $defaults, $updateSource, $updatedBy);

	public function reset();

	public function getSnapshot();

	public function setPolicy($enabled, $switchLock, $updateSource, $updatedBy, array $context = array());

	public function assign($login, $team, $updateSource, $updatedBy, array $context = array());

	public function unassign($login, $updateSource, $updatedBy, array $context = array());

	public function replaceAssignments(array $assignments, $updateSource, $updatedBy, array $context = array());

	public function isPolicyEnabled();

	public function isSwitchLockEnabled();

	public function hasAssignment($login);

	public function getAssignedTeam($login);

	public function getAssignedTeamId($login);

	public function getAssignments();
}
