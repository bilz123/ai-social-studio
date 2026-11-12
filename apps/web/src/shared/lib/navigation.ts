/**
 * Leaves the app for another site (for example a provider's consent screen). Kept in one tiny
 * function so tests can replace it; jsdom cannot navigate.
 */
export function redirectTo(url: string): void {
  window.location.assign(url)
}
