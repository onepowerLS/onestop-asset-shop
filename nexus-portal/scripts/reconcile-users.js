/**
 * User Reconciliation Script
 * 
 * Merges user data from multiple 1PWR systems and identifies conflicts.
 * 
 * Input files (place in same directory):
 *   - pr-users-export.json
 *   - hr-users-export.json
 *   - om-users-export.json (optional)
 * 
 * Output:
 *   - reconciled-users.json (merged user profiles)
 *   - reconciliation-report.md (conflicts and issues)
 * 
 * Usage: node reconcile-users.js
 */

const fs = require('fs');
const path = require('path');

const PERMISSION_LEVEL_NAMES = {
  1: 'Admin',
  2: 'Approver',
  3: 'Procurement',
  4: 'Finance Admin',
  5: 'Requester',
  6: 'Approver 2',
};

function loadJsonFile(filename) {
  const filepath = path.join(__dirname, filename);
  if (!fs.existsSync(filepath)) {
    console.log(`File not found: ${filename} (skipping)`);
    return [];
  }
  const content = fs.readFileSync(filepath, 'utf-8');
  return JSON.parse(content);
}

function normalizeEmail(email) {
  return (email || '').toLowerCase().trim();
}

function normalizeUser(user, source) {
  return {
    email: normalizeEmail(user.email),
    firstName: (user.firstName || user.first_name || '').trim(),
    lastName: (user.lastName || user.last_name || '').trim(),
    displayName: (user.displayName || user.name || '').trim(),
    isActive: user.isActive !== false && user.is_active !== false && user.is_active !== 'false',
    source: source,
    originalData: user,
  };
}

