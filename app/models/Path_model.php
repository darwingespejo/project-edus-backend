<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** learning_paths + items + progress_records. */
class Path_model extends Model
{
    protected $table = 'learning_paths';
    protected $primary_key = 'id';
    private $db;

    public function __construct()
    {
        parent::__construct();
        $this->db = load_class('database', 'database')::instance(NULL);
    }

    public function activeFor($userId)
    {
        return $this->db->table('learning_paths')
            ->where(['user_id' => $userId, 'status' => 'active'])
            ->limit(1)
            ->get();
    }

    public function byId($id)
    {
        return $this->db->table('learning_paths')->where('id', $id)->limit(1)->get();
    }

    public function itemsOf($pathId)
    {
        return $this->db->raw(
            'SELECT pi.id item_id, pi.position, pi.status, pi.completed_at, l.id lesson_id, l.title, l.estimated_minutes, m.title module_title
             FROM learning_path_items pi JOIN lessons l ON l.id = pi.lesson_id JOIN modules m ON m.id = l.module_id
             WHERE pi.path_id = ? ORDER BY pi.position',
            [$pathId]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function questionsForPath($pathId): array
    {
        $questions = $this->db->raw(
            'SELECT m.title module_title, q.title quiz_title, qq.id question_id, qq.type, qq.prompt, qq.options
             FROM module_quizzes q
             JOIN modules m ON m.id = q.module_id
             JOIN module_quiz_questions qq ON qq.quiz_id = q.id
             WHERE EXISTS (
               SELECT 1 FROM learning_path_items pi
               JOIN lessons l ON l.id = pi.lesson_id
               WHERE pi.path_id = ? AND pi.status != \'done\' AND l.module_id = m.id
             )
             ORDER BY (
               SELECT MIN(pi.position) FROM learning_path_items pi
               JOIN lessons l ON l.id = pi.lesson_id
               WHERE pi.path_id = ? AND pi.status != \'done\' AND l.module_id = m.id
             ), q.created_at, qq.position
             LIMIT 20',
            [$pathId, $pathId]
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($questions as &$question) {
            $question['options'] = $question['options'] === null
                ? []
                : json_decode($question['options'], true, 512, JSON_THROW_ON_ERROR);
        }
        unset($question);

        return $questions;
    }

    public function assistantLessonsForPath($pathId): array
    {
        $lessons = $this->db->raw(
            "SELECT l.title, m.title module_title, l.content
             FROM learning_path_items pi
             JOIN lessons l ON l.id = pi.lesson_id
             JOIN modules m ON m.id = l.module_id
             WHERE pi.path_id = ? AND pi.status != 'done'
             ORDER BY pi.position LIMIT 8",
            [$pathId]
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($lessons as &$lesson) {
            $lesson['title'] = mb_substr((string)$lesson['title'], 0, 160);
            $lesson['module'] = mb_substr((string)$lesson['module_title'], 0, 160);
            $lesson['content'] = mb_substr((string)$lesson['content'], 0, 2500);
            unset($lesson['module_title']);
        }
        unset($lesson);

        return $lessons;
    }

    public function itemById($itemId)
    {
        return $this->db->table('learning_path_items')->where('id', $itemId)->limit(1)->get();
    }

    public function createPath($pathId, $userId, $courseId)
    {
        $this->db->raw(
            "INSERT INTO learning_paths (id, user_id, course_id, status, rationale, generated_at)
             VALUES (?, ?, ?, 'active', ?, NOW())",
            [$pathId, $userId, $courseId, 'Your personalized learning path is being prepared.']
        );
    }

    public function clearOpenItems($pathId)
    {
        return $this->db->table('learning_path_items')
            ->where('path_id', $pathId)
            ->where('status', '!=', 'done')
            ->delete();
    }

    public function doneLessonIds($pathId)
    {
        return $this->db->table('learning_path_items')
            ->select('lesson_id')
            ->where(['path_id' => $pathId, 'status' => 'done'])
            ->get_all(PDO::FETCH_COLUMN);
    }

    public function insertItem($pathId, $lessonId, $position, $status)
    {
        $this->db->raw('INSERT INTO learning_path_items (id, path_id, lesson_id, position, status) VALUES (?, ?, ?, ?, ?)', [uuid4(), $pathId, $lessonId, $position, $status]);
    }

    public function updatePathMeta($pathId, $velocity, $rationale)
    {
        $this->db->table('learning_paths')
            ->where('id', $pathId)
            ->update(['velocity' => $velocity, 'rationale' => $rationale]);
    }

    public function closePath($pathId)
    {
        $this->db->raw("UPDATE learning_paths SET status = 'completed', completed_at = NOW() WHERE id = ?", [$pathId]);
    }

    public function markItemDone($itemId)
    {
        $this->db->raw("UPDATE learning_path_items SET status = 'done', completed_at = NOW() WHERE id = ?", [$itemId]);
    }

    public function openItems($pathId)
    {
        return $this->db->table('learning_path_items')
            ->where('path_id', $pathId)
            ->in('status', ['current', 'locked'])
            ->order_by('position')
            ->get_all();
    }

    public function promoteItem($itemId)
    {
        $this->db->table('learning_path_items')
            ->where('id', $itemId)
            ->update(['status' => 'current']);
    }

    public function recordProgress($userId, $lessonId, $timeSpentSec)
    {
        $this->db->raw(
            'INSERT INTO progress_records (id, user_id, lesson_id, time_spent_sec, completed_at) VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE time_spent_sec = time_spent_sec + VALUES(time_spent_sec)',
            [uuid4(), $userId, $lessonId, $timeSpentSec]
        );
    }

    public function lessonsWithSkills($courseId)
    {
        return $this->db->raw(
            'SELECT l.id lesson_id, l.title, l.position lesson_position,
                    m.position module_position, ls.skill_id, s.name skill_name
             FROM lessons l JOIN modules m ON m.id = l.module_id
             LEFT JOIN lesson_skills ls ON ls.lesson_id = l.id
             LEFT JOIN skills s ON s.id = ls.skill_id
             WHERE m.course_id = ? ORDER BY m.position, l.position',
            [$courseId]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function issueCertificate($userId, $courseId): string
    {
        $existing = $this->certificateForCourse($userId, $courseId);
        if ($existing) return $existing;
        $code = 'EDU-' . strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2)));
        try {
            $this->db->raw(
                'INSERT INTO certificates (id, user_id, course_id, code, issued_at)
                 VALUES (?, ?, ?, ?, NOW())',
                [uuid4(), $userId, $courseId, $code]
            );
        } catch (PDOException $error) {
            if ($error->getCode() !== '23000') throw $error;
            $existing = $this->certificateForCourse($userId, $courseId);
            if ($existing) return $existing;
            throw $error;
        }

        return $code;
    }

    public function certificateForCourse($userId, $courseId): ?string
    {
        $row = $this->db->raw(
            'SELECT code FROM certificates WHERE user_id = ? AND course_id = ? LIMIT 1',
            [$userId, $courseId]
        )->fetch(PDO::FETCH_ASSOC);
        return $row['code'] ?? null;
    }

    public function latestMastery($userId)
    {
        $rows = $this->db->raw(
            'SELECT s.skill_id, s.score FROM student_skill_scores s
             JOIN (SELECT skill_id, MAX(measured_at) m FROM student_skill_scores WHERE user_id = ? GROUP BY skill_id) latest
               ON latest.skill_id = s.skill_id AND latest.m = s.measured_at WHERE s.user_id = ?',
            [$userId, $userId]
        )->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $r) $map[$r['skill_id']] = (float)$r['score'];
        return $map;
    }

    public function recentCompletionCount($userId)
    {
        return (int)$this->db->raw(
            'SELECT COUNT(*) c FROM progress_records WHERE user_id = ? AND completed_at > DATE_SUB(NOW(), INTERVAL 14 DAY)',
            [$userId]
        )->fetch(PDO::FETCH_ASSOC)['c'];
    }
}
