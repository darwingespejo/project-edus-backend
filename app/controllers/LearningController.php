<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
class LearningController extends MY_Controller
{
    private $users;
    private $content;
    private $assessments;
    private $paths_model;
    private $auth;
    private $gamify;
    private $paths;
    private $meta;

    public function __construct()
    {
        parent::__construct();
        $this->call->library('Clients');
        $this->users = $this->call->model('User_model');
        $gamifyModel = $this->call->model('Gamify_model');
        $this->gamify = $this->call->library('Gamify', $gamifyModel);
        $this->content = $this->call->model('Content_model');
        $this->assessments = $this->call->model('Assessment_model');
        $this->paths_model = $this->call->model('Path_model');
        $this->auth = $this->call->model('Auth_model');
        $this->meta = $this->call->model('Meta_model');
        $this->paths = $this->call->library('PathEngine', [
            $this->paths_model,
            $this->content,
            $this->gamify,
            $this->users,
        ]);
    }

    public function courses()
    {
        $user = auth_user($this->users);
        $courses = $this->call->model('Course_model')->coursesForUser($user);
        respond(array_map(static function (array $course): array {
            $course['published'] = (bool)$course['published'];
            $course['enrolled'] = (bool)$course['enrolled'];
            $course['lessonCount'] = (int)$course['lessonCount'];
            $course['completedLessons'] = (int)$course['completedLessons'];
            return $course;
        }, $courses));
    }

    public function lesson($id)
    {
        $user = auth_user($this->users);
        $lesson = $this->content->lessonBundle($id, $user['id'], $user['role']);
        if (!$lesson) fail_api('Lesson not found.', 404);
        respond($lesson);
    }

    // ---------- placement ----------

    public function placement()
    {
        $user = auth_user($this->users);
        $asm = $this->assessments->placementHeader();
        if (!$asm) respond(['assessment' => null, 'questions' => [], 'bestScore' => null], 'No placement assessment.');
        respond([
            'assessment' => $asm,
            'questions' => $this->assessments->publicQuestions($asm['id']),
            'bestScore' => $this->assessments->bestScore($user['id']),
        ]);
    }

    // Grades, writes per-skill mastery, then re-sequences the learning path around the gaps.
    public function placement_submit()
    {
        $user = require_role($this->users, ['student']);
        $b = json_body();
        $answers = $b['answers'] ?? [];
        if (!is_array($answers) || count($answers) === 0) fail_api('Answer at least one question.', 422);

        $byId = [];
        foreach ($this->assessments->allQuestionsWithKeys() as $q) $byId[$q['id']] = $q;

        $graded = [];
        $earned = $possible = 0;
        foreach ($answers as $a) {
            $q = $byId[$a['questionId'] ?? ''] ?? null;
            if (!$q) continue;
            $response = trim((string)($a['response'] ?? ''));
            $correct = $q['type'] === 'mcq'
                ? $response === $q['answer']
                : mb_stripos($response, $q['answer']) !== false;
            $awarded = $correct ? (int)$q['points'] : 0;
            $earned += $awarded;
            $possible += (int)$q['points'];
            $graded[] = ['q' => $q, 'response' => $response, 'correct' => $correct, 'awarded' => $awarded];
        }
        if (count($graded) === 0) fail_api('Unknown questions.', 422);
        $scorePct = $possible > 0 ? $earned / $possible * 100 : 0;

        $attemptId = uuid4();
        $this->assessments->recordAttempt($attemptId, $graded[0]['q']['assessment_id'], $user['id'], $scorePct);
        $perSkill = [];
        foreach ($graded as $g) {
            $this->assessments->recordAnswer($attemptId, $g['q']['id'], $g['response'], $g['correct'], $g['awarded']);
            $k = $g['q']['skill_id'];
            $perSkill[$k] = $perSkill[$k] ?? ['name' => $g['q']['skill_name'] ?? 'Skill', 'earned' => 0, 'possible' => 0];
            $perSkill[$k]['earned'] += $g['awarded'];
            $perSkill[$k]['possible'] += (int)$g['q']['points'];
        }
        $gaps = $this->recordSkillGaps($user['id'], $perSkill);

        $badges = array_filter([$this->gamify->award($user['id'], 'pathfinder'), $scorePct >= 90 ? $this->gamify->award($user['id'], 'sharp-mind') : null]);
        $xp = $this->gamify->addXp($user['id'], 30);
        $streak = $this->gamify->touchStreak($user['id']);
        $path = $this->paths->generate($user['id'], true);

        respond([
            'scorePct' => (int)round($scorePct), 'earned' => $earned, 'possible' => $possible,
            'perQuestion' => array_map(fn($g) => [
                'questionId' => $g['q']['id'], 'prompt' => $g['q']['prompt'], 'response' => $g['response'],
                'correct' => $g['correct'], 'expected' => $g['q']['answer'], 'pointsAwarded' => $g['awarded'], 'skill' => $g['q']['skill_name'],
            ], $graded),
            'skillGaps' => $gaps, 'xp' => $xp['xp'], 'level' => $xp['level'], 'streak' => $streak,
            'badges' => array_values($badges), 'pathId' => $path['path_id'],
        ], 'Scored ' . round($scorePct) . '%. Your path has been re-sequenced around the gaps.');
    }

