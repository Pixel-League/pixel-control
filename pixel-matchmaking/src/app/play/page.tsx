'use client';

import { useTranslations } from 'next-intl';
import { Card, Badge, Button } from '@pixel-series/design-system-neumorphic';

export default function PlayPage() {
  const t = useTranslations('play');

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

      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        <Card
          title={t('quickMatch.title')}
          description={t('quickMatch.description')}
          badge={<Badge variant="primary">{t('quickMatch.badge')}</Badge>}
        >
          <Button>{t('quickMatch.button')}</Button>
        </Card>
        <Card
          title={t('customLobby.title')}
          description={t('customLobby.description')}
          badge={<Badge variant="warning">{t('customLobby.badge')}</Badge>}
        >
          <Button variant="secondary" disabled>{t('customLobby.button')}</Button>
        </Card>
      </div>
    </div>
  );
}
