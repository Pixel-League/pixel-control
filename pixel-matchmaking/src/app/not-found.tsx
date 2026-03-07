'use client';

import { useTranslations } from 'next-intl';
import { Card, Button } from '@pixel-series/design-system-neumorphic';
import { useRouter } from 'next/navigation';

export default function NotFound() {
  const t = useTranslations('errors.notFound');
  const tCommon = useTranslations('common');
  const router = useRouter();

  return (
    <div className="flex min-h-[60vh] items-center justify-center">
      <Card title={t('title')} description={t('description')}>
        <div className="text-center space-y-6">
          <p className="font-display text-8xl text-px-primary">{t('code')}</p>
          <Button onClick={() => router.push('/')}>{tCommon('goHome')}</Button>
        </div>
      </Card>
    </div>
  );
}
