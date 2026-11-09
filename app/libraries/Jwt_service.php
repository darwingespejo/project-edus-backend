<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Access (15 min) + refresh (30 d) token pairs. Refresh tokens are single-use:
 * the server stores only sha256(jti), and revokes it on rotation.
 * Uses jwt_sign/jwt_verify from helpers/api_helper.php and delegates persistence
 * to Auth_model and User_model.
 */
class Jwt_service
{
    private $tokens;
    private $users;

    public function __construct(array $dependencies)
    {
        [$this->tokens, $this->users] = $dependencies;
    }

    public function issue(array $user): array
    {
        $access = jwt_sign(['sub' => $user['id'], 'role' => $user['role'], 'typ' => 'access', 'jti' => bin2hex(random_bytes(8))], 900);
        $jti = uuid4();
        $refresh = jwt_sign(['sub' => $user['id'], 'typ' => 'refresh', 'jti' => $jti], 30 * 86400);
        $this->tokens->storeRefreshToken(uuid4(), $user['id'], hash('sha256', $jti));
        return ['access' => $access, 'refresh' => $refresh];
    }

    /** Rotate: verify + hash-match + revoke old, then issue a fresh pair. Null = reuse/expired. */
    public function rotate(string $token): ?array
    {
        $payload = jwt_verify($token);
        if (!$payload || ($payload['typ'] ?? '') !== 'refresh') return null;
        $row = $this->tokens->validRefreshToken(
            $payload['sub'],
            hash('sha256', $payload['jti'])
        );
        if (!$row) return null;
        $this->tokens->revokeRefreshTokenById($row['id']);
        $user = $this->users->activeVerifiedById($payload['sub']);
        if (!$user) return null;
        return ['user' => $user] + $this->issue($user);
    }

    public function revoke(string $token): void
    {
        $payload = jwt_verify($token);
        if (!$payload || empty($payload['jti'])) return;
        $this->tokens->revokeRefreshToken(
            $payload['sub'],
            hash('sha256', $payload['jti'])
        );
    }
}
