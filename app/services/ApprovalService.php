<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

final class ApprovalService
{
    /** Maker-checker: the person approving must not be the one who requested (unless explicitly allowed). */
    public static function assertMakerChecker(?int $requestedBy, int $approverId): void
    {
        if ($requestedBy === $approverId && setting('allow_self_approval', '0') !== '1') {
            throw new BankingException('You cannot approve a request you created. Another authorized staff member must approve it.');
        }
    }

    public static function record(string $subjectType, int $subjectId, string $decision, int $by, ?string $note): void
    {
        Db::insert('transaction_approvals', [
            'subject_type' => $subjectType,
            'subject_id'   => $subjectId,
            'decision'     => $decision,
            'decided_by'   => $by,
            'note'         => $note ?: null,
        ]);
    }
}
