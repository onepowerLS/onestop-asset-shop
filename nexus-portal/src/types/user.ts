import { Timestamp } from 'firebase/firestore';

export interface HRAccess {
  enabled: boolean;
  role: string;
  employeeId?: string;
}

export interface PRAccess {
  enabled: boolean;
  permissionLevel: number;
  role: string;
}

export interface JobCardsAccess {
  enabled: boolean;
  canEdit: boolean;
}

export interface AMAccess {
  enabled: boolean;
  role: string;
  countryAccess: string[];
}

export interface OMAccess {
  enabled: boolean;
  role: string;
}

export interface UGPAccess {
  enabled: boolean;
}

export interface SystemAccess {
  hr: HRAccess;
  pr: PRAccess;
  jobcards: JobCardsAccess;
  am: AMAccess;
  om: OMAccess;
  ugp: UGPAccess;
}

export interface NexusUser {
  uid: string | null;
  email: string;
  displayName: string;
  firstName: string;
  lastName: string;
  department: string;
  organization: string;
  isActive: boolean;
  systemAccess: SystemAccess;
  sources: string[];
  needsFirebaseAccount: boolean;
  createdAt: Timestamp;
  updatedAt: Timestamp;
  lastLoginAt: Timestamp | null;
  importedAt?: Timestamp;
  importVersion?: string;
}

export interface AccessRequest {
  requestId: string;
  userId: string;
  userEmail: string;
  requestedSystem: keyof SystemAccess;
  requestedRole: string;
  justification: string;
  status: 'pending' | 'approved' | 'rejected';
  reviewedBy: string | null;
  reviewedAt: Timestamp | null;
  reviewNotes: string;
  createdAt: Timestamp;
}

export const PERMISSION_LEVELS = {
  ADMIN: 1,
  APPROVER: 2,
  PROC: 3,
  FIN_AD: 4,
  REQ: 5,
  APPROVER_2: 6,
} as const;

export const PERMISSION_NAMES: Record<number, string> = {
  [PERMISSION_LEVELS.ADMIN]: 'Administrator',
  [PERMISSION_LEVELS.APPROVER]: 'Approver',
  [PERMISSION_LEVELS.PROC]: 'Procurement',
  [PERMISSION_LEVELS.FIN_AD]: 'Finance Admin',
  [PERMISSION_LEVELS.REQ]: 'Requester',
  [PERMISSION_LEVELS.APPROVER_2]: 'Approver 2',
};

export type SystemKey = keyof SystemAccess;

export interface SystemInfo {
  key: SystemKey;
  name: string;
  description: string;
  url: string;
  icon: string;
  color: string;
}

export const SYSTEMS: SystemInfo[] = [
  {
    key: 'hr',
    name: 'HR Portal',
    description: 'Human Resources & Employee Management',
    url: 'https://nexus.1pwrafrica.com',
    icon: 'users',
    color: '#6366f1',
  },
  {
    key: 'pr',
    name: 'PR System',
    description: 'Purchase Requests & Procurement',
    url: 'https://pr.1pwrafrica.com',
    icon: 'shopping-cart',
    color: '#10b981',
  },
  {
    key: 'jobcards',
    name: 'Job Cards',
    description: 'Production Job Card Management',
    url: 'https://prod.1pwrafrica.com',
    icon: 'clipboard-list',
    color: '#f59e0b',
  },
  {
    key: 'am',
    name: 'Asset Management',
    description: 'Asset Tracking & Inventory',
    url: 'https://assets.1pwrafrica.com',
    icon: 'archive',
    color: '#8b5cf6',
  },
  {
    key: 'om',
    name: 'O&M Portal',
    description: 'Operations & Maintenance',
    url: 'https://om.1pwrafrica.com',
    icon: 'tool',
    color: '#ef4444',
  },
  {
    key: 'ugp',
    name: 'uGridPlan',
    description: 'Grid Planning & Design',
    url: '#',
    icon: 'map',
    color: '#06b6d4',
  },
];
