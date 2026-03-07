import type { Metadata } from 'next';
import { NextIntlClientProvider } from 'next-intl';
import { getLocale, getMessages } from 'next-intl/server';
import { Providers } from '@/components/Providers';
import { AppTopNav } from '@/components/AppTopNav';
import './globals.css';

export const metadata: Metadata = {
  title: 'Pixel MatchMaking',
  description: 'Plateforme de matchmaking compétitif pour ShootMania',
};

export default async function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  const locale = await getLocale();
  const messages = await getMessages();

  return (
    <html lang={locale}>
      <body className="bg-nm-dark text-px-white font-body antialiased">
        <NextIntlClientProvider locale={locale} messages={messages}>
          <Providers>
            <AppTopNav />
            <main className="max-w-7xl mx-auto px-6 py-8">
              {children}
            </main>
          </Providers>
        </NextIntlClientProvider>
      </body>
    </html>
  );
}
