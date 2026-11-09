<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** XP/levels, streaks, badges, in-app notifications. All writes go through prepared statements. */
class Gamify
{
    private $model;

    public function __construct($model)
    {
        $this->model = $model;
    }

    public function addXp($userId, $amount)
    {
        $this->model->incrementXp($userId, $amount);
        $xp = $this->model->xpFor($userId);
        $level = (int)floor(sqrt($xp / 100)) + 1;
        $this->model->setLevel($userId, $level);
        return ['xp' => $xp, 'level' => $level];
    }

    public function notify($userId, $type, $title, $body)
    {
        $this->model->insertNotification($userId, $type, $title, $body);
        $user = $this->model->notificationRecipient($userId);
        if (!$user) throw new RuntimeException("Cannot deliver notification: user $userId was not found.");
        if ($user['email_verified'] && !MailService::send(
            $user['email'],
            $title,
            '<div style="font-family:system-ui;color:#12142b"><h2>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') .
            '</h2><p>' . nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')) . '</p><p>— EduSphere</p></div>'
        )) {
            error_log("Notification email delivery failed for user $userId.");
        }
    }

    public function award($userId, $code): ?array
    {
        $badge = $this->model->badgeByCode($code);
        if (!$badge) return null;
        if ($this->model->ownsBadge($userId, $badge['id'])) return null;
        $this->model->insertBadge($userId, $badge['id']);
        if ((int)$badge['xp_reward'] > 0) $this->addXp($userId, (int)$badge['xp_reward']);
        $this->notify($userId, 'badge', "Badge earned: {$badge['name']}", $badge['description']);
        return $badge;
    }

    public function touchStreak($userId)
    {
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $row = $this->model->streakFor($userId);
        if (!$row) {
            $this->model->insertStreak($userId, $today);
            return ['current' => 1, 'longest' => 1];
        }
        if ($row['last_activity_date'] === $today) return ['current' => (int)$row['current'], 'longest' => (int)$row['longest']];
        $current = $row['last_activity_date'] === $yesterday ? (int)$row['current'] + 1 : 1;
        $longest = max($current, (int)$row['longest']);
        $this->model->updateStreak($userId, $current, $longest, $today);
        if ($current >= 3) $this->award($userId, 'streak-3');
        if ($current >= 7) $this->award($userId, 'streak-7');
        return ['current' => $current, 'longest' => $longest];
    }
}
