ALTER TABLE users
  ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN avatar MEDIUMTEXT NULL,
  ADD COLUMN bio VARCHAR(180) NOT NULL DEFAULT 'Building momentum, one lesson at a time.';

CREATE TABLE email_verifications (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  code_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY email_verifications_user_uq (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
