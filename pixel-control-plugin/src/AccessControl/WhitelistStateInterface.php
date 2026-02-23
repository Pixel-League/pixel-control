<?php

namespace PixelControl\AccessControl;

interface WhitelistStateInterface {
	public function bootstrap(array $defaults, $updateSource, $updatedBy);

	public function reset();

	public function getSnapshot();

	public function setEnabled($enabled, $updateSource, $updatedBy, array $context = array());

	public function addLogin($login, $updateSource, $updatedBy, array $context = array());

	public function removeLogin($login, $updateSource, $updatedBy, array $context = array());

	public function clean($updateSource, $updatedBy, array $context = array());

	public function replaceLogins(array $logins, $updateSource, $updatedBy, array $context = array());

	public function isEnabled();

	public function hasLogin($login);

	public function getLogins();
}
