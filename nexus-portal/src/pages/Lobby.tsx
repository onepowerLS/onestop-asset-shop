import { useAuth } from '../contexts/AuthContext';
import { SYSTEMS, SystemKey } from '../types/user';
import { openWithSSO } from '../lib/sso';
import {
  Users,
  ShoppingCart,
  ClipboardList,
  Archive,
  Wrench,
  Map,
  ExternalLink,
  Lock,
} from 'lucide-react';

const iconMap: Record<string, React.ComponentType<{ size?: number; className?: string }>> = {
  users: Users,
  'shopping-cart': ShoppingCart,
  'clipboard-list': ClipboardList,
  archive: Archive,
  tool: Wrench,
  map: Map,
};

export default function Lobby() {
  const { user, nexusUser } = useAuth();

  const getSystemAccess = (key: SystemKey) => {
    return nexusUser?.systemAccess?.[key]?.enabled ?? false;
  };

  const handleSystemClick = async (url: string, systemKey: string, hasAccess: boolean) => {
    if (!hasAccess || url === '#' || !user) {
      return;
    }
    
    // Use SSO for supported systems
    try {
      await openWithSSO(user, url, systemKey);
    } catch (error) {
      console.error('SSO error:', error);
      // Fallback to direct link
      window.open(url, '_blank', 'noopener,noreferrer');
    }
  };

  const firstName = nexusUser?.firstName || nexusUser?.displayName?.split(' ')[0] || 'there';

  return (
    <div className="space-y-8">
      {/* Welcome Section */}
      <div className="bg-gradient-to-r from-onepwr-dark to-slate-800 rounded-2xl p-8 text-white">
        <h1 className="text-3xl font-bold mb-2">
          Welcome back, {firstName}!
        </h1>
        <p className="text-slate-300 text-lg">
          Access all your 1PWR tools from one place
        </p>
      </div>

      {/* Systems Grid */}
      <div>
        <h2 className="text-xl font-semibold text-slate-900 mb-4">Your Tools</h2>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {SYSTEMS.map((system) => {
            const hasAccess = getSystemAccess(system.key);
            const Icon = iconMap[system.icon] || Archive;

            return (
              <button
                key={system.key}
                onClick={() => handleSystemClick(system.url, system.key, hasAccess)}
                disabled={!hasAccess || system.url === '#'}
                className={`card p-6 text-left transition-all duration-200 ${
                  hasAccess && system.url !== '#'
                    ? 'hover:shadow-lg hover:border-slate-300 hover:-translate-y-0.5 cursor-pointer'
                    : 'opacity-50 cursor-not-allowed'
                }`}
              >
                <div className="flex items-start justify-between mb-4">
                  <div
                    className="w-12 h-12 rounded-xl flex items-center justify-center"
                    style={{ backgroundColor: hasAccess ? system.color + '20' : '#e2e8f0' }}
                  >
                    <Icon
                      size={24}
                      style={{ color: hasAccess ? system.color : '#94a3b8' }}
                    />
                  </div>
                  {hasAccess && system.url !== '#' ? (
                    <ExternalLink size={16} className="text-slate-400" />
                  ) : (
                    <Lock size={16} className="text-slate-400" />
                  )}
                </div>

                <h3 className="font-semibold text-slate-900 mb-1">{system.name}</h3>
                <p className="text-sm text-slate-500 mb-3">{system.description}</p>

                {hasAccess ? (
                  <span
                    className="inline-flex items-center text-xs font-medium px-2 py-1 rounded-full"
                    style={{ backgroundColor: system.color + '20', color: system.color }}
                  >
                    {nexusUser?.systemAccess?.[system.key]?.role || 'Access Granted'}
                  </span>
                ) : (
                  <span className="inline-flex items-center text-xs font-medium px-2 py-1 rounded-full bg-slate-100 text-slate-500">
                    No Access
                  </span>
                )}
              </button>
            );
          })}
        </div>
      </div>

      {/* Quick Stats */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div className="card p-4">
          <p className="text-sm text-slate-500 mb-1">Department</p>
          <p className="font-semibold text-slate-900">
            {nexusUser?.department || 'Not Set'}
          </p>
        </div>
        <div className="card p-4">
          <p className="text-sm text-slate-500 mb-1">Organization</p>
          <p className="font-semibold text-slate-900">
            {nexusUser?.organization || 'OnePower'}
          </p>
        </div>
        <div className="card p-4">
          <p className="text-sm text-slate-500 mb-1">Systems Access</p>
          <p className="font-semibold text-slate-900">
            {SYSTEMS.filter(s => getSystemAccess(s.key)).length} of {SYSTEMS.length}
          </p>
        </div>
        <div className="card p-4">
          <p className="text-sm text-slate-500 mb-1">Account Status</p>
          <p className={`font-semibold ${nexusUser?.isActive ? 'text-green-600' : 'text-red-600'}`}>
            {nexusUser?.isActive ? 'Active' : 'Inactive'}
          </p>
        </div>
      </div>

      {/* Request Access Section */}
      <div className="card p-6 bg-slate-50 border-dashed">
        <h3 className="font-semibold text-slate-900 mb-2">Need access to another system?</h3>
        <p className="text-sm text-slate-600 mb-4">
          Contact your manager or IT administrator to request access to additional tools.
        </p>
        <a
          href="mailto:it@1pwrafrica.com?subject=System Access Request"
          className="btn btn-secondary inline-flex items-center gap-2"
        >
          Request Access
        </a>
      </div>
    </div>
  );
}
