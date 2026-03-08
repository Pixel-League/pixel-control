'use client';

import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { useSession } from 'next-auth/react';
import { useTranslations } from 'next-intl';
import { TopNav, Button } from '@pixel-series/design-system-neumorphic';
import type { TopNavLink } from '@pixel-series/design-system-neumorphic';
import { NAV_ITEMS } from '@/shared/lib/navigation';
import { LanguageSelector } from '@/shared/components/LanguageSelector';
import { UserMenu } from '@/features/navigation/components/UserMenu';

export function AppTopNav() {
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const router = useRouter();
  const { data: session, status } = useSession();
  const tNav = useTranslations('nav');
  const tCommon = useTranslations('common');

  // When redirected to sign-in, use callbackUrl to keep the intended page active
  const activePath = pathname === '/auth/signin'
    ? searchParams.get('callbackUrl') ?? pathname
    : pathname;

  const navLinks: TopNavLink[] = NAV_ITEMS.map((item) => ({
    label: tNav(item.translationKey),
    href: item.href,
    active: activePath === item.href,
    onClick: () => {
      router.push(item.href);
    },
  }));

  const brand = (
    <span className="font-display text-2xl uppercase tracking-display text-px-white">
      PIXEL MATCHMAKING
    </span>
  );

  const authActions = session?.user ? (
    <div className="flex items-center gap-3">
      <UserMenu
        nickname={session.user.nickname ?? session.user.name ?? ''}
        login={session.user.login ?? ''}
      />
      <LanguageSelector />
    </div>
  ) : (
    <div className={`flex items-center gap-3${status === 'loading' ? ' invisible' : ''}`}>
      <Button
        variant="secondary"
        size="sm"
        onClick={() => router.push('/auth/signin')}
      >
        {tCommon('login')}
      </Button>
      <LanguageSelector />
    </div>
  );

  return (
    <TopNav
      brand={brand}
      links={navLinks}
      actions={authActions}
    />
  );
}
