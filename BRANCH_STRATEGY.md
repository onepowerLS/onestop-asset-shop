# Branch Strategy & Development Workflow

## Branch Structure

```
main (production)
  ↑
develop (integration/testing)
  ↑
feature/* (development branches)
```

## Branch Descriptions

### `main` (Production)
- **Purpose**: Live production code
- **Protection**: 
  - Requires pull request review
  - Requires all tests to pass
  - Auto-deploys to AWS EC2 on merge
- **Who can merge**: Administrators only
- **Deployment**: Automatic via GitHub Actions

### `develop` (Development/Staging)
- **Purpose**: Integration branch for testing
- **Protection**: 
  - Requires tests to pass
  - Can be deployed to staging environment
- **Who can merge**: Developers with write access
- **Deployment**: Manual or staging auto-deploy

### `feature/*` (Feature Branches)
- **Purpose**: Individual feature development
- **Naming**: `feature/qr-scanning`, `feature/tablet-ui`, etc.
- **Protection**: None (developer branches)
- **Workflow**: 
  1. Create from `develop`
  2. Develop feature
  3. Create PR to `develop`
  4. After review, merge to `develop`
  5. Delete feature branch

### `hotfix/*` (Hotfix Branches)
- **Purpose**: Critical production fixes
- **Naming**: `hotfix/critical-bug-fix`
- **Workflow**:
  1. Create from `main`
  2. Fix issue
  3. Create PR to both `main` and `develop`
  4. Merge to `main` first (deploys immediately)
  5. Merge to `develop` to keep in sync

## Development Workflow

### Starting a New Feature

```bash
# 1. Ensure you're on develop and up to date
git checkout develop
git pull origin develop

# 2. Create feature branch
git checkout -b feature/my-new-feature

# 3. Develop your feature
# ... make changes ...

# 4. Commit frequently
git add .
git commit -m "Add feature: description"

# 5. Push to remote
git push origin feature/my-new-feature

# 6. Create Pull Request on GitHub
# - Target: develop
# - Add description
# - Request review
```

### Merging to Develop

1. **Create Pull Request** from `feature/*` to `develop`
2. **Wait for CI tests** to pass
3. **Get code review** approval
4. **Merge** (squash and merge recommended)
5. **Delete** feature branch after merge

### Deploying to Production

1. **Merge `develop` to `main`**:
   ```bash
   git checkout main
   git pull origin main
   git merge develop
   git push origin main
   ```

2. **GitHub Actions automatically**:
   - Runs tests
   - Deploys to EC2
   - Runs health check

3. **Verify deployment**:
   - Check https://assets.1pwrafrica.com/health.php
   - Test critical functionality
   - Monitor error logs

## Code Review Guidelines

### Before Creating PR

- [ ] Code follows project style guide
- [ ] All tests pass locally
- [ ] No console.log or debug code
- [ ] Database migrations tested
- [ ] Security considerations addressed
- [ ] Documentation updated if needed

### Review Checklist

- [ ] Code is readable and maintainable
- [ ] No security vulnerabilities
- [ ] Performance considerations addressed
- [ ] Error handling is appropriate
- [ ] Database queries are optimized
- [ ] No hardcoded credentials or secrets

## Testing Strategy

### Before Merging to `develop`

- Run local tests
- Test in development environment
- Manual testing of new features
- Check for breaking changes

### Before Merging to `main`

- All automated tests pass
- Staging environment testing
- Performance testing
- Security review
- Database migration tested
- Rollback plan documented

## Release Process

1. **Feature Freeze**: Stop merging new features to `develop`
2. **Testing Phase**: Comprehensive testing on `develop`
3. **Bug Fixes**: Fix any issues found
4. **Documentation**: Update release notes
5. **Merge to Main**: Deploy to production
6. **Post-Deployment**: Monitor and verify

## Emergency Hotfix Process

For critical production issues:

```bash
# 1. Create hotfix from main
git checkout main
git pull origin main
git checkout -b hotfix/critical-fix

# 2. Fix the issue
# ... make changes ...

# 3. Test thoroughly
# ... test locally ...

# 4. Create PR to main (fast-track review)
# 5. Merge to main (deploys immediately)
# 6. Merge to develop to keep in sync
```

## Branch Protection Rules

Configure in GitHub: `Settings > Branches`

### `main` Branch Protection

- ✅ Require a pull request before merging
- ✅ Require approvals: 1
- ✅ Require status checks to pass
- ✅ Require branches to be up to date
- ✅ Do not allow force pushes
- ✅ Do not allow deletions

### `develop` Branch Protection

- ✅ Require a pull request before merging
- ✅ Require status checks to pass
- ⚠️ Allow force pushes (for emergency fixes only)

## Best Practices

1. **Keep branches small**: One feature per branch
2. **Commit often**: Small, logical commits
3. **Write clear commit messages**: Use conventional commits format
4. **Sync frequently**: Pull latest changes from `develop` regularly
5. **Test before PR**: Run tests locally before creating PR
6. **Communicate**: Use PR descriptions to explain changes
7. **Clean up**: Delete merged branches

## Commit Message Format

```
type(scope): subject

body (optional)

footer (optional)
```

**Types**: `feat`, `fix`, `docs`, `style`, `refactor`, `test`, `chore`

**Examples**:
```
feat(qr): Add QR code generation for assets
fix(database): Fix SQL connection error
docs(deployment): Update AWS setup instructions
```
