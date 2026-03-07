'use client';

import { usePathname, useRouter } from 'next/navigation';
import { useSession, signOut } from 'next-auth/react';
import { useTranslations } from 'next-intl';
import { TopNav, Button } from '@pixel-series/design-system-neumorphic';
import type { TopNavLink } from '@pixel-series/design-system-neumorphic';
import { NAV_ITEMS } from '@/lib/navigation';
import { LanguageSelector } from '@/components/LanguageSelector';

export function AppTopNav() {
  const pathname = usePathname();
  const router = useRouter();
  const { data: session, status } = useSession();
  const tNav = useTranslations('nav');
  const tCommon = useTranslations('common');

  const navLinks: TopNavLink[] = NAV_ITEMS.map((item) => ({
    label: tNav(item.translationKey),
    href: item.href,
    active: pathname === item.href,
    onClick: () => {
      router.push(item.href);
    },
  }));

  const brand = (
    <span className="font-display text-2xl uppercase tracking-display text-px-white">
      PIXEL MATCHMAKING
    </span>
  );

  const authActions =
    status === 'loading' ? null : session?.user ? (
      <div className="flex items-center gap-3">
        <span className="text-sm font-body text-px-label tracking-wide-body">
          {session.user.nickname ?? session.user.name}
        </span>
        <Button
          variant="ghost"
          size="sm"
          onClick={() => signOut({ callbackUrl: '/' })}
        >
          {tCommon('logout')}
        </Button>
        <LanguageSelector />
      </div>
    ) : (
      <div className="flex items-center gap-3">
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
