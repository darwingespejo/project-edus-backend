<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** users + streaks. All reads/writes through prepared statements. */
class User_model extends Model
{
    protected $table = 'users';
    protected $primary_key = 'id';
    protected $fillable = ['name', 'email', 'password_hash', 'role'];
    private $db;

    public function __construct()
    {
        parent::__construct();
        $this->db = load_class('database', 'database')::instance(NULL);
    }

    public function byEmail($email)
    {
        return $this->db->table('users')->where('email', $email)->limit(1)->get();
    }

    public function byId($id)
    {
        return $this->db->table('users')->where('id', $id)->limit(1)->get();
    }

    public function activeVerifiedById($id)
    {
        return $this->db->table('users')
            ->where(['id' => $id, 'active' => 1, 'email_verified' => 1])
            ->limit(1)
            ->get();
    }

    public function createStudent($id, $name, $email, $hash)
    {
        $this->db->raw(
            "INSERT INTO users (id, name, email, password_hash, role, xp, level, active, email_verified, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'student', 0, 1, 1, 0, NOW(), NOW())",
            [$id, $name, $email, $hash]
        );
    }

    public function createGoogleStudent($id, $name, $email, $hash): void
    {
        $this->db->raw(
            "INSERT INTO users (id, name, email, password_hash, role, xp, level, active, email_verified, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'student', 0, 1, 1, 1, NOW(), NOW())",
            [$id, $name, $email, $hash]
        );
    }

    public function verifyGoogleEmail($id): void
    {
        $this->db->raw(
            'UPDATE users SET email_verified = 1, updated_at = NOW() WHERE id = ?',
            [$id]
        );
    }

    public function deleteById($id): void
    {
        $this->db->raw('DELETE FROM users WHERE id = ?', [$id]);
    }

    public function saveVerificationCode($userId, $codeHash): void
    {
        $this->db->raw(
            'INSERT INTO email_verifications (id, user_id, code_hash, expires_at, created_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), NOW())
             ON DUPLICATE KEY UPDATE
                code_hash = VALUES(code_hash),
                expires_at = VALUES(expires_at),
                created_at = NOW()',
            [uuid4(), $userId, $codeHash]
        );
    }

    public function verificationCodeFor($userId, $codeHash)
    {
        return $this->db->raw(
            'SELECT id FROM email_verifications
             WHERE user_id = ? AND code_hash = ? AND expires_at > NOW()
             LIMIT 1',
            [$userId, $codeHash]
        )->fetch(PDO::FETCH_ASSOC);
    }

    public function verifyEmail($userId, $verificationId): void
    {
        $this->db->raw('UPDATE users SET email_verified = 1 WHERE id = ?', [$userId]);
        $this->db->raw('DELETE FROM email_verifications WHERE id = ?', [$verificationId]);
    }

    public function deleteVerificationCode($userId): void
    {
        $this->db->raw('DELETE FROM email_verifications WHERE user_id = ?', [$userId]);
    }

    public function streakOf($userId)
    {
        return $this->db->table('streaks')->select('current, longest')->where('user_id', $userId)->limit(1)->get();
    }

    public function unreadCount($userId)
    {
        return (int)$this->db->table('notifications')->where('user_id', $userId)->where_null('read_at')->count();
    }

    public function leaderboard()
    {
        return $this->db->raw(
            "SELECT u.id, u.name, u.xp, u.level, COALESCE(s.current, 0) streak FROM users u
             LEFT JOIN streaks s ON s.user_id = u.id WHERE u.role = 'student' ORDER BY u.xp DESC LIMIT 25"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function adminLists()
    {
        return [
            'rows' => $this->db->raw(
                "SELECT id, name, email, role, active = 1 AS active, xp, level, created_at AS createdAt
                 FROM users ORDER BY FIELD(role, 'admin', 'instructor', 'student'), xp DESC"
            )->fetchAll(PDO::FETCH_ASSOC),
            'counts' => $this->db->table('users')
                ->select('role')
                ->select_count('id', 'count')
                ->group_by('role')
                ->order_by('role')
                ->get_all(),
        ];
    }

    public function setActive($id, $active)
    {
        $this->db->raw('UPDATE users SET active = ?, updated_at = NOW() WHERE id = ?', [$active ? 1 : 0, $id]);
    }

    public function updateProfile($id, $bio, $avatar)
    {
        $this->db->table('users')
            ->where('id', $id)
            ->update(['bio' => $bio, 'avatar' => $avatar]);

        return $this->byId($id);
    }
}
