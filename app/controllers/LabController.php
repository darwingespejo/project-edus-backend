<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
class LabController extends MY_Controller
{
    private $users;
    private $auth;
    private $content;
    private $assessments;
    private $reviews;
    private $gamify;
    private $assigner;
    private $classroom;
    private $certificates;

    public function __construct()
    {
        parent::__construct();
        $this->call->library('Clients');
        $this->users = $this->call->model('User_model');
        $this->auth = $this->call->model('Auth_model');
        $gamifyModel = $this->call->model('Gamify_model');
        $this->gamify = $this->call->library('Gamify', $gamifyModel);
        $this->content = $this->call->model('Content_model');
        $this->classroom = $this->call->model('Classroom_model');
        $this->certificates = $this->call->library('CourseCertificate', [
            $this->call->model('Course_model'),
            $this->users,
            $this->gamify,
        ]);
        $this->assessments = $this->call->model('Assessment_model');
        $this->reviews = $this->call->model('Review_model');
        $this->assigner = $this->call->library('PeerAssigner', [$this->reviews, $this->gamify]);
    }

    public function exercises()
    {
        $user = auth_user($this->users);
        $visibleCases = $this->content->visibleTestCasesByExercise();
        $exercises = array_map(static function (array $exercise) use ($visibleCases): array {
            $isJavascript = $exercise['language'] === 'javascript';
            return [
                'id' => $exercise['id'],
                'title' => $exercise['title'],
                'prompt' => $exercise['prompt'],
                'language' => $exercise['language'],
                'starterCode' => $exercise['starter_code'],
                'course' => $exercise['course'],
                'skill' => $exercise['skill'],
                'visibleTests' => (int)$exercise['visible_tests'],
                'totalTests' => (int)$exercise['total_tests'],
                'bestPassed' => $exercise['best_passed'] === null ? null : (int)$exercise['best_passed'],
                'runnerAvailable' => $isJavascript || Judge0Client::available(),
                'serverRunnerAvailable' => Judge0Client::available(),
                'visibleTestCases' => $isJavascript ? ($visibleCases[$exercise['id']] ?? []) : [],
            ];
        }, $this->content->exercisesWithBest($user['id']));
        respond($exercises);
    }

    /** Execution endpoint: JavaScript runs in Judge0 for submissions; other languages use Judge0. */
    public function run()
    {
        $user = auth_user($this->users);
        if (rate_limited($this->auth, "run:{$user['id']}", 30, 60)) fail_api('Slow down — you can run code 30 times per minute.', 429);

        $b = json_body();
        $code = (string)($b['code'] ?? '');
        if (trim($code) === '' || strlen($code) > 50000) fail_api('Nothing to run yet (max 50,000 chars).', 422);
        $language = $b['language'] ?? 'javascript';
        $submit = (bool)($b['submit'] ?? false);
        $assignmentId = (string)($b['assignmentId'] ?? '');
        $assignment = null;
        if ($assignmentId !== '') {
            if ($user['role'] !== 'student') fail_api('Only students can submit coding assignments.', 403);
            if (!$submit) fail_api('Run the assignment before submitting it.', 422);
            $assignment = $this->classroom->assignmentForStudent($assignmentId, $user['id']);
            if (!$assignment) fail_api('Assignment not found or not available to your account.', 404);
            if (!empty($b['exerciseId']) && $b['exerciseId'] !== $assignment['exerciseId']) {
                fail_api('The selected exercise does not match this assignment.', 422);
            }
            $b['exerciseId'] = $assignment['exerciseId'];
        }

        $exercise = null;
        $tests = [];
        if (!empty($b['exerciseId'])) {
            $exercise = $this->content->exercise($b['exerciseId']);
            if (!$exercise) fail_api('Exercise not found.', 404);
            $tests = $this->content->testCases($exercise['id']);
            $language = $exercise['language'];
        }

        if (!$exercise) {
            if ($submit) fail_api('Choose an exercise before submitting code.', 422);
            $result = Judge0Client::runFree($code, (string)$language, (string)($b['input'] ?? ''));
            $this->reviews->insertExecution([
                'id' => uuid4(), 'userId' => $user['id'], 'exerciseId' => null,
                'stdout' => $result['stdout'], 'stderr' => $result['stderr'], 'runtimeMs' => $result['runtimeMs'],
                'passed' => 0, 'total' => 0, 'results' => [],
            ]);
            respond($result, 'Ran.');
        }

        $result = $this->execute($code, $language, $tests);

        $execId = uuid4();
        $this->reviews->insertExecution([
            'id' => $execId, 'userId' => $user['id'], 'exerciseId' => $exercise['id'] ?? null,
            'stdout' => $result['stdout'], 'stderr' => $result['stderr'], 'runtimeMs' => $result['runtime_ms'],
            'passed' => $result['passed_count'], 'total' => $result['total_count'], 'results' => $result['results'],
        ]);
        [$xpGained, $badges] = $this->rewardProgress($user['id'], $exercise, $execId, $result);

        $submissionId = null;
        $assignmentAttempt = null;
        if ($assignment) {
            $isLate = !empty($assignment['dueAt']) && strtotime($assignment['dueAt']) < time();
            $score = $result['total_count'] > 0
                ? round($result['passed_count'] / $result['total_count'] * 100, 2)
                : 0;
            $assignmentAttempt = $this->classroom->recordAttempt(
                $assignmentId,
                $user['id'],
                $execId,
                $code,
                $result['passed_count'],
                $result['total_count'],
                $score,
                $isLate
            );
            $issued = $score >= 100
                ? $this->certificates->issue($assignment['courseId'], $user['id'])
                : ['code' => null];
            $assignmentAttempt['certificateCode'] = $issued['code'];
        } elseif ($submit) {
            $submissionId = uuid4();
            $this->reviews->insertSubmission($submissionId, $user['id'], $exercise['id'] ?? null, 'exercise', null, $code, $language);
        }
        $clientResults = array_map(static function ($case) {
            if (empty($case['hidden'])) return $case;
            return array_merge($case, [
                'input' => '',
                'expected' => '',
                'actual' => $case['passed'] ? 'Passed' : 'Hidden test failed',
                'log' => [],
            ]);
        }, $result['results']);
        respond([
            'submissionId' => $submissionId,
            'stdout' => $result['stdout'],
            'stderr' => $result['stderr'],
            'runtimeMs' => $result['runtime_ms'],
            'passedCount' => $result['passed_count'],
            'totalCount' => $result['total_count'],
            'results' => $clientResults,
            'xpGained' => $xpGained,
            'badges' => $badges,
            'assignmentAttempt' => $assignmentAttempt,
        ], $assignment ? 'Assignment submitted.' : 'Ran.');
    }

