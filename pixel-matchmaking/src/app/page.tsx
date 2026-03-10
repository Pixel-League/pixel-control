'use client';

import { observer } from 'mobx-react-lite';
import { useRouter } from 'next/navigation';
import { useSession } from 'next-auth/react';
import { useTranslations } from 'next-intl';
import { Button } from '@pixel-series/design-system-neumorphic';
import { matchmakingStore } from '@/features/matchmaking/store/matchmakingStore';
import { OngoingMatchCard } from '@/features/matchmaking/components/OngoingMatchCard';
import { mockMatches } from '@/features/matchmaking/data/mockMatches';

const Home = observer(function Home() {
  const t = useTranslations('play');
  const router = useRouter();
  const { data: session } = useSession();
  const login = session?.user?.login ?? '';

  async function handleSearchToggle() {
    if (!session) {
      router.push('/auth/signin');
      return;
    }
    if (matchmakingStore.searching) {
      await matchmakingStore.cancelSearch(login);
    } else {
      await matchmakingStore.startSearch(login);
    }
  }

  return (
    <div className="flex flex-col items-center gap-12 py-8">
      {/* Header — minimal, not dominant */}
      <div className="text-center space-y-2">
        <h1 className="font-display text-4xl uppercase tracking-display">
          {t('title')}
        </h1>
        <p className="font-body text-px-label text-sm tracking-wide-body">
          {t('subtitle')}
        </p>
      </div>

      {/* Search section — the dominant CTA */}
      <div className="w-full max-w-md flex flex-col items-center gap-4">
        {matchmakingStore.searching && (
          <div className="text-center space-y-1">
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

        {!session && !matchmakingStore.searching && (
          <p className="font-body text-xs text-px-label">
            {t('loginRequired')}
          </p>
        )}

        <Button
          size="lg"
          variant={matchmakingStore.searching ? 'secondary' : 'primary'}
          className="w-full"
          onClick={handleSearchToggle}
        >
          {matchmakingStore.searching
            ? t('quickMatch.cancelSearch')
            : t('searchButton')}
        </Button>
      </div>

      {/* Ongoing matches */}
      <div className="w-full space-y-4">
        <h2 className="font-display text-xl uppercase tracking-display">
          {t('ongoingMatches.title')}
        </h2>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          {mockMatches.map((match) => (
            <OngoingMatchCard
              key={match.id}
              teamA={match.teamA}
              teamB={match.teamB}
              scoreA={match.scoreA}
              scoreB={match.scoreB}
              map={match.map}
              duration={match.duration}
              mode={match.mode}
            />
          ))}
        </div>
      </div>
    </div>
  );
});

export default Home;
