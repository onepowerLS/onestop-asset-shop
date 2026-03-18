-- Export HR Portal Users from MySQL
-- 
-- Run this on the HR Portal database (nexus.1pwrafrica.com)
-- to export users for reconciliation.
--
-- Usage:
--   mysql -h HOST -u USER -p hr_portal < export-hr-users.sql > hr-users-export.json
--
-- Or run the SELECT and export manually.

-- Option 1: JSON export (MySQL 5.7+)
SELECT JSON_ARRAYAGG(
  JSON_OBJECT(
    'id', id,
    'email', email,
    'name', name,
    'first_name', COALESCE(first_name, SUBSTRING_INDEX(name, ' ', 1)),
    'last_name', COALESCE(last_name, SUBSTRING_INDEX(name, ' ', -1)),
    'role', COALESCE(role, 'user'),
    'department', department,
    'employee_id', employee_id,
    'is_active', IF(status = 'active' OR active = 1, true, false),
    'created_at', created_at,
    'last_login', last_login_at,
    'source', 'hr-portal'
  )
) AS users
FROM users
WHERE email IS NOT NULL AND email != '';

-- Option 2: CSV export for manual processing
-- SELECT 
--   id,
--   email,
--   name,
--   COALESCE(first_name, SUBSTRING_INDEX(name, ' ', 1)) AS first_name,
--   COALESCE(last_name, SUBSTRING_INDEX(name, ' ', -1)) AS last_name,
--   COALESCE(role, 'user') AS role,
--   department,
--   employee_id,
--   IF(status = 'active' OR active = 1, 'true', 'false') AS is_active,
--   created_at,
--   last_login_at AS last_login,
--   'hr-portal' AS source
-- FROM users
-- WHERE email IS NOT NULL AND email != ''
-- INTO OUTFILE '/tmp/hr-users-export.csv'
-- FIELDS TERMINATED BY ',' 
-- ENCLOSED BY '"'
-- LINES TERMINATED BY '\n';
