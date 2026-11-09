<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ClassroomController extends MY_Controller
{
    private $users;
    private $courses;
    private $classroom;

    public function __construct()
    {
        parent::__construct();
        $this->users = $this->call->model('User_model');
        $this->courses = $this->call->model('Course_model');
        $this->classroom = $this->call->model('Classroom_model');
    }

    public function index()
    {
        $user = require_role($this->users, ['student', 'instructor']);
        respond($this->classroom->overview($user));
    }

    public function create()
    {
        $instructor = require_role($this->users, ['instructor']);
        $body = json_body();
        $courseId = (string)($body['courseId'] ?? '');
        $title = trim((string)($body['title'] ?? ''));
        $prompt = trim((string)($body['prompt'] ?? ''));
        $instructions = trim((string)($body['instructions'] ?? ''));
        $starterCode = (string)($body['starterCode'] ?? '');
        $published = $body['published'] ?? true;
        $dueAt = $body['dueAt'] ?? null;
        $testCases = $body['testCases'] ?? null;
        if (!preg_match('/^[0-9a-f-]{36}$/i', $courseId)) fail_api('Choose a valid course.', 422);
        if (!$this->courses->courseOwner($courseId, $instructor['id'])) fail_api('Choose a course you teach.', 403);
        if (strlen($title) < 3 || strlen($title) > 160) fail_api('Assignment title must be between 3 and 160 characters.', 422);
        if (strlen($prompt) < 10 || strlen($prompt) > 10000) fail_api('Problem prompt must be between 10 and 10,000 characters.', 422);
        if (strlen($instructions) > 10000) fail_api('Instructions cannot exceed 10,000 characters.', 422);
        if (strlen($starterCode) > 50000) fail_api('Starter code cannot exceed 50,000 characters.', 422);
        if (!is_bool($published)) fail_api('Published must be true or false.', 422);
        if ($dueAt !== null) {
            if (!is_string($dueAt) || strtotime($dueAt) === false) fail_api('Due date must be a valid date and time.', 422);
            $dueAt = date('Y-m-d H:i:s', strtotime($dueAt));
        }
        if (!is_array($testCases) || count($testCases) < 1 || count($testCases) > 30) fail_api('Add between 1 and 30 test cases.', 422);
        $visibleCount = 0;
        $validatedCases = [];
        foreach ($testCases as $testCase) {
            if (!is_array($testCase) || !array_key_exists('input', $testCase) || !array_key_exists('expected', $testCase)) {
                fail_api('Each test case must include input and expected output.', 422);
            }
            $hidden = $testCase['hidden'] ?? false;
            if (!is_bool($hidden)) fail_api('Test case hidden must be true or false.', 422);
            if (!$hidden) $visibleCount++;
            $validatedCases[] = ['input' => $testCase['input'], 'expected' => $testCase['expected'], 'hidden' => $hidden];
        }
        if (!$visibleCount) fail_api('Add at least one visible test case.', 422);

        $assignment = $this->classroom->createAssignment([
            'courseId' => $courseId,
            'title' => $title,
            'prompt' => $prompt,
            'instructions' => $instructions,
            'starterCode' => $starterCode,
            'dueAt' => $dueAt,
            'published' => $published,
            'testCases' => $validatedCases,
        ], $instructor['id']);
        respond($assignment, $published ? 'Assignment published.' : 'Assignment saved as a draft.', 201);
    }

    public function detail($id)
    {
        $student = require_role($this->users, ['student']);
        $assignment = $this->classroom->assignmentForStudent($id, $student['id']);
        if (!$assignment) fail_api('Assignment not found or not available to your account.', 404);
        $assignment['attempts'] = $this->classroom->studentAttempts($id, $student['id']);
        respond($assignment);
    }

    public function update($id)
    {
        $instructor = require_role($this->users, ['instructor']);
        if (!$this->classroom->assignmentOwner($id, $instructor['id'])) fail_api('Assignment not found.', 404);
        $body = json_body();
        $updates = [];
        if (array_key_exists('published', $body)) {
            if (!is_bool($body['published'])) fail_api('Published must be true or false.', 422);
            $updates['published'] = $body['published'];
        }
        if (array_key_exists('dueAt', $body)) {
            if ($body['dueAt'] !== null && (!is_string($body['dueAt']) || strtotime($body['dueAt']) === false)) {
                fail_api('Due date must be a valid date and time.', 422);
            }
            $updates['dueAt'] = $body['dueAt'] === null ? null : date('Y-m-d H:i:s', strtotime($body['dueAt']));
        }
        if (!$updates) fail_api('Change a publish setting or due date.', 422);
        $this->classroom->updateAssignment($id, $updates);
        respond(['id' => $id], ($updates['published'] ?? null) === false ? 'Assignment unpublished.' : 'Assignment updated.');
    }

    public function attempts($id)
    {
        $user = auth_user($this->users);
        if ($user['role'] === 'instructor') {
            if (!$this->classroom->assignmentOwner($id, $user['id'])) fail_api('Assignment not found in a course you teach.', 404);
        } elseif ($user['role'] === 'admin') {
            if (!$this->classroom->assignmentExists($id)) fail_api('Assignment not found.', 404);
        } else {
            fail_api('Instructor access required.', 403);
        }
        respond($this->classroom->instructorAttempts($id));
    }
}
