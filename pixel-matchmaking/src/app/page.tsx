'use client';

import { Button, Card, Badge } from '@pixel-series/design-system-neumorphic';

export default function Home() {
  return (
    <div className="space-y-8">
      <div className="text-center space-y-4">
        <h1 className="font-display text-5xl uppercase tracking-display">
          Pixel MatchMaking
        </h1>
        <p className="font-body text-px-label tracking-wide-body text-lg">
          Plateforme de matchmaking competitif pour ShootMania
        </p>
      </div>

      <div className="flex justify-center gap-4">
        <Badge variant="primary">Foundation</Badge>
        <Badge variant="success">Online</Badge>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-6 max-w-4xl mx-auto">
        <Card
          title="Matchmaking"
          description="Trouvez un match competitif en quelques secondes. File d'attente automatique et matchmaking par niveau."
        >
          <Button>Jouer</Button>
        </Card>
        <Card
          title="Classement"
          description="Suivez votre progression et comparez-vous aux meilleurs joueurs de la communaute."
        >
          <Button variant="secondary">Voir le classement</Button>
        </Card>
      </div>
    </div>
  );
}
