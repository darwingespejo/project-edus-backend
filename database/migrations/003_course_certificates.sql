CREATE TABLE course_enrollments (
  id CHAR(36) PRIMARY KEY, course_id CHAR(36) NOT NULL, user_id CHAR(36) NOT NULL,
  enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY course_enrollments_uq (course_id, user_id), KEY idx_user (user_id),
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE course_materials (
  id CHAR(36) PRIMARY KEY, course_id CHAR(36) NOT NULL, module_id CHAR(36) NOT NULL,
  uploaded_by CHAR(36) NOT NULL, name VARCHAR(200) NOT NULL, storage_key CHAR(36) NOT NULL,
  content_type VARCHAR(120) NOT NULL, bytes INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY course_materials_storage_uq (storage_key), KEY idx_module (module_id), KEY idx_course (course_id),
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE final_assessments (
  id CHAR(36) PRIMARY KEY, course_id CHAR(36) NOT NULL,
  title VARCHAR(160) NOT NULL, pass_score INT NOT NULL DEFAULT 80,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY final_assessments_course_uq (course_id),
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE final_assessment_questions (
  id CHAR(36) PRIMARY KEY, assessment_id CHAR(36) NOT NULL, prompt TEXT NOT NULL,
  options JSON NOT NULL, correct_option INT NOT NULL, points INT NOT NULL DEFAULT 1,
  position INT NOT NULL DEFAULT 0, KEY idx_assessment (assessment_id),
  FOREIGN KEY (assessment_id) REFERENCES final_assessments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE final_assessment_attempts (
  id CHAR(36) PRIMARY KEY, assessment_id CHAR(36) NOT NULL, user_id CHAR(36) NOT NULL,
  score DECIMAL(5,2) NOT NULL, passed TINYINT(1) NOT NULL,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_assessment_user (assessment_id, user_id), KEY idx_user (user_id),
  FOREIGN KEY (assessment_id) REFERENCES final_assessments(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE certificates
  ADD UNIQUE KEY certificates_user_course_uq (user_id, course_id);
