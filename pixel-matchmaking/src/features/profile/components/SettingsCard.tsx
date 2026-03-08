'use client';

import { useTranslations } from 'next-intl';
import { Card, Badge } from '@pixel-series/design-system-neumorphic';
import type { Session } from 'next-auth';

interface SettingsCardProps {
  user: Session['user'] | undefined;
}

export function SettingsCard({ user }: SettingsCardProps) {
  const t = useTranslations('profile');

  return (
    <Card
      title={t('settings.title')}
      description={t('settings.description')}
      badge={<Badge variant="warning">{t('settings.badge')}</Badge>}
    >
      <div className="text-center py-4 text-px-label">
        <p className="font-body text-sm">
          {user ? t('settings.empty') : t('settings.loginRequired')}
        </p>
      </div>
    </Card>
  );
}
