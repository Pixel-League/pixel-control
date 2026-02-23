<?php

namespace PixelControl\Domain\Player;

trait PlayerDomainTrait {
	use PlayerSourceSnapshotTrait;
	use PlayerContinuityCorrelationTrait;
	use PlayerPolicySignalsTrait;
}
