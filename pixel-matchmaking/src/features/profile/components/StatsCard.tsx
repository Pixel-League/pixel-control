'use client';

import { useTranslations } from 'next-intl';
import { Card, Badge } from '@pixel-series/design-system-neumorphic';
import { MpNickname } from '@/shared/components/MpNickname';
import type { Session } from 'next-auth';

interface StatsCardProps {
  user: Session['user'] | undefined;
}

export function StatsCard({ user }: StatsCardProps) {
  const t = useTranslations('profile');

  return (
    <Card
      title={t('stats.title')}
      description={t('stats.description')}
      badge={<Badge variant="primary">{t('stats.badge')}</Badge>}
    >
      {user ? (
        <div className="space-y-3 py-2">
          <div className="flex items-center gap-2">
            <MpNickname
              nickname={user.nickname ?? user.name ?? user.login ?? ''}
              className="font-body font-semibold text-px-offblack"
            />
            <span className="font-body text-xs text-px-label">
              {user.login}
            </span>
          </div>
          <p className="font-body text-sm text-px-label">{t('stats.empty')}</p>
        </div>
      ) : (
        <div className="text-center py-4 text-px-label">
          <p className="font-body text-sm">{t('stats.loginRequired')}</p>
        </div>
      )}
    </Card>
  );
}
