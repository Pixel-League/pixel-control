'use client';

import { useTranslations } from 'next-intl';
import { Card, Badge } from '@pixel-series/design-system-neumorphic';

export default function ProfilePage() {
  const t = useTranslations('profile');

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
          title={t('stats.title')}
          description={t('stats.description')}
          badge={<Badge variant="primary">{t('stats.badge')}</Badge>}
        >
          <div className="text-center py-4 text-px-label">
            <p className="font-body text-sm">{t('stats.loginRequired')}</p>
          </div>
        </Card>
        <Card
          title={t('settings.title')}
          description={t('settings.description')}
          badge={<Badge variant="warning">{t('settings.badge')}</Badge>}
        >
          <div className="text-center py-4 text-px-label">
            <p className="font-body text-sm">{t('settings.loginRequired')}</p>
          </div>
        </Card>
      </div>
    </div>
  );
}
