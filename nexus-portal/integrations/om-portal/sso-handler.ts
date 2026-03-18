/**
 * SSO Handler for O&M Portal
 * 
 * Add this to the O&M Portal frontend to handle SSO redirects from Nexus.
 * 
 * Installation:
 *   1. Copy this file to src/lib/sso-handler.ts
 *   2. Import and call in App.tsx or main entry point
 *   3. Update the backend to validate Firebase tokens (see sso-backend.ts)
 */

import { setToken } from './api'; // Your existing token storage

interface SSOPayload {
  uid: string;
  email: string;
  displayName: string;
  idToken: string;
  targetSystem: string;
  timestamp: number;
  nonce: string;
}

const SSO_TOKEN_EXPIRY_MS = 5 * 60 * 1000; // 5 minutes

/**
 * Check URL for SSO token and process it
 */
export async function handleSSORedirect(): Promise<boolean> {
  const params = new URLSearchParams(window.location.search);
  const ssoToken = params.get('sso_token');
  const fromNexus = params.get('from') === 'nexus';
  
  if (!ssoToken || !fromNexus) {
    return false;
  }
  
  try {
    // Decode payload
    const payload: SSOPayload = JSON.parse(atob(ssoToken));
    
    // Validate timestamp
    const tokenAge = Date.now() - payload.timestamp;
    if (tokenAge > SSO_TOKEN_EXPIRY_MS) {
      console.error('SSO token expired');
      cleanupURL();
      return false;
    }
    
    // Exchange Firebase token for O&M token
    const response = await fetch('/api/auth/sso/validate', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        idToken: payload.idToken,
        email: payload.email,
      }),
    });
    
    if (!response.ok) {
      const error = await response.json();
      console.error('SSO validation failed:', error);
      cleanupURL();
      return false;
    }
    
    const { token, user } = await response.json();
    
    // Store the O&M token (using your existing method)
    setToken(token);
    
    // Clean up URL
    cleanupURL();
    
    console.log('SSO login successful:', user.email);
    return true;
  } catch (error) {
    console.error('SSO error:', error);
    cleanupURL();
    return false;
  }
}

/**
 * Remove SSO parameters from URL
 */
function cleanupURL(): void {
  const url = new URL(window.location.href);
  url.searchParams.delete('sso_token');
  url.searchParams.delete('from');
  window.history.replaceState({}, '', url.pathname + url.search);
}

/**
 * Initialize SSO handler - call this in App.tsx
 */
export async function initSSO(): Promise<void> {
  const success = await handleSSORedirect();
  if (success) {
    // Reload to pick up authenticated state
    window.location.reload();
  }
}
