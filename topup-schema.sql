CREATE TABLE IF NOT EXISTS gt_customers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  login_id VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The API creates the remaining gt_devices, gt_conversations, gt_messages,
-- gt_game_accounts and gt_purchases tables automatically. All admin foreign
-- keys reference the existing Clash League cl_admin_users table.
