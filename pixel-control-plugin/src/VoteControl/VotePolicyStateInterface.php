<?php

namespace PixelControl\VoteControl;

interface VotePolicyStateInterface {
	public function bootstrap(array $defaults, $updateSource, $updatedBy);

	public function reset();

	public function getSnapshot();

	public function setMode($mode, $updateSource, $updatedBy, array $context = array());

	public function getMode();
}
