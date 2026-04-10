import { createContext, useContext, useState, useEffect, ReactNode } from 'react';
import {
  User,
  signInWithEmailAndPassword,
  signOut,
  onAuthStateChanged,
} from 'firebase/auth';
import { doc, getDoc, updateDoc, serverTimestamp } from 'firebase/firestore';
import { auth, db } from '../config/firebase';
import { NexusUser, PERMISSION_LEVELS } from '../types/user';

interface AuthContextType {
  user: User | null;
  nexusUser: NexusUser | null;
  loading: boolean;
  error: string | null;
  isAdmin: boolean;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  clearError: () => void;
}

const AuthContext = createContext<AuthContextType | null>(null);

export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [nexusUser, setNexusUser] = useState<NexusUser | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const isAdmin = nexusUser?.systemAccess?.pr?.permissionLevel === PERMISSION_LEVELS.ADMIN;

  useEffect(() => {
    const unsubscribe = onAuthStateChanged(auth, async (firebaseUser) => {
      setUser(firebaseUser);

      if (firebaseUser) {
        try {
          // Try to get nexus_users profile first
          let userDoc = await getDoc(doc(db, 'nexus_users', firebaseUser.uid));
          
          // Fallback to legacy users collection if nexus_users doesn't exist
          if (!userDoc.exists()) {
            userDoc = await getDoc(doc(db, 'users', firebaseUser.uid));
          }

          if (userDoc.exists()) {
            const userData = userDoc.data();
            
            // Convert legacy user format to NexusUser if needed
            const nexusUserData: NexusUser = {
              uid: firebaseUser.uid,
              email: userData.email || firebaseUser.email || '',
              displayName: userData.displayName || userData.firstName + ' ' + userData.lastName || '',
              firstName: userData.firstName || '',
              lastName: userData.lastName || '',
              department: userData.department || '',
              organization: userData.organization || '',
              isActive: userData.isActive !== false,
              systemAccess: userData.systemAccess || {
                hr: { enabled: false, role: '' },
                pr: {
                  enabled: true,
                  permissionLevel: userData.permissionLevel || PERMISSION_LEVELS.REQ,
                  role: userData.role || 'Requester',
                },
                jobcards: { enabled: false, canEdit: false },
                am: { enabled: false, role: 'Viewer', countryAccess: [] },
                om: { enabled: false, role: '' },
                ugp: { enabled: false },
              },
              sources: userData.sources || ['pr'],
              needsFirebaseAccount: false,
              createdAt: userData.createdAt,
              updatedAt: userData.updatedAt,
              lastLoginAt: userData.lastLoginAt,
            };

            setNexusUser(nexusUserData);

            // Update last login
            await updateDoc(doc(db, userDoc.ref.parent.id, firebaseUser.uid), {
              lastLoginAt: serverTimestamp(),
            });
          } else {
            // User exists in Firebase Auth but not in Firestore
            setNexusUser({
              uid: firebaseUser.uid,
              email: firebaseUser.email || '',
              displayName: firebaseUser.displayName || '',
              firstName: '',
              lastName: '',
              department: '',
              organization: '',
              isActive: true,
              systemAccess: {
                hr: { enabled: false, role: '' },
                pr: { enabled: true, permissionLevel: PERMISSION_LEVELS.REQ, role: 'Requester' },
                jobcards: { enabled: false, canEdit: false },
                am: { enabled: false, role: 'Viewer', countryAccess: [] },
                om: { enabled: false, role: '' },
                ugp: { enabled: false },
              },
              sources: [],
              needsFirebaseAccount: false,
              createdAt: null as any,
              updatedAt: null as any,
              lastLoginAt: null,
            });
          }
        } catch (err) {
          console.error('Error fetching user profile:', err);
        }
      } else {
        setNexusUser(null);
      }

      setLoading(false);
    });

    return unsubscribe;
  }, []);

  const login = async (email: string, password: string) => {
    setError(null);
    try {
      await signInWithEmailAndPassword(auth, email, password);
    } catch (err: any) {
      const message = err.code === 'auth/invalid-credential'
        ? 'Invalid email or password'
        : err.code === 'auth/too-many-requests'
        ? 'Too many failed attempts. Please try again later.'
        : 'Login failed. Please try again.';
      setError(message);
      throw err;
    }
  };

  const logout = async () => {
    await signOut(auth);
    setNexusUser(null);
  };

  const clearError = () => setError(null);

  return (
    <AuthContext.Provider
      value={{
        user,
        nexusUser,
        loading,
        error,
        isAdmin,
        login,
        logout,
        clearError,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
}
