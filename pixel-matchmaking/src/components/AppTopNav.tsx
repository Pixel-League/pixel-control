'use client';

import { usePathname, useRouter } from 'next/navigation';
import { TopNav } from '@pixel-series/design-system-neumorphic';
import type { TopNavLink } from '@pixel-series/design-system-neumorphic';
import { buildTopNavLinks } from '@/lib/navigation';

export function AppTopNav() {
  const pathname = usePathname();
  const router = useRouter();
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

  return (
    <TopNav
      brand={brand}
      links={navLinks}
    />
  );
}
