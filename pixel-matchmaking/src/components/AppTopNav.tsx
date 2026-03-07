'use client';

import { usePathname, useRouter } from 'next/navigation';
import { useSession, signOut } from 'next-auth/react';
import { TopNav, Button } from '@pixel-series/design-system-neumorphic';
import type { TopNavLink } from '@pixel-series/design-system-neumorphic';
import { buildTopNavLinks } from '@/lib/navigation';

export function AppTopNav() {
  const pathname = usePathname();
  const router = useRouter();
  const { data: session, status } = useSession();
  const links = buildTopNavLinks(pathname);

  const navLinks: TopNavLink[] = links.map((link) => ({
    ...link,
    onClick: () => {
      if (link.href) {
        router.push(link.href);
      }
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
          Deconnexion
        </Button>
      </div>
    ) : (
      <Button
        variant="secondary"
        size="sm"
        onClick={() => router.push('/auth/signin')}
      >
        Se connecter
      </Button>
    );

  return (
    <TopNav
      brand={brand}
      links={navLinks}
      actions={authActions}
    />
  );
}
