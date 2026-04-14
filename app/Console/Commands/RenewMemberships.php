<?php

namespace App\Console\Commands;

use App\Models\CustomerMembership;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RenewMemberships extends Command
{
    protected $signature = 'memberships:renew';

    protected $description = 'Auto-renew customer memberships that are due today';

    public function handle(): int
    {
        $today = Carbon::today();

        $due = CustomerMembership::query()
            ->withoutGlobalScopes()
            ->where('status', 'active')
            ->where('auto_renew', true)
            ->where('renewal_date', '<=', $today)
            ->get();

        if ($due->isEmpty()) {
            $this->info('No memberships due for renewal.');
            return self::SUCCESS;
        }

        $renewed = 0;
        $failed = 0;

        foreach ($due as $membership) {
            try {
                DB::transaction(function () use ($membership) {
                    $newRenewalDate = Carbon::parse($membership->renewal_date)
                        ->addMonths($membership->interval_months);

                    $membership->update([
                        'renewal_date' => $newRenewalDate,
                        'remaining_services' => $membership->service_credits_per_renewal,
                    ]);
                });
                $renewed++;
            } catch (\Throwable $e) {
                Log::error("Membership renewal failed for ID {$membership->id}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("Renewed: {$renewed}, Failed: {$failed}");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
