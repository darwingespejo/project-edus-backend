<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Instructor coding assignments and student submission history. */
class Classroom_model extends Model
{
    protected $table = 'coding_assignments';
    protected $primary_key = 'id';
    private $db;

    public function __construct()
    {
        parent::__construct();
        $this->db = load_class('database', 'database')::instance(NULL);
    }

    public function overview(array $user): array
    {
        if ($user['role'] === 'student') {
            $rows = $this->db->raw(
                "SELECT ca.id, ca.course_id courseId, ca.exercise_id exerciseId, ca.title, ca.instructions, ca.due_at dueAt,
                        c.title course, e.prompt, e.language,
                        (SELECT COUNT(*) FROM coding_assignment_attempts aa
                         WHERE aa.assignment_id = ca.id AND aa.user_id = ?) attemptCount,
                        (SELECT MAX(aa.score) FROM coding_assignment_attempts aa
                         WHERE aa.assignment_id = ca.id AND aa.user_id = ?) bestScore,
                        (SELECT aa.score FROM coding_assignment_attempts aa
                         WHERE aa.assignment_id = ca.id AND aa.user_id = ? ORDER BY aa.submitted_at DESC LIMIT 1) latestScore,
                        (SELECT aa.passed_count FROM coding_assignment_attempts aa
                         WHERE aa.assignment_id = ca.id AND aa.user_id = ? ORDER BY aa.submitted_at DESC LIMIT 1) latestPassed,
                        (SELECT aa.total_count FROM coding_assignment_attempts aa
                         WHERE aa.assignment_id = ca.id AND aa.user_id = ? ORDER BY aa.submitted_at DESC LIMIT 1) latestTotal,
                        (SELECT aa.submitted_at FROM coding_assignment_attempts aa
                         WHERE aa.assignment_id = ca.id AND aa.user_id = ? ORDER BY aa.submitted_at DESC LIMIT 1) lastSubmittedAt,
                        (SELECT aa.is_late FROM coding_assignment_attempts aa
                         WHERE aa.assignment_id = ca.id AND aa.user_id = ? ORDER BY aa.submitted_at DESC LIMIT 1) lastAttemptLate
                 FROM coding_assignments ca
                 JOIN courses c ON c.id = ca.course_id
                 JOIN coding_exercises e ON e.id = ca.exercise_id
                 WHERE ca.published = 1 AND (
                   EXISTS (SELECT 1 FROM course_enrollments ce WHERE ce.course_id = ca.course_id AND ce.user_id = ?)
                   OR EXISTS (SELECT 1 FROM learning_paths lp WHERE lp.course_id = ca.course_id AND lp.user_id = ?)
                 )
                 ORDER BY ca.due_at IS NULL, ca.due_at, ca.created_at",
                array_fill(0, 9, $user['id'])
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                $row['isOverdue'] = !empty($row['dueAt']) && strtotime($row['dueAt']) < time();
                $row['attemptCount'] = (int)$row['attemptCount'];
                $row['latestPassed'] = $row['latestPassed'] === null ? null : (int)$row['latestPassed'];
                $row['latestTotal'] = $row['latestTotal'] === null ? null : (int)$row['latestTotal'];
                $row['lastAttemptLate'] = $row['lastAttemptLate'] === null ? null : (bool)$row['lastAttemptLate'];
            }
            unset($row);
            return ['assignments' => $rows];
        }

        $courses = $this->db->raw(
            'SELECT id, title FROM courses WHERE instructor_id = ? ORDER BY title',
            [$user['id']]
        )->fetchAll(PDO::FETCH_ASSOC);
        $assignments = $this->db->raw(
            "SELECT ca.id, ca.course_id courseId, ca.exercise_id exerciseId, c.title course,
                    ca.title, ca.instructions, ca.due_at dueAt, ca.published, ca.created_at createdAt,
                    (SELECT COUNT(DISTINCT enrolled_students.user_id) FROM (
                      SELECT ce.course_id, ce.user_id FROM course_enrollments ce
                      UNION
                      SELECT lp.course_id, lp.user_id FROM learning_paths lp
                    ) enrolled_students WHERE enrolled_students.course_id = ca.course_id) studentCount,
                    (SELECT COUNT(*) FROM coding_assignment_attempts aa WHERE aa.assignment_id = ca.id) submittedCount,
                    (SELECT COUNT(DISTINCT aa.user_id) FROM coding_assignment_attempts aa
                     WHERE aa.assignment_id = ca.id AND aa.score >= 100) completedCount,
                    (SELECT ROUND(AVG(aa.score), 1) FROM coding_assignment_attempts aa
                     WHERE aa.assignment_id = ca.id) averageScore
             FROM coding_assignments ca JOIN courses c ON c.id = ca.course_id
             WHERE ca.instructor_id = ? ORDER BY ca.due_at IS NULL, ca.due_at, ca.created_at",
            [$user['id']]
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($assignments as &$assignment) {
            $assignment['published'] = (bool)$assignment['published'];
            $assignment['studentCount'] = (int)$assignment['studentCount'];
            $assignment['submittedCount'] = (int)$assignment['submittedCount'];
            $assignment['completedCount'] = (int)$assignment['completedCount'];
        }
        unset($assignment);
        return ['courses' => $courses, 'assignments' => $assignments];
    }

    public function createAssignment(array $input, $instructorId): array
    {
        $exerciseId = uuid4();
        $assignmentId = uuid4();
        $this->db->transaction();
        try {
            $this->db->raw(
                "INSERT INTO coding_exercises (id, course_id, title, prompt, language, starter_code, time_limit_sec, created_at)
                 VALUES (?, ?, ?, ?, 'javascript', ?, 10, NOW())",
                [$exerciseId, $input['courseId'], $input['title'], $input['prompt'], $input['starterCode']]
            );
            foreach ($input['testCases'] as $position => $testCase) {
                $this->db->raw(
                    'INSERT INTO test_cases (id, exercise_id, input, expected, hidden, position)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [
                        uuid4(),
                        $exerciseId,
                        json_encode($testCase['input'], JSON_THROW_ON_ERROR),
                        json_encode($testCase['expected'], JSON_THROW_ON_ERROR),
                        $testCase['hidden'] ? 1 : 0,
                        $position,
                    ]
                );
            }
            $this->db->raw(
                'INSERT INTO coding_assignments (id, course_id, exercise_id, instructor_id, title, instructions, due_at, published, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    $assignmentId,
                    $input['courseId'],
                    $exerciseId,
                    $instructorId,
                    $input['title'],
                    $input['instructions'],
                    $input['dueAt'],
                    $input['published'] ? 1 : 0,
                ]
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
        return ['id' => $assignmentId, 'exerciseId' => $exerciseId];
    }

    public function assignmentForStudent($assignmentId, $userId)
    {
        return $this->db->raw(
            "SELECT ca.id, ca.course_id courseId, ca.exercise_id exerciseId, ca.title, ca.instructions, ca.due_at dueAt,
                    c.title course, e.prompt, e.language, e.starter_code starterCode
             FROM coding_assignments ca
             JOIN courses c ON c.id = ca.course_id
             JOIN coding_exercises e ON e.id = ca.exercise_id
             WHERE ca.id = ? AND ca.published = 1 AND (
               EXISTS (SELECT 1 FROM course_enrollments ce WHERE ce.course_id = ca.course_id AND ce.user_id = ?)
               OR EXISTS (SELECT 1 FROM learning_paths lp WHERE lp.course_id = ca.course_id AND lp.user_id = ?)
             ) LIMIT 1",
            [$assignmentId, $userId, $userId]
        )->fetch(PDO::FETCH_ASSOC);
    }

    public function studentAttempts($assignmentId, $userId): array
    {
        $attempts = $this->db->raw(
            'SELECT id, score, passed_count passedCount, total_count totalCount, is_late isLate, submitted_at submittedAt
             FROM coding_assignment_attempts WHERE assignment_id = ? AND user_id = ? ORDER BY submitted_at',
            [$assignmentId, $userId]
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($attempts as &$attempt) {
            $attempt['passedCount'] = (int)$attempt['passedCount'];
            $attempt['totalCount'] = (int)$attempt['totalCount'];
            $attempt['isLate'] = (bool)$attempt['isLate'];
        }
        unset($attempt);
        return $attempts;
    }

    public function assignmentOwner($assignmentId, $instructorId): bool
    {
        return (bool)$this->db->raw(
            'SELECT ca.id FROM coding_assignments ca
             JOIN courses c ON c.id = ca.course_id
             WHERE ca.id = ? AND ca.instructor_id = ? AND c.instructor_id = ? LIMIT 1',
            [$assignmentId, $instructorId, $instructorId]
        )->fetch(PDO::FETCH_ASSOC);
    }

    public function assignmentExists($assignmentId): bool
    {
        return (bool)$this->db->raw(
            'SELECT id FROM coding_assignments WHERE id = ? LIMIT 1',
            [$assignmentId]
        )->fetch(PDO::FETCH_ASSOC);
    }

    public function updateAssignment($assignmentId, array $updates): void
    {
        $fields = [];
        $values = [];
        if (array_key_exists('published', $updates)) {
            $fields[] = 'published = ?';
            $values[] = $updates['published'] ? 1 : 0;
        }
        if (array_key_exists('dueAt', $updates)) {
            $fields[] = 'due_at = ?';
            $values[] = $updates['dueAt'];
        }
        $values[] = $assignmentId;
        $this->db->raw('UPDATE coding_assignments SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = ?', $values);
    }

    public function instructorAttempts($assignmentId, $limit = 100): array
    {
        $attempts = $this->db->raw(
            'SELECT aa.id, u.name student, aa.score, aa.passed_count passedCount,
                    aa.total_count totalCount, aa.is_late isLate, aa.submitted_at submittedAt, aa.code
             FROM coding_assignment_attempts aa JOIN users u ON u.id = aa.user_id
             WHERE aa.assignment_id = ? ORDER BY aa.submitted_at DESC LIMIT ' . max(1, min(100, (int)$limit)),
            [$assignmentId]
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($attempts as &$attempt) {
            $attempt['passedCount'] = (int)$attempt['passedCount'];
            $attempt['totalCount'] = (int)$attempt['totalCount'];
            $attempt['isLate'] = (bool)$attempt['isLate'];
        }
        unset($attempt);
        return $attempts;
    }

    public function recordAttempt($assignmentId, $userId, $executionId, $code, $passed, $total, $score, $isLate): array
    {
        $id = uuid4();
        $this->db->raw(
            'INSERT INTO coding_assignment_attempts
             (id, assignment_id, user_id, execution_result_id, code, passed_count, total_count, score, is_late, submitted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [$id, $assignmentId, $userId, $executionId, $code, $passed, $total, $score, $isLate ? 1 : 0]
        );
        return ['id' => $id, 'score' => $score, 'isLate' => (bool)$isLate];
    }
}
