<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** submissions, executions, peer_assignments, peer_reviews, review_scores. */
class Review_model extends Model
{
    protected $table = 'submissions';
    protected $primary_key = 'id';
    private $db;

    public function __construct()
    {
        parent::__construct();
        $this->db = load_class('database', 'database')::instance(NULL);
    }

    public function listSubmissions($forStudentIdOrNull)
    {
        if ($forStudentIdOrNull) {
            return $this->db->raw(
                "SELECT s.id, s.kind, s.title, s.status, s.language, s.created_at, e.title exercise,
                        (SELECT COUNT(*) FROM peer_assignments pa WHERE pa.submission_id = s.id) reviewers,
                        (SELECT COUNT(*) FROM peer_reviews pr WHERE pr.submission_id = s.id) reviews_done,
                        (SELECT ROUND(AVG(rs.score), 1) FROM review_scores rs JOIN peer_reviews pr2 ON pr2.id = rs.review_id WHERE pr2.submission_id = s.id) avg_score
                 FROM submissions s LEFT JOIN coding_exercises e ON e.id = s.exercise_id
                 WHERE s.user_id = ? ORDER BY s.created_at DESC",
                [$forStudentIdOrNull]
            )->fetchAll(PDO::FETCH_ASSOC);
        }
        return $this->db->raw(
            "SELECT s.id, s.kind, s.title, s.status, s.language, s.created_at, e.title exercise, u.name author, u.id author_id,
                    (SELECT COUNT(*) FROM peer_assignments pa WHERE pa.submission_id = s.id) reviewers,
                    (SELECT COUNT(*) FROM peer_reviews pr WHERE pr.submission_id = s.id) reviews_done,
                    (SELECT ROUND(AVG(rs.score), 1) FROM review_scores rs JOIN peer_reviews pr2 ON pr2.id = rs.review_id WHERE pr2.submission_id = s.id) avg_score
             FROM submissions s LEFT JOIN coding_exercises e ON e.id = s.exercise_id JOIN users u ON u.id = s.user_id
             ORDER BY s.created_at DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insertSubmission($id, $userId, $exerciseId, $kind, $title, $code, $language)
    {
        $this->db->raw(
            "INSERT INTO submissions (id, user_id, exercise_id, kind, title, code, language, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'submitted', NOW())",
            [$id, $userId, $exerciseId, $kind, $title, $code, $language]
        );
    }

    public function setSubmissionStatus($id, $status)
    {
        $this->db->table('submissions')->where('id', $id)->update(['status' => $status]);
    }

    public function submission($id)
    {
        return $this->db->table('submissions')->where('id', $id)->limit(1)->get();
    }

    public function insertExecution($data)
    {
        $this->db->raw(
            'INSERT INTO execution_results (id, user_id, exercise_id, stdout, stderr, runtime_ms, passed_count, total_count, results, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [$data['id'], $data['userId'], $data['exerciseId'], $data['stdout'], $data['stderr'], $data['runtimeMs'], $data['passed'], $data['total'], json_encode($data['results'])]
        );
    }

    public function priorBest($userId, $exerciseId, $excludingId)
    {
        $rows = $this->db->raw(
            'SELECT passed_count FROM execution_results WHERE user_id = ? AND exercise_id = ? AND id != ?',
            [$userId, $exerciseId, $excludingId]
        )->fetchAll(PDO::FETCH_COLUMN);
        return $rows ? max(array_map('intval', $rows)) : 0;
    }

    public function reviewerCandidates($authorId, $submissionId, $limit)
    {
        return $this->db->raw(
            "SELECT u.id, (SELECT COUNT(*) FROM peer_assignments pa WHERE pa.reviewer_id = u.id) load
             FROM users u WHERE u.role = 'student' AND u.active = 1 AND u.id != ?
               AND NOT EXISTS (
                   SELECT 1 FROM peer_assignments existing
                   WHERE existing.submission_id = ? AND existing.reviewer_id = u.id
               )
             ORDER BY load ASC, RAND() LIMIT " . intval($limit),
            [$authorId, $submissionId]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function tryInsertAssignment($submissionId, $reviewerId): bool
    {
        $statement = $this->db->raw(
            "INSERT INTO peer_assignments (id, submission_id, reviewer_id, status, assigned_at)
             VALUES (?, ?, ?, 'pending', NOW())
             ON DUPLICATE KEY UPDATE reviewer_id = reviewer_id",
            [uuid4(), $submissionId, $reviewerId]
        );

        return $statement->rowCount() > 0;
    }

    public function queueFor($reviewerId)
    {
        return $this->db->raw(
            "SELECT a.id assignment_id, a.status, a.assigned_at, s.user_id author, s.title, s.code, s.language, s.created_at submitted_at,
                    e.title exercise_title, e.prompt, e.course_id
             FROM peer_assignments a JOIN submissions s ON s.id = a.submission_id LEFT JOIN coding_exercises e ON e.id = s.exercise_id
             WHERE a.reviewer_id = ? ORDER BY a.status ASC, a.assigned_at DESC",
            [$reviewerId]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function assignment($id)
    {
        return $this->db->table('peer_assignments')->where('id', $id)->limit(1)->get();
    }

    public function criteriaByIds(array $ids)
    {
        if (count($ids) === 0) return [];
        return $this->db->raw(
            'SELECT * FROM rubric_criteria WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insertReview($reviewId, $assignmentId, $submissionId, $reviewerId, $comment)
    {
        $this->db->raw(
            'INSERT INTO peer_reviews (id, assignment_id, submission_id, reviewer_id, overall_comment, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
            [$reviewId, $assignmentId, $submissionId, $reviewerId, $comment]
        );
    }

    public function insertReviewScore($reviewId, $criterionId, $score)
    {
        $this->db->raw('INSERT INTO review_scores (id, review_id, criterion_id, score) VALUES (?, ?, ?, ?)', [uuid4(), $reviewId, $criterionId, $score]);
    }

    public function markAssignmentSubmitted($assignmentId)
    {
        $this->db->raw("UPDATE peer_assignments SET status = 'submitted', completed_at = NOW() WHERE id = ?", [$assignmentId]);
    }

    public function openAssignmentCount($submissionId)
    {
        return (int)$this->db->table('peer_assignments')
            ->where('submission_id', $submissionId)
            ->where('status', '!=', 'submitted')
            ->count();
    }

    public function feedbackFor($authorId)
    {
        $rows = $this->db->raw(
            'SELECT r.id review_id, r.overall_comment comment, r.created_at, r.reviewer_id, s.title
             FROM peer_reviews r JOIN submissions s ON s.id = r.submission_id
             WHERE s.user_id = ? ORDER BY r.created_at DESC',
            [$authorId]
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['scores'] = $this->db->raw(
                'SELECT rc.label, rc.max_points, rs.score FROM review_scores rs JOIN rubric_criteria rc ON rc.id = rs.criterion_id WHERE rs.review_id = ? ORDER BY rc.position',
                [$r['review_id']]
            )->fetchAll(PDO::FETCH_ASSOC);
        }
        return $rows;
    }
}
