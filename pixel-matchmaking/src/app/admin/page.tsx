'use client';

import { useTranslations } from 'next-intl';
import { Card, Badge } from '@pixel-series/design-system-neumorphic';

export default function AdminPage() {
  const t = useTranslations('admin');

  return (
    <div className="space-y-8">
      <div className="space-y-2">
        <h1 className="font-display text-4xl uppercase tracking-display">
          {t('title')}
        </h1>
        <p className="font-body text-px-label tracking-wide-body">
          {t('subtitle')}
        </p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        <Card
          title={t('servers.title')}
          description={t('servers.description')}
          badge={<Badge variant="primary">{t('servers.badge')}</Badge>}
        />
        <Card
          title={t('players.title')}
          description={t('players.description')}
          badge={<Badge variant="primary">{t('players.badge')}</Badge>}
        />
        <Card
          title={t('matchmaking.title')}
          description={t('matchmaking.description')}
          badge={<Badge variant="warning">{t('matchmaking.badge')}</Badge>}
        />
      </div>
    </div>
  );
}
