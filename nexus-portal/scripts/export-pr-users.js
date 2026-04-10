/**
 * Export PR System Users from Firestore
 * 
 * This script exports all users from the PR system's Firestore database
 * for reconciliation with other 1PWR systems.
 * 
 * Usage:
 *   1. Set GOOGLE_APPLICATION_CREDENTIALS to your service account key
 *   2. Run: node export-pr-users.js
 *   3. Output: pr-users-export.json
 */

const admin = require('firebase-admin');
const fs = require('fs');
const path = require('path');

const FIREBASE_PROJECT_ID = 'pr-system-4ea55';

async function initializeFirebase() {
  if (admin.apps.length === 0) {
    if (process.env.GOOGLE_APPLICATION_CREDENTIALS) {
      admin.initializeApp({
        credential: admin.credential.applicationDefault(),
        projectId: FIREBASE_PROJECT_ID,
      });
    } else {
      console.error('ERROR: Set GOOGLE_APPLICATION_CREDENTIALS environment variable');
      console.log('Example: export GOOGLE_APPLICATION_CREDENTIALS=/path/to/service-account-key.json');
      process.exit(1);
    }
  }
  return admin.firestore();
}

async function exportPRUsers() {
  console.log('=== PR System User Export ===\n');
  
  const db = await initializeFirebase();
  
  const usersRef = db.collection('users');
  const snapshot = await usersRef.get();
  
  const users = [];
  
  snapshot.forEach(doc => {
    const data = doc.data();
    users.push({
      uid: doc.id,
      email: data.email || '',
      firstName: data.firstName || '',
      lastName: data.lastName || '',
      displayName: data.displayName || `${data.firstName || ''} ${data.lastName || ''}`.trim(),
      role: data.role || '',
      permissionLevel: data.permissionLevel ?? null,
      department: data.department || '',
      organization: data.organization || '',
      isActive: data.isActive !== false,
      createdAt: data.createdAt?.toDate?.()?.toISOString() || null,
      lastLogin: data.lastLogin?.toDate?.()?.toISOString() || null,
      source: 'pr-system',
    });
  });
  
  console.log(`Found ${users.length} users in PR System\n`);
  
  // Save to file
  const outputPath = path.join(__dirname, 'pr-users-export.json');
  fs.writeFileSync(outputPath, JSON.stringify(users, null, 2));
  console.log(`Exported to: ${outputPath}`);
  
  // Print summary
  console.log('\n=== Summary ===');
  const activeUsers = users.filter(u => u.isActive);
  const inactiveUsers = users.filter(u => !u.isActive);
  console.log(`Active users: ${activeUsers.length}`);
  console.log(`Inactive users: ${inactiveUsers.length}`);
  
  // Group by permission level
  const byPermission = {};
  users.forEach(u => {
    const level = u.permissionLevel ?? 'unset';
    byPermission[level] = (byPermission[level] || 0) + 1;
  });
  console.log('\nBy Permission Level:');
  Object.entries(byPermission).forEach(([level, count]) => {
    console.log(`  Level ${level}: ${count}`);
  });
  
  return users;
}

// Run if called directly
if (require.main === module) {
  exportPRUsers()
    .then(() => process.exit(0))
    .catch(err => {
      console.error('Error:', err);
      process.exit(1);
    });
}

module.exports = { exportPRUsers };
