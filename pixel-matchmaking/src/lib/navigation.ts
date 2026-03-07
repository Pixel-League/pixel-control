import type { TopNavLink } from '@pixel-series/design-system-neumorphic';

export interface NavItem {
  label: string;
  href: string;
}

/**
 * Main navigation links for the Pixel MatchMaking platform.
 * The `active` state is set dynamically by `AppTopNav` based on the current route.
 */
export const NAV_ITEMS: NavItem[] = [
  { label: 'Accueil', href: '/' },
  { label: 'Jouer', href: '/play' },
  { label: 'Classement', href: '/leaderboard' },
  { label: 'Profil', href: '/me' },
  { label: 'Admin', href: '/admin' },
];

/**
 * Build TopNav links with active state from the current pathname.
 */
export function buildTopNavLinks(pathname: string): TopNavLink[] {
  return NAV_ITEMS.map((item) => ({
    label: item.label,
    href: item.href,
    active: pathname === item.href,
  }));
}
