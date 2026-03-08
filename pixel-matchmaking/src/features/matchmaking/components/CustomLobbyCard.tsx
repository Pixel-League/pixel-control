'use client';

import { useTranslations } from 'next-intl';
import { Card, Badge, Button } from '@pixel-series/design-system-neumorphic';

export function CustomLobbyCard() {
  const t = useTranslations('play');

  return (
    <Card
      title={t('customLobby.title')}
      description={t('customLobby.description')}
      badge={<Badge variant="warning">{t('customLobby.badge')}</Badge>}
    >
      <Button variant="secondary" disabled>{t('customLobby.button')}</Button>
    </Card>
  );
}
