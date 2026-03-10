/**
 * Route protection configuration — used by middleware and tests.
 */

/** Route prefixes that require authentication. */
export const PROTECTED_PREFIXES = ["/me", "/admin"];

/** Public routes that are always accessible. */
export const PUBLIC_ROUTES = [
  "/",
  "/leaderboard",
  "/matches",
  "/auth",
  "/api/auth",
];

/** Returns true if the pathname requires authentication. */
export function isProtectedRoute(pathname: string): boolean {
  return PROTECTED_PREFIXES.some(
    (prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`),
  );
}

/** Returns true if the pathname is a public route. */
export function isPublicRoute(pathname: string): boolean {
  if (pathname === "/") return true;
  return PUBLIC_ROUTES.some(
    (route) =>
      route !== "/" &&
      (pathname === route || pathname.startsWith(`${route}/`)),
  );
}
