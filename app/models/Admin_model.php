<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Database aggregates used by the administrator dashboard. */
class Admin_model extends Model
{
    protected $table = 'users';
    protected $primary_key = 'id';
    private $db;

    public function __construct()
    {
        parent::__construct();
        $this->db = load_class('database', 'database')::instance(NULL);
    }

    public function analytics()
    {
        $users = $this->db->raw(
            "SELECT COUNT(*) total,
                    COALESCE(SUM(active = 1), 0) active,
                    COALESCE(SUM(active = 0), 0) inactive,
                    COALESCE(SUM(role = 'student'), 0) students,
                    COALESCE(SUM(role = 'instructor'), 0) instructors,
                    COALESCE(SUM(role = 'admin'), 0) admins,
                    COALESCE(SUM(email_verified = 1), 0) verified,
                    COALESCE(SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)), 0) new_users_7d,
                    COALESCE(SUM(created_at >= DATE_SUB(NOW(), INTERVAL 27 DAY)), 0) new_users_28d,
                    COALESCE(SUM(xp), 0) total_xp,
                    COALESCE(AVG(xp), 0) average_xp
             FROM users"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $roleRows = $this->db->raw(
            'SELECT role, COUNT(*) count FROM users GROUP BY role ORDER BY role'
        )->fetchAll(PDO::FETCH_ASSOC);

        $registrations = $this->db->raw(
            "SELECT DATE_FORMAT(created_at, '%Y-%m-%d') date, COUNT(*) count
             FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 27 DAY)
             GROUP BY DATE_FORMAT(created_at, '%Y-%m-%d')"
        )->fetchAll(PDO::FETCH_ASSOC);
        $dailyLessons = $this->db->raw(
            "SELECT DATE_FORMAT(completed_at, '%Y-%m-%d') date, COUNT(*) count
             FROM progress_records WHERE completed_at >= DATE_SUB(NOW(), INTERVAL 27 DAY)
             GROUP BY DATE_FORMAT(completed_at, '%Y-%m-%d')"
        )->fetchAll(PDO::FETCH_ASSOC);
        $dailyRuns = $this->db->raw(
            "SELECT DATE_FORMAT(created_at, '%Y-%m-%d') date, COUNT(*) count
             FROM execution_results WHERE created_at >= DATE_SUB(NOW(), INTERVAL 27 DAY)
             GROUP BY DATE_FORMAT(created_at, '%Y-%m-%d')"
        )->fetchAll(PDO::FETCH_ASSOC);

        $activity = [];
        for ($offset = 27; $offset >= 0; $offset--) {
            $date = date('Y-m-d', strtotime("-$offset day"));
            $activity[$date] = ['date' => $date, 'signups' => 0, 'lessons' => 0, 'runs' => 0];
        }
        foreach ($registrations as $row) {
            if (isset($activity[$row['date']])) $activity[$row['date']]['signups'] = (int)$row['count'];
        }
        foreach ($dailyLessons as $row) {
            if (isset($activity[$row['date']])) $activity[$row['date']]['lessons'] = (int)$row['count'];
        }
        foreach ($dailyRuns as $row) {
            if (isset($activity[$row['date']])) $activity[$row['date']]['runs'] = (int)$row['count'];
        }

        $enrollments = $this->scalar('SELECT COUNT(*) FROM course_enrollments');
        $peerReviewsSubmitted = $this->countWhere('peer_assignments', "status = 'submitted'");
        $peerReviewsPending = $this->countWhere('peer_assignments', "status = 'pending'");
        $assignmentStats = $this->db->raw(
            'SELECT COUNT(*) attempts, COALESCE(AVG(score), 0) average_score,
                    COALESCE(SUM(is_late = 1), 0) late,
                    COALESCE(SUM(score >= 100), 0) perfect
             FROM coding_assignment_attempts'
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $content = $this->db->raw(
            "SELECT (SELECT COUNT(*) FROM courses) courses,
                    (SELECT COUNT(*) FROM courses WHERE published = 1) published_courses,
                    (SELECT COUNT(*) FROM lessons) lessons,
                    (SELECT COUNT(*) FROM coding_exercises) exercises,
                    (SELECT COUNT(*) FROM coding_assignments) assignments,
                    (SELECT COUNT(*) FROM coding_assignments WHERE published = 1) published_assignments,
                    (SELECT COUNT(*) FROM certificates) certificates,
                    (SELECT COUNT(*) FROM badges) badges"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $payments = $this->db->raw(
            "SELECT currency,
                    COALESCE(SUM(CASE WHEN status = 'paid' THEN amount_cents ELSE 0 END), 0) paid_cents,
                    COALESCE(SUM(status = 'paid'), 0) paid_transactions,
                    COALESCE(SUM(status = 'demo'), 0) demo_transactions
             FROM payments GROUP BY currency"
        )->fetchAll(PDO::FETCH_ASSOC);
        $activeSubscriptions = $this->scalar(
            "SELECT COUNT(*) FROM subscriptions WHERE status = 'active' AND (expires_at IS NULL OR expires_at > NOW())"
        );
        $recentPayments = $this->db->raw(
            'SELECT p.id, p.amount_cents amountCents, p.currency, p.status,
                    p.created_at createdAt, u.name userName, u.email userEmail
             FROM payments p JOIN users u ON u.id = p.user_id
             ORDER BY p.created_at DESC LIMIT 6'
        )->fetchAll(PDO::FETCH_ASSOC);

        $mastery = $this->db->raw(
            'SELECT s.name skill, ROUND(AVG(latest.score), 0) averageScore, COUNT(*) learnerCount
             FROM (
                 SELECT scores.skill_id, scores.user_id, scores.score
                 FROM student_skill_scores scores
                 JOIN (
                     SELECT skill_id, user_id, MAX(measured_at) measured_at
                     FROM student_skill_scores
                     GROUP BY skill_id, user_id
                 ) latest_reading ON latest_reading.skill_id = scores.skill_id
                     AND latest_reading.user_id = scores.user_id
                     AND latest_reading.measured_at = scores.measured_at
             ) latest
             JOIN skills s ON s.id = latest.skill_id
             GROUP BY s.name ORDER BY ROUND(AVG(latest.score), 0)'
        )->fetchAll(PDO::FETCH_ASSOC);

        $topLearners = $this->db->raw(
            "SELECT u.id, u.name, u.email, u.xp, u.level,
                    (SELECT COUNT(*) FROM progress_records pr WHERE pr.user_id = u.id) lessons,
                    (SELECT COUNT(*) FROM certificates c WHERE c.user_id = u.id) certificates,
                    (SELECT COUNT(*) FROM user_badges ub WHERE ub.user_id = u.id) badges
             FROM users u WHERE u.role = 'student' AND u.active = 1
             ORDER BY u.xp DESC LIMIT 8"
        )->fetchAll(PDO::FETCH_ASSOC);

        $popularCourses = $this->db->raw(
            'SELECT c.id, c.title, c.published,
                    (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.course_id = c.id) enrollments,
                    (SELECT COUNT(*) FROM lessons l JOIN modules m ON m.id = l.module_id WHERE m.course_id = c.id) lessons,
                    (SELECT COUNT(*) FROM certificates cert WHERE cert.course_id = c.id) certificates
             FROM courses c
             ORDER BY (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.course_id = c.id) DESC
             LIMIT 6'
        )->fetchAll(PDO::FETCH_ASSOC);

        return [
            'users' => [
                'total' => (int)($users['total'] ?? 0),
                'active' => (int)($users['active'] ?? 0),
                'inactive' => (int)($users['inactive'] ?? 0),
                'students' => (int)($users['students'] ?? 0),
                'instructors' => (int)($users['instructors'] ?? 0),
                'admins' => (int)($users['admins'] ?? 0),
                'verified' => (int)($users['verified'] ?? 0),
                'newUsers7d' => (int)($users['new_users_7d'] ?? 0),
                'newUsers28d' => (int)($users['new_users_28d'] ?? 0),
                'totalXp' => (int)($users['total_xp'] ?? 0),
                'averageXp' => (int)round((float)($users['average_xp'] ?? 0)),
                'roles' => array_map(static fn($row) => [
                    'role' => $row['role'], 'count' => (int)$row['count'],
                ], $roleRows),
            ],
            'activity' => array_values($activity),
            'learning' => [
                'enrollments' => $enrollments,
                'codeRuns' => array_sum(array_map(static fn($row) => (int)$row['count'], $dailyRuns)),
                'lessonsCompleted' => array_sum(array_map(static fn($row) => (int)$row['count'], $dailyLessons)),
                'peerReviewsSubmitted' => $peerReviewsSubmitted,
                'peerReviewsPending' => $peerReviewsPending,
                'assignmentAttempts' => (int)($assignmentStats['attempts'] ?? 0),
                'assignmentAverageScore' => (int)round((float)($assignmentStats['average_score'] ?? 0)),
                'lateAttempts' => (int)($assignmentStats['late'] ?? 0),
                'perfectAssignmentAttempts' => (int)($assignmentStats['perfect'] ?? 0),
            ],
            'content' => [
                'courses' => (int)($content['courses'] ?? 0),
                'publishedCourses' => (int)($content['published_courses'] ?? 0),
                'lessons' => (int)($content['lessons'] ?? 0),
                'exercises' => (int)($content['exercises'] ?? 0),
                'assignments' => (int)($content['assignments'] ?? 0),
                'publishedAssignments' => (int)($content['published_assignments'] ?? 0),
                'certificates' => (int)($content['certificates'] ?? 0),
                'badges' => (int)($content['badges'] ?? 0),
            ],
            'revenue' => [
                'currencies' => array_map(static fn($row) => [
                    'currency' => strtoupper($row['currency']),
                    'paidCents' => (int)$row['paid_cents'],
                    'paidTransactions' => (int)$row['paid_transactions'],
                    'demoTransactions' => (int)$row['demo_transactions'],
                ], $payments),
                'activeSubscriptions' => $activeSubscriptions,
                'recent' => array_map(static function ($row) {
                    $row['amountCents'] = (int)$row['amountCents'];
                    return $row;
                }, $recentPayments),
            ],
            'mastery' => array_map(static fn($row) => [
                'skill' => $row['skill'],
                'averageScore' => (int)$row['averageScore'],
                'learnerCount' => (int)$row['learnerCount'],
            ], $mastery),
            'topLearners' => array_map(static function ($row) {
                foreach (['xp', 'level', 'lessons', 'certificates', 'badges'] as $key) {
                    $row[$key] = (int)$row[$key];
                }
                return $row;
            }, $topLearners),
            'popularCourses' => array_map(static function ($row) {
                foreach (['enrollments', 'lessons', 'certificates'] as $key) {
                    $row[$key] = (int)$row[$key];
                }
                $row['published'] = (bool)$row['published'];
                return $row;
            }, $popularCourses),
        ];
    }

    private function scalar($query)
    {
        return (int)$this->db->raw($query)->fetchColumn();
    }

    private function countWhere($table, $condition)
    {
        return $this->scalar("SELECT COUNT(*) FROM `$table` WHERE $condition");
    }
}
