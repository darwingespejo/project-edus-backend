<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Assigns anonymous reviews to the least-loaded eligible students. */
class PeerAssigner
{
    private $reviews;
    private $gamify;

    public function __construct(array $dependencies)
    {
        [$this->reviews, $this->gamify] = $dependencies;
    }

    public function assign($submissionId, $authorId, $limit = 3): int
    {
        $assigned = 0;
        foreach ($this->reviews->reviewerCandidates($authorId, $submissionId, $limit + 2) as $candidate) {
            if ($assigned >= $limit) {
                break;
            }

            if ($this->reviews->tryInsertAssignment($submissionId, $candidate['id'])) {
                $assigned++;
                $this->gamify->notify(
                    $candidate['id'],
                    'review_assigned',
                    'A peer submission is waiting for your review',
                    'It was added to your review queue. The author stays anonymous.'
                );
            }
        }

        if ($assigned > 0) {
            $this->reviews->setSubmissionStatus($submissionId, 'reviewing');
        }

        return $assigned;
    }
}
