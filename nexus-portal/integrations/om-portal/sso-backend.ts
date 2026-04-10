/**
 * SSO Backend Route for O&M Portal
 * 
 * Add this endpoint to your O&M Portal backend to validate
 * Firebase ID tokens and issue O&M JWT tokens.
 * 
 * Requirements:
 *   npm install firebase-admin
 * 
 * Environment variables:
 *   FIREBASE_PROJECT_ID=pr-system-4ea55
 *   FIREBASE_CLIENT_EMAIL=...
 *   FIREBASE_PRIVATE_KEY=...
 */

import express, { Request, Response, Router } from 'express';
import * as admin from 'firebase-admin';
import jwt from 'jsonwebtoken'; // Your existing JWT library

const router: Router = express.Router();

// Initialize Firebase Admin (do this once in your app setup)
if (!admin.apps.length) {
  admin.initializeApp({
    credential: admin.credential.cert({
      projectId: process.env.FIREBASE_PROJECT_ID,
      clientEmail: process.env.FIREBASE_CLIENT_EMAIL,
      privateKey: process.env.FIREBASE_PRIVATE_KEY?.replace(/\\n/g, '\n'),
    }),
  });
}

interface SSOValidateRequest {
  idToken: string;
  email: string;
}

/**
 * POST /api/auth/sso/validate
 * 
 * Validates a Firebase ID token from Nexus SSO and returns
 * an O&M Portal JWT token.
 */
router.post('/sso/validate', async (req: Request, res: Response) => {
  const { idToken, email }: SSOValidateRequest = req.body;
  
  if (!idToken) {
    return res.status(400).json({ error: 'Missing idToken' });
  }
  
  try {
    // Verify the Firebase ID token
    const decodedToken = await admin.auth().verifyIdToken(idToken);
    
    // Verify email matches (extra security check)
    if (email && decodedToken.email !== email) {
      return res.status(401).json({ error: 'Email mismatch' });
    }
    
    // Find user in O&M database
    // Replace this with your actual user lookup
    const user = await findUserByEmail(decodedToken.email!);
    
    if (!user) {
      return res.status(404).json({ 
        error: 'User not found',
        message: 'No O&M Portal account exists for this email. Contact your administrator.',
      });
    }
    
    if (!user.isActive) {
      return res.status(403).json({
        error: 'Account disabled',
        message: 'Your O&M Portal account is disabled.',
      });
    }
    
    // Generate O&M Portal JWT token (using your existing method)
    const omToken = generateOMToken(user);
    
    // Update last login
    await updateLastLogin(user.id, decodedToken.uid);
    
    return res.json({
      token: omToken,
      user: {
        user_id: user.id,
        email: user.email,
        name: user.name,
        role: user.role,
        user_type: user.user_type,
      },
    });
  } catch (error: any) {
    console.error('SSO validation error:', error);
    
    if (error.code === 'auth/id-token-expired') {
      return res.status(401).json({ error: 'Token expired' });
    }
    
    if (error.code === 'auth/argument-error') {
      return res.status(400).json({ error: 'Invalid token format' });
    }
    
    return res.status(500).json({ error: 'Internal server error' });
  }
});

// Placeholder functions - replace with your actual implementations

async function findUserByEmail(email: string): Promise<any> {
  // TODO: Replace with your database query
  // Example with Prisma:
  // return prisma.user.findUnique({ where: { email } });
  throw new Error('Implement findUserByEmail');
}

function generateOMToken(user: any): string {
  // TODO: Replace with your existing JWT generation
  // Example:
  // return jwt.sign(
  //   { userId: user.id, email: user.email, role: user.role },
  //   process.env.JWT_SECRET!,
  //   { expiresIn: '7d' }
  // );
  throw new Error('Implement generateOMToken');
}

async function updateLastLogin(userId: string, firebaseUid: string): Promise<void> {
  // TODO: Replace with your database update
  // Example with Prisma:
  // await prisma.user.update({
  //   where: { id: userId },
  //   data: { lastLoginAt: new Date(), firebaseUid },
  // });
}

export default router;
