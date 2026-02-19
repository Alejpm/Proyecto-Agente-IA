-- migrate.sql
CREATE DATABASE IF NOT EXISTS devforge CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE devforge;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE missions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NOT NULL,
  status ENUM('pending','running','completed','failed') DEFAULT 'pending',
  current_step INT DEFAULT 0,
  total_steps_estimate INT DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE mission_steps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mission_id INT NOT NULL,
  step_index INT NOT NULL,
  description TEXT,
  generated_files JSON NULL,
  evaluation TEXT NULL,
  status ENUM('pending','done','error') DEFAULT 'pending',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (mission_id) REFERENCES missions(id) ON DELETE CASCADE
);

CREATE TABLE agent_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mission_id INT NULL,
  level ENUM('info','warning','error') DEFAULT 'info',
  message TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (mission_id) REFERENCES missions(id) ON DELETE SET NULL
);

