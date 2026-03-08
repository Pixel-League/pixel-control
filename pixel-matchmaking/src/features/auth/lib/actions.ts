"use server";

import { signIn, signOut } from "@/auth";

/**
 * Server Action: sign in with ManiaPlanet OAuth.
 * Used by the sign-in page form.
 */
export async function signInWithManiaPlanet(redirectTo: string = "/") {
  await signIn("maniaplanet", { redirectTo });
}

/**
 * Server Action: sign out and redirect to home.
 * Used by server components that need a sign-out form.
 */
export async function signOutAction() {
  await signOut({ redirectTo: "/" });
}
