-- Phase 1 Multi-tenant Reporting System (Plain PHP)
-- MySQL schema (utf8mb4 + InnoDB)

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(255) NOT NULL,
  name VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('ADMIN','USER') NOT NULL DEFAULT 'USER',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS projects (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  ga4_property_id VARCHAR(64) NULL,
  gsc_site_url VARCHAR(255) NULL,
  show_sales_section TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_project (
  user_id INT UNSIGNED NOT NULL,
  project_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, project_id),
  KEY idx_up_project (project_id),
  CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_up_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monthly_reports (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  year INT NOT NULL,
  month INT NOT NULL,
  status ENUM('READY','PARTIAL','GENERATING','ERROR') NOT NULL DEFAULT 'GENERATING',
  generated_at DATETIME NULL,
  data_json JSON NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_monthly_reports_project_period (project_id, year, month),
  KEY idx_monthly_reports_project (project_id),
  CONSTRAINT fk_monthly_reports_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monthly_notes (
  project_id INT UNSIGNED NOT NULL,
  year INT NOT NULL,
  month INT NOT NULL,
  work_summary TEXT NULL,
  indexed_pages_manual INT NULL,
  PRIMARY KEY (project_id, year, month),
  CONSTRAINT fk_monthly_notes_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

