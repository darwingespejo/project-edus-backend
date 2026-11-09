<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
class MetaController extends MY_Controller
{
    private $users;
    private $meta;
    private $auth;

    public function __construct()
    {
        parent::__construct();
        $this->call->library('Clients');
        $this->users = $this->call->model('User_model');
        $this->meta = $this->call->model('Meta_model');
        $this->auth = $this->call->model('Auth_model');
    }

    // ---------- analytics ----------

    public function analytics_student()
    {
        $user = require_role($this->users, ['student']);
        $data = $this->meta->studentAnalytics($user);
        $streak = $this->users->streakOf($user['id']);
        respond($data + [
            'streak' => ['current' => (int)($streak['current'] ?? 0), 'longest' => (int)($streak['longest'] ?? 0)],
            'velocity' => $this->meta->activePathVelocity($user['id']),
            'activity' => $this->meta->activitySeries($user['id']),
        ]);
    }

    public function analytics_instructor()
    {
        require_role($this->users, ['instructor', 'admin']);
        respond($this->meta->instructorAggregate());
    }

    public function leaderboard()
    {
        auth_user($this->users);
        respond(['rows' => $this->users->leaderboard()]);
    }

    // ---------- credentials ----------

    public function badges()
    {
        $user = auth_user($this->users);
        $earned = $this->meta->earnedBadges($user['id']);
        $owned = array_column($earned, 'code');
        respond([
            'earned' => $earned,
            'upcoming' => array_values(array_filter($this->meta->allBadges(), fn($b) => !in_array($b['code'], $owned, true))),
        ]);
    }

    public function certificates()
    {
        $user = auth_user($this->users);
        respond($this->meta->certificatesOf($user['id']));
    }

    /** Public — no auth. This is what the link on a certificate points to. */
    public function verify($code)
    {
        $row = $this->meta->credentialByCode(strtoupper(trim((string)$code)));
        if (!$row) fail_api('No credential matches this code. Check the letters and try again.', 404);
        respond($row + ['valid' => true], 'Credential verified.');
    }

    public function email_certificate($code)
    {
        $user = auth_user($this->users);
        if (rate_limited($this->auth, "certificate-email:{$user['id']}", 3, 3600)) {
            fail_api('You can email certificates up to 3 times per hour. Try again later.', 429);
        }
        $certificate = $this->meta->certificateForUser(strtoupper(trim((string)$code)), $user['id']);
        if (!$certificate) fail_api('Certificate not found for this account.', 404);
        if (!MailService::sendCertificate($certificate)) {
            fail_api('Certificate email could not be sent. Check the email service configuration and try again.', 502);
        }
        respond(['sent' => true], 'Certificate emailed to your registered address.');
    }

    // ---------- sessions / notifications ----------

    public function sessions()
    {
        auth_user($this->users);
        respond($this->meta->upcomingSessions());
    }

    /** Join links use Jitsi open rooms — no account or key needed. */
    public function session_create()
    {
        $user = require_role($this->users, ['instructor', 'admin']);
        $b = json_body();
        $title = trim((string)($b['title'] ?? ''));
        $startsAt = (string)($b['startsAt'] ?? '');
        if (strlen($title) < 4 || empty($b['courseId']) || !$startsAt) fail_api('Missing course, title, or start time.', 422);
        if (!$this->meta->courseExists($b['courseId'])) fail_api('Course not found.', 404);

        $room = 'edusphere-' . bin2hex(random_bytes(4));
        $id = uuid4();
        $this->meta->insertSession($id, $b['courseId'], $user['id'], $title,
            date('Y-m-d H:i:s', strtotime($startsAt)), max(15, min(240, (int)($b['durationMin'] ?? 60))), $room);
        respond(['id' => $id, 'roomCode' => $room], 'Session scheduled.', 201);
    }

    public function notifications()
    {
        $user = auth_user($this->users);
        $rows = $this->meta->notificationsOf($user['id']);
        respond(['rows' => $rows, 'unread' => count(array_filter($rows, fn($r) => !$r['read_at']))]);
    }

