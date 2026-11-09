<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Refresh-token and API rate-limit persistence. */
class Auth_model extends Model
{
    protected $table = 'refresh_tokens';
    protected $primary_key = 'id';
    private $db;

    public function __construct()
    {
        parent::__construct();
        $this->db = load_class('database', 'database')::instance(NULL);
    }

    public function storeRefreshToken($id, $userId, $tokenHash): void
    {
        $this->db->raw(
            'INSERT INTO refresh_tokens (id, user_id, token_hash, expires_at, created_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), NOW())',
            [$id, $userId, $tokenHash]
        );
    }

    public function validRefreshToken($userId, $tokenHash)
    {
        return $this->db->raw(
            'SELECT id FROM refresh_tokens
             WHERE user_id = ? AND token_hash = ? AND revoked_at IS NULL AND expires_at > NOW()
             LIMIT 1',
            [$userId, $tokenHash]
        )->fetch(PDO::FETCH_ASSOC);
    }

    public function revokeRefreshTokenById($id): void
    {
        $this->db->raw('UPDATE refresh_tokens SET revoked_at = NOW() WHERE id = ?', [$id]);
    }

    public function revokeRefreshToken($userId, $tokenHash): void
    {
        $this->db->raw(
            'UPDATE refresh_tokens SET revoked_at = NOW() WHERE user_id = ? AND token_hash = ?',
            [$userId, $tokenHash]
        );
    }

    public function rateLimited($key, $maximum, $windowSeconds): bool
    {
        $since = date('Y-m-d H:i:s', time() - $windowSeconds);
        $count = (int)$this->db->raw(
            'SELECT COUNT(*) c FROM audit_logs WHERE action = ? AND created_at > ?',
            ["rl:$key", $since]
        )->fetch(PDO::FETCH_ASSOC)['c'];

        if ($count >= $maximum) {
            return true;
        }

        $this->db->raw(
            'INSERT INTO audit_logs (id, action, created_at) VALUES (?, ?, NOW())',
            [uuid4(), "rl:$key"]
        );

        return false;
    }

    public function databaseAvailable(): bool
    {
        $this->db->raw('SELECT 1');
        return true;
    }
}
