CREATE TABLE IF NOT EXISTS cl_teams (
  id INT AUTO_INCREMENT PRIMARY KEY,
  team_name VARCHAR(100) NOT NULL UNIQUE,
  logo_url VARCHAR(500) NULL,
  logo_path VARCHAR(255) NULL,
  phone VARCHAR(40) NOT NULL,
  password_hash VARCHAR(255) NULL,
  chat_token_hash CHAR(64) NULL,
  coach_name VARCHAR(100) NULL,
  manager_name VARCHAR(100) NULL,
  status ENUM('pending','accepted','rejected','removed') NOT NULL DEFAULT 'pending',
  slot_no INT NULL,
  admin_note VARCHAR(255) NULL,
  admin_checked TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  INDEX idx_cl_teams_status_slot (status, slot_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cl_players (
  id INT AUTO_INCREMENT PRIMARY KEY,
  team_id INT NOT NULL,
  player_slot TINYINT NOT NULL,
  ign VARCHAR(100) NULL,
  player_id VARCHAR(80) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_cl_player_slot (team_id, player_slot),
  FOREIGN KEY (team_id) REFERENCES cl_teams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cl_rooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  room_type ENUM('admin','deal','match','group') NOT NULL DEFAULT 'admin',
  team_a_id INT NULL,
  team_b_id INT NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  FOREIGN KEY (team_a_id) REFERENCES cl_teams(id) ON DELETE SET NULL,
  FOREIGN KEY (team_b_id) REFERENCES cl_teams(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cl_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  room_id INT NOT NULL,
  sender_type ENUM('admin','team','guest','system') NOT NULL DEFAULT 'team',
  sender_team_id INT NULL,
  guest_name VARCHAR(60) NULL,
  reply_to_message_id INT NULL,
  message VARCHAR(700) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (room_id) REFERENCES cl_rooms(id) ON DELETE CASCADE,
  FOREIGN KEY (sender_team_id) REFERENCES cl_teams(id) ON DELETE SET NULL,
  INDEX idx_cl_messages_room (room_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cl_matches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  team_a_id INT NULL,
  team_b_id INT NULL,
  match_name VARCHAR(120) NOT NULL DEFAULT 'Next Match',
  match_time DATETIME NULL,
  status ENUM('up_next','live','completed','hidden') NOT NULL DEFAULT 'hidden',
  team_a_point INT NULL,
  team_b_point INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  FOREIGN KEY (team_a_id) REFERENCES cl_teams(id) ON DELETE SET NULL,
  FOREIGN KEY (team_b_id) REFERENCES cl_teams(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cl_match_results (
  id INT AUTO_INCREMENT PRIMARY KEY,
  match_id INT NOT NULL,
  team_id INT NOT NULL,
  team_a_point INT NOT NULL,
  team_b_point INT NOT NULL,
  screenshot_url VARCHAR(500) NULL,
  screenshot_path VARCHAR(255) NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  admin_note VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY uniq_cl_match_result_team (match_id, team_id),
  FOREIGN KEY (match_id) REFERENCES cl_matches(id) ON DELETE CASCADE,
  FOREIGN KEY (team_id) REFERENCES cl_teams(id) ON DELETE CASCADE,
  INDEX idx_cl_match_results_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cl_admin_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(80) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cl_room_reads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  owner_type ENUM('team','admin') NOT NULL,
  team_id INT NULL,
  admin_id INT NULL,
  room_id INT NOT NULL,
  last_message_id INT NOT NULL DEFAULT 0,
  updated_at DATETIME NULL,
  UNIQUE KEY uniq_cl_room_read_admin (admin_id, room_id),
  UNIQUE KEY uniq_cl_room_read_team (team_id, room_id),
  FOREIGN KEY (team_id) REFERENCES cl_teams(id) ON DELETE CASCADE,
  FOREIGN KEY (admin_id) REFERENCES cl_admin_users(id) ON DELETE CASCADE,
  FOREIGN KEY (room_id) REFERENCES cl_rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cl_settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value TEXT NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cl_push_subscriptions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  owner_type ENUM('team','admin') NOT NULL DEFAULT 'team',
  team_id INT NULL,
  admin_id INT NULL,
  endpoint_hash CHAR(64) NOT NULL UNIQUE,
  endpoint TEXT NOT NULL,
  p256dh VARCHAR(255) NULL,
  auth VARCHAR(255) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  FOREIGN KEY (team_id) REFERENCES cl_teams(id) ON DELETE CASCADE,
  FOREIGN KEY (admin_id) REFERENCES cl_admin_users(id) ON DELETE CASCADE,
  INDEX idx_cl_push_team (team_id),
  INDEX idx_cl_push_admin (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cl_push_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  owner_type ENUM('team','admin') NOT NULL DEFAULT 'team',
  team_id INT NULL,
  admin_id INT NULL,
  title VARCHAR(120) NOT NULL,
  body VARCHAR(500) NOT NULL,
  url VARCHAR(255) NOT NULL DEFAULT 'clash-league.html',
  tag VARCHAR(80) NOT NULL DEFAULT 'clash-league',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (team_id) REFERENCES cl_teams(id) ON DELETE CASCADE,
  FOREIGN KEY (admin_id) REFERENCES cl_admin_users(id) ON DELETE CASCADE,
  INDEX idx_cl_push_events_team (team_id, id),
  INDEX idx_cl_push_events_admin (admin_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
