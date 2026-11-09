-- EduSphere — MySQL DDL (Aiven). InnoDB, utf8mb4, FKs + indexes.
-- Import via Navicat (see NAVICAT.md) or: mysql --ssl-mode=REQUIRED -h $DB_HOST edusphere < schema.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS audit_logs, notifications, files, live_sessions, payouts, payments, subscriptions,
  certificates, user_badges, badges, streaks, progress_records, review_scores, peer_reviews,
  peer_assignments, rubric_criteria, rubrics, execution_results, submissions, test_cases,
  coding_exercises, module_quiz_attempt_answers, module_quiz_attempts, module_quiz_questions,
  module_quizzes, learning_path_items, learning_paths, student_skill_scores, attempt_answers,
  assessment_attempts, assessment_questions, assessments, lesson_skills, course_skills, skills,
  lessons, modules, courses, refresh_tokens, users, institutions;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE institutions (
  id CHAR(36) PRIMARY KEY, name VARCHAR(120) NOT NULL, domain VARCHAR(120),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE users (
  id CHAR(36) PRIMARY KEY,
  institution_id CHAR(36) NULL REFERENCES institutions(id),
  name VARCHAR(100) NOT NULL, email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('student','instructor','admin') NOT NULL DEFAULT 'student',
  xp INT NOT NULL DEFAULT 0, level INT NOT NULL DEFAULT 1, active TINYINT(1) NOT NULL DEFAULT 1,
  email_verified TINYINT(1) NOT NULL DEFAULT 1,
  avatar MEDIUMTEXT NULL, bio VARCHAR(180) NOT NULL DEFAULT 'Building momentum, one lesson at a time.',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY users_email_uq (email)
) ENGINE=InnoDB;

CREATE TABLE email_verifications (
  id CHAR(36) PRIMARY KEY, user_id CHAR(36) NOT NULL,
  code_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY email_verifications_user_uq (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE refresh_tokens (
  id CHAR(36) PRIMARY KEY, user_id CHAR(36) NOT NULL,
  token_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL, revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE courses (
  id CHAR(36) PRIMARY KEY, instructor_id CHAR(36) NOT NULL,
  title VARCHAR(160) NOT NULL, slug VARCHAR(80) NOT NULL, description TEXT NOT NULL,
  level VARCHAR(20) NOT NULL DEFAULT 'beginner', accent VARCHAR(20) NOT NULL DEFAULT 'indigo',
  published TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY courses_slug_uq (slug), FOREIGN KEY (instructor_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE modules (
  id CHAR(36) PRIMARY KEY, course_id CHAR(36) NOT NULL,
  title VARCHAR(160) NOT NULL, position INT NOT NULL DEFAULT 0,
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

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

CREATE TABLE lessons (
  id CHAR(36) PRIMARY KEY, module_id CHAR(36) NOT NULL,
  title VARCHAR(160) NOT NULL, content MEDIUMTEXT NOT NULL,
  estimated_minutes INT NOT NULL DEFAULT 20, position INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE skills (
  id CHAR(36) PRIMARY KEY, name VARCHAR(80) NOT NULL, slug VARCHAR(60) NOT NULL,
  category VARCHAR(40) NOT NULL DEFAULT 'engineering',
  UNIQUE KEY skills_slug_uq (slug)
) ENGINE=InnoDB;

CREATE TABLE course_skills (
  course_id CHAR(36) NOT NULL, skill_id CHAR(36) NOT NULL,
  PRIMARY KEY (course_id, skill_id),
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE lesson_skills (
  lesson_id CHAR(36) NOT NULL, skill_id CHAR(36) NOT NULL,
  PRIMARY KEY (lesson_id, skill_id),
  FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
  FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE assessments (
  id CHAR(36) PRIMARY KEY, course_id CHAR(36) NOT NULL,
  title VARCHAR(160) NOT NULL, kind ENUM('placement','quiz') NOT NULL DEFAULT 'placement',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE assessment_questions (
  id CHAR(36) PRIMARY KEY, assessment_id CHAR(36) NOT NULL, skill_id CHAR(36) NOT NULL,
  type ENUM('mcq','short') NOT NULL DEFAULT 'mcq', prompt TEXT NOT NULL,
  options JSON NULL, answer TEXT NOT NULL, points INT NOT NULL DEFAULT 10, position INT NOT NULL DEFAULT 0,
  FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE,
  FOREIGN KEY (skill_id) REFERENCES skills(id)
) ENGINE=InnoDB;

CREATE TABLE assessment_attempts (
  id CHAR(36) PRIMARY KEY, assessment_id CHAR(36) NOT NULL, user_id CHAR(36) NOT NULL,
  status ENUM('in_progress','submitted') NOT NULL DEFAULT 'in_progress',
  score DECIMAL(5,2) NOT NULL DEFAULT 0,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, submitted_at DATETIME NULL,
  KEY idx_user (user_id),
  FOREIGN KEY (assessment_id) REFERENCES assessments(id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE attempt_answers (
  id CHAR(36) PRIMARY KEY, attempt_id CHAR(36) NOT NULL, question_id CHAR(36) NOT NULL,
  response TEXT NOT NULL, is_correct TINYINT(1) NOT NULL DEFAULT 0, points_awarded INT NOT NULL DEFAULT 0,
  FOREIGN KEY (attempt_id) REFERENCES assessment_attempts(id) ON DELETE CASCADE,
  FOREIGN KEY (question_id) REFERENCES assessment_questions(id)
) ENGINE=InnoDB;

CREATE TABLE student_skill_scores (
  id CHAR(36) PRIMARY KEY, user_id CHAR(36) NOT NULL, skill_id CHAR(36) NOT NULL,
  source ENUM('assessment','exercise','review') NOT NULL DEFAULT 'assessment',
  score DECIMAL(5,2) NOT NULL, measured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (skill_id) REFERENCES skills(id)
) ENGINE=InnoDB;

CREATE TABLE learning_paths (
  id CHAR(36) PRIMARY KEY, user_id CHAR(36) NOT NULL, course_id CHAR(36) NOT NULL,
  status ENUM('active','completed') NOT NULL DEFAULT 'active',
  velocity DECIMAL(5,2) NOT NULL DEFAULT 0.50, rationale TEXT NOT NULL,
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, completed_at DATETIME NULL,
  KEY idx_user (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (course_id) REFERENCES courses(id)
) ENGINE=InnoDB;

CREATE TABLE learning_path_items (
  id CHAR(36) PRIMARY KEY, path_id CHAR(36) NOT NULL, lesson_id CHAR(36) NOT NULL,
  position INT NOT NULL DEFAULT 0,
  status ENUM('locked','current','done','skipped') NOT NULL DEFAULT 'locked',
  completed_at DATETIME NULL,
  KEY idx_path (path_id),
  FOREIGN KEY (path_id) REFERENCES learning_paths(id) ON DELETE CASCADE,
  FOREIGN KEY (lesson_id) REFERENCES lessons(id)
) ENGINE=InnoDB;

CREATE TABLE coding_exercises (
  id CHAR(36) PRIMARY KEY, course_id CHAR(36) NOT NULL, skill_id CHAR(36) NULL,
  title VARCHAR(160) NOT NULL, prompt TEXT NOT NULL, language VARCHAR(20) NOT NULL DEFAULT 'javascript',
  starter_code MEDIUMTEXT NOT NULL, time_limit_sec INT NOT NULL DEFAULT 10,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  FOREIGN KEY (skill_id) REFERENCES skills(id)
) ENGINE=InnoDB;

CREATE TABLE test_cases (
  id CHAR(36) PRIMARY KEY, exercise_id CHAR(36) NOT NULL,
  input MEDIUMTEXT NOT NULL, expected TEXT NOT NULL, hidden TINYINT(1) NOT NULL DEFAULT 0,
  position INT NOT NULL DEFAULT 0,
  FOREIGN KEY (exercise_id) REFERENCES coding_exercises(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE submissions (
  id CHAR(36) PRIMARY KEY, user_id CHAR(36) NOT NULL, exercise_id CHAR(36) NULL,
  kind ENUM('exercise','assignment') NOT NULL DEFAULT 'exercise',
  title VARCHAR(160) NULL, code MEDIUMTEXT NOT NULL, language VARCHAR(20) NOT NULL DEFAULT 'javascript',
  status ENUM('submitted','reviewing','reviewed') NOT NULL DEFAULT 'submitted',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (exercise_id) REFERENCES coding_exercises(id)
) ENGINE=InnoDB;

CREATE TABLE execution_results (
  id CHAR(36) PRIMARY KEY, user_id CHAR(36) NOT NULL,
  submission_id CHAR(36) NULL, exercise_id CHAR(36) NULL,
  stdout MEDIUMTEXT NOT NULL, stderr MEDIUMTEXT NOT NULL, runtime_ms INT NOT NULL DEFAULT 0,
  passed_count INT NOT NULL DEFAULT 0, total_count INT NOT NULL DEFAULT 0, results JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE SET NULL,
  FOREIGN KEY (exercise_id) REFERENCES coding_exercises(id)
) ENGINE=InnoDB;

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

CREATE TABLE rubrics (
  id CHAR(36) PRIMARY KEY, course_id CHAR(36) NOT NULL, title VARCHAR(160) NOT NULL,
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE rubric_criteria (
  id CHAR(36) PRIMARY KEY, rubric_id CHAR(36) NOT NULL,
  label VARCHAR(120) NOT NULL, description TEXT NOT NULL, max_points INT NOT NULL DEFAULT 10,
  position INT NOT NULL DEFAULT 0,
  FOREIGN KEY (rubric_id) REFERENCES rubrics(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE peer_assignments (
  id CHAR(36) PRIMARY KEY, submission_id CHAR(36) NOT NULL, reviewer_id CHAR(36) NOT NULL,
  status ENUM('pending','submitted') NOT NULL DEFAULT 'pending',
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, completed_at DATETIME NULL,
  UNIQUE KEY peer_assignments_uq (submission_id, reviewer_id),
  KEY idx_reviewer (reviewer_id),
  FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
  FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE peer_reviews (
  id CHAR(36) PRIMARY KEY, assignment_id CHAR(36) NOT NULL,
  submission_id CHAR(36) NOT NULL, reviewer_id CHAR(36) NOT NULL,
  overall_comment TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY peer_reviews_assignment_uq (assignment_id),
  FOREIGN KEY (assignment_id) REFERENCES peer_assignments(id) ON DELETE CASCADE,
  FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
  FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE review_scores (
  id CHAR(36) PRIMARY KEY, review_id CHAR(36) NOT NULL, criterion_id CHAR(36) NOT NULL,
  score INT NOT NULL,
  FOREIGN KEY (review_id) REFERENCES peer_reviews(id) ON DELETE CASCADE,
  FOREIGN KEY (criterion_id) REFERENCES rubric_criteria(id)
) ENGINE=InnoDB;

CREATE TABLE progress_records (
  id CHAR(36) PRIMARY KEY, user_id CHAR(36) NOT NULL, lesson_id CHAR(36) NOT NULL,
  time_spent_sec INT NOT NULL DEFAULT 0, completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY progress_uq (user_id, lesson_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE streaks (
  id CHAR(36) PRIMARY KEY, user_id CHAR(36) NOT NULL,
  current INT NOT NULL DEFAULT 0, longest INT NOT NULL DEFAULT 0, last_activity_date DATE NULL,
  UNIQUE KEY streaks_user_uq (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE badges (
  id CHAR(36) PRIMARY KEY, code VARCHAR(40) NOT NULL, name VARCHAR(80) NOT NULL,
  description TEXT NOT NULL, icon VARCHAR(30) NOT NULL DEFAULT 'award',
  accent VARCHAR(20) NOT NULL DEFAULT 'amber', xp_reward INT NOT NULL DEFAULT 0,
  UNIQUE KEY badges_code_uq (code)
) ENGINE=InnoDB;

CREATE TABLE user_badges (
  id CHAR(36) PRIMARY KEY, user_id CHAR(36) NOT NULL, badge_id CHAR(36) NOT NULL,
  awarded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY user_badges_uq (user_id, badge_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (badge_id) REFERENCES badges(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE certificates (
  id CHAR(36) PRIMARY KEY, user_id CHAR(36) NOT NULL, course_id CHAR(36) NOT NULL,
  code VARCHAR(20) NOT NULL, issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY certificates_code_uq (code),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (course_id) REFERENCES courses(id)
) ENGINE=InnoDB;

CREATE TABLE subscriptions (
  id CHAR(36) PRIMARY KEY, user_id CHAR(36) NOT NULL,
  plan VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'active',
  provider_reference VARCHAR(120) NULL, expires_at DATETIME NULL,
  UNIQUE KEY subscriptions_provider_reference_uq (provider_reference),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE payments (
  id CHAR(36) PRIMARY KEY, user_id CHAR(36) NOT NULL,
  amount_cents INT NOT NULL, currency VARCHAR(8) NOT NULL DEFAULT 'PHP',
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  provider VARCHAR(20) NOT NULL DEFAULT 'paymongo',
  provider_reference VARCHAR(120) NULL, plan VARCHAR(20) NULL,
  UNIQUE KEY payments_provider_reference_uq (provider_reference),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE payouts (
  id CHAR(36) PRIMARY KEY, instructor_id CHAR(36) NOT NULL,
  amount_cents INT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'demo',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (instructor_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE live_sessions (
  id CHAR(36) PRIMARY KEY, course_id CHAR(36) NOT NULL, host_id CHAR(36) NOT NULL,
  title VARCHAR(160) NOT NULL, starts_at DATETIME NOT NULL, duration_min INT NOT NULL DEFAULT 60,
  room_code VARCHAR(60) NOT NULL, join_url VARCHAR(190) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  FOREIGN KEY (host_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE files (
  id CHAR(36) PRIMARY KEY, user_id CHAR(36) NOT NULL,
  `key` VARCHAR(255) NOT NULL, bytes INT NOT NULL DEFAULT 0, content_type VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id CHAR(36) PRIMARY KEY, user_id CHAR(36) NOT NULL,
  type VARCHAR(30) NOT NULL, title VARCHAR(190) NOT NULL, body TEXT NOT NULL,
  read_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
  id CHAR(36) PRIMARY KEY, user_id CHAR(36) NULL, action VARCHAR(80) NOT NULL,
  meta JSON NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_action (action),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
