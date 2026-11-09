<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Issues an eligible course credential and delivers it through the account mail service. */
class CourseCertificate
{
    private $courses;
    private $users;
    private $gamify;

    public function __construct(array $dependencies)
    {
        [$this->courses, $this->users, $this->gamify] = $dependencies;
    }

    public function issue($courseId, $userId): array
    {
        $issued = $this->courses->issueCertificateIfEligible($courseId, $userId);
        if (!$issued['created']) return ['code' => $issued['code'] ?? null, 'emailSent' => null];

        $user = $this->users->byId($userId);
        $course = $issued['course'];
        $this->gamify->notify(
            $userId,
            'certificate',
            'Certificate issued',
            "Course complete. Certificate {$issued['code']} is live at /verify/{$issued['code']}."
        );
        $emailSent = $user && MailService::sendCertificate([
            'email' => $user['email'],
            'holder' => $user['name'],
            'course' => $course['title'],
            'level' => $course['level'],
            'code' => $issued['code'],
            'issued_at' => date('Y-m-d H:i:s'),
        ]);
        if (!$emailSent) error_log("Certificate email delivery failed for issued certificate {$issued['code']}.");
        return ['code' => $issued['code'], 'emailSent' => (bool)$emailSent];
    }
}