    private function recordSkillGaps($userId, array $perSkill): array
    {
        $gaps = [];
        foreach ($perSkill as $skillId => $v) {
            $score = (int)round($v['earned'] / $v['possible'] * 100);
            $gaps[] = ['skillId' => $skillId, 'skill' => $v['name'], 'score' => $score];
            $this->assessments->recordSkillScore($userId, $skillId, 'assessment', $score);
        }
        usort($gaps, fn($a, $b) => $a['score'] <=> $b['score']);
        return $gaps;
    }

    // ---------- learning path ----------

    public function path()
    {
        $user = auth_user($this->users);
        $path = $this->paths_model->activeFor($user['id']);
        if (!$path) {
            respond(['path' => null, 'hasPlacement' => $this->assessments->hasAttempt($user['id'])], 'No active learning path.');
        }
        respond($this->pathPayload($path));
    }

    public function path_generate()
    {
        $user = require_role($this->users, ['student']);
        $existing = $this->paths_model->activeFor($user['id']);
        $result = $this->paths->generate($user['id'], (bool)$existing);
        $badge = $result['created'] ? $this->gamify->award($user['id'], 'pathfinder') : null;
        $path = $this->paths_model->byId($result['path_id']);
        respond($this->pathPayload($path) + ['badge' => $badge], $existing ? 'Path regenerated around your latest scores.' : 'Your path is ready.');
    }

