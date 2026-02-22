<?php

namespace PixelControl\SeriesControl;

interface SeriesControlStateInterface {
	public function bootstrap(array $defaults, $updateSource, $updatedBy);

	public function reset();

	public function getSnapshot();

	public function setBestOf($bestOf, $updateSource, $updatedBy, array $context = array());

	public function setMatchMapsScore($targetTeam, $mapsScore, $updateSource, $updatedBy, array $context = array());

	public function setCurrentMapScore($targetTeam, $score, $updateSource, $updatedBy, array $context = array());
}
