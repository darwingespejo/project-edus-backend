<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** courses, modules, lessons, skills, lesson/progress reads, coding exercises + test cases, rubrics. */
class Content_model extends Model
{
    protected $table = 'courses';
    protected $primary_key = 'id';
    private $db;

    public function __construct()
    {
        parent::__construct();
        $this->db = load_class('database', 'database')::instance(NULL);
    }

    public function publishedCourses()
    {
        return $this->db->raw(
            "SELECT c.id, c.title, c.slug, c.description, c.level, c.accent, u.name instructor,
                    (SELECT COUNT(*) FROM lessons l JOIN modules m ON m.id = l.module_id WHERE m.course_id = c.id) lesson_count
             FROM courses c JOIN users u ON u.id = c.instructor_id WHERE c.published = 1 ORDER BY c.created_at"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function lessonBundle($lessonId, $userId, $role)
    {
        $row = $this->db->raw(
            'SELECT l.id, l.title, l.content, l.estimated_minutes estimatedMinutes,
                    m.id moduleId, m.title moduleTitle, c.title courseTitle, c.id courseId,
                    c.instructor_id instructorId, c.published
             FROM lessons l JOIN modules m ON m.id = l.module_id JOIN courses c ON c.id = m.course_id
             WHERE l.id = ? LIMIT 1',
            [$lessonId]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $allowed = $role === 'admin' || ($role === 'instructor' && $row['instructorId'] === $userId);
        if ($role === 'student' && $row['published']) {
            $allowed = (bool)$this->db->raw(
                'SELECT id FROM course_enrollments WHERE course_id = ? AND user_id = ?
                 UNION SELECT id FROM learning_paths WHERE course_id = ? AND user_id = ? LIMIT 1',
                [$row['courseId'], $userId, $row['courseId'], $userId]
            )->fetch(PDO::FETCH_ASSOC);
        }
        if (!$allowed) return null;
        $row['skills'] = $this->db->raw('SELECT s.name FROM lesson_skills ls JOIN skills s ON s.id = ls.skill_id WHERE ls.lesson_id = ?', [$lessonId])->fetchAll(PDO::FETCH_COLUMN);
        $row['quizzes'] = [];
        if ($role === 'student') {
            $quizzes = $this->db->raw(
                'SELECT q.id, q.title, COUNT(DISTINCT qq.id) questionCount,
                        MAX(qa.score) bestScore, COUNT(DISTINCT qa.id) attempts
                 FROM module_quizzes q
                 LEFT JOIN module_quiz_questions qq ON qq.quiz_id = q.id
                 LEFT JOIN module_quiz_attempts qa ON qa.quiz_id = q.id AND qa.user_id = ?
                 WHERE q.module_id = ?
                 GROUP BY q.id, q.title, q.created_at ORDER BY q.created_at',
                [$userId, $row['moduleId']]
            )->fetchAll(PDO::FETCH_ASSOC);

            foreach ($quizzes as &$quiz) {
                $quiz['questionCount'] = (int)$quiz['questionCount'];
                $quiz['attempts'] = (int)$quiz['attempts'];
                $quiz['bestScore'] = $quiz['bestScore'] === null ? null : (float)$quiz['bestScore'];
                $quiz['questions'] = $this->db->raw(
                    'SELECT id, type, prompt, options, points
                     FROM module_quiz_questions WHERE quiz_id = ? ORDER BY position',
                    [$quiz['id']]
                )->fetchAll(PDO::FETCH_ASSOC);
                foreach ($quiz['questions'] as &$question) {
                    $question['options'] = $question['options'] === null
                        ? []
                        : json_decode($question['options'], true, 512, JSON_THROW_ON_ERROR);
                    $question['points'] = (int)$question['points'];
                }
                unset($question);
            }
            unset($quiz);
            $row['quizzes'] = $quizzes;
        }
        $row['completed'] = (bool)$this->db->raw(
            "SELECT pr.id FROM progress_records pr WHERE pr.user_id = ? AND pr.lesson_id = ?
             UNION
             SELECT lpi.id FROM learning_path_items lpi
             JOIN learning_paths lp ON lp.id = lpi.path_id
             WHERE lp.user_id = ? AND lpi.lesson_id = ? AND lpi.status = 'done' LIMIT 1",
            [$userId, $lessonId, $userId, $lessonId]
        )->fetch(PDO::FETCH_ASSOC);
        unset($row['instructorId'], $row['published']);
        unset($row['moduleId']);
        return $row;
    }

    public function courseById($id)
    {
        return $this->db->table('courses')->where('id', $id)->limit(1)->get();
    }

    public function courseBySlug($slug)
    {
        return $this->db->table('courses')->where('slug', $slug)->limit(1)->get();
    }

    public function exercisesWithBest($userId)
    {
        return $this->db->raw(
            "SELECT e.id, e.title, e.prompt, e.language, e.starter_code, c.title course, s.name skill,
                    SUM(CASE WHEN t.hidden = 0 THEN 1 ELSE 0 END) visible_tests, COUNT(t.id) total_tests,
                    (SELECT MAX(er.passed_count) FROM execution_results er WHERE er.exercise_id = e.id AND er.user_id = ?) best_passed
             FROM coding_exercises e
             JOIN courses c ON c.id = e.course_id
             LEFT JOIN skills s ON s.id = e.skill_id
             LEFT JOIN test_cases t ON t.exercise_id = e.id
             GROUP BY e.id ORDER BY e.created_at",
            [$userId]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function exercise($id)
    {
        return $this->db->table('coding_exercises')->where('id', $id)->limit(1)->get();
    }

    public function testCases($exerciseId)
    {
        return $this->db->table('test_cases')
            ->select('input, expected, hidden')
            ->where('exercise_id', $exerciseId)
            ->order_by('position')
            ->get_all();
    }

    public function visibleTestCasesByExercise(): array
    {
        $rows = $this->db->raw(
            'SELECT exercise_id, input, expected FROM test_cases WHERE hidden = 0 ORDER BY exercise_id, position'
        )->fetchAll(PDO::FETCH_ASSOC);
        $cases = [];
        foreach ($rows as $row) {
            $cases[$row['exercise_id']][] = [
                'input' => $row['input'],
                'expected' => $row['expected'],
            ];
        }
        return $cases;
    }

    /** Rubric criteria keyed by course, with the first rubric as fallback. */
    public function rubricByCourse()
    {
        $rows = $this->db->raw(
            'SELECT r.course_id, r.title rubric_title, rc.id criterion_id, rc.label, rc.description, rc.max_points
             FROM rubrics r JOIN rubric_criteria rc ON rc.rubric_id = r.id ORDER BY rc.position'
        )->fetchAll(PDO::FETCH_ASSOC);
        $byCourse = [];
        $fallback = null;
        foreach ($rows as $r) {
            $byCourse[$r['course_id']] = $byCourse[$r['course_id']] ?? ['title' => $r['rubric_title'], 'criteria' => []];
            $byCourse[$r['course_id']]['criteria'][] = ['id' => $r['criterion_id'], 'label' => $r['label'], 'description' => $r['description'], 'maxPoints' => (int)$r['max_points']];
            if (!$fallback) $fallback = $byCourse[$r['course_id']];
        }
        return [$byCourse, $fallback];
    }
}
