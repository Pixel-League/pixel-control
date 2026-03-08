'use client';

import { useTranslations } from 'next-intl';
import { Card, Button, Alert } from '@pixel-series/design-system-neumorphic';
import { signInWithManiaPlanet } from '@/features/auth/lib/actions';

interface SignInFormProps {
  callbackUrl: string;
  error?: string;
}

export function SignInForm({ callbackUrl, error }: SignInFormProps) {
  const t = useTranslations('auth');

  const errorMessage = error
    ? t.has(`errors.${error}`) ? t(`errors.${error}`) : t('errors.default')
    : undefined;

  return (
    <div className="w-full max-w-md space-y-6">
      <div className="text-center space-y-2">
        <h1 className="font-display text-5xl uppercase tracking-display">
          {t('title')}
        </h1>
        <p className="font-body text-px-label tracking-wide-body">
          {t('subtitle')}
        </p>
      </div>

      {errorMessage && (
        <Alert variant="error" title={t('errorTitle')}>
          {errorMessage}
        </Alert>
      )}

      <Card title={t('ssoTitle')} description={t('ssoDescription')}>
        <form action={() => signInWithManiaPlanet(callbackUrl)}>
          <Button type="submit" size="lg" className="w-full">
            {t('button')}
          </Button>
        </form>
      </Card>

      <p className="text-center text-sm text-px-label tracking-wide-body">
        {t('noAccount')}{' '}
        <a
          href="https://www.maniaplanet.com/account/register"
          target="_blank"
          rel="noopener noreferrer"
          className="text-px-primary hover:text-px-primary-light underline"
        >
          {t('createAccount')}
        </a>
      </p>
    </div>
  );
}
