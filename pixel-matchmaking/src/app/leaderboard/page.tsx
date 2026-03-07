'use client';

import { Card, Badge } from '@pixel-series/design-system-neumorphic';

export default function LeaderboardPage() {
  return (
    <div className="space-y-8">
      <div className="space-y-2">
        <h1 className="font-display text-4xl uppercase tracking-display">
          Classement
        </h1>
        <p className="font-body text-px-label tracking-wide-body">
          Les meilleurs joueurs de la communaute ShootMania.
        </p>
      </div>

      <Card
        title="Classement general"
        description="Le classement sera disponible une fois le systeme de matchmaking actif."
        badge={<Badge variant="warning">Bientot</Badge>}
      >
        <div className="text-center py-8 text-px-label">
          <p className="font-body text-sm">Aucune donnee de classement disponible.</p>
        </div>
      </Card>
    </div>
  );
}
