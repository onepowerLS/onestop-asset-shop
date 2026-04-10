# O&M Portal SSO Integration

## Overview

The O&M Portal uses a custom JWT authentication system. This integration allows users to log in via Nexus SSO while maintaining the existing auth flow.

## Frontend Integration

### 1. Install SSO Handler

Copy `sso-handler.ts` to `src/lib/sso-handler.ts`

### 2. Initialize in App

```tsx
// src/App.tsx
import { useEffect } from 'react';
import { initSSO } from './lib/sso-handler';

function App() {
  useEffect(() => {
    // Check for SSO redirect on app load
    initSSO();
  }, []);

  return (
    // ... your app
  );
}
```

## Backend Integration

### 1. Install Firebase Admin SDK

```bash
npm install firebase-admin
```

### 2. Set Environment Variables

```env
FIREBASE_PROJECT_ID=pr-system-4ea55
FIREBASE_CLIENT_EMAIL=firebase-adminsdk-xxxxx@pr-system-4ea55.iam.gserviceaccount.com
FIREBASE_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n"
```

Get these values from Firebase Console > Project Settings > Service Accounts > Generate New Private Key

### 3. Add SSO Route

Copy `sso-backend.ts` and integrate into your Express routes:

```typescript
// src/routes/auth.ts
import ssoRouter from './sso-backend';

router.use('/auth', ssoRouter);
```

### 4. Implement Database Functions

Update the placeholder functions in `sso-backend.ts`:
- `findUserByEmail` - Query your user table
- `generateOMToken` - Use your existing JWT generation
- `updateLastLogin` - Update user record

## Database Migration

Add `firebase_uid` column to track Firebase user IDs:

```sql
ALTER TABLE users ADD COLUMN firebase_uid VARCHAR(255) UNIQUE;
CREATE INDEX idx_users_firebase_uid ON users(firebase_uid);
```

## Testing

1. Log into Nexus at nexus.1pwrafrica.com
2. Click on "O&M Portal" tile
3. You should be redirected to om.1pwrafrica.com
4. The SSO handler exchanges the token and logs you in
5. Check network tab for `/api/auth/sso/validate` call

## Security Notes

- Firebase ID tokens expire after 1 hour
- SSO tokens from Nexus expire after 5 minutes
- Always validate tokens server-side
- The Firebase private key should be kept secret
