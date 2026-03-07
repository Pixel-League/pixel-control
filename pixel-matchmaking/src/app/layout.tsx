import type { Metadata } from 'next';
import { Providers } from '@/components/Providers';
import { AppTopNav } from '@/components/AppTopNav';
import './globals.css';

export const metadata: Metadata = {
  title: 'Pixel MatchMaking',
  description: 'Plateforme de matchmaking competitif pour ShootMania',
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="fr">
      <body className="bg-nm-dark text-px-white font-body antialiased">
        <Providers>
          <AppTopNav />
          <main className="max-w-7xl mx-auto px-6 py-8">
            {children}
          </main>
        </Providers>
      </body>
    </html>
  );
}