    public function notifications_read()
    {
        $user = auth_user($this->users);
        $this->meta->markAllRead($user['id']);
        respond(null, 'Marked as read.');
    }

    // ---------- commerce / storage / admin ----------

    public function checkout()
    {
        $user = auth_user($this->users);
        $plan = json_body()['plan'] ?? '';
        if (!in_array($plan, ['pro', 'team'], true)) fail_api('Unknown plan.', 422);
        respond(PayMongoService::checkout($this->meta, $user['id'], $plan, $this->frontendUrl()),
            'Redirecting to PayMongo Checkout.');
    }

    public function subscription()
    {
        $user = auth_user($this->users);
        respond([
            'subscription' => $this->meta->subscriptionOf($user['id']),
            'assistantAvailable' => $this->meta->hasActiveAssistantSubscription($user['id']),
        ]);
    }

    public function verify_payment()
    {
        $user = auth_user($this->users);
        $pending = $this->meta->latestPayMongoPayment($user['id']);
        if (!$pending) {
            respond(['verified' => false], 'No PayMongo checkout is available to verify.');
        }

        $session = PayMongoService::retrieveCheckoutSession($pending['provider_reference']);
        if (($session['id'] ?? '') !== $pending['provider_reference']) {
            error_log('PayMongo checkout verification returned a different session.');
            fail_api('PayMongo returned a checkout that did not match this account.', 502);
        }

        $attributes = $session['attributes'] ?? [];
        $metadata = $attributes['metadata'] ?? [];
        if (($pending['user_id'] ?? '') !== ($metadata['userId'] ?? '')
            || ($pending['plan'] ?? '') !== ($metadata['plan'] ?? '')) {
            error_log('PayMongo checkout verification metadata did not match its pending payment record.');
            fail_api('PayMongo payment details did not match this account. The plan was not activated.', 500);
        }

        $paid = false;
        foreach (($attributes['payments'] ?? []) as $payment) {
            $paymentAttributes = $payment['attributes'] ?? [];
            if (($paymentAttributes['status'] ?? '') === 'paid'
                && strtolower((string)($paymentAttributes['currency'] ?? '')) === 'php'
                && (int)($paymentAttributes['amount'] ?? 0) === (int)$pending['amount_cents']) {
                $paid = true;
                break;
            }
        }
        if (!$paid) {
            respond(['verified' => false], 'PayMongo has not confirmed a matching paid transaction yet.');
        }

        $sessionId = $pending['provider_reference'];
        $this->meta->markPayMongoPaymentPaid($sessionId);
        $this->meta->activatePaidPlan($pending['user_id'], $pending['plan'], $sessionId);
        respond(['verified' => true], 'PayMongo payment verified and plan activated.');
    }

