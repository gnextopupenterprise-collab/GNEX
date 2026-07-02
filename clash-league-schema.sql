CREATE TABLE IF NOT EXISTS cl_teams (
  id INT AUTO_INCREMENT PRIMARY KEY,
  team_name VARCHAR(100) NOT NULL UNIQUE,
  logo_url VARCHAR(500) NULL,
  logo_path VARCHAR(255) NULL,
  phone VARCHAR(40) NOT NULL,
  password_hash VARCHAR(255) NULL,
  coach_name VARCHAR(100) NULL,
  manager_name VARCHAR(100) NULL,
  status ENUM('pending','accepted','rejected','removed') NOT NULL DEFAULT 'pending',
  slot_no INT NULL,
  admin_note VARCHAR(255) NULL,
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
  room_type ENUM('admin','deal','match') NOT NULL DEFAULT 'admin',
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
  sender_type ENUM('admin','team','system') NOT NULL DEFAULT 'team',
  sender_team_id INT NULL,
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

CREATE TABLE IF NOT EXISTS cl_admin_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(80) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cl_settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value TEXT NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
