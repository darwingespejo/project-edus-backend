<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Shared API plumbing for JSON envelopes, request bodies, auth, RBAC,
 * rate limiting, and review-safety aliases.
 * Registered via app/config/autoload.php: $autoload['helpers'] = array('api');
 */

function respond($data, $message = 'OK', $status = 200)
{
    lava_instance()->response->send_json([
        'success' => $status < 400,
        'message' => $message,
        'data' => $data,
        'errors' => null,
    ], $status);
    exit;
}

function fail_api($message, $status = 400, $errors = null)
{
    lava_instance()->response->send_json([
        'success' => false,
        'message' => $message,
        'data' => null,
        'errors' => $errors,
    ], $status);
    exit;
}

function json_body()
{
    $request = lava_instance()->request;
    $raw = $request->raw_body();
    $data = $request->json();
    if ($raw === '' || !is_array($data)) fail_api('Request body must be valid JSON.', 400);
    return $data;
}

function requires($body, array $fields)
{
    $errors = [];
    foreach ($fields as $f => $rule) {
        $v = trim((string)($body[$f] ?? ''));
        if ($v === '') { $errors[$f] = $rule; return fail_api($rule, 422, $errors ?: [$f => $rule]); }
        if (str_starts_with($f, 'email') && !filter_var($v, FILTER_VALIDATE_EMAIL)) fail_api('That email does not look valid.', 422, ['email' => 'Invalid email.']);
    }
    return $body;
}

// ---- JWT (HS256, zero-dependency) ----

function jwt_sign(array $payload, $ttl)
{
    $secret = jwt_secret();
    $head = rtrim(strtr(base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
    $payload = array_merge(['iat' => time(), 'exp' => time() + $ttl], $payload);
    $body = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', "$head.$body", $secret, true)), '+/', '-_'), '=');
    return "$head.$body.$sig";
}

function jwt_verify($token)
{
    $secret = jwt_secret();
    $parts = explode('.', (string)$token);
    if (count($parts) !== 3) return null;
    [$h, $b, $s] = $parts;
    $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "$h.$b", $secret, true)), '+/', '-_'), '=');
    if (!hash_equals($expected, $s)) return null;
    $payload = json_decode(base64_decode(strtr($b, '-_', '+/')), true);
    if (!$payload || ($payload['exp'] ?? 0) < time()) return null;
    return $payload;
}

// Bearer → user row (fresh from DB, so deactivation applies immediately). Exits 401.
function auth_user($users)
{
    $token = lava_instance()->request->bearer_token();
    $payload = $token ? jwt_verify($token) : null;
    if (!$payload || ($payload['typ'] ?? '') !== 'access') fail_api('Sign in to continue.', 401);
    $user = $users->byId($payload['sub']);
    if (!$user || !$user['active']) fail_api('Account unavailable.', 401);
    if (empty($user['email_verified'])) fail_api('Verify your email before signing in.', 403);
    return $user;
}

function jwt_secret(): string
{
    $secret = getenv('JWT_SECRET');
    if (!is_string($secret) || strlen($secret) < 32) {
        fail_api('JWT_SECRET must be configured with at least 32 characters.', 500);
    }

    return $secret;
}

function require_role($users, array $roles)
{
    $user = auth_user($users);
    if (!in_array($user['role'], $roles, true)) fail_api("You don't have access to this.", 403);
    return $user;
}

function public_user(array $u)
{
    return [
        'id' => $u['id'],
        'name' => $u['name'],
        'email' => $u['email'],
        'role' => $u['role'],
        'xp' => (int)$u['xp'],
        'level' => (int)$u['level'],
        'avatar' => $u['avatar'] ?? null,
        'bio' => $u['bio'] ?? 'Building momentum, one lesson at a time.',
    ];
}

// Sliding-window limiter backed by the DB so it survives restarts (audit_logs table).
function rate_limited($authModel, $key, $max, $windowSec)
{
    return $authModel->rateLimited($key, $max, $windowSec);
}

function uuid4()
{
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

// Deterministic pseudonym for peer-review payloads — never expose user ids/names.
function alias_for($id)
{
    $h = 2166136261;
    for ($i = 0; $i < strlen($id); $i++) {
        $h ^= ord($id[$i]);
        $h = ($h * 16777619) & 0xffffffff;
    }
    return '#' . strtoupper(substr(str_pad(base_convert((string)$h, 10, 36), 7, '0', STR_PAD_LEFT), 0, 5));
}