function reconcileUsers() {
  console.log('=== User Reconciliation ===\n');
  
  // Load user exports
  const prUsers = loadJsonFile('pr-users-export.json');
  const hrUsers = loadJsonFile('hr-users-export.json');
  const omUsers = loadJsonFile('om-users-export.json');
  
  console.log(`PR System users: ${prUsers.length}`);
  console.log(`HR Portal users: ${hrUsers.length}`);
  console.log(`O&M Portal users: ${omUsers.length}`);
  console.log('');
  
  // Create unified user map by email
  const usersByEmail = new Map();
  const conflicts = [];
  const issues = [];
  
  // Process PR users (primary source since it uses Firebase Auth)
  prUsers.forEach(user => {
    const normalized = normalizeUser(user, 'pr');
    if (!normalized.email) {
      issues.push({ type: 'missing_email', source: 'pr', data: user });
      return;
    }
    
    usersByEmail.set(normalized.email, {
      email: normalized.email,
      uid: user.uid || null,
      firstName: normalized.firstName,
      lastName: normalized.lastName,
      displayName: normalized.displayName || `${normalized.firstName} ${normalized.lastName}`.trim(),
      department: user.department || '',
      organization: user.organization || '',
      isActive: normalized.isActive,
      
      systemAccess: {
        pr: {
          enabled: true,
          permissionLevel: user.permissionLevel ?? 5,
          role: user.role || PERMISSION_LEVEL_NAMES[user.permissionLevel] || 'Requester',
        },
        hr: { enabled: false },
        jobcards: { enabled: false, canEdit: false },
        am: { enabled: false, role: 'Viewer', countryAccess: [] },
        om: { enabled: false, role: '' },
        ugp: { enabled: false },
      },
      
      sources: ['pr'],
      createdAt: user.createdAt,
      lastLoginAt: user.lastLogin,
    });
  });
  
  // Merge HR users
  hrUsers.forEach(user => {
    const normalized = normalizeUser(user, 'hr');
    if (!normalized.email) {
      issues.push({ type: 'missing_email', source: 'hr', data: user });
      return;
    }
    
    if (usersByEmail.has(normalized.email)) {
      // Merge with existing
      const existing = usersByEmail.get(normalized.email);
      existing.sources.push('hr');
      existing.systemAccess.hr = {
        enabled: true,
        role: user.role || 'user',
        employeeId: user.employee_id || user.id,
      };
      
      // Check for conflicts
      if (normalized.firstName && existing.firstName && 
          normalized.firstName.toLowerCase() !== existing.firstName.toLowerCase()) {
        conflicts.push({
          email: normalized.email,
          field: 'firstName',
          pr: existing.firstName,
          hr: normalized.firstName,
        });
      }
      if (normalized.lastName && existing.lastName &&
          normalized.lastName.toLowerCase() !== existing.lastName.toLowerCase()) {
        conflicts.push({
          email: normalized.email,
          field: 'lastName',
          pr: existing.lastName,
          hr: normalized.lastName,
        });
      }
      
      // Fill in missing data from HR
      if (!existing.firstName && normalized.firstName) existing.firstName = normalized.firstName;
      if (!existing.lastName && normalized.lastName) existing.lastName = normalized.lastName;
      if (!existing.department && user.department) existing.department = user.department;
      
    } else {
      // New user from HR only
      usersByEmail.set(normalized.email, {
        email: normalized.email,
        uid: null, // No Firebase UID yet
        firstName: normalized.firstName,
        lastName: normalized.lastName,
        displayName: normalized.displayName || `${normalized.firstName} ${normalized.lastName}`.trim(),
        department: user.department || '',
        organization: '',
        isActive: normalized.isActive,
        
        systemAccess: {
          pr: { enabled: false },
          hr: {
            enabled: true,
            role: user.role || 'user',
            employeeId: user.employee_id || user.id,
          },
          jobcards: { enabled: false, canEdit: false },
          am: { enabled: false, role: 'Viewer', countryAccess: [] },
          om: { enabled: false, role: '' },
          ugp: { enabled: false },
        },
        
        sources: ['hr'],
        createdAt: user.created_at,
        lastLoginAt: user.last_login,
        needsFirebaseAccount: true,
      });
    }
  });
  
  // Merge O&M users
  omUsers.forEach(user => {
    const normalized = normalizeUser(user, 'om');
    if (!normalized.email) {
      issues.push({ type: 'missing_email', source: 'om', data: user });
      return;
    }
    
    if (usersByEmail.has(normalized.email)) {
      const existing = usersByEmail.get(normalized.email);
      existing.sources.push('om');
      existing.systemAccess.om = {
        enabled: true,
        role: user.role || user.user_type || 'employee',
      };
    } else {
      usersByEmail.set(normalized.email, {
        email: normalized.email,
        uid: null,
        firstName: normalized.firstName,
        lastName: normalized.lastName,
        displayName: normalized.displayName || `${normalized.firstName} ${normalized.lastName}`.trim(),
        department: '',
        organization: '',
        isActive: normalized.isActive,
        
        systemAccess: {
          pr: { enabled: false },
          hr: { enabled: false },
          jobcards: { enabled: false, canEdit: false },
          am: { enabled: false, role: 'Viewer', countryAccess: [] },
          om: {
            enabled: true,
            role: user.role || user.user_type || 'employee',
          },
          ugp: { enabled: false },
        },
        
        sources: ['om'],
        createdAt: user.created_at,
        lastLoginAt: user.last_login,
        needsFirebaseAccount: true,
      });
    }
  });
  
  // Convert to array
  const reconciledUsers = Array.from(usersByEmail.values());
  
  // Generate statistics
  const stats = {
    total: reconciledUsers.length,
    active: reconciledUsers.filter(u => u.isActive).length,
    inactive: reconciledUsers.filter(u => !u.isActive).length,
    hasFirebaseUid: reconciledUsers.filter(u => u.uid).length,
    needsFirebaseAccount: reconciledUsers.filter(u => u.needsFirebaseAccount).length,
    bySourceCount: {
      prOnly: reconciledUsers.filter(u => u.sources.length === 1 && u.sources[0] === 'pr').length,
      hrOnly: reconciledUsers.filter(u => u.sources.length === 1 && u.sources[0] === 'hr').length,
      omOnly: reconciledUsers.filter(u => u.sources.length === 1 && u.sources[0] === 'om').length,
      multiple: reconciledUsers.filter(u => u.sources.length > 1).length,
    },
    conflicts: conflicts.length,
    issues: issues.length,
  };
  
  // Save reconciled users
  const outputPath = path.join(__dirname, 'reconciled-users.json');
  fs.writeFileSync(outputPath, JSON.stringify(reconciledUsers, null, 2));
  console.log(`\nReconciled users saved to: ${outputPath}`);
  
  // Generate report
  const report = generateReport(stats, conflicts, issues, reconciledUsers);
  const reportPath = path.join(__dirname, 'reconciliation-report.md');
  fs.writeFileSync(reportPath, report);
  console.log(`Report saved to: ${reportPath}`);
  
  // Print summary
  console.log('\n=== Summary ===');
  console.log(`Total unified users: ${stats.total}`);
  console.log(`  Active: ${stats.active}`);
  console.log(`  Inactive: ${stats.inactive}`);
  console.log(`  With Firebase UID: ${stats.hasFirebaseUid}`);
  console.log(`  Need Firebase account: ${stats.needsFirebaseAccount}`);
  console.log(`\nBy source:`);
  console.log(`  PR only: ${stats.bySourceCount.prOnly}`);
  console.log(`  HR only: ${stats.bySourceCount.hrOnly}`);
  console.log(`  O&M only: ${stats.bySourceCount.omOnly}`);
  console.log(`  Multiple systems: ${stats.bySourceCount.multiple}`);
  console.log(`\nConflicts: ${stats.conflicts}`);
  console.log(`Issues: ${stats.issues}`);
  
  return { reconciledUsers, conflicts, issues, stats };
}

