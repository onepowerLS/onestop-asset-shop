# Nexus User Schema

## Overview

The `nexus_users` collection is the single source of truth for user identity and system access across all 1PWR tools. It lives in the shared Firebase project (`pr-system-4ea55`).

## Collection: `nexus_users`

Document ID: Firebase Auth UID (or email hash for users without Firebase accounts)

### Schema

```typescript
interface NexusUser {
  // Identity
  uid: string | null;           // Firebase Auth UID
  email: string;                // Primary identifier (unique)
  displayName: string;          // Full name for display
  firstName: string;
  lastName: string;
  
  // Organization
  department: string;           // e.g., "Engineering", "Finance"
  organization: string;         // e.g., "OnePower Lesotho"
  
  // Status
  isActive: boolean;            // Can user access any systems?
  
  // Per-System Access
  systemAccess: {
    hr: HRAccess;
    pr: PRAccess;
    jobcards: JobCardsAccess;
    am: AMAccess;
    om: OMAccess;
    ugp: UGPAccess;
  };
  
  // Migration metadata
  sources: string[];            // Where user data came from: ['pr', 'hr', 'om']
  needsFirebaseAccount: boolean; // True if no Firebase Auth account exists
  
  // Timestamps
  createdAt: Timestamp;
  updatedAt: Timestamp;
  lastLoginAt: Timestamp | null;
  importedAt: Timestamp;
  importVersion: string;
}

// System-specific access interfaces
interface HRAccess {
  enabled: boolean;
  role: string;                 // 'admin', 'manager', 'user'
  employeeId?: string;          // Link to HR MySQL users.id
}

interface PRAccess {
  enabled: boolean;
  permissionLevel: number;      // 1=Admin, 2=Approver, 3=Proc, 4=Fin_AD, 5=Req, 6=Approver2
  role: string;                 // Human-readable role name
}

interface JobCardsAccess {
  enabled: boolean;
  canEdit: boolean;
}

interface AMAccess {
  enabled: boolean;
  role: string;                 // 'Admin', 'Manager', 'Operator', 'Viewer'
  countryAccess: string[];      // ['LSO', 'ZMB', 'BEN']
}

interface OMAccess {
  enabled: boolean;
  role: string;                 // 'superadmin', 'admin', 'finance', 'employee'
}

interface UGPAccess {
  enabled: boolean;
  // Add more fields as uGridPlan auth is defined
}
```

## Permission Level Reference (PR System)

| Level | Name | Description |
|-------|------|-------------|
| 1 | Admin | Full system access |
| 2 | Approver | Can approve PRs |
| 3 | Procurement | Procurement team member |
| 4 | Finance Admin | Finance team with approval |
| 5 | Requester | Can create PRs |
| 6 | Approver 2 | Secondary approver |

## Collection: `access_requests`

When users need access to a system they don't have, they can submit a request.

```typescript
interface AccessRequest {
  requestId: string;
  userId: string;               // Firebase UID of requester
  userEmail: string;
  requestedSystem: string;      // 'hr' | 'pr' | 'am' | 'om' | 'ugp'
  requestedRole: string;        // What role they want
  justification: string;        // Why they need access
  status: 'pending' | 'approved' | 'rejected';
  reviewedBy: string | null;    // UID of admin who reviewed
  reviewedAt: Timestamp | null;
  reviewNotes: string;
  createdAt: Timestamp;
}
```

## Indexes

Create these composite indexes in Firebase Console or via CLI:

```
Collection: nexus_users
  - email (Ascending) + isActive (Ascending)
  - systemAccess.pr.enabled (Ascending) + isActive (Ascending)
  - systemAccess.am.enabled (Ascending) + isActive (Ascending)
  - department (Ascending) + isActive (Ascending)

Collection: access_requests
  - status (Ascending) + createdAt (Descending)
  - userId (Ascending) + createdAt (Descending)
```

## Usage Examples

### Check if user has system access

```typescript
import { doc, getDoc } from 'firebase/firestore';

async function canAccessSystem(uid: string, system: keyof NexusUser['systemAccess']) {
  const userDoc = await getDoc(doc(db, 'nexus_users', uid));
  if (!userDoc.exists()) return false;
  
  const user = userDoc.data() as NexusUser;
  return user.isActive && user.systemAccess[system]?.enabled === true;
}
```

### Get user's role for a system

```typescript
async function getSystemRole(uid: string, system: keyof NexusUser['systemAccess']) {
  const userDoc = await getDoc(doc(db, 'nexus_users', uid));
  if (!userDoc.exists()) return null;
  
  const user = userDoc.data() as NexusUser;
  if (!user.isActive || !user.systemAccess[system]?.enabled) return null;
  
  return user.systemAccess[system].role;
}
```

### Update last login timestamp

```typescript
async function updateLastLogin(uid: string) {
  await updateDoc(doc(db, 'nexus_users', uid), {
    lastLoginAt: serverTimestamp(),
  });
}
```

## Migration Notes

1. Users from PR System already have Firebase UIDs - use those as document IDs
2. Users from HR/O&M only need Firebase accounts created, then link by email
3. The `sources` array tracks where user data originated for audit purposes
4. `needsFirebaseAccount: true` flags users who exist in HR/O&M but not Firebase
