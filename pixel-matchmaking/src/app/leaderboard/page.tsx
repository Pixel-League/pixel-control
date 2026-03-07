'use client';

import { useTranslations } from 'next-intl';
import { Card, Badge } from '@pixel-series/design-system-neumorphic';

export default function LeaderboardPage() {
  const t = useTranslations('leaderboard');

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

      <Card
        title={t('general.title')}
        description={t('general.description')}
        badge={<Badge variant="warning">{t('general.badge')}</Badge>}
      >
        <div className="text-center py-8 text-px-label">
          <p className="font-body text-sm">{t('general.empty')}</p>
        </div>
      </Card>
    </div>
  );
}
