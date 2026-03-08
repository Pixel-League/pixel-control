'use client';

import { SessionProvider } from 'next-auth/react';
import { ThemeProvider, useTheme } from '@pixel-series/design-system-neumorphic';
import type { ReactNode } from 'react';

function ThemedRoot({ children }: { children: ReactNode }) {
  const { theme } = useTheme();
  const isDark = theme === 'dark';
  return (
    <div className={`min-h-screen ${isDark ? 'bg-nm-dark text-px-white' : 'bg-nm-light text-px-offblack'}`}>
      {children}
    </div>
  );
}

interface ProvidersProps {
  children: ReactNode;
}

export function Providers({ children }: ProvidersProps) {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const content = children as any;
  return (
    <SessionProvider>
      <ThemeProvider defaultTheme="dark">
        <ThemedRoot>{content}</ThemedRoot>
      </ThemeProvider>
    </SessionProvider>
  );
}
