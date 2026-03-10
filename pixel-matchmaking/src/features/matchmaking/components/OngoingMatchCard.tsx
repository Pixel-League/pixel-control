'use client';

import { useTranslations } from 'next-intl';
import { Card, Badge } from '@pixel-series/design-system-neumorphic';

export interface OngoingMatchCardProps {
  teamA: string;
  teamB: string;
  scoreA: number;
  scoreB: number;
  map: string;
  duration: string;
  mode: string;
}

export function OngoingMatchCard({
  teamA,
  teamB,
  scoreA,
  scoreB,
  map,
  duration,
  mode,
}: OngoingMatchCardProps) {
  const t = useTranslations('play');

  return (
    <Card
      tone="surface"
      badge={<Badge variant="success">{mode}</Badge>}
      title={map}
      metadata={
        <span className="font-body text-xs text-px-label">
          {t('ongoingMatches.duration')}: {duration}
        </span>
      }
    >
      <div className="flex items-center justify-between gap-2 pt-2">
        <div className="flex-1 min-w-0 text-left">
          <p className="font-display text-xs uppercase tracking-display text-px-white truncate">
            {teamA}
          </p>
          <p className="font-display text-4xl font-bold text-px-white leading-none mt-1">
            {scoreA}
          </p>
        </div>

        <div className="font-body text-xs text-px-label shrink-0 px-1">—</div>

        <div className="flex-1 min-w-0 text-right">
          <p className="font-display text-xs uppercase tracking-display text-px-label truncate">
            {teamB}
          </p>
          <p className="font-display text-4xl font-bold text-px-label leading-none mt-1">
            {scoreB}
          </p>
        </div>
      </div>
    </Card>
  );
}
