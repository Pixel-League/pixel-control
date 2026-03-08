'use client';

import { configure } from 'mobx';
import { SessionProvider } from 'next-auth/react';
import { ThemeProvider } from '@pixel-series/design-system-neumorphic';
import type { ReactNode } from 'react';

configure({ enforceActions: 'never' });

interface ProvidersProps {
  children: ReactNode;
}

export function Providers({ children }: ProvidersProps) {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const content = children as any;
  return (
    <SessionProvider>
      <ThemeProvider defaultTheme="light">
        {content}
      </ThemeProvider>
    </SessionProvider>
  );
}
