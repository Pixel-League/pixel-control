'use client';

import { useSession } from 'next-auth/react';
import { useTranslations } from 'next-intl';
import { StatsCard } from '@/features/profile/components/StatsCard';
import { SettingsCard } from '@/features/profile/components/SettingsCard';

export default function ProfilePage() {
  const t = useTranslations('profile');
  const { data: session } = useSession();

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
        <StatsCard user={session?.user} />
        <SettingsCard user={session?.user} />
      </div>
    </div>
  );
}
