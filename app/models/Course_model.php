<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Course catalog, enrollment, materials, progress, and final assessments. */
class Course_model extends Model
{
    protected $table = 'courses';
    protected $primary_key = 'id';
    private $db;

    public function __construct()
    {
        parent::__construct();
        $this->db = load_class('database', 'database')::instance(NULL);
    }

    public function coursesForUser(array $user): array
    {
        $where = $user['role'] === 'instructor'
            ? 'WHERE c.instructor_id = ?'
            : ($user['role'] === 'admin' ? '' : 'WHERE c.published = 1');
        $params = [$user['id'], $user['id'], $user['id'], $user['id']];
        if ($user['role'] === 'instructor') $params[] = $user['id'];
        return $this->db->raw(
            "SELECT c.id, c.title, c.slug, c.description, c.level, c.accent, c.published,
                    u.name instructor,
                    (EXISTS (SELECT 1 FROM course_enrollments ce WHERE ce.course_id = c.id AND ce.user_id = ?)
                     OR EXISTS (SELECT 1 FROM learning_paths lp WHERE lp.course_id = c.id AND lp.user_id = ?)) enrolled,
                    (SELECT COUNT(*) FROM lessons l JOIN modules m ON m.id = l.module_id WHERE m.course_id = c.id) lessonCount,
                    (SELECT COUNT(*) FROM lessons l JOIN modules m ON m.id = l.module_id
                     WHERE m.course_id = c.id AND (
                       EXISTS (SELECT 1 FROM progress_records pr WHERE pr.lesson_id = l.id AND pr.user_id = ?)
                       OR EXISTS (
                         SELECT 1 FROM learning_path_items lpi JOIN learning_paths lp ON lp.id = lpi.path_id
                         WHERE lpi.lesson_id = l.id AND lpi.status = 'done' AND lp.user_id = ?
                       )
                    )) completedLessons
             FROM courses c JOIN users u ON u.id = c.instructor_id $where ORDER BY c.created_at",
            $params
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createCourse($instructorId, $title, $description, $level): array
    {
        $id = uuid4();
        $base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        $base = strtolower(trim((string)$base));
        $base = trim((string)preg_replace('/[^a-z0-9]+/', '-', $base), '-');
        $slug = substr($base ?: 'course', 0, 60) . '-' . substr($id, 0, 8);
        $this->db->raw(
            "INSERT INTO courses (id, instructor_id, title, slug, description, level, published, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, NOW())",
            [$id, $instructorId, $title, $slug, $description, $level]
        );
        return ['id' => $id, 'slug' => $slug];
    }

    public function courseOwner($courseId, $instructorId): bool
    {
        return (bool)$this->db->raw(
            'SELECT id FROM courses WHERE id = ? AND instructor_id = ? LIMIT 1',
            [$courseId, $instructorId]
        )->fetch(PDO::FETCH_ASSOC);
    }

    public function courseDetail($courseId, array $user): ?array
    {
        $course = $this->db->raw(
            'SELECT c.id, c.title, c.description, c.level, c.published, c.instructor_id, u.name instructor
             FROM courses c JOIN users u ON u.id = c.instructor_id WHERE c.id = ? LIMIT 1',
            [$courseId]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$course || (!$course['published'] && $course['instructor_id'] !== $user['id'] && $user['role'] !== 'admin')) {
            return null;
        }

        $enrolled = false;
        $legacyPath = false;
        if ($user['role'] === 'student') {
            $enrolled = (bool)$this->db->raw(
                'SELECT id FROM course_enrollments WHERE course_id = ? AND user_id = ? LIMIT 1',
                [$courseId, $user['id']]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$enrolled) {
                $legacyPath = (bool)$this->db->raw(
                    'SELECT id FROM learning_paths WHERE course_id = ? AND user_id = ? LIMIT 1',
                    [$courseId, $user['id']]
                )->fetch(PDO::FETCH_ASSOC);
            }
        }
        $canView = $user['role'] === 'admin' || $course['instructor_id'] === $user['id']
            || ($user['role'] === 'student' && ($enrolled || $legacyPath));

        $modules = [];
        $moduleRows = $this->db->raw(
            'SELECT id, title, position FROM modules WHERE course_id = ? ORDER BY position',
            [$courseId]
        )->fetchAll(PDO::FETCH_ASSOC);
        if ($canView) {
            foreach ($moduleRows as $module) {
                $lessons = $this->db->raw(
                    'SELECT l.id, l.title, l.estimated_minutes estimatedMinutes, FALSE completed
                     FROM lessons l WHERE l.module_id = ? ORDER BY l.position',
                    [$module['id']]
                )->fetchAll(PDO::FETCH_ASSOC);
                foreach ($lessons as &$lesson) {
                    $lesson['estimatedMinutes'] = (int)$lesson['estimatedMinutes'];
                    $lesson['completed'] = false;
                }
                unset($lesson);
                if ($user['role'] === 'student' && $lessons) {
                    $completed = $this->db->raw(
                        "SELECT l.id FROM lessons l
                         WHERE l.module_id = ? AND (
                           EXISTS (SELECT 1 FROM progress_records pr WHERE pr.lesson_id = l.id AND pr.user_id = ?)
                           OR EXISTS (
                             SELECT 1 FROM learning_path_items lpi JOIN learning_paths lp ON lp.id = lpi.path_id
                             WHERE lpi.lesson_id = l.id AND lpi.status = 'done' AND lp.user_id = ?
                           )
                         )",
                        [$module['id'], $user['id'], $user['id']]
                    )->fetchAll(PDO::FETCH_COLUMN);
                    $completedIds = array_fill_keys($completed, true);
                    foreach ($lessons as &$lesson) $lesson['completed'] = isset($completedIds[$lesson['id']]);
                    unset($lesson);
                }
                $materials = $this->db->raw(
                    'SELECT id, name, content_type contentType, bytes FROM course_materials WHERE module_id = ? ORDER BY created_at',
                    [$module['id']]
                )->fetchAll(PDO::FETCH_ASSOC);
                $quizzes = $this->quizzesForModule($module['id'], $user);
                $modules[] = [
                    'id' => $module['id'],
                    'title' => $module['title'],
                    'position' => (int)$module['position'],
                    'lessons' => $lessons,
                    'materials' => array_map(static function (array $material): array {
                        $material['bytes'] = (int)$material['bytes'];
                        return $material;
                    }, $materials),
                    'quizzes' => $quizzes,
                ];
            }
        }

        $counts = $this->db->raw(
            "SELECT
               (SELECT COUNT(*) FROM lessons l JOIN modules m ON m.id = l.module_id WHERE m.course_id = ?) lessonsTotal,
               (SELECT COUNT(*) FROM lessons l JOIN modules m ON m.id = l.module_id
                WHERE m.course_id = ? AND (
                  EXISTS (SELECT 1 FROM progress_records pr WHERE pr.lesson_id = l.id AND pr.user_id = ?)
                  OR EXISTS (SELECT 1 FROM learning_path_items lpi JOIN learning_paths lp ON lp.id = lpi.path_id
                    WHERE lpi.lesson_id = l.id AND lpi.status = 'done' AND lp.user_id = ?)
                )) lessonsCompleted,
               (SELECT COUNT(*) FROM coding_assignments ca WHERE ca.course_id = ? AND ca.published = 1) assignmentsTotal,
               (SELECT COUNT(*) FROM coding_assignments ca WHERE ca.course_id = ? AND ca.published = 1
                AND EXISTS (SELECT 1 FROM coding_assignment_attempts aa
                  WHERE aa.assignment_id = ca.id AND aa.user_id = ? AND aa.score >= 100)) assignmentsPassed",
            [$courseId, $courseId, $user['id'], $user['id'], $courseId, $courseId, $user['id']]
        )->fetch(PDO::FETCH_ASSOC);

        $certificate = $canView ? $this->certificateFor($courseId, $user['id']) : null;
        $assessment = $this->assessmentForCourse($courseId, $user['id'], $canView);
        return [
            'course' => [
                'id' => $course['id'],
                'title' => $course['title'],
                'description' => $course['description'],
                'level' => $course['level'],
                'instructor' => $course['instructor'],
            ],
            'enrolled' => $enrolled || $legacyPath,
            'modules' => $modules,
            'requirements' => [
                'lessonsDone' => (int)$counts['lessonsTotal'] > 0 && (int)$counts['lessonsCompleted'] >= (int)$counts['lessonsTotal'],
                'lessonsCompleted' => (int)$counts['lessonsCompleted'],
                'lessonsTotal' => (int)$counts['lessonsTotal'],
                'assignmentsDone' => (int)$counts['assignmentsTotal'] > 0 && (int)$counts['assignmentsPassed'] >= (int)$counts['assignmentsTotal'],
                'assignmentsPassed' => (int)$counts['assignmentsPassed'],
                'assignmentsTotal' => (int)$counts['assignmentsTotal'],
                'finalAssessmentDone' => (bool)($assessment['passed'] ?? false),
                'certificateCode' => $certificate,
            ],
            'finalAssessment' => $assessment,
        ];
    }

    private function assessmentForCourse($courseId, $userId, $canView): ?array
    {
        if (!$canView) return null;
        $assessment = $this->db->raw(
            'SELECT id, title, pass_score passScore FROM final_assessments WHERE course_id = ? LIMIT 1',
            [$courseId]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$assessment) return null;
        $questions = $this->db->raw(
            'SELECT id, prompt, options, points FROM final_assessment_questions
             WHERE assessment_id = ? ORDER BY position',
            [$assessment['id']]
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($questions as &$question) {
            $question['options'] = json_decode($question['options'], true);
            $question['points'] = (int)$question['points'];
        }
        unset($question);
        $attempts = $this->db->raw(
            'SELECT score, passed FROM final_assessment_attempts WHERE assessment_id = ? AND user_id = ?',
            [$assessment['id'], $userId]
        )->fetchAll(PDO::FETCH_ASSOC);
        $scores = array_map(static fn($attempt) => (float)$attempt['score'], $attempts);
        return [
            'id' => $assessment['id'],
            'title' => $assessment['title'],
            'passScore' => (int)$assessment['passScore'],
            'questions' => $questions,
            'bestScore' => $scores ? max($scores) : null,
            'passed' => (bool)array_filter($attempts, static fn($attempt) => (bool)$attempt['passed']),
        ];
    }

    public function enroll($courseId, $userId): bool
    {
        $course = $this->db->raw(
            'SELECT id FROM courses WHERE id = ? AND published = 1 LIMIT 1',
            [$courseId]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$course) return false;
        $this->db->raw(
            'INSERT INTO course_enrollments (id, course_id, user_id, enrolled_at)
             VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE course_id = VALUES(course_id)',
            [uuid4(), $courseId, $userId]
        );
        return true;
    }

    public function saveAssessment($courseId, $title, $passScore, array $questions): string
    {
        $assessment = $this->db->raw(
            'SELECT id FROM final_assessments WHERE course_id = ? LIMIT 1',
            [$courseId]
        )->fetch(PDO::FETCH_ASSOC);
        $assessmentId = $assessment['id'] ?? uuid4();
        $this->db->transaction();
        try {
            if ($assessment) {
                $this->db->raw(
                    'UPDATE final_assessments SET title = ?, pass_score = ?, updated_at = NOW() WHERE id = ?',
                    [$title, $passScore, $assessmentId]
                );
                $this->db->raw('DELETE FROM final_assessment_questions WHERE assessment_id = ?', [$assessmentId]);
            } else {
                $this->db->raw(
                    'INSERT INTO final_assessments (id, course_id, title, pass_score) VALUES (?, ?, ?, ?)',
                    [$assessmentId, $courseId, $title, $passScore]
                );
            }
            foreach ($questions as $position => $question) {
                $options = json_encode($question['options'], JSON_THROW_ON_ERROR);
                $this->db->raw(
                    'INSERT INTO final_assessment_questions (id, assessment_id, prompt, options, correct_option, points, position)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [uuid4(), $assessmentId, $question['prompt'], $options, $question['correctOption'], $question['points'], $position]
                );
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
        return $assessmentId;
    }

    public function assessmentForSubmission($courseId)
    {
        return $this->db->raw(
            'SELECT id, title, pass_score FROM final_assessments WHERE course_id = ? LIMIT 1',
            [$courseId]
        )->fetch(PDO::FETCH_ASSOC);
    }

    public function questionsForGrading($assessmentId): array
    {
        return $this->db->raw(
            'SELECT id, correct_option, points FROM final_assessment_questions
             WHERE assessment_id = ? ORDER BY position',
            [$assessmentId]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function recordAssessmentAttempt($assessmentId, $userId, $score, $passed): void
    {
        $this->db->raw(
            'INSERT INTO final_assessment_attempts (id, assessment_id, user_id, score, passed, submitted_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [uuid4(), $assessmentId, $userId, $score, $passed ? 1 : 0]
        );
    }

    public function hasAccess($courseId, $userId): bool
    {
        return (bool)$this->db->raw(
            'SELECT id FROM course_enrollments WHERE course_id = ? AND user_id = ?
             UNION SELECT id FROM learning_paths WHERE course_id = ? AND user_id = ? LIMIT 1',
            [$courseId, $userId, $courseId, $userId]
        )->fetch(PDO::FETCH_ASSOC);
    }

    public function lessonInCourse($lessonId, $courseId): bool
    {
        return (bool)$this->db->raw(
            'SELECT l.id FROM lessons l JOIN modules m ON m.id = l.module_id
             JOIN courses c ON c.id = m.course_id
             WHERE l.id = ? AND c.id = ? AND c.published = 1 LIMIT 1',
            [$lessonId, $courseId]
        )->fetch(PDO::FETCH_ASSOC);
    }

    public function recordCourseProgress($userId, $lessonId, $timeSpentSec): bool
    {
        $statement = $this->db->raw(
            'INSERT INTO progress_records (id, user_id, lesson_id, time_spent_sec, completed_at)
             VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE id = id',
            [uuid4(), $userId, $lessonId, $timeSpentSec]
        );
        return $statement->rowCount() > 0;
    }

    public function createModule($courseId, $title): string
    {
        $row = $this->db->raw(
            'SELECT COALESCE(MAX(position), -1) position FROM modules WHERE course_id = ?',
            [$courseId]
        )->fetch(PDO::FETCH_ASSOC);
        $id = uuid4();
        $this->db->raw(
            'INSERT INTO modules (id, course_id, title, position) VALUES (?, ?, ?, ?)',
            [$id, $courseId, $title, (int)$row['position'] + 1]
        );
        return $id;
    }

    private function quizzesForModule($moduleId, array $user): array
    {
        $quizzes = $this->db->raw(
            'SELECT q.id, q.title, COUNT(DISTINCT qq.id) questionCount,
                    MAX(qa.score) bestScore, COUNT(DISTINCT qa.id) attempts
             FROM module_quizzes q
             LEFT JOIN module_quiz_questions qq ON qq.quiz_id = q.id
             LEFT JOIN module_quiz_attempts qa ON qa.quiz_id = q.id AND qa.user_id = ?
             WHERE q.module_id = ?
             GROUP BY q.id, q.title, q.created_at ORDER BY q.created_at',
            [$user['role'] === 'student' ? $user['id'] : '', $moduleId]
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($quizzes as &$quiz) {
            $quiz['questionCount'] = (int)$quiz['questionCount'];
            $quiz['attempts'] = (int)$quiz['attempts'];
            $quiz['bestScore'] = $quiz['bestScore'] === null ? null : (float)$quiz['bestScore'];
            $quiz['questions'] = [];
            if ($user['role'] !== 'student') continue;

            $questions = $this->db->raw(
                'SELECT id, type, prompt, options, points FROM module_quiz_questions
                 WHERE quiz_id = ? ORDER BY position',
                [$quiz['id']]
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($questions as &$question) {
                $question['options'] = $question['options'] === null
                    ? []
                    : json_decode($question['options'], true, 512, JSON_THROW_ON_ERROR);
                $question['points'] = (int)$question['points'];
            }
            unset($question);
            $quiz['questions'] = $questions;
        }
        unset($quiz);
        return $quizzes;
    }

    public function createModuleQuiz($moduleId, $title, array $questions): string
    {
        $quizId = uuid4();
        $this->db->transaction();
        try {
            $this->db->raw(
                'INSERT INTO module_quizzes (id, module_id, title, created_at) VALUES (?, ?, ?, NOW())',
                [$quizId, $moduleId, $title]
            );
            foreach ($questions as $position => $question) {
                $options = $question['type'] === 'multiple_choice'
                    ? json_encode($question['options'], JSON_THROW_ON_ERROR)
                    : null;
                $acceptedAnswers = $question['type'] === 'identification'
                    ? json_encode($question['acceptedAnswers'], JSON_THROW_ON_ERROR)
                    : null;
                $this->db->raw(
                    'INSERT INTO module_quiz_questions
                     (id, quiz_id, type, prompt, options, correct_option, accepted_answers, points, position)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        uuid4(), $quizId, $question['type'], $question['prompt'], $options,
                        $question['correctOption'], $acceptedAnswers, $question['points'], $position,
                    ]
                );
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
        return $quizId;
    }

    public function quizCourse($quizId)
    {
        return $this->db->raw(
            'SELECT c.id courseId FROM module_quizzes q
             JOIN modules m ON m.id = q.module_id JOIN courses c ON c.id = m.course_id
             WHERE q.id = ? LIMIT 1',
            [$quizId]
        )->fetch(PDO::FETCH_ASSOC);
    }

    public function questionsForQuiz($quizId): array
    {
        return $this->db->raw(
            'SELECT id, type, prompt, options, correct_option correctOption,
                    accepted_answers acceptedAnswers, points
             FROM module_quiz_questions WHERE quiz_id = ? ORDER BY position',
            [$quizId]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function recordQuizAttempt($quizId, $userId, $score, $earnedPoints, $possiblePoints, array $answers): void
    {
        $attemptId = uuid4();
        $this->db->transaction();
        try {
            $this->db->raw(
                'INSERT INTO module_quiz_attempts
                 (id, quiz_id, user_id, score, earned_points, possible_points, submitted_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [$attemptId, $quizId, $userId, $score, $earnedPoints, $possiblePoints]
            );
            foreach ($answers as $answer) {
                $this->db->raw(
                    'INSERT INTO module_quiz_attempt_answers
                     (id, attempt_id, question_id, response, is_correct, points_awarded)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [
                        uuid4(), $attemptId, $answer['questionId'], $answer['response'],
                        $answer['correct'] ? 1 : 0, $answer['pointsAwarded'],
                    ]
                );
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function moduleCourse($moduleId)
    {
        return $this->db->raw('SELECT course_id FROM modules WHERE id = ? LIMIT 1', [$moduleId])->fetch(PDO::FETCH_ASSOC);
    }

    public function createLesson($moduleId, $title, $content, $estimatedMinutes): string
    {
        $row = $this->db->raw(
            'SELECT COALESCE(MAX(position), -1) position FROM lessons WHERE module_id = ?',
            [$moduleId]
        )->fetch(PDO::FETCH_ASSOC);
        $id = uuid4();
        $this->db->raw(
            'INSERT INTO lessons (id, module_id, title, content, estimated_minutes, position, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [$id, $moduleId, $title, $content, $estimatedMinutes, (int)$row['position'] + 1]
        );
        return $id;
    }

    public function insertMaterial(array $material): void
    {
        $this->db->raw(
            'INSERT INTO course_materials (id, course_id, module_id, uploaded_by, name, storage_key, content_type, bytes, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [$material['id'], $material['courseId'], $material['moduleId'], $material['uploadedBy'],
                $material['name'], $material['storageKey'], $material['contentType'], $material['bytes']]
        );
    }

    public function materialById($id)
    {
        return $this->db->raw(
            'SELECT cm.id, cm.course_id courseId, cm.name, cm.storage_key storageKey,
                    cm.content_type contentType, cm.bytes, c.instructor_id instructorId
             FROM course_materials cm JOIN courses c ON c.id = cm.course_id WHERE cm.id = ? LIMIT 1',
            [$id]
        )->fetch(PDO::FETCH_ASSOC);
    }

    public function instructorCourses($instructorId)
    {
        return $this->db->raw(
            'SELECT id, title FROM courses WHERE instructor_id = ? ORDER BY title',
            [$instructorId]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function setPublished($courseId, $published): void
    {
        $this->db->raw('UPDATE courses SET published = ? WHERE id = ?', [$published ? 1 : 0, $courseId]);
    }

    public function canPublish($courseId): bool
    {
        $row = $this->db->raw(
            "SELECT
               (SELECT COUNT(*) FROM lessons l JOIN modules m ON m.id = l.module_id WHERE m.course_id = ?) lessonCount,
               (SELECT COUNT(*) FROM coding_assignments WHERE course_id = ? AND published = 1) assignmentCount,
               (SELECT COUNT(*) FROM final_assessments fa JOIN final_assessment_questions faq ON faq.assessment_id = fa.id
                WHERE fa.course_id = ?) questionCount",
            [$courseId, $courseId, $courseId]
        )->fetch(PDO::FETCH_ASSOC);
        return (int)$row['lessonCount'] > 0 && (int)$row['assignmentCount'] > 0 && (int)$row['questionCount'] > 0;
    }

    public function certificateFor($courseId, $userId): ?string
    {
        $row = $this->db->raw(
            'SELECT code FROM certificates WHERE course_id = ? AND user_id = ? LIMIT 1',
            [$courseId, $userId]
        )->fetch(PDO::FETCH_ASSOC);
        return $row['code'] ?? null;
    }

    public function issueCertificateIfEligible($courseId, $userId): array
    {
        $existing = $this->certificateFor($courseId, $userId);
        if ($existing) return ['code' => $existing, 'created' => false];

        $counts = $this->db->raw(
            "SELECT
               (SELECT COUNT(*) FROM lessons l JOIN modules m ON m.id = l.module_id WHERE m.course_id = ?) lessonTotal,
               (SELECT COUNT(*) FROM lessons l JOIN modules m ON m.id = l.module_id
                WHERE m.course_id = ? AND (
                  EXISTS (SELECT 1 FROM progress_records pr WHERE pr.lesson_id = l.id AND pr.user_id = ?)
                  OR EXISTS (SELECT 1 FROM learning_path_items lpi JOIN learning_paths lp ON lp.id = lpi.path_id
                    WHERE lpi.lesson_id = l.id AND lpi.status = 'done' AND lp.user_id = ?)
                )) lessonsDone,
               (SELECT COUNT(*) FROM coding_assignments WHERE course_id = ? AND published = 1) assignmentTotal,
               (SELECT COUNT(*) FROM coding_assignments ca WHERE ca.course_id = ? AND ca.published = 1
                AND EXISTS (SELECT 1 FROM coding_assignment_attempts aa
                  WHERE aa.assignment_id = ca.id AND aa.user_id = ? AND aa.score >= 100)) assignmentsDone",
            [$courseId, $courseId, $userId, $userId, $courseId, $courseId, $userId]
        )->fetch(PDO::FETCH_ASSOC);
        if ((int)$counts['lessonTotal'] < 1 || (int)$counts['lessonTotal'] !== (int)$counts['lessonsDone']
            || (int)$counts['assignmentTotal'] < 1 || (int)$counts['assignmentTotal'] !== (int)$counts['assignmentsDone']) {
            return ['code' => null, 'created' => false];
        }
        $assessment = $this->assessmentForCourse($courseId, $userId, true);
        if (!$assessment || !$assessment['questions'] || !$assessment['passed']) return ['code' => null, 'created' => false];

        $course = $this->db->raw('SELECT title, level FROM courses WHERE id = ? LIMIT 1', [$courseId])->fetch(PDO::FETCH_ASSOC);
        if (!$course) return ['code' => null, 'created' => false];
        $code = 'EDU-' . strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2)));
        try {
            $this->db->raw(
                'INSERT INTO certificates (id, user_id, course_id, code, issued_at) VALUES (?, ?, ?, ?, NOW())',
                [uuid4(), $userId, $courseId, $code]
            );
        } catch (PDOException $error) {
            if ($error->getCode() !== '23000') throw $error;
            $existing = $this->certificateFor($courseId, $userId);
            if ($existing) return ['code' => $existing, 'created' => false];
            throw $error;
        }
        return ['code' => $code, 'created' => true, 'course' => $course];
    }
}
