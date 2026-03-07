'use client';

import { Card, Button, Alert } from '@pixel-series/design-system-neumorphic';
import { signInWithManiaPlanet } from '@/lib/auth/actions';

interface SignInFormProps {
  callbackUrl: string;
  error?: string;
}

/** Map Auth.js error codes to user-friendly messages. */
function getErrorMessage(error: string): string {
  switch (error) {
    case 'OAuthCallbackError':
      return 'Erreur lors de la connexion avec ManiaPlanet. Veuillez reessayer.';
    case 'OAuthSignin':
      return 'Impossible de demarrer la connexion OAuth. Verifiez la configuration.';
    case 'OAuthAccountNotLinked':
      return 'Ce compte est deja lie a un autre mode de connexion.';
    case 'AccessDenied':
      return 'Acces refuse. Vous n\'avez pas la permission de vous connecter.';
    default:
      return 'Une erreur est survenue lors de la connexion. Veuillez reessayer.';
  }
}

export function SignInForm({ callbackUrl, error }: SignInFormProps) {
  return (
    <div className="w-full max-w-md space-y-6">
      <div className="text-center space-y-2">
        <h1 className="font-display text-5xl uppercase tracking-display">
          Connexion
        </h1>
        <p className="font-body text-px-label tracking-wide-body">
          Connectez-vous avec votre compte ManiaPlanet pour acceder a la plateforme.
        </p>
      </div>

      {error && (
        <Alert variant="error" title="Erreur d'authentification">
          {getErrorMessage(error)}
        </Alert>
      )}

      <Card title="ManiaPlanet SSO" description="Utilisez votre compte ManiaPlanet pour vous connecter a Pixel MatchMaking.">
        <form action={() => signInWithManiaPlanet(callbackUrl)}>
          <Button type="submit" size="lg" className="w-full">
            Se connecter avec ManiaPlanet
          </Button>
        </form>
      </Card>

      <p className="text-center text-sm text-px-label tracking-wide-body">
        Pas encore de compte ?{' '}
        <a
          href="https://www.maniaplanet.com/account/register"
          target="_blank"
          rel="noopener noreferrer"
          className="text-px-primary hover:text-px-primary-light underline"
        >
          Creer un compte ManiaPlanet
        </a>
      </p>
    </div>
  );
}
