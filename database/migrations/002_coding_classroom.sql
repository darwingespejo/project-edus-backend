CREATE TABLE coding_assignments (
  id CHAR(36) PRIMARY KEY, course_id CHAR(36) NOT NULL, exercise_id CHAR(36) NOT NULL,
  instructor_id CHAR(36) NOT NULL, title VARCHAR(160) NOT NULL, instructions TEXT NOT NULL,
  due_at DATETIME NULL, published TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_course (course_id), KEY idx_instructor (instructor_id),
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  FOREIGN KEY (exercise_id) REFERENCES coding_exercises(id) ON DELETE CASCADE,
  FOREIGN KEY (instructor_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE coding_assignment_attempts (
  id CHAR(36) PRIMARY KEY, assignment_id CHAR(36) NOT NULL, user_id CHAR(36) NOT NULL,
  execution_result_id CHAR(36) NULL, code MEDIUMTEXT NOT NULL,
  passed_count INT NOT NULL DEFAULT 0, total_count INT NOT NULL DEFAULT 0,
  score DECIMAL(5,2) NOT NULL DEFAULT 0, is_late TINYINT(1) NOT NULL DEFAULT 0,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_assignment_user (assignment_id, user_id), KEY idx_user (user_id),
  FOREIGN KEY (assignment_id) REFERENCES coding_assignments(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (execution_result_id) REFERENCES execution_results(id) ON DELETE SET NULL
) ENGINE=InnoDB;
