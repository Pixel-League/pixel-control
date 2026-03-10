import { config } from '@/shared/lib/config';

/**
 * Returns whether the application is running in developer mode.
 * Dev mode reveals hidden pages (leaderboard, profile, admin) in the navigation.
 * Controlled by NEXT_PUBLIC_DEV_MODE env var.
 */
export function useDevMode(): boolean {
  return config.devMode;
}
