'use client';

import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import { Button, Card, Badge } from '@pixel-series/design-system-neumorphic';

export default function Home() {
  const t = useTranslations('home');
  const router = useRouter();

  return (
    <div className="space-y-8">
      <div className="text-center space-y-4">
        <h1 className="font-display text-5xl uppercase tracking-display">
          {t('title')}
        </h1>
        <p className="font-body text-px-label tracking-wide-body text-lg">
          {t('subtitle')}
        </p>
      </div>

      <div className="flex justify-center gap-4">
        <Badge variant="primary">{t('badgeFoundation')}</Badge>
        <Badge variant="success">{t('badgeOnline')}</Badge>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-6 max-w-4xl mx-auto">
        <Card
          title={t('matchmaking.title')}
          description={t('matchmaking.description')}
        >
          <Button onClick={() => router.push('/play')}>{t('matchmaking.button')}</Button>
        </Card>
        <Card
          title={t('leaderboard.title')}
          description={t('leaderboard.description')}
        >
          <Button variant="secondary" onClick={() => router.push('/leaderboard')}>{t('leaderboard.button')}</Button>
        </Card>
      </div>
    </div>
  );
}
