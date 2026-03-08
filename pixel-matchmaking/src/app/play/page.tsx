'use client';

import { useTranslations } from 'next-intl';
import { QuickMatchCard } from '@/features/matchmaking/components/QuickMatchCard';
import { CustomLobbyCard } from '@/features/matchmaking/components/CustomLobbyCard';

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
        <QuickMatchCard />
        <CustomLobbyCard />
      </div>
    </div>
  );
}
