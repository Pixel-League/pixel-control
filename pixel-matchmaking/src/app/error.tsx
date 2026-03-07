'use client';

import { useTranslations } from 'next-intl';
import { Card, Button } from '@pixel-series/design-system-neumorphic';
import { useRouter } from 'next/navigation';

interface ErrorPageProps {
  error: Error & { digest?: string };
  reset: () => void;
}

export default function ErrorPage({ error, reset }: ErrorPageProps) {
  const t = useTranslations('errors.serverError');
  const tCommon = useTranslations('common');
  const router = useRouter();

  return (
    <div className="flex min-h-[60vh] items-center justify-center">
      <Card title={t('title')} description={t('description')}>
        <div className="text-center space-y-6">
          <p className="font-display text-8xl text-px-error">{t('code')}</p>

          {process.env.NODE_ENV === 'development' && (
            <p className="text-sm text-px-label font-mono break-all">
              {error.message}
            </p>
          )}

          <div className="flex gap-4 justify-center">
            <Button onClick={reset}>{tCommon('retry')}</Button>
            <Button variant="secondary" onClick={() => router.push('/')}>
              {tCommon('goHome')}
            </Button>
          </div>
        </div>
      </Card>
    </div>
  );
}