    public function path_coach()
    {
        $user = require_role($this->users, ['student']);
        if (!$this->meta->hasActiveAssistantSubscription($user['id'])) {
            fail_api('Gemini study assistant is available only with an active Pro or Team subscription.', 403);
        }
        $path = $this->paths_model->activeFor($user['id']);
        if (!$path) fail_api('Build a learning path before asking Gemini for guidance.', 409);
        if (!GeminiPathCoach::configured()) {
            fail_api('Gemini is not configured. Add GEMINI_API_KEY to backend/.env and restart the API.', 503);
        }
        if (rate_limited($this->auth, "path-coach:{$user['id']}", 20, 3600)) {
            fail_api('Gemini study assistant is limited to 20 messages per hour.', 429);
        }

        $body = json_body();
        $message = $body['message'] ?? null;
        if (!is_string($message)) fail_api('Message must be text.', 422);
        $message = trim($message);
        if ($message === '' || mb_strlen($message) > 1200) {
            fail_api('Enter a message between 1 and 1200 characters.', 422);
        }
        $history = $body['history'] ?? [];
        if (!is_array($history) || !array_is_list($history)) fail_api('Chat history must be a list of messages.', 422);

        $conversation = [];
        foreach (array_slice($history, -10) as $entry) {
            if (!is_array($entry) || !in_array($entry['role'] ?? null, ['user', 'assistant'], true)) {
                fail_api('Chat history contains an invalid message.', 422);
            }
            $text = $entry['text'] ?? null;
            if (!is_string($text)) fail_api('Chat messages must be text.', 422);
            $text = trim($text);
            if ($text === '' || mb_strlen($text) > 1200) {
                fail_api('Chat messages must be between 1 and 1200 characters.', 422);
            }
            $conversation[] = [
                'role' => $entry['role'] === 'assistant' ? 'model' : 'user',
                'text' => $text,
            ];
        }
        while ($conversation && $conversation[0]['role'] === 'model') {
            array_shift($conversation);
        }
        $normalizedConversation = [];
        foreach ($conversation as $entry) {
            $lastIndex = count($normalizedConversation) - 1;
            if ($lastIndex >= 0 && $normalizedConversation[$lastIndex]['role'] === $entry['role']) {
                $normalizedConversation[$lastIndex]['text'] .= "\n" . $entry['text'];
            } else {
                $normalizedConversation[] = $entry;
            }
        }

        $items = $this->paths_model->itemsOf($path['id']);
        $remaining = array_values(array_filter(
            $items,
            static fn($item) => $item['status'] !== 'done'
        ));
        $course = $this->content->courseById($path['course_id']);
        $context = [
            'course' => mb_substr((string)($course['title'] ?? 'Learning path'), 0, 120),
            'pathRationale' => mb_substr((string)($path['rationale'] ?? ''), 0, 800),
            'progress' => [
                'completed' => count($items) - count($remaining),
                'total' => count($items),
            ],
            'lessons' => $this->paths_model->assistantLessonsForPath($path['id']),
            'databaseQuizQuestions' => $this->paths_model->questionsForPath($path['id']),
        ];

        respond(
            ['reply' => GeminiPathCoach::chat($context, $normalizedConversation, $message)],
            'Gemini replied.'
        );
    }

    public function path_progress()
    {
        $user = require_role($this->users, ['student']);
        $b = json_body();
        $result = $this->paths->completeItem((string)($b['itemId'] ?? ''), $user['id']);
        if (isset($result['error'])) fail_api($result['error'], $result['status']);

        $item = $this->paths_model->itemById((string)$b['itemId']);
        if ($item) {
            $this->paths_model->recordProgress($user['id'], $item['lesson_id'], max(0, min(86400, (int)($b['timeSpentSec'] ?? 0))));
        }
        $xp = $this->gamify->addXp($user['id'], 40);
        $streak = $this->gamify->touchStreak($user['id']);
        $badges = array_filter([
            $this->gamify->award($user['id'], 'first-steps'),
            !empty($result['completed']) ? $this->gamify->award($user['id'], 'constellation') : null,
        ]);
        respond([
            'completed' => $result['completed'],
            'certificateCode' => $result['certificate_code'] ?? null,
            'certificateEmailSent' => $result['certificate_email_sent'] ?? false,
            'xp' => $xp['xp'], 'level' => $xp['level'], 'streak' => $streak, 'badges' => array_values($badges),
        ], $result['completed']
            ? (!empty($result['certificate_email_sent'])
                ? 'Path complete — certificate issued and emailed to your registered address.'
                : 'Path complete — certificate issued, but email delivery failed. Resend it from Badges & certificates.')
            : 'Lesson complete. Next item unlocked.');
    }

    private function pathPayload(array $path): array
    {
        return [
            'path' => $path,
            'items' => array_map(static function (array $item): array {
                return [
                    'itemId' => $item['item_id'],
                    'lessonId' => $item['lesson_id'],
                    'title' => $item['title'],
                    'moduleTitle' => $item['module_title'],
                    'estimatedMinutes' => (int)$item['estimated_minutes'],
                    'status' => $item['status'],
                    'position' => (int)$item['position'],
                ];
            }, $this->paths_model->itemsOf($path['id'])),
            'course' => $this->content->courseById($path['course_id']),
            'questions' => $this->paths_model->questionsForPath($path['id']),
        ];
    }
}
