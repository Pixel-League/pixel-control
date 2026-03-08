'use client';

import { useState, useEffect, useRef } from 'react';
import { useSession } from 'next-auth/react';
import { useTranslations } from 'next-intl';
import { Card, Badge, Button } from '@pixel-series/design-system-neumorphic';

const QUEUE_POLL_INTERVAL = 5000;

async function joinQueue(login: string): Promise<number> {
  const res = await fetch('/api/matchmaking/queue', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ login }),
  });
  const data = await res.json() as { count: number };
  return data.count;
}

async function leaveQueue(login: string): Promise<void> {
  await fetch('/api/matchmaking/queue', {
    method: 'DELETE',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ login }),
  });
}

async function fetchQueueCount(): Promise<number> {
  const res = await fetch('/api/matchmaking/queue');
  const data = await res.json() as { count: number };
  return data.count;
}

export function QuickMatchCard() {
  const t = useTranslations('play');
  const { data: session } = useSession();
  const [searching, setSearching] = useState(false);
  const [queueCount, setQueueCount] = useState<number | null>(null);
  const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const login = session?.user?.login ?? '';

  useEffect(() => {
    if (!searching || !login) return;

    joinQueue(login).then(setQueueCount);

    intervalRef.current = setInterval(() => {
      fetchQueueCount().then(setQueueCount);
    }, QUEUE_POLL_INTERVAL);

    return () => {
      if (intervalRef.current) clearInterval(intervalRef.current);
      leaveQueue(login);
    };
  }, [searching, login]);

  function handleSearchToggle() {
    setSearching((prev) => {
      if (prev) setQueueCount(null);
      return !prev;
    });
  }

  return (
    <Card
      title={t('quickMatch.title')}
      description={t('quickMatch.description')}
      badge={<Badge variant="primary">{t('quickMatch.badge')}</Badge>}
    >
      <div className="space-y-3">
        {searching && (
          <div className="space-y-1">
            <p className="font-body text-sm text-px-label animate-pulse">
              {t('quickMatch.searching')}
            </p>
            {queueCount !== null && (
              <p className="font-body text-xs text-px-label">
                {t('quickMatch.queueCount', { count: queueCount })}
              </p>
            )}
          </div>
        )}
        <Button
          variant={searching ? 'secondary' : 'primary'}
          onClick={handleSearchToggle}
        >
          {searching ? t('quickMatch.cancelSearch') : t('quickMatch.button')}
        </Button>
      </div>
    </Card>
  );
}