    public function paymongo_webhook()
    {
        $secret = trim((string)getenv('PAYMONGO_WEBHOOK_SECRET'));
        if ($secret === '') fail_api('PayMongo webhook verification is not configured.', 503);
        $raw = $this->request->raw_body();
        $parts = [];
        foreach (preg_split('/[,;]/', (string)($this->request->header('Paymongo-Signature') ?? '')) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) === 2) $parts[$pair[0]] = $pair[1];
        }
        $timestamp = $parts['t'] ?? '';
        $expectedSignature = hash_hmac('sha256', "$timestamp.$raw", $secret);
        $validSignature = false;
        foreach (['te', 'li'] as $mode) {
            if (!empty($parts[$mode]) && hash_equals($expectedSignature, $parts[$mode])) {
                $validSignature = true;
            }
        }
        if ($timestamp === '' || !$validSignature) {
            fail_api('Invalid PayMongo webhook signature.', 400);
        }
        $event = json_decode($raw, true);
        if (!is_array($event)) fail_api('Malformed PayMongo webhook payload.', 400);
        $eventData = $event['data'] ?? null;
        if (!is_array($eventData)) fail_api('Malformed PayMongo webhook event.', 400);
        $eventType = $eventData['type'] ?? '';
        $payload = $eventData['data'] ?? null;
        if (!is_array($payload)) fail_api('Malformed PayMongo checkout session.', 400);
        $attributes = $payload['attributes'] ?? [];
        if (!is_array($attributes)) fail_api('Malformed PayMongo checkout session.', 400);
        if ($eventType === 'checkout_session.payment.paid') {
            $sessionId = (string)($payload['id'] ?? '');
            $metadata = $attributes['metadata'] ?? [];
            $pending = $sessionId !== '' ? $this->meta->pendingPayMongoPayment($sessionId) : null;
            if (!$pending) {
                error_log('PayMongo paid checkout has no matching pending payment record.');
                fail_api('PayMongo payment could not be matched to a pending checkout. The plan was not activated.', 500);
            }
            if (($pending['user_id'] ?? '') !== ($metadata['userId'] ?? '')
                || ($pending['plan'] ?? '') !== ($metadata['plan'] ?? '')) {
                error_log('PayMongo paid checkout metadata did not match its pending payment record.');
                fail_api('PayMongo payment details did not match the pending checkout. The plan was not activated.', 500);
            }
            $paid = false;
            foreach (($attributes['payments'] ?? []) as $payment) {
                $paymentAttributes = $payment['attributes'] ?? [];
                if (($paymentAttributes['status'] ?? '') === 'paid'
                    && strtolower((string)($paymentAttributes['currency'] ?? '')) === 'php'
                    && (int)($paymentAttributes['amount'] ?? 0) === (int)$pending['amount_cents']) {
                    $paid = true;
                    break;
                }
            }
            if (!$paid) {
                error_log('PayMongo paid checkout did not contain a matching paid PHP payment.');
                fail_api('PayMongo reported a payment that did not match the pending checkout amount. The plan was not activated.', 500);
            }
            $this->meta->markPayMongoPaymentPaid($sessionId);
            $this->meta->activatePaidPlan(
                $pending['user_id'],
                $pending['plan'],
                $sessionId
            );
        }
        respond(['received' => true], 'PayMongo webhook received.');
    }

    private function frontendUrl(): string
    {
        $appUrl = trim((string)getenv('APP_URL'));
        if ($appUrl !== '') return rtrim($appUrl, '/');

        $origin = trim((string)($this->request->header('Origin') ?? ''));
        $configuredOrigins = array_filter(array_map('trim', explode(',', (string)getenv('ALLOWED_ORIGINS'))));
        $localOrigins = ['http://localhost:5173', 'http://127.0.0.1:5173', 'http://localhost:3000'];
        if ($origin !== '' && in_array($origin, array_merge($configuredOrigins, $localOrigins), true)) {
            return rtrim($origin, '/');
        }
        return 'http://localhost:5173';
    }

    public function presign()
    {
        $user = auth_user($this->users);
        $b = json_body();
        $type = (string)($b['contentType'] ?? '');
        $allowed = ['image/png', 'image/jpeg', 'image/webp', 'application/pdf', 'text/plain', 'text/markdown'];
        if (!in_array($type, $allowed, true)) respond(['accepted' => false, 'reason' => "$type isn't accepted. Use PNG, JPEG, WebP, PDF, or text."], 'Type rejected.');
        if ((int)($b['bytes'] ?? 0) > 25 * 1024 * 1024) fail_api('Files are capped at 25 MB.', 422);

        $key = 'u/' . substr($user['id'], 0, 8) . '/' . time() . '-' . preg_replace('/[^a-z0-9.\-_]/i', '_', (string)($b['name'] ?? 'file'));
        $this->meta->insertFile($user['id'], $key, (int)($b['bytes'] ?? 0), $type);
        $url = StorageService::presign($key, $type);
        respond(['accepted' => true, 'key' => $key, 'url' => $url, 'demo' => $url === null],
            $url === null ? "File registered. Object storage isn't configured in this demo, so nothing was uploaded." : 'Upload URL ready.');
    }

}
