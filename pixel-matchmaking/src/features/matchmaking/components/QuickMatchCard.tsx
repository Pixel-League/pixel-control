'use client';

import { observer } from 'mobx-react-lite';
import { useSession } from 'next-auth/react';
import { useTranslations } from 'next-intl';
import { Card, Badge, Button } from '@pixel-series/design-system-neumorphic';
import { matchmakingStore } from '@/features/matchmaking/store/matchmakingStore';

export const QuickMatchCard = observer(function QuickMatchCard() {
  const t = useTranslations('play');
  const { data: session } = useSession();
  const login = session?.user?.login ?? '';

  async function handleSearchToggle() {
    if (matchmakingStore.searching) {
      await matchmakingStore.cancelSearch(login);
    } else {
      await matchmakingStore.startSearch(login);
    }
  }

  return (
    <Card
      title={t('quickMatch.title')}
      description={t('quickMatch.description')}
      badge={<Badge variant="primary">{t('quickMatch.badge')}</Badge>}
    >
      <div className="space-y-3">
        {matchmakingStore.searching && (
          <div className="space-y-1">
            <p className="font-body text-sm text-px-label animate-pulse">
              {t('quickMatch.searching')}
            </p>
            {matchmakingStore.queueCount !== null && (
              <p className="font-body text-xs text-px-label">
                {t('quickMatch.queueCount', { count: matchmakingStore.queueCount })}
              </p>
            )}
          </div>
        )}
        <Button
          variant={matchmakingStore.searching ? 'secondary' : 'primary'}
          onClick={handleSearchToggle}
        >
          {matchmakingStore.searching ? t('quickMatch.cancelSearch') : t('quickMatch.button')}
        </Button>
      </div>
    </Card>
  );
});
