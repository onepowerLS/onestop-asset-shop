/**
 * SSO Token Generation and Validation
 * 
 * This module handles SSO token flow for redirecting authenticated users
 * from Nexus to other 1PWR systems.
 */

import { User } from 'firebase/auth';

export interface SSOPayload {
  uid: string;
  email: string;
  displayName: string;
  idToken: string;
  targetSystem: string;
  timestamp: number;
  nonce: string;
}

/**
 * Generate a nonce for SSO request
 */
function generateNonce(): string {
  const array = new Uint8Array(16);
  crypto.getRandomValues(array);
  return Array.from(array, byte => byte.toString(16).padStart(2, '0')).join('');
}

/**
 * Create SSO redirect URL with token
 * The target system will validate the Firebase ID token
 */
export async function createSSORedirect(
  user: User,
  targetUrl: string,
  targetSystem: string
): Promise<string> {
  const idToken = await user.getIdToken();
  
  const payload: SSOPayload = {
    uid: user.uid,
    email: user.email || '',
    displayName: user.displayName || '',
    idToken,
    targetSystem,
    timestamp: Date.now(),
    nonce: generateNonce(),
  };

  // Encode payload as base64
  const encodedPayload = btoa(JSON.stringify(payload));
  
  // Create redirect URL
  const url = new URL(targetUrl);
  url.searchParams.set('sso_token', encodedPayload);
  url.searchParams.set('from', 'nexus');
  
  return url.toString();
}

/**
 * Redirect to target system with SSO token
 */
export async function redirectWithSSO(
  user: User,
  targetUrl: string,
  targetSystem: string
): Promise<void> {
  const ssoUrl = await createSSORedirect(user, targetUrl, targetSystem);
  window.location.href = ssoUrl;
}

/**
 * Open target system in new tab with SSO token
 */
export async function openWithSSO(
  user: User,
  targetUrl: string,
  targetSystem: string
): Promise<Window | null> {
  const ssoUrl = await createSSORedirect(user, targetUrl, targetSystem);
  return window.open(ssoUrl, '_blank', 'noopener');
}
