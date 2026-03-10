export interface NavItem {
  translationKey: string;
  href: string;
}

/**
 * Production navigation links — always visible.
 * Play IS the homepage now (/ is the matchmaking hub).
 */
export const NAV_ITEMS: NavItem[] = [
  { translationKey: 'play', href: '/' },
];

/**
 * Developer-only navigation links — shown only when NEXT_PUBLIC_DEV_MODE=true.
 */
export const DEV_NAV_ITEMS: NavItem[] = [
  { translationKey: 'leaderboard', href: '/leaderboard' },
  { translationKey: 'profile', href: '/me' },
  { translationKey: 'admin', href: '/admin' },
];
