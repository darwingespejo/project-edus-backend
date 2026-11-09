CREATE TABLE module_quizzes (
  id CHAR(36) PRIMARY KEY, module_id CHAR(36) NOT NULL,
  title VARCHAR(160) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_module_quizzes_module (module_id),
  FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE module_quiz_questions (
  id CHAR(36) PRIMARY KEY, quiz_id CHAR(36) NOT NULL,
  type ENUM('multiple_choice','identification') NOT NULL,
  prompt TEXT NOT NULL, options JSON NULL, correct_option TINYINT NULL,
  accepted_answers JSON NULL, points INT NOT NULL DEFAULT 1, position INT NOT NULL DEFAULT 0,
  KEY idx_module_quiz_questions_quiz (quiz_id),
  FOREIGN KEY (quiz_id) REFERENCES module_quizzes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE module_quiz_attempts (
  id CHAR(36) PRIMARY KEY, quiz_id CHAR(36) NOT NULL, user_id CHAR(36) NOT NULL,
  score DECIMAL(5,2) NOT NULL, earned_points INT NOT NULL, possible_points INT NOT NULL,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_module_quiz_attempts_user (quiz_id, user_id),
  FOREIGN KEY (quiz_id) REFERENCES module_quizzes(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE module_quiz_attempt_answers (
  id CHAR(36) PRIMARY KEY, attempt_id CHAR(36) NOT NULL, question_id CHAR(36) NOT NULL,
  response TEXT NOT NULL, is_correct TINYINT(1) NOT NULL DEFAULT 0, points_awarded INT NOT NULL DEFAULT 0,
  FOREIGN KEY (attempt_id) REFERENCES module_quiz_attempts(id) ON DELETE CASCADE,
  FOREIGN KEY (question_id) REFERENCES module_quiz_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB;
