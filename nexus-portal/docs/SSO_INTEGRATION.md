# SSO Integration Guide

This document explains how to integrate existing 1PWR systems with the Nexus SSO flow.

## Overview

Nexus uses Firebase Authentication as the identity provider. When a user clicks on a system in the Nexus lobby, they are redirected with an SSO token that the target system can validate.

## SSO Token Flow

```
┌─────────┐     ┌─────────┐     ┌─────────────┐     ┌──────────────┐
│  User   │────▶│  Nexus  │────▶│ Target App  │────▶│ Firebase API │
│         │     │ Portal  │     │ (PR/AM/etc) │     │              │
└─────────┘     └─────────┘     └─────────────┘     └──────────────┘
     │               │                 │                    │
     │   Click app   │                 │                    │
     │──────────────▶│                 │                    │
     │               │                 │                    │
     │               │ Redirect with   │                    │
     │               │ sso_token       │                    │
     │               │────────────────▶│                    │
     │               │                 │                    │
     │               │                 │ Validate token     │
     │               │                 │───────────────────▶│
     │               │                 │                    │
     │               │                 │◀───────────────────│
     │               │                 │ User info          │
     │               │                 │                    │
     │◀──────────────────────────────────────────────────────│
     │                 Session created                      │
```

## Token Structure

The SSO token is a base64-encoded JSON payload:

```json
{
  "uid": "firebase-user-id",
  "email": "user@1pwrafrica.com",
  "displayName": "User Name",
  "idToken": "firebase-id-token-jwt",
  "targetSystem": "pr",
  "timestamp": 1706000000000,
  "nonce": "random-hex-string"
}
```

## Integration for React Apps (PR System)

### 1. Create SSO Handler Component

```tsx
// src/components/SSOHandler.tsx
import { useEffect } from 'react';
import { signInWithCustomToken } from 'firebase/auth';
import { auth } from '../config/firebase';

export function SSOHandler() {
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const ssoToken = params.get('sso_token');
    
    if (ssoToken) {
      handleSSO(ssoToken);
    }
  }, []);

  const handleSSO = async (token: string) => {
    try {
      const payload = JSON.parse(atob(token));
      
      // Validate timestamp (token expires after 5 minutes)
      if (Date.now() - payload.timestamp > 5 * 60 * 1000) {
        console.error('SSO token expired');
        return;
      }
      
      // The idToken is already a valid Firebase token
      // Just verify it's valid by making a test call
      const response = await fetch(
        `https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=${FIREBASE_API_KEY}`,
        {
          method: 'POST',
          body: JSON.stringify({ idToken: payload.idToken }),
        }
      );
      
      if (response.ok) {
        // Token is valid - user is already authenticated in Firebase
        // The onAuthStateChanged listener will pick up the session
        
        // Clean up URL
        window.history.replaceState({}, '', window.location.pathname);
      }
    } catch (error) {
      console.error('SSO error:', error);
    }
  };

  return null;
}
```

### 2. Add to App Root

```tsx
// src/App.tsx
import { SSOHandler } from './components/SSOHandler';

function App() {
  return (
    <>
      <SSOHandler />
      {/* rest of app */}
    </>
  );
}
```

## Integration for PHP Apps (HR Portal)

### 1. Create SSO Middleware

```php
<?php
// app/Http/Middleware/FirebaseSSO.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

class FirebaseSSO
{
    public function handle(Request $request, Closure $next)
    {
        $ssoToken = $request->query('sso_token');
        
        if ($ssoToken && !auth()->check()) {
            $this->handleSSO($ssoToken, $request);
        }
        
        return $next($request);
    }
    
    private function handleSSO(string $token, Request $request): void
    {
        try {
            $payload = json_decode(base64_decode($token), true);
            
            // Validate timestamp
            if (time() * 1000 - $payload['timestamp'] > 5 * 60 * 1000) {
                return;
            }
            
            // Validate Firebase ID token
            $user = $this->validateFirebaseToken($payload['idToken']);
            
            if ($user) {
                // Find or create local user
                $localUser = \App\Models\User::where('email', $user['email'])->first();
                
                if ($localUser) {
                    auth()->login($localUser);
                }
            }
        } catch (\Exception $e) {
            \Log::error('SSO error: ' . $e->getMessage());
        }
    }
    
    private function validateFirebaseToken(string $idToken): ?array
    {
        $projectId = config('services.firebase.project_id');
        
        // Fetch Google's public keys
        $keysUrl = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';
        $keys = json_decode(file_get_contents($keysUrl), true);
        
        try {
            $decoded = JWT::decode($idToken, JWK::parseKeySet(['keys' => $keys]));
            
            // Verify claims
            if ($decoded->aud !== $projectId) {
                return null;
            }
            if ($decoded->iss !== "https://securetoken.google.com/{$projectId}") {
                return null;
            }
            
            return [
                'uid' => $decoded->sub,
                'email' => $decoded->email,
                'name' => $decoded->name ?? '',
            ];
        } catch (\Exception $e) {
            return null;
        }
    }
}
```

### 2. Register Middleware

```php
// app/Http/Kernel.php
protected $middlewareGroups = [
    'web' => [
        // ... other middleware
        \App\Http\Middleware\FirebaseSSO::class,
    ],
];
```

### 3. Install JWT Package

```bash
composer require firebase/php-jwt
```

## Integration for O&M Portal (Custom Backend)

The O&M Portal uses a custom JWT system. To integrate with Nexus SSO:

### 1. Add Token Validation Endpoint

```typescript
// backend/routes/auth.ts
import * as admin from 'firebase-admin';

router.post('/sso/validate', async (req, res) => {
  const { idToken } = req.body;
  
  try {
    // Verify the Firebase ID token
    const decodedToken = await admin.auth().verifyIdToken(idToken);
    
    // Find user in your database
    const user = await User.findOne({ email: decodedToken.email });
    
    if (!user) {
      return res.status(404).json({ error: 'User not found' });
    }
    
    // Generate your existing JWT token
    const token = generateJWT(user);
    
    res.json({ token, user });
  } catch (error) {
    res.status(401).json({ error: 'Invalid token' });
  }
});
```

### 2. Initialize Firebase Admin SDK

```typescript
// backend/config/firebase.ts
import * as admin from 'firebase-admin';

admin.initializeApp({
  credential: admin.credential.cert({
    projectId: process.env.FIREBASE_PROJECT_ID,
    clientEmail: process.env.FIREBASE_CLIENT_EMAIL,
    privateKey: process.env.FIREBASE_PRIVATE_KEY,
  }),
});

export default admin;
```

## Security Considerations

1. **Token Expiry**: SSO tokens expire after 5 minutes
2. **Nonce**: Each token includes a unique nonce to prevent replay attacks
3. **HTTPS**: All communications must use HTTPS
4. **Domain Validation**: Target apps should verify the referrer is from nexus.1pwrafrica.com
5. **Token Validation**: Always validate the Firebase ID token server-side

## Testing SSO

1. Log into Nexus at hub.1pwrafrica.com
2. Click on a system tile (e.g., PR System)
3. You should be redirected to pr.1pwrafrica.com with SSO token
4. The PR system validates the token and creates a session
5. You're logged in without entering credentials again

## Troubleshooting

### "SSO token expired"
- Ensure your server's clock is synchronized
- The token is valid for 5 minutes

### "Invalid token"
- Verify the Firebase project ID matches
- Check that the Firebase ID token is being passed correctly

### "User not found"
- The user must exist in both Nexus and the target system
- Run the user reconciliation script to sync users
