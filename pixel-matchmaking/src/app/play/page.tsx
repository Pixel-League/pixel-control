'use client';

import { Card, Badge, Button } from '@pixel-series/design-system-neumorphic';

export default function PlayPage() {
  return (
    <div className="space-y-8">
      <div className="space-y-2">
        <h1 className="font-display text-4xl uppercase tracking-display">
          Jouer
        </h1>
        <p className="font-body text-px-label tracking-wide-body">
          Rejoignez une partie competitive ou creez votre propre lobby.
        </p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        <Card
          title="Matchmaking rapide"
          description="Trouvez automatiquement un match avec des joueurs de votre niveau."
          badge={<Badge variant="primary">Ranked</Badge>}
        >
          <Button>Rechercher un match</Button>
        </Card>
        <Card
          title="Lobby personnalise"
          description="Creez un lobby prive et invitez vos amis pour une partie personnalisee."
          badge={<Badge variant="warning">Bientot</Badge>}
        >
          <Button variant="secondary" disabled>Creer un lobby</Button>
        </Card>
      </div>
    </div>
  );
}