function generateReport(stats, conflicts, issues, users) {
  let report = `# User Reconciliation Report

Generated: ${new Date().toISOString()}

## Summary

| Metric | Count |
|--------|-------|
| Total Users | ${stats.total} |
| Active | ${stats.active} |
| Inactive | ${stats.inactive} |
| Has Firebase UID | ${stats.hasFirebaseUid} |
| Needs Firebase Account | ${stats.needsFirebaseAccount} |

## Users by Source

| Source | Count |
|--------|-------|
| PR System Only | ${stats.bySourceCount.prOnly} |
| HR Portal Only | ${stats.bySourceCount.hrOnly} |
| O&M Portal Only | ${stats.bySourceCount.omOnly} |
| Multiple Systems | ${stats.bySourceCount.multiple} |

`;

  if (conflicts.length > 0) {
    report += `## Conflicts Requiring Review

The following users have conflicting data between systems:

| Email | Field | PR Value | HR Value |
|-------|-------|----------|----------|
`;
    conflicts.forEach(c => {
      report += `| ${c.email} | ${c.field} | ${c.pr} | ${c.hr} |\n`;
    });
    report += '\n';
  }

  if (issues.length > 0) {
    report += `## Issues

| Type | Source | Details |
|------|--------|---------|
`;
    issues.forEach(i => {
      report += `| ${i.type} | ${i.source} | ${JSON.stringify(i.data).substring(0, 50)}... |\n`;
    });
    report += '\n';
  }

  // Users needing Firebase accounts
  const needsFirebase = users.filter(u => u.needsFirebaseAccount);
  if (needsFirebase.length > 0) {
    report += `## Users Needing Firebase Accounts

These users exist in HR/O&M but not in Firebase Auth. They will need accounts created:

| Email | Name | Sources |
|-------|------|---------|
`;
    needsFirebase.slice(0, 50).forEach(u => {
      report += `| ${u.email} | ${u.displayName} | ${u.sources.join(', ')} |\n`;
    });
    if (needsFirebase.length > 50) {
      report += `\n... and ${needsFirebase.length - 50} more\n`;
    }
    report += '\n';
  }

  // Multi-system users
  const multiSystem = users.filter(u => u.sources.length > 1);
  if (multiSystem.length > 0) {
    report += `## Users with Multiple System Access

| Email | Name | Systems |
|-------|------|---------|
`;
    multiSystem.forEach(u => {
      report += `| ${u.email} | ${u.displayName} | ${u.sources.join(', ')} |\n`;
    });
    report += '\n';
  }

  report += `## Next Steps

1. Review conflicts above and decide which values to use
2. Create Firebase accounts for users who need them
3. Import reconciled users into \`nexus_users\` Firestore collection
4. Update each system to read from unified profile

`;

  return report;
}

// Run if called directly
if (require.main === module) {
  reconcileUsers();
}

module.exports = { reconcileUsers };
