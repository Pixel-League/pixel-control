import { auth } from "@/auth";
import { NextResponse } from "next/server";
import { isProtectedRoute } from "@/features/auth/lib/routes";

/**
 * Route protection middleware using Auth.js v5.
 *
 * Protected routes: /play, /me, /me/*, /admin, /admin/*
 * Public routes: /, /leaderboard, /player/*, /matches, /auth/*, /api/auth/*
 *
 * Unauthenticated users on protected routes are redirected to /auth/signin
 * with a callbackUrl to return them after login.
 */

export default auth((req) => {
  const { pathname } = req.nextUrl;

  // Skip static assets, API auth routes, and auth pages
  if (
    pathname.startsWith("/_next") ||
    pathname.startsWith("/api/auth") ||
    pathname.startsWith("/auth") ||
    pathname === "/favicon.ico"
  ) {
    return NextResponse.next();
  }

  // Check if route is protected and user is not authenticated
  if (isProtectedRoute(pathname) && !req.auth) {
    const signInUrl = new URL("/auth/signin", req.url);
    signInUrl.searchParams.set("callbackUrl", pathname);
    return NextResponse.redirect(signInUrl);
  }

  return NextResponse.next();
});

/**
 * Matcher config: run middleware on all routes except static assets.
 */
export const config = {
  matcher: ["/((?!_next/static|_next/image|favicon.ico).*)"],
};
