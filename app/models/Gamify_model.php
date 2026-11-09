<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Persistence for XP, badges, notifications, and streaks. */
class Gamify_model extends Model
{
    protected $table = 'users';
    protected $primary_key = 'id';
    private $db;

    public function __construct()
    {
        parent::__construct();
        $this->db = load_class('database', 'database')::instance(NULL);
    }

    public function incrementXp($userId, $amount): void
    {
        $this->db->table('users')->where('id', $userId)->increment('xp', $amount);
    }

    public function xpFor($userId): int
    {
        return (int)$this->db->table('users')->select('xp')->where('id', $userId)->get()['xp'];
    }

    public function setLevel($userId, $level): void
    {
        $this->db->table('users')->where('id', $userId)->update(['level' => $level]);
    }

    public function insertNotification($userId, $type, $title, $body): void
    {
        $this->db->raw(
            'INSERT INTO notifications (id, user_id, type, title, body, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [uuid4(), $userId, $type, $title, $body]
        );
    }

    public function notificationRecipient($userId)
    {
        return $this->db->raw(
            'SELECT email, email_verified FROM users WHERE id = ? LIMIT 1',
            [$userId]
        )->fetch(PDO::FETCH_ASSOC);
    }

    public function badgeByCode($code)
    {
        return $this->db->table('badges')->where('code', $code)->limit(1)->get();
    }

    public function ownsBadge($userId, $badgeId): bool
    {
        return (bool)$this->db->table('user_badges')
            ->select('id')
            ->where(['user_id' => $userId, 'badge_id' => $badgeId])
            ->limit(1)
            ->get();
    }

    public function insertBadge($userId, $badgeId): void
    {
        $this->db->raw(
            'INSERT INTO user_badges (id, user_id, badge_id, awarded_at) VALUES (?, ?, ?, NOW())',
            [uuid4(), $userId, $badgeId]
        );
    }

    public function streakFor($userId)
    {
        return $this->db->table('streaks')->where('user_id', $userId)->limit(1)->get();
    }

    public function insertStreak($userId, $today): void
    {
        $this->db->raw(
            'INSERT INTO streaks (id, user_id, current, longest, last_activity_date) VALUES (?, ?, 1, 1, ?)',
            [uuid4(), $userId, $today]
        );
    }

    public function updateStreak($userId, $current, $longest, $today): void
    {
        $this->db->table('streaks')
            ->where('user_id', $userId)
            ->update(['current' => $current, 'longest' => $longest, 'last_activity_date' => $today]);
    }
}
