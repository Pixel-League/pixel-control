'use client';

import { useLocale } from 'next-intl';
import { useRouter } from 'next/navigation';
import { useTransition } from 'react';
import { Button } from '@pixel-series/design-system-neumorphic';
import { setLocale } from '@/shared/i18n/actions';
import { type Locale, locales } from '@/shared/i18n/config';

const LOCALE_LABELS: Record<Locale, string> = {
  fr: 'FR',
  en: 'EN',
};

export function LanguageSelector() {
  const currentLocale = useLocale() as Locale;
  const router = useRouter();
  const [isPending, startTransition] = useTransition();

  function handleSwitch() {
    const nextLocale = locales.find((l) => l !== currentLocale) ?? locales[0];
    startTransition(async () => {
      await setLocale(nextLocale);
      window.location.reload();
    });
  }

  return (
    <Button
      variant="ghost"
      size="sm"
      onClick={handleSwitch}
      disabled={isPending}
    >
      {LOCALE_LABELS[currentLocale === 'fr' ? 'en' : 'fr']}
    </Button>
  );
}
