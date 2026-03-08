'use client';

import { hasMpStyles, mpToHtml } from '@/shared/lib/mp-text';

interface MpNicknameProps {
  nickname: string;
  className?: string;
}

/**
 * Renders a ManiaPlanet nickname with proper color and style formatting.
 * If the nickname contains no ManiaPlanet codes, renders as plain text.
 */
export function MpNickname({ nickname, className }: MpNicknameProps) {
  if (hasMpStyles(nickname)) {
    return (
      <span
        className={className}
        // tm-text htmlify output contains only inline color/style attributes — safe to render
        // eslint-disable-next-line react/no-danger
        dangerouslySetInnerHTML={{ __html: mpToHtml(nickname) }}
      />
    );
  }

  return <span className={className}>{nickname}</span>;
}
