import { Outlet, Link, useLocation } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { Home, User, Settings, LogOut, Menu, X } from 'lucide-react';
import { useState } from 'react';

export default function Layout() {
  const { nexusUser, logout, isAdmin } = useAuth();
  const location = useLocation();
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

  const navItems = [
    { path: '/', label: 'Dashboard', icon: Home },
    { path: '/profile', label: 'Profile', icon: User },
    ...(isAdmin ? [{ path: '/admin', label: 'Admin', icon: Settings }] : []),
  ];

  const handleLogout = async () => {
    await logout();
  };

  return (
    <div className="min-h-screen bg-slate-50">
      {/* Header */}
      <header className="bg-onepwr-dark text-white shadow-lg sticky top-0 z-50">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="flex items-center justify-between h-16">
            {/* Logo */}
            <Link to="/" className="flex items-center gap-3">
              <div className="w-10 h-10 bg-onepwr-green rounded-lg flex items-center justify-center font-bold text-lg">
                1P
              </div>
              <span className="text-xl font-semibold hidden sm:block">Nexus</span>
            </Link>

            {/* Desktop Navigation */}
            <nav className="hidden md:flex items-center gap-1">
              {navItems.map((item) => {
                const Icon = item.icon;
                const isActive = location.pathname === item.path || 
                  (item.path !== '/' && location.pathname.startsWith(item.path));
                return (
                  <Link
                    key={item.path}
                    to={item.path}
                    className={`flex items-center gap-2 px-4 py-2 rounded-lg transition-colors ${
                      isActive
                        ? 'bg-white/10 text-white'
                        : 'text-slate-300 hover:bg-white/5 hover:text-white'
                    }`}
                  >
                    <Icon size={18} />
                    <span>{item.label}</span>
                  </Link>
                );
              })}
            </nav>

            {/* User Menu */}
            <div className="flex items-center gap-4">
              <div className="hidden sm:block text-right">
                <p className="text-sm font-medium">{nexusUser?.displayName || 'User'}</p>
                <p className="text-xs text-slate-400">{nexusUser?.email}</p>
              </div>
              
              <button
                onClick={handleLogout}
                className="hidden md:flex items-center gap-2 px-3 py-2 text-sm text-slate-300 hover:text-white hover:bg-white/10 rounded-lg transition-colors"
              >
                <LogOut size={18} />
                <span>Sign Out</span>
              </button>

              {/* Mobile menu button */}
              <button
                onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
                className="md:hidden p-2 text-slate-300 hover:text-white"
              >
                {mobileMenuOpen ? <X size={24} /> : <Menu size={24} />}
              </button>
            </div>
          </div>
        </div>

        {/* Mobile Navigation */}
        {mobileMenuOpen && (
          <div className="md:hidden border-t border-white/10">
            <div className="px-4 py-3 space-y-1">
              {navItems.map((item) => {
                const Icon = item.icon;
                const isActive = location.pathname === item.path;
                return (
                  <Link
                    key={item.path}
                    to={item.path}
                    onClick={() => setMobileMenuOpen(false)}
                    className={`flex items-center gap-3 px-4 py-3 rounded-lg ${
                      isActive ? 'bg-white/10' : 'hover:bg-white/5'
                    }`}
                  >
                    <Icon size={20} />
                    <span>{item.label}</span>
                  </Link>
                );
              })}
              <button
                onClick={handleLogout}
                className="flex items-center gap-3 px-4 py-3 w-full text-left text-slate-300 hover:bg-white/5 rounded-lg"
              >
                <LogOut size={20} />
                <span>Sign Out</span>
              </button>
            </div>
          </div>
        )}
      </header>

      {/* Main Content */}
      <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <Outlet />
      </main>

      {/* Footer */}
      <footer className="bg-white border-t border-slate-200 mt-auto">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
          <p className="text-center text-sm text-slate-500">
            &copy; {new Date().getFullYear()} OnePower Africa. All rights reserved.
          </p>
        </div>
      </footer>
    </div>
  );
}
