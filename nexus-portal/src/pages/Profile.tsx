import { useAuth } from '../contexts/AuthContext';
import { SYSTEMS, PERMISSION_NAMES } from '../types/user';
import { User, Mail, Building, Briefcase, Shield, Clock } from 'lucide-react';

export default function Profile() {
  const { nexusUser } = useAuth();

  const formatDate = (timestamp: any) => {
    if (!timestamp) return 'Never';
    const date = timestamp.toDate ? timestamp.toDate() : new Date(timestamp);
    return date.toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  };

  return (
    <div className="max-w-4xl mx-auto space-y-6">
      <h1 className="text-2xl font-bold text-slate-900">Your Profile</h1>

      {/* Basic Info Card */}
      <div className="card p-6">
        <h2 className="text-lg font-semibold text-slate-900 mb-4 flex items-center gap-2">
          <User size={20} />
          Personal Information
        </h2>
        
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <label className="block text-sm font-medium text-slate-500 mb-1">Full Name</label>
            <p className="text-slate-900">{nexusUser?.displayName || 'Not set'}</p>
          </div>
          
          <div>
            <label className="block text-sm font-medium text-slate-500 mb-1">Email Address</label>
            <p className="text-slate-900 flex items-center gap-2">
              <Mail size={16} className="text-slate-400" />
              {nexusUser?.email}
            </p>
          </div>
          
          <div>
            <label className="block text-sm font-medium text-slate-500 mb-1">Department</label>
            <p className="text-slate-900 flex items-center gap-2">
              <Briefcase size={16} className="text-slate-400" />
              {nexusUser?.department || 'Not assigned'}
            </p>
          </div>
          
          <div>
            <label className="block text-sm font-medium text-slate-500 mb-1">Organization</label>
            <p className="text-slate-900 flex items-center gap-2">
              <Building size={16} className="text-slate-400" />
              {nexusUser?.organization || 'OnePower'}
            </p>
          </div>
        </div>
      </div>

      {/* System Access Card */}
      <div className="card p-6">
        <h2 className="text-lg font-semibold text-slate-900 mb-4 flex items-center gap-2">
          <Shield size={20} />
          System Access
        </h2>
        
        <div className="space-y-4">
          {SYSTEMS.map((system) => {
            const access = nexusUser?.systemAccess?.[system.key];
            const hasAccess = access?.enabled ?? false;

            return (
              <div
                key={system.key}
                className={`flex items-center justify-between p-4 rounded-lg ${
                  hasAccess ? 'bg-green-50' : 'bg-slate-50'
                }`}
              >
                <div className="flex items-center gap-3">
                  <div
                    className="w-10 h-10 rounded-lg flex items-center justify-center"
                    style={{ backgroundColor: hasAccess ? system.color + '20' : '#e2e8f0' }}
                  >
                    <span style={{ color: hasAccess ? system.color : '#94a3b8' }}>
                      {system.name.charAt(0)}
                    </span>
                  </div>
                  <div>
                    <p className="font-medium text-slate-900">{system.name}</p>
                    <p className="text-sm text-slate-500">{system.description}</p>
                  </div>
                </div>
                
                <div className="text-right">
                  {hasAccess ? (
                    <>
                      <span className="inline-flex items-center text-sm font-medium text-green-700 bg-green-100 px-3 py-1 rounded-full">
                        Active
                      </span>
                      <p className="text-sm text-slate-500 mt-1">
                        {system.key === 'pr' && access?.permissionLevel
                          ? PERMISSION_NAMES[access.permissionLevel] || access.role
                          : access?.role || 'User'}
                      </p>
                    </>
                  ) : (
                    <span className="inline-flex items-center text-sm font-medium text-slate-500 bg-slate-200 px-3 py-1 rounded-full">
                      No Access
                    </span>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      </div>

      {/* Account Info Card */}
      <div className="card p-6">
        <h2 className="text-lg font-semibold text-slate-900 mb-4 flex items-center gap-2">
          <Clock size={20} />
          Account Information
        </h2>
        
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <label className="block text-sm font-medium text-slate-500 mb-1">Account Status</label>
            <span
              className={`inline-flex items-center text-sm font-medium px-3 py-1 rounded-full ${
                nexusUser?.isActive
                  ? 'bg-green-100 text-green-700'
                  : 'bg-red-100 text-red-700'
              }`}
            >
              {nexusUser?.isActive ? 'Active' : 'Inactive'}
            </span>
          </div>
          
          <div>
            <label className="block text-sm font-medium text-slate-500 mb-1">User ID</label>
            <p className="text-slate-900 font-mono text-sm">{nexusUser?.uid || 'N/A'}</p>
          </div>
          
          <div>
            <label className="block text-sm font-medium text-slate-500 mb-1">Last Login</label>
            <p className="text-slate-900">{formatDate(nexusUser?.lastLoginAt)}</p>
          </div>
          
          <div>
            <label className="block text-sm font-medium text-slate-500 mb-1">Data Sources</label>
            <div className="flex flex-wrap gap-2">
              {nexusUser?.sources?.map((source) => (
                <span
                  key={source}
                  className="inline-flex items-center text-xs font-medium px-2 py-1 rounded bg-slate-100 text-slate-600"
                >
                  {source.toUpperCase()}
                </span>
              )) || <span className="text-slate-500">None</span>}
            </div>
          </div>
        </div>
      </div>

      {/* Help Section */}
      <div className="card p-6 bg-blue-50 border-blue-200">
        <h3 className="font-semibold text-blue-900 mb-2">Need to update your information?</h3>
        <p className="text-sm text-blue-700 mb-4">
          Contact HR or your administrator to update your profile details or request additional system access.
        </p>
        <a
          href="mailto:hr@1pwrafrica.com?subject=Profile Update Request"
          className="btn bg-blue-600 text-white hover:bg-blue-700"
        >
          Contact HR
        </a>
      </div>
    </div>
  );
}
