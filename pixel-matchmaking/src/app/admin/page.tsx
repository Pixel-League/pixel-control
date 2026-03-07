'use client';

import { Card, Badge } from '@pixel-series/design-system-neumorphic';

export default function AdminPage() {
  return (
    <div className="space-y-8">
      <div className="space-y-2">
        <h1 className="font-display text-4xl uppercase tracking-display">
          Administration
        </h1>
        <p className="font-body text-px-label tracking-wide-body">
          Panneau d'administration de la plateforme Pixel MatchMaking.
        </p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        <Card
          title="Serveurs"
          description="Gerez les serveurs de jeu connectes a la plateforme."
          badge={<Badge variant="primary">Admin</Badge>}
        />
        <Card
          title="Joueurs"
          description="Consultez et gerez les comptes joueurs enregistres."
          badge={<Badge variant="primary">Admin</Badge>}
        />
        <Card
          title="Matchmaking"
          description="Configurez les parametres du systeme de matchmaking."
          badge={<Badge variant="warning">Bientot</Badge>}
        />
      </div>
    </div>
  );
}
