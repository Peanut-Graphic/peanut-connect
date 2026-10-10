/**
 * Hub pairing tokens (peanut-hub#1756): `hubpair_` + 56 alphanumerics, issued
 * from Hub → site → "Pair site", valid for 30 minutes and a single use.
 * Mirrors Peanut_Connect_API::is_well_formed_pairing_token() on the server.
 */
export const PAIRING_TOKEN_PATTERN = /^hubpair_[A-Za-z0-9]{56}$/;

export function isWellFormedPairingToken(token: string): boolean {
  return PAIRING_TOKEN_PATTERN.test(token.trim());
}
