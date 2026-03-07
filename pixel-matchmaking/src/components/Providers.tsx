'use client';

import { ThemeProvider } from '@pixel-series/design-system-neumorphic';
import type { ReactNode } from 'react';

interface ProvidersProps {
  children: ReactNode;
}

export function Providers({ children }: ProvidersProps) {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const content = children as any;
  return (
    <ThemeProvider defaultTheme="dark">
      {content}
    </ThemeProvider>
  );
}