    private function execute(string $code, string $language, array $tests): array
    {
        if (!Judge0Client::available()) {
            fail_api('Code execution is not configured. Set JUDGE0_URL to a Judge0 instance.', 503);
        }
        $t0 = microtime(true);
        $cases = $language === 'javascript'
            ? Judge0Client::run($code, $tests)
            : Judge0Client::runProgramTests($code, $language, $tests);
        $passed = count(array_filter($cases, fn($c) => $c['passed']));
        return ['stdout' => '', 'stderr' => '', 'runtime_ms' => max(1, (int)round((microtime(true) - $t0) * 1000)),
            'passed_count' => $passed, 'total_count' => count($cases), 'results' => $cases];
    }

    /** XP for beating your previous best; skill score snapshot; bug-hunter badge on first full pass. */
    private function rewardProgress($userId, $exercise, $execId, array $result): array
    {
        if (!$exercise || $result['total_count'] === 0) return [0, []];
        $priorBest = $this->reviews->priorBest($userId, $exercise['id'], $execId);
        $xpGained = max(0, $result['passed_count'] - $priorBest) * 15;
        $badges = [];
        if ($result['passed_count'] === $result['total_count'] && $priorBest < $result['total_count']) {
            $xpGained += 20;
            $b = $this->gamify->award($userId, 'bug-hunter');
            if ($b) $badges[] = $b;
        }
        if ($xpGained > 0) $this->gamify->addXp($userId, $xpGained);
        $this->gamify->touchStreak($userId);
        if (!empty($exercise['skill_id'])) {
            $this->assessments->recordSkillScore($userId, $exercise['skill_id'], 'exercise',
                (int)round($result['passed_count'] / $result['total_count'] * 100));
        }
        return [$xpGained, $badges];
    }

    // ---------- submissions ----------

    public function submissions()
    {
        $user = auth_user($this->users);
        $rows = $user['role'] === 'student'
            ? $this->reviews->listSubmissions($user['id'])
            : $this->reviews->listSubmissions(null);
        if ($user['role'] === 'student') respond($rows);
        respond(array_map(fn($r) => $r + ['authorAlias' => alias_for($r['author_id'])], $rows));
    }

    /** Assignment submission → automatic anonymous reviewer assignment (3 least-loaded classmates). */
    public function submit_assignment()
    {
        $user = require_role($this->users, ['student']);
        $b = json_body();
        if (empty($b['exerciseId']) || trim((string)($b['code'] ?? '')) === '') fail_api('Add some code before submitting.', 422);
        $exercise = $this->content->exercise($b['exerciseId']);
        if (!$exercise) fail_api('Exercise not found.', 404);

        $id = uuid4();
        $this->reviews->insertSubmission($id, $user['id'], $exercise['id'], 'assignment',
            preg_replace('/^Assignment — /', '', $exercise['title']), (string)$b['code'], $b['language'] ?? 'html');
        $assigned = $this->assigner->assign($id, $user['id'], 3);
        respond(['id' => $id, 'assigned' => $assigned], $assigned > 0
            ? "Submitted. $assigned anonymous classmates will review it."
            : 'Submitted, but no classmates are available to review it yet.', 201);
    }

