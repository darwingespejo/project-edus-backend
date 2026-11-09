<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** assessments, questions, attempts, answers, per-skill scores. */
class Assessment_model extends Model
{
    protected $table = 'assessments';
    protected $primary_key = 'id';
    private $db;

    public function __construct()
    {
        parent::__construct();
        $this->db = load_class('database', 'database')::instance(NULL);
    }

    public function placementHeader()
    {
        return $this->db->table('assessments')
            ->select('id, title')
            ->where('kind', 'placement')
            ->limit(1)
            ->get();
    }

    public function publicQuestions($assessmentId)
    {
        $qs = $this->db->raw(
            'SELECT q.id, q.type, q.prompt, q.options, q.points, s.name skill FROM assessment_questions q
             LEFT JOIN skills s ON s.id = q.skill_id WHERE q.assessment_id = ? ORDER BY q.position',
            [$assessmentId]
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($qs as &$q) $q['options'] = $q['options'] ? json_decode($q['options'], true) : null;
        return $qs;
    }

    public function allQuestionsWithKeys()
    {
        return $this->db->raw('SELECT q.*, s.name skill_name FROM assessment_questions q LEFT JOIN skills s ON s.id = q.skill_id')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function bestScore($userId)
    {
        $s = $this->db->table('assessment_attempts')
            ->select_max('score', 's')
            ->where('user_id', $userId)
            ->get()['s'];
        return $s !== null ? (float)$s : null;
    }

    public function recordAttempt($attemptId, $assessmentId, $userId, $scorePct)
    {
        $this->db->raw(
            "INSERT INTO assessment_attempts (id, assessment_id, user_id, status, score, started_at, submitted_at)
             VALUES (?, ?, ?, 'submitted', ?, NOW(), NOW())",
            [$attemptId, $assessmentId, $userId, number_format($scorePct, 2, '.', '')]
        );
    }

    public function recordAnswer($attemptId, $questionId, $response, $correct, $points)
    {
        $this->db->raw(
            'INSERT INTO attempt_answers (id, attempt_id, question_id, response, is_correct, points_awarded) VALUES (?, ?, ?, ?, ?, ?)',
            [uuid4(), $attemptId, $questionId, $response, $correct ? 1 : 0, $points]
        );
    }

    public function recordSkillScore($userId, $skillId, $source, $score)
    {
        $this->db->raw(
            'INSERT INTO student_skill_scores (id, user_id, skill_id, source, score, measured_at) VALUES (?, ?, ?, ?, ?, NOW())',
            [uuid4(), $userId, $skillId, $source, $score]
        );
    }

    public function hasAttempt($userId)
    {
        return (bool)$this->db->table('assessment_attempts')->select('id')->where('user_id', $userId)->limit(1)->get();
    }
}
