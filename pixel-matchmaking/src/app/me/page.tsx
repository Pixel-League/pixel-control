'use client';

import { Card, Badge } from '@pixel-series/design-system-neumorphic';

export default function ProfilePage() {
  return (
    <div className="space-y-8">
      <div className="space-y-2">
        <h1 className="font-display text-4xl uppercase tracking-display">
          Mon Profil
        </h1>
        <p className="font-body text-px-label tracking-wide-body">
          Gerez votre profil et consultez vos statistiques.
        </p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        <Card
          title="Statistiques"
          description="Vos performances en matchmaking : victoires, defaites, ratio et ELO."
          badge={<Badge variant="primary">Stats</Badge>}
        >
          <div className="text-center py-4 text-px-label">
            <p className="font-body text-sm">Connectez-vous pour voir vos statistiques.</p>
          </div>
        </Card>
        <Card
          title="Parametres"
          description="Configurez vos preferences de jeu et votre profil public."
          badge={<Badge variant="warning">Bientot</Badge>}
        >
          <div className="text-center py-4 text-px-label">
            <p className="font-body text-sm">Authentification requise.</p>
          </div>
        </Card>
      </div>
    </div>
  );
}