    // ---------- peer review ----------

    public function review_queue()
    {
        $user = require_role($this->users, ['student']);
        [$byCourse, $fallback] = $this->content->rubricByCourse();
        respond(array_map(fn($r) => [
            'assignmentId' => $r['assignment_id'], 'status' => $r['status'],
            'assignedAt' => $r['assigned_at'], 'submittedAt' => $r['submitted_at'],
            'authorAlias' => 'Author ' . alias_for($r['author']),
            'title' => $r['title'] ?? $r['exercise_title'] ?? 'Assignment',
            'prompt' => $r['prompt'], 'code' => $r['code'], 'language' => $r['language'],
            'rubric' => !empty($r['course_id']) ? ($byCourse[$r['course_id']] ?? $fallback) : $fallback,
        ], $this->reviews->queueFor($user['id'])));
    }

    public function review_submit()
    {
        $user = require_role($this->users, ['student']);
        $b = json_body();
        $assignmentId = (string)($b['assignmentId'] ?? '');
        $comment = trim((string)($b['comment'] ?? ''));
        if (strlen($comment) < 20) fail_api('Write at least a sentence of feedback — 20 characters minimum.', 422, ['comment' => 'Too short.']);

        $a = $this->reviews->assignment($assignmentId);
        if (!$a || $a['reviewer_id'] !== $user['id']) fail_api('Assignment not found.', 404);
        if ($a['status'] !== 'pending') fail_api("You've already reviewed this submission.", 409);

        $scores = $b['scores'] ?? [];
        [$criteria, $total, $maxTotal] = $this->validateScores($scores);

        $reviewId = uuid4();
        $this->reviews->insertReview($reviewId, $assignmentId, $a['submission_id'], $user['id'], $comment);
        foreach ($scores as $s) $this->reviews->insertReviewScore($reviewId, $s['criterionId'], (int)$s['score']);
        $this->reviews->markAssignmentSubmitted($assignmentId);

        if ($this->reviews->openAssignmentCount($a['submission_id']) === 0) {
            $this->reviews->setSubmissionStatus($a['submission_id'], 'reviewed');
        }
        $sub = $this->reviews->submission($a['submission_id']);
        if ($sub) {
            $this->gamify->notify($sub['user_id'], 'review_received', "New feedback on \"{$sub['title']}\"",
                "A peer finished their review: $total/$maxTotal. Read it on the Feedback page.");
        }
        $xp = $this->gamify->addXp($user['id'], 50);
        $streak = $this->gamify->touchStreak($user['id']);
        $badge = $this->gamify->award($user['id'], 'reviewer');
        respond(['xp' => $xp['xp'], 'level' => $xp['level'], 'streak' => $streak, 'badges' => $badge ? [$badge] : []], 'Review submitted. Thanks for the careful read.');
    }

    private function validateScores(array $scores): array
    {
        $criteria = $this->reviews->criteriaByIds(array_map(fn($s) => $s['criterionId'] ?? '', $scores));
        if (count($scores) === 0 || count($criteria) !== count($scores)) fail_api('Unknown rubric criterion.', 422);
        $byId = [];
        foreach ($criteria as $c) $byId[$c['id']] = $c;
        $total = $maxTotal = 0;
        foreach ($scores as $s) {
            $c = $byId[$s['criterionId']];
            $v = (int)$s['score'];
            if ($v < 0 || $v > (int)$c['max_points']) fail_api("\"{$c['label']}\" is out of {$c['max_points']} — score $v is out of range.", 422);
            $total += $v;
            $maxTotal += (int)$c['max_points'];
        }
        return [$criteria, $total, $maxTotal];
    }

    public function review_feedback()
    {
        $user = require_role($this->users, ['student']);
        respond(array_map(fn($r) => [
            'reviewId' => $r['review_id'],
            'reviewerAlias' => 'Reviewer ' . alias_for($r['reviewer_id']),
            'title' => $r['title'] ?? 'Submission', 'comment' => $r['comment'], 'createdAt' => $r['created_at'],
            'total' => array_sum(array_column($r['scores'], 'score')),
            'max' => array_sum(array_column($r['scores'], 'max_points')),
            'criteria' => $r['scores'],
        ], $this->reviews->feedbackFor($user['id'])));
    }
}
