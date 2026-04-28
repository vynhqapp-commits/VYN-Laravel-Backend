<?php

namespace App\Console\Commands;

use App\Models\ApprovalRequest;
use Illuminate\Console\Command;

class ExpireApprovalRequests extends Command
{
    protected $signature = 'approval-requests:expire';
    protected $description = 'Expire stale pending approval requests';

    public function handle(): int
    {
        $count = ApprovalRequest::withoutGlobalScopes()
            ->where('status', ApprovalRequest::STATUS_PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => ApprovalRequest::STATUS_EXPIRED]);

        $this->info("Expired {$count} approval requests.");

        return self::SUCCESS;
    }
}

