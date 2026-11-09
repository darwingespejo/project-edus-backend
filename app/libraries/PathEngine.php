<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Builds and advances personalized learning paths. */
class PathEngine
{
    private $paths;
    private $content;
    private $gamify;
    private $users;

    public function __construct(array $dependencies)
    {
        [$this->paths, $this->content, $this->gamify, $this->users] = $dependencies;
    }

    public function generate($userId, $regenerate): array
    {
        $mastery = $this->paths->latestMastery($userId);
        $existing = $this->paths->activeFor($userId);

        if ($existing && !$regenerate) {
            return ['path_id' => $existing['id'], 'created' => false];
        }

        $course = $existing
            ? $this->content->courseById($existing['course_id'])
            : $this->content->courseBySlug('js-foundations');
        if (!$course) {
            fail_api('No course available to build a path from.', 500);
        }

        $courseId = $course['id'];
        $pathId = $existing['id'] ?? uuid4();
        $doneIds = $existing
            ? array_flip($this->paths->doneLessonIds($pathId))
            : [];

        if ($existing) {
            $this->paths->clearOpenItems($pathId);
        } else {
            $this->paths->createPath($pathId, $userId, $courseId);
        }

        $rows = $this->paths->lessonsWithSkills($courseId);
        $lessons = $this->groupLessons($rows);
        $open = $this->orderOpenLessons($lessons, $doneIds, $mastery);

        if (!$open) {
            $this->paths->closePath($pathId);
            return ['path_id' => $pathId, 'created' => false];
        }

        $velocity = max(0.1, $this->paths->recentCompletionCount($userId) / 14);
        $unlockCount = max(1, min(4, (int)round($velocity * 4)));
        foreach ($open as $index => $lesson) {
            $this->paths->insertItem(
                $pathId,
                $lesson['id'],
                count($doneIds) + $index,
                $index < $unlockCount ? 'current' : 'locked'
            );
        }

        $this->paths->updatePathMeta(
            $pathId,
            number_format($velocity, 2, '.', ''),
            $this->rationale($lessons, $mastery, $open, $velocity, $unlockCount)
        );

        if (!$existing) {
            $this->gamify->notify(
                $userId,
                'path',
                'Your learning path is ready',
                count($open) . ' lessons sequenced around your placement gaps.'
            );
        }

        return ['path_id' => $pathId, 'created' => !$existing];
    }

    private function groupLessons(array $rows): array
    {
        $lessons = [];
        foreach ($rows as $row) {
            $id = $row['lesson_id'];
            $lessons[$id] = $lessons[$id] ?? [
                'title' => $row['title'],
                'position' => (int)$row['lesson_position'],
                'modulePosition' => (int)$row['module_position'],
                'skills' => [],
            ];
            if ($row['skill_id']) {
                $lessons[$id]['skills'][] = [
                    'id' => $row['skill_id'],
                    'name' => $row['skill_name'],
                ];
            }
        }

        return $lessons;
    }

    private function orderOpenLessons(array $lessons, array $doneIds, array $mastery): array
    {
        $open = [];
        foreach ($lessons as $id => $lesson) {
            if (isset($doneIds[$id])) {
                continue;
            }

            $skills = $lesson['skills'];
            $gap = !$skills
                ? 50.0
                : array_sum(array_map(
                    static fn($skill) => 100 - ($mastery[$skill['id']] ?? 0),
                    $skills
                )) / count($skills);

            $open[] = ['id' => $id] + $lesson + ['gap' => $gap];
        }

        usort($open, static fn($left, $right) =>
            $right['gap'] <=> $left['gap']
            ?: $left['modulePosition'] <=> $right['modulePosition']
            ?: $left['position'] <=> $right['position']
        );

        return $open;
    }

