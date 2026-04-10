# Work Order: Cross-Tool SSO — AM ↔ PR Portal

**Date:** 26 March 2026
**From:** Asset Management Team
**To:** PR Portal Team
**Priority:** Medium
**Estimated effort:** ~2 hours (PR-side changes)

---

## Background

The Asset Management portal (`am.1pwrafrica.com`) now uses the **same Firebase JS SDK and the same Firebase project** (`pr-system-4ea55`) as the PR portal for authentication — same API key, same `signInWithEmailAndPassword`, same `users` collection. All 1PWR staff use one set of credentials across both systems.

The AM sidebar includes a "Switch Tool → Procurement" link. Currently, clicking it requires the user to log in again on PR because Firebase Auth state is per-browser-origin (`am.1pwrafrica.com` ≠ `pr.1pwrafrica.com`).

## Objective

Allow a user who is already signed into AM to switch to the PR portal without re-entering credentials.

## Proposed Solution

A two-part change using a **Firebase Cloud Function** (shared project) and a **small relay route on the PR portal**.

### Part 1 — Cloud Function (AM team will deploy)

We will deploy a callable Cloud Function `createAuthRelay` to the shared `pr-system-4ea55` project. It:

1. Receives the caller's Firebase ID token (automatically passed by the JS SDK)
2. Verifies the token using Firebase Admin SDK
3. Creates and returns a short-lived **custom token** for the same UID

```typescript
// functions/src/createAuthRelay.ts
import { onCall, HttpsError } from 'firebase-functions/v2/https';
import { getAuth } from 'firebase-admin/auth';
import { initializeApp } from 'firebase-admin/app';

initializeApp();

export const createAuthRelay = onCall(async (request) => {
  if (!request.auth) {
    throw new HttpsError('unauthenticated', 'Must be signed in.');
  }
  const customToken = await getAuth().createCustomToken(request.auth.uid);
  return { customToken };
});
```

This function is already secured — only authenticated users can call it, and the custom token is scoped to their own UID.

### Part 2 — PR Portal (PR team to implement)

Add a small relay route that accepts a custom token and signs in automatically.

#### 2a. Add `signInWithCustomToken` to auth imports

In `src/services/auth.ts`, add to the import:

```typescript
import {
  signInWithEmailAndPassword,
  signInWithCustomToken,   // ← add this
  signOut as firebaseSignOut,
  // ... rest unchanged
} from 'firebase/auth';
```

And export a helper:

```typescript
export const signInWithRelay = async (customToken: string): Promise<void> => {
  const auth = getAuth();
  const cred = await signInWithCustomToken(auth, customToken);
  const userDetails = await getUserDetails(cred.user.uid);
  if (!userDetails) throw new Error('User account not found');
  await startTokenRefresh(cred.user);
  store.dispatch(setUser(userDetails));
};
```

#### 2b. Create the relay component

```typescript
// src/components/auth/AuthRelay.tsx
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { signInWithRelay } from '../../services/auth';
import { Box, CircularProgress, Typography } from '@mui/material';

export const AuthRelay = () => {
  const navigate = useNavigate();
  const [error, setError] = useState('');

  useEffect(() => {
    const hash = window.location.hash.slice(1);
    const params = new URLSearchParams(hash);
    const token = params.get('token');

    if (!token) {
      navigate('/login', { replace: true });
      return;
    }

    // Clear the token from the URL immediately
    window.history.replaceState(null, '', '/auth/relay');

    signInWithRelay(token)
      .then(() => navigate('/dashboard', { replace: true }))
      .catch(() => {
        setError('Session expired. Please sign in.');
        setTimeout(() => navigate('/login', { replace: true }), 2000);
      });
  }, [navigate]);

  if (error) {
    return (
      <Box sx={{ display: 'flex', flexDirection: 'column', justifyContent: 'center', alignItems: 'center', height: '100vh' }}>
        <Typography color="error">{error}</Typography>
      </Box>
    );
  }

  return (
    <Box sx={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '100vh' }}>
      <CircularProgress />
    </Box>
  );
};
```

#### 2c. Add the route to `App.tsx`

In the `<Routes>` block, add **before** the `<PrivateRoute>`:

```tsx
import { AuthRelay } from './components/auth/AuthRelay';

// Inside <Routes>:
<Route path="/auth/relay" element={<AuthRelay />} />
```

This route is intentionally **outside** `<PrivateRoute>` — the whole point is to sign the user in.

### How It Works End-to-End

```
AM portal (user is signed in)
  │
  │  User clicks "Procurement" in sidebar
  │
  ├─ AM's Firebase JS SDK calls createAuthRelay Cloud Function
  │  → returns a custom token (valid ~60 min, single use)
  │
  ├─ AM opens: https://pr.1pwrafrica.com/auth/relay#token=<customToken>
  │
  └─ PR's AuthRelay component:
     1. Reads token from URL hash (never sent to server)
     2. Calls signInWithCustomToken(auth, token)
     3. Loads user profile from Firestore users/{uid}
     4. Redirects to /dashboard — user is signed in
```

## Security Notes

- **Custom tokens expire in 60 minutes** and are single-use once exchanged for an ID token
- The token is passed in the **URL fragment** (hash), not the query string — it is never sent to the server or logged in access logs
- The relay component **clears the token from the URL** immediately after reading it
- The Cloud Function only creates tokens for the **caller's own UID** — no privilege escalation
- No passwords, refresh tokens, or long-lived secrets are transmitted between apps

## What the AM Team Will Handle

- [ ] Deploy the `createAuthRelay` Cloud Function to `pr-system-4ea55`
- [ ] Update the AM sidebar "Procurement" link to call the function and redirect with the custom token
- [ ] Test end-to-end after the PR team adds the relay route

## What the PR Team Needs To Do

- [ ] Add `signInWithCustomToken` import to `src/services/auth.ts`
- [ ] Add `signInWithRelay` function to `src/services/auth.ts`
- [ ] Create `src/components/auth/AuthRelay.tsx`
- [ ] Add `<Route path="/auth/relay" element={<AuthRelay />} />` to `src/App.tsx`
- [ ] Deploy to production

## Files Changed on PR Side

| File | Change |
|---|---|
| `src/services/auth.ts` | Add `signInWithCustomToken` import, add `signInWithRelay` function |
| `src/components/auth/AuthRelay.tsx` | New file (~30 lines) |
| `src/App.tsx` | Add one `<Route>` line + import |

Total: **~40 lines of new code across 3 files.**

## Testing

After both sides deploy:

1. Sign in to `am.1pwrafrica.com` with any 1PWR account
2. Click "Procurement" in the sidebar
3. Should arrive at `pr.1pwrafrica.com/dashboard` — signed in as the same user, no login prompt
4. Verify by checking the user name/role in the PR portal header

## Contact

Questions about this work order or the AM-side implementation — reach out to the AM team.
