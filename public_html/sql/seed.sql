-- Seed data for Phase 1 Multi-tenant Reporting System
-- Default admin credentials:
--   admin@example.com / admin123
--
-- NOTE: This seed uses a one-time legacy marker for the password so the app
-- can convert it to a proper password_hash() on first login, without requiring
-- CLI tools during installation on shared hosting.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

INSERT INTO users (email, name, password_hash, role)
VALUES
  ('admin@example.com', 'Default Admin', 'LEGACY:admin123', 'ADMIN'),
  ('user@example.com', 'Example User', 'LEGACY:user123', 'USER')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  role = VALUES(role);

INSERT INTO projects (name, ga4_property_id, gsc_site_url, show_sales_section)
VALUES
  ('Example Project', NULL, NULL, 1)
ON DUPLICATE KEY UPDATE
  show_sales_section = VALUES(show_sales_section);

-- Assign Example User to Example Project (admin will see all projects anyway)
INSERT IGNORE INTO user_project (user_id, project_id)
SELECT u.id, p.id
FROM users u
JOIN projects p ON p.name = 'Example Project'
WHERE u.email = 'user@example.com';

