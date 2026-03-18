/**
 * Import Reconciled Users to Firestore
 * 
 * Imports the reconciled user profiles into the nexus_users collection.
 * 
 * Usage:
 *   1. Run reconcile-users.js first to generate reconciled-users.json
 *   2. Set GOOGLE_APPLICATION_CREDENTIALS
 *   3. Run: node import-to-firestore.js [--dry-run]
 */

const admin = require('firebase-admin');
const fs = require('fs');
const path = require('path');

const FIREBASE_PROJECT_ID = 'pr-system-4ea55';
const COLLECTION_NAME = 'nexus_users';

async function initializeFirebase() {
  if (admin.apps.length === 0) {
    if (process.env.GOOGLE_APPLICATION_CREDENTIALS) {
      admin.initializeApp({
        credential: admin.credential.applicationDefault(),
        projectId: FIREBASE_PROJECT_ID,
      });
    } else {
      console.error('ERROR: Set GOOGLE_APPLICATION_CREDENTIALS environment variable');
      process.exit(1);
    }
  }
  return admin.firestore();
}

async function importUsers(dryRun = false) {
  console.log(`=== Import Users to Firestore ===`);
  console.log(`Mode: ${dryRun ? 'DRY RUN' : 'LIVE'}\n`);
  
  // Load reconciled users
  const inputPath = path.join(__dirname, 'reconciled-users.json');
  if (!fs.existsSync(inputPath)) {
    console.error('ERROR: reconciled-users.json not found. Run reconcile-users.js first.');
    process.exit(1);
  }
  
  const users = JSON.parse(fs.readFileSync(inputPath, 'utf-8'));
  console.log(`Loaded ${users.length} reconciled users\n`);
  
  if (dryRun) {
    console.log('DRY RUN - No changes will be made\n');
    console.log('Sample documents that would be created:\n');
    users.slice(0, 3).forEach(user => {
      console.log(JSON.stringify(convertToFirestoreDoc(user), null, 2));
      console.log('---');
    });
    console.log(`\nWould import ${users.length} users to ${COLLECTION_NAME}`);
    return;
  }
  
  const db = await initializeFirebase();
  const batch = db.batch();
  let batchCount = 0;
  let totalImported = 0;
  
  for (const user of users) {
    const docData = convertToFirestoreDoc(user);
    
    // Use UID as document ID if available, otherwise use email hash
    const docId = user.uid || hashEmail(user.email);
    const docRef = db.collection(COLLECTION_NAME).doc(docId);
    
    batch.set(docRef, docData, { merge: true });
    batchCount++;
    
    // Firestore batches are limited to 500 operations
    if (batchCount >= 500) {
      await batch.commit();
      console.log(`Committed batch of ${batchCount} users`);
      totalImported += batchCount;
      batchCount = 0;
    }
  }
  
  // Commit remaining
  if (batchCount > 0) {
    await batch.commit();
    totalImported += batchCount;
    console.log(`Committed final batch of ${batchCount} users`);
  }
  
  console.log(`\nSuccessfully imported ${totalImported} users to ${COLLECTION_NAME}`);
}

function convertToFirestoreDoc(user) {
  return {
    uid: user.uid || null,
    email: user.email,
    displayName: user.displayName || `${user.firstName} ${user.lastName}`.trim() || user.email,
    firstName: user.firstName || '',
    lastName: user.lastName || '',
    department: user.department || '',
    organization: user.organization || '',
    isActive: user.isActive !== false,
    
    systemAccess: {
      hr: user.systemAccess?.hr || { enabled: false },
      pr: user.systemAccess?.pr || { enabled: false },
      jobcards: user.systemAccess?.jobcards || { enabled: false, canEdit: false },
      am: user.systemAccess?.am || { enabled: false, role: 'Viewer', countryAccess: [] },
      om: user.systemAccess?.om || { enabled: false, role: '' },
      ugp: user.systemAccess?.ugp || { enabled: false },
    },
    
    sources: user.sources || [],
    needsFirebaseAccount: user.needsFirebaseAccount || false,
    
    createdAt: user.createdAt ? admin.firestore.Timestamp.fromDate(new Date(user.createdAt)) : admin.firestore.FieldValue.serverTimestamp(),
    updatedAt: admin.firestore.FieldValue.serverTimestamp(),
    lastLoginAt: user.lastLoginAt ? admin.firestore.Timestamp.fromDate(new Date(user.lastLoginAt)) : null,
    
    // Metadata
    importedAt: admin.firestore.FieldValue.serverTimestamp(),
    importVersion: '1.0.0',
  };
}

function hashEmail(email) {
  const crypto = require('crypto');
  return crypto.createHash('sha256').update(email.toLowerCase()).digest('hex').substring(0, 28);
}

// Parse arguments
const args = process.argv.slice(2);
const dryRun = args.includes('--dry-run');

importUsers(dryRun)
  .then(() => process.exit(0))
  .catch(err => {
    console.error('Error:', err);
    process.exit(1);
  });
