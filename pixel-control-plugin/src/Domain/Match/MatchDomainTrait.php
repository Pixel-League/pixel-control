<?php

namespace PixelControl\Domain\Match;

trait MatchDomainTrait {
	use MatchAggregateTelemetryTrait;
	use MatchWinContextTrait;
	use MatchVetoRotationTrait;
}
