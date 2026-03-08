'use client';

import { useRouter } from 'next/navigation';
import { signOut } from 'next-auth/react';
import { useTranslations } from 'next-intl';
import { DropdownMenu } from '@pixel-series/design-system-neumorphic';
import type { DropdownMenuItem } from '@pixel-series/design-system-neumorphic';
import { stripMpStyles } from '@/shared/lib/mp-text';
import { MpNickname } from '@/shared/components/MpNickname';

interface UserMenuProps {
  nickname: string;
  login: string;
}

/**
 * Avatar-triggered dropdown menu for the logged-in user.
 * The avatar displays the player's ManiaPlanet-formatted nickname with colors.
 * The dropdown contains profile navigation and logout.
 */
export function UserMenu({ nickname, login }: UserMenuProps) {
  const router = useRouter();
  const tNav = useTranslations('nav');
  const tCommon = useTranslations('common');

  const displayName = stripMpStyles(nickname) || login;

  const items: DropdownMenuItem[] = [
    {
      label: <MpNickname nickname={nickname || login} />,
      disabled: true,
    },
    { label: '', divider: true },
    {
      label: tNav('profile'),
      onClick: () => router.push('/me'),
    },
    {
      label: tCommon('logout'),
      danger: true,
      onClick: () => signOut({ callbackUrl: '/' }),
    },
  ];

  // Avatar-styled trigger (matches DS Avatar sm classes) containing the formatted nickname
  const trigger = (
    <span
      className="inline-flex items-center justify-center w-8 h-8 overflow-hidden shrink-0 cursor-pointer bg-nm-light-s shadow-nm-raised-l border border-black/[0.08]"
      aria-label={displayName}
    >
      <MpNickname
        nickname={nickname || login}
        className="font-display font-bold uppercase tracking-display text-px-offblack text-[10px] leading-none"
      />
    </span>
  );

  return <DropdownMenu trigger={trigger} items={items} align="right" />;
}