    private function rationale(array $lessons, array $mastery, array $open, float $velocity, int $unlockCount): string
    {
        $skillNames = [];
        foreach ($lessons as $lesson) {
            foreach ($lesson['skills'] as $skill) {
                $skillNames[$skill['id']] = $skill['name'];
            }
        }

        $weakestSkills = $mastery;
        asort($weakestSkills);
        $weakNames = [];
        foreach (array_slice($weakestSkills, 0, 4, true) as $id => $score) {
            if ($score < 60) {
                $weakNames[] = ($skillNames[$id] ?? 'Unrated skill') . ' at ' . round($score) . '%';
            }
        }

        $firstTitles = array_map(
            static fn($lesson) => '"' . $lesson['title'] . '"',
            array_slice($open, 0, 3)
        );
        $pace = number_format($velocity, 1);
        $unlockText = "$unlockCount item" . ($unlockCount > 1 ? 's are' : ' is') . ' open at a time.';

        if (!$mastery) {
            return "No placement scores yet, so the path follows the course order. At your recent pace of $pace lessons/day, $unlockText Finish the placement and it re-sequences around your gaps.";
        }

        $weakText = $weakNames ? implode(', ', $weakNames) : 'none below 60%';
        return 'Your weakest measured skills: ' . $weakText . '. The path re-sequences the course so '
            . implode(', ', $firstTitles)
            . " come first and keeps anything you already finished. At your recent pace of $pace lessons/day, $unlockText";
    }

    /** Complete an item, unlock the next one, and issue a certificate when finished. */
    public function completeItem($itemId, $userId): array
    {
        $item = $this->paths->itemById($itemId);
        if (!$item) {
            return ['error' => 'Path item not found.', 'status' => 404];
        }

        $path = $this->paths->byId($item['path_id']);
        if (!$path || $path['user_id'] !== $userId) {
            return ['error' => 'Path item not found.', 'status' => 404];
        }
        if ($item['status'] === 'done') {
            return ['error' => 'Already completed.', 'status' => 409];
        }
        if ($item['status'] === 'locked') {
            return ['error' => 'This lesson unlocks after you finish the open ones.', 'status' => 409];
        }

        $this->paths->markItemDone($item['id']);
        $remaining = $this->paths->openItems($path['id']);

        if (!$remaining) {
            return $this->completePath($path, $userId);
        }

        $slots = max(1, min(4, (int)round((float)$path['velocity'] * 4)));
        $openCount = count(array_filter(
            $remaining,
            static fn($row) => $row['status'] === 'current'
        ));
        if ($openCount < $slots) {
            $lockedItems = array_filter(
                $remaining,
                static fn($row) => $row['status'] === 'locked'
            );
            $next = current($lockedItems);
            if ($next) {
                $this->paths->promoteItem($next['id']);
            }
        }

        return ['ok' => true, 'completed' => false];
    }

    private function completePath(array $path, $userId): array
    {
        $this->paths->closePath($path['id']);
        $existing = $this->paths->certificateForCourse($userId, $path['course_id']);
        if ($existing) {
            return ['ok' => true, 'completed' => true, 'certificate_code' => $existing, 'certificate_email_sent' => null];
        }
        $code = $this->paths->issueCertificate($userId, $path['course_id']);
        $this->gamify->notify(
            $userId,
            'certificate',
            'Certificate issued',
            "Course complete. Certificate $code is live at /verify/$code."
        );
        $user = $this->users->byId($userId);
        $course = $this->content->courseById($path['course_id']);
        $emailSent = $user && $course && MailService::sendCertificate([
            'email' => $user['email'],
            'holder' => $user['name'],
            'course' => $course['title'],
            'level' => $course['level'],
            'code' => $code,
            'issued_at' => date('Y-m-d H:i:s'),
        ]);
        if (!$emailSent) error_log("Certificate email delivery failed for issued certificate $code.");

        return ['ok' => true, 'completed' => true, 'certificate_code' => $code, 'certificate_email_sent' => (bool)$emailSent];
    }
}
