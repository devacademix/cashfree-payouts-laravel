<?php

namespace App\Console\Commands;

use App\Models\Payout;
use App\Services\CashfreePayoutService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class TrackPayoutStatus extends Command
{
    protected $signature = 'payouts:track-status';

    protected $description = 'Poll Cashfree transfer status for non-terminal payouts and reconcile DB state.';

    public function handle(CashfreePayoutService $cashfree): int
    {
        $processed = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;

        Payout::query()
            // Only track non-terminal payouts; terminal rows are immutable for idempotency.
            ->whereIn('status', Payout::trackableStatuses())
            ->orderBy('id')
            ->chunkById(100, function ($payouts) use ($cashfree, &$processed, &$updated, &$skipped, &$failed): void {
                foreach ($payouts as $payout) {
                    $processed++;

                    try {
                        DB::transaction(function () use ($cashfree, $payout, &$updated, &$skipped): void {
                            $lockedPayout = Payout::query()
                                ->whereKey($payout->id)
                                ->lockForUpdate()
                                ->first();

                            if (! $lockedPayout || ! $lockedPayout->isTrackable()) {
                                $skipped++;
                                return;
                            }

                            $statusResponse = $cashfree->getTransferStatusV2($lockedPayout->transfer_id);

                            $gatewayStatus = strtoupper((string) ($statusResponse['status']
                                ?? Payout::STATUS_PENDING));

                            $newStatus = match ($gatewayStatus) {
                                Payout::STATUS_SUCCESS => Payout::STATUS_SUCCESS,
                                Payout::STATUS_FAILED => Payout::STATUS_FAILED,
                                Payout::STATUS_REVERSED => Payout::STATUS_REVERSED,
                                default => Payout::STATUS_PENDING,
                            };

                            $failureReason = null;
                            if (in_array($newStatus, [Payout::STATUS_FAILED, Payout::STATUS_REVERSED], true)) {
                                $failureReason = (string) ($statusResponse['reason']
                                    ?? $statusResponse['message']
                                    ?? 'Transfer failed or reversed');
                            }

                            $lockedPayout->update([
                                'status' => $newStatus,
                                'reference_id' => $statusResponse['cf_transfer_id'] ?? $lockedPayout->reference_id,
                                'failure_reason' => $failureReason,
                                // Persist full provider payload for audit/reconciliation.
                                'raw_response' => $statusResponse,
                            ]);

                            $updated++;
                        });
                    } catch (Throwable $e) {
                        $failed++;
                        $this->warn("Payout {$payout->transfer_id} reconciliation failed: {$e->getMessage()}");
                    }
                }
            });

        $this->info("Processed={$processed}, Updated={$updated}, Skipped={$skipped}, Failed={$failed}");

        return self::SUCCESS;
    }
}
