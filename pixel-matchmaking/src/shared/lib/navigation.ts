export interface NavItem {
  translationKey: string;
  href: string;
}

/**
 * Main navigation links for the Pixel MatchMaking platform.
 * Labels are translation keys resolved via useTranslations('nav') in AppTopNav.
 */
export const NAV_ITEMS: NavItem[] = [
  { translationKey: 'home', href: '/' },
  { translationKey: 'play', href: '/play' },
  { translationKey: 'leaderboard', href: '/leaderboard' },
  { translationKey: 'profile', href: '/me' },
  { translationKey: 'admin', href: '/admin' },
];
