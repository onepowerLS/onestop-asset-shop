import { useState, useEffect } from 'react';
import { collection, getDocs, doc, updateDoc, query, orderBy } from 'firebase/firestore';
import { db } from '../config/firebase';
import { NexusUser, SYSTEMS, PERMISSION_NAMES, SystemKey } from '../types/user';
import { Users, Search, Check, X, ChevronDown, ChevronUp, Save } from 'lucide-react';

export default function Admin() {
  const [users, setUsers] = useState<NexusUser[]>([]);
  const [loading, setLoading] = useState(true);
  const [searchTerm, setSearchTerm] = useState('');
  const [expandedUser, setExpandedUser] = useState<string | null>(null);
  const [saving, setSaving] = useState<string | null>(null);
  const [editedAccess, setEditedAccess] = useState<Record<string, any>>({});

  useEffect(() => {
    fetchUsers();
  }, []);

  const fetchUsers = async () => {
    try {
      // Try nexus_users first, fallback to users
      let q = query(collection(db, 'nexus_users'), orderBy('email'));
      let snapshot = await getDocs(q);
      
      if (snapshot.empty) {
        q = query(collection(db, 'users'), orderBy('email'));
        snapshot = await getDocs(q);
      }

      const userList: NexusUser[] = [];
      snapshot.forEach((doc) => {
        const data = doc.data();
        userList.push({
          uid: doc.id,
          email: data.email || '',
          displayName: data.displayName || `${data.firstName || ''} ${data.lastName || ''}`.trim(),
          firstName: data.firstName || '',
          lastName: data.lastName || '',
          department: data.department || '',
          organization: data.organization || '',
          isActive: data.isActive !== false,
          systemAccess: data.systemAccess || {
            hr: { enabled: false, role: '' },
            pr: { enabled: true, permissionLevel: 5, role: 'Requester' },
            jobcards: { enabled: false, canEdit: false },
            am: { enabled: false, role: 'Viewer', countryAccess: [] },
            om: { enabled: false, role: '' },
            ugp: { enabled: false },
          },
          sources: data.sources || [],
          needsFirebaseAccount: data.needsFirebaseAccount || false,
          createdAt: data.createdAt,
          updatedAt: data.updatedAt,
          lastLoginAt: data.lastLoginAt,
        });
      });

      setUsers(userList);
    } catch (error) {
      console.error('Error fetching users:', error);
    } finally {
      setLoading(false);
    }
  };

  const filteredUsers = users.filter((user) => {
    const term = searchTerm.toLowerCase();
    return (
      user.email.toLowerCase().includes(term) ||
      user.displayName.toLowerCase().includes(term) ||
      user.department.toLowerCase().includes(term)
    );
  });

  const toggleUserExpand = (uid: string) => {
    if (expandedUser === uid) {
      setExpandedUser(null);
    } else {
      setExpandedUser(uid);
      setEditedAccess({
        ...users.find(u => u.uid === uid)?.systemAccess,
      });
    }
  };

  const handleAccessChange = (system: SystemKey, field: string, value: any) => {
    setEditedAccess((prev) => ({
      ...prev,
      [system]: {
        ...prev[system],
        [field]: value,
      },
    }));
  };

  const handleSaveAccess = async (uid: string) => {
    setSaving(uid);
    try {
      // Try nexus_users first
      const nexusRef = doc(db, 'nexus_users', uid);
      await updateDoc(nexusRef, {
        systemAccess: editedAccess,
        updatedAt: new Date(),
      });

      // Update local state
      setUsers((prev) =>
        prev.map((u) =>
          u.uid === uid ? { ...u, systemAccess: editedAccess } : u
        )
      );

      setExpandedUser(null);
    } catch (error) {
      console.error('Error saving:', error);
      // Try legacy users collection
      try {
        const userRef = doc(db, 'users', uid);
        await updateDoc(userRef, {
          systemAccess: editedAccess,
          updatedAt: new Date(),
        });
        setUsers((prev) =>
          prev.map((u) =>
            u.uid === uid ? { ...u, systemAccess: editedAccess } : u
          )
        );
        setExpandedUser(null);
      } catch (e) {
        console.error('Error saving to users collection:', e);
        alert('Failed to save changes. Please try again.');
      }
    } finally {
      setSaving(null);
    }
  };

  const toggleUserActive = async (uid: string, currentStatus: boolean) => {
    try {
      const userRef = doc(db, 'nexus_users', uid);
      await updateDoc(userRef, {
        isActive: !currentStatus,
        updatedAt: new Date(),
      });

      setUsers((prev) =>
        prev.map((u) =>
          u.uid === uid ? { ...u, isActive: !currentStatus } : u
        )
      );
    } catch (error) {
      console.error('Error toggling status:', error);
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className="w-8 h-8 border-4 border-primary-200 border-t-primary-600 rounded-full animate-spin"></div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-900 flex items-center gap-3">
          <Users size={28} />
          User Management
        </h1>
        <div className="text-sm text-slate-500">
          {users.length} users total
        </div>
      </div>

      {/* Search */}
      <div className="relative">
        <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={20} />
        <input
          type="text"
          placeholder="Search by email, name, or department..."
          value={searchTerm}
          onChange={(e) => setSearchTerm(e.target.value)}
          className="input pl-10"
        />
      </div>

      {/* Users List */}
      <div className="space-y-3">
        {filteredUsers.map((user) => (
          <div key={user.uid} className="card overflow-hidden">
            {/* User Header */}
            <div
              className="p-4 flex items-center justify-between cursor-pointer hover:bg-slate-50"
              onClick={() => toggleUserExpand(user.uid!)}
            >
              <div className="flex items-center gap-4">
                <div className="w-10 h-10 rounded-full bg-primary-100 flex items-center justify-center text-primary-700 font-semibold">
                  {user.displayName?.charAt(0) || user.email.charAt(0).toUpperCase()}
                </div>
                <div>
                  <p className="font-medium text-slate-900">{user.displayName || user.email}</p>
                  <p className="text-sm text-slate-500">{user.email}</p>
                </div>
              </div>

              <div className="flex items-center gap-4">
                <span
                  className={`text-xs font-medium px-2 py-1 rounded-full ${
                    user.isActive
                      ? 'bg-green-100 text-green-700'
                      : 'bg-red-100 text-red-700'
                  }`}
                >
                  {user.isActive ? 'Active' : 'Inactive'}
                </span>
                <div className="flex gap-1">
                  {SYSTEMS.map((sys) => (
                    <span
                      key={sys.key}
                      className={`w-6 h-6 rounded flex items-center justify-center text-xs font-bold ${
                        user.systemAccess?.[sys.key]?.enabled
                          ? 'bg-green-100 text-green-700'
                          : 'bg-slate-100 text-slate-400'
                      }`}
                      title={`${sys.name}: ${user.systemAccess?.[sys.key]?.enabled ? 'Enabled' : 'Disabled'}`}
                    >
                      {sys.key.charAt(0).toUpperCase()}
                    </span>
                  ))}
                </div>
                {expandedUser === user.uid ? (
                  <ChevronUp size={20} className="text-slate-400" />
                ) : (
                  <ChevronDown size={20} className="text-slate-400" />
                )}
              </div>
            </div>

            {/* Expanded Content */}
            {expandedUser === user.uid && (
              <div className="border-t border-slate-200 p-4 bg-slate-50">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                  <div>
                    <p className="text-sm text-slate-500">Department</p>
                    <p className="font-medium">{user.department || 'Not set'}</p>
                  </div>
                  <div>
                    <p className="text-sm text-slate-500">Organization</p>
                    <p className="font-medium">{user.organization || 'Not set'}</p>
                  </div>
                </div>

                <h4 className="font-semibold text-slate-900 mb-3">System Access</h4>
                <div className="space-y-3">
                  {SYSTEMS.map((sys) => (
                    <div
                      key={sys.key}
                      className="flex items-center justify-between p-3 bg-white rounded-lg border border-slate-200"
                    >
                      <div className="flex items-center gap-3">
                        <span
                          className="w-8 h-8 rounded flex items-center justify-center text-sm font-bold text-white"
                          style={{ backgroundColor: sys.color }}
                        >
                          {sys.key.charAt(0).toUpperCase()}
                        </span>
                        <span className="font-medium">{sys.name}</span>
                      </div>

                      <div className="flex items-center gap-4">
                        {sys.key === 'pr' && editedAccess[sys.key]?.enabled && (
                          <select
                            value={editedAccess[sys.key]?.permissionLevel || 5}
                            onChange={(e) =>
                              handleAccessChange(sys.key, 'permissionLevel', parseInt(e.target.value))
                            }
                            className="text-sm border border-slate-300 rounded px-2 py-1"
                          >
                            {Object.entries(PERMISSION_NAMES).map(([level, name]) => (
                              <option key={level} value={level}>
                                {name}
                              </option>
                            ))}
                          </select>
                        )}

                        <button
                          onClick={(e) => {
                            e.stopPropagation();
                            handleAccessChange(sys.key, 'enabled', !editedAccess[sys.key]?.enabled);
                          }}
                          className={`w-10 h-6 rounded-full relative transition-colors ${
                            editedAccess[sys.key]?.enabled
                              ? 'bg-green-500'
                              : 'bg-slate-300'
                          }`}
                        >
                          <span
                            className={`absolute top-1 w-4 h-4 rounded-full bg-white transition-transform ${
                              editedAccess[sys.key]?.enabled
                                ? 'translate-x-5'
                                : 'translate-x-1'
                            }`}
                          />
                        </button>
                      </div>
                    </div>
                  ))}
                </div>

                <div className="flex justify-between mt-4 pt-4 border-t border-slate-200">
                  <button
                    onClick={() => toggleUserActive(user.uid!, user.isActive)}
                    className={`btn ${
                      user.isActive
                        ? 'bg-red-100 text-red-700 hover:bg-red-200'
                        : 'bg-green-100 text-green-700 hover:bg-green-200'
                    }`}
                  >
                    {user.isActive ? (
                      <>
                        <X size={16} className="mr-2" />
                        Deactivate User
                      </>
                    ) : (
                      <>
                        <Check size={16} className="mr-2" />
                        Activate User
                      </>
                    )}
                  </button>

                  <button
                    onClick={() => handleSaveAccess(user.uid!)}
                    disabled={saving === user.uid}
                    className="btn btn-primary flex items-center gap-2"
                  >
                    {saving === user.uid ? (
                      <span className="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                    ) : (
                      <Save size={16} />
                    )}
                    Save Changes
                  </button>
                </div>
              </div>
            )}
          </div>
        ))}
      </div>

      {filteredUsers.length === 0 && (
        <div className="text-center py-12 text-slate-500">
          No users found matching "{searchTerm}"
        </div>
      )}
    </div>
  );
}
