<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\DocumentStatus;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Supplier\Services\LabourCost;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ চালানের লোডিং খরচ — মালিক, ১৮ নভেম্বর ২০২৬ ("ওকে")।
 *
 * ⓘ ঐচ্ছিক: অঙ্ক আর কীভাবে — এখনই নগদে (নিজের টিল থেকে) · হাম্মালি ঠিকাদারের কাছে বাকি (২১১৭) · লাগেনি ([[LabourCost]])।
 * ⛔ নিয়ন্ত্রণ প্যানেলের সুইচ `sales.loading.mandatory` (ডিফল্ট বন্ধ) চালু হলে ভাড়ার মতোই: অঙ্ক বা "লাগেনি" না দিলে চালান
 *   পাকা হয় না ([[assertSet()]])।
 *
 * ── খাতা ────────────────────────────────────────────────────────────────────────
 *  Dr লোডিং (৫২১৮, বিক্রয়ের খরচ) / Cr টিল বা ২১১৭ ঠিকাদারের নামে — চালান পাকা হলে, নিজের দাখিলা-নামে ([[SOURCE]])। ⓘ চালানের
 *  নিজের দাখিলা ভাড়ার, আর ভাড়া পাকা চালানেও পরে লেখা যায় ([[DeliveryChallanService::bookFare()]]) — এক নামে দুই কাজ মিশলে
 *  একটা আরেকটাকে "আগেই বসেছে" বলে থামাত। দিনের খাতা নামের প্রথম অংশ ধরে চালানেই খোলে ([[DayBookService]])।
 *  বাতিল বা সম্পাদনায় উল্টো দাখিলা ([[undo()]])।
 */
final class ChallanLoading
{
    public const SOURCE = 'delivery_challan:loading';

    public const MANDATORY_KEY = 'sales.loading.mandatory';

    public function __construct(
        private readonly LabourCost $labour,
        private readonly PostingEngine $posting,
        private readonly SettingsService $settings,
    ) {}

    public function mandatory(): bool
    {
        return (bool) $this->settings->get(self::MANDATORY_KEY, false);
    }

    /**
     * খসড়ায় — দরজা পাঠালে বাছাই বসানো। ⓘ না পাঠালে চালান যেমন ছিল (পুরনো পর্দা, পুরনো ফোন)।
     *
     * @param  array<string, mixed>  $data
     */
    public function stamp(DeliveryChallan $challan, array $data): void
    {
        if (! array_key_exists('loading_mode', $data)) {
            return;
        }

        $challan->forceFill($this->labour->read($data, 'loading_', LabourCost::LOADING))->save();
    }

    /** ⛔ পাকা করার আগে — সুইচ চালু থাকলে বাছাই পূর্ণ; বন্ধ থাকলেও যা বাছা আছে তা পূর্ণ */
    public function assertSet(DeliveryChallan $challan): void
    {
        if ($this->mandatory() || filled($challan->loading_mode)) {
            $this->labour->assertComplete($challan, 'loading_', 'loading');
        }
    }

    /** চালান পাকা হলে খাতায় — খরচ না থাকলে কিছুই নয় */
    public function post(DeliveryChallan $challan): void
    {
        $lines = $this->labour->lines(
            $challan,
            'loading_',
            (int) StandardChart::accountFor(StandardChart::LOADING)->id,
            __('supplier::labour.loading_narration', ['no' => $challan->document_no]),
        );

        if ($lines === []) {
            return;
        }

        $this->posting->post(
            sourceType: self::SOURCE,
            sourceId: (int) $challan->id,
            trxDate: $challan->trx_date,
            lines: $lines,
            documentNo: $challan->document_no,
            // ⛔ চালানের নিজের শাখায় (RCV-0143)
            branchId: $challan->branch_id === null ? null : (int) $challan->branch_id,
        );
    }

    /**
     * ⭐ পাকা চালানে প্রথমবার লোডিং লেখা — ঐচ্ছিক হলে প্রায়ই মাল তোলার পরে জানা যায় (ভাড়ার [[FarePayment::recordOnConfirmed()]]-এর
     * একই ধাঁচ)। ⛔ আগে লেখা থাকলে আবার নয় — খরচ দুইবার বসত। তালা দিয়ে আসল অবস্থা।
     *
     * @param  array<string, mixed>  $data
     */
    public function recordOnConfirmed(DeliveryChallan $challan, array $data): DeliveryChallan
    {
        return DB::transaction(function () use ($challan, $data): DeliveryChallan {
            $challan = DeliveryChallan::acrossBranches()->lockForUpdate()->findOrFail($challan->id);

            if ($challan->status !== DocumentStatus::CONFIRMED) {
                throw ValidationException::withMessages(['loading_mode' => __('sales::fare.only_confirmed')]);
            }

            if (filled($challan->loading_mode)) {
                throw ValidationException::withMessages(['loading_mode' => __('sales::fare.already_recorded')]);
            }

            if (trim((string) ($data['loading_mode'] ?? '')) === '') {
                throw ValidationException::withMessages(['loading_mode' => __('supplier::labour.pick_one')]);
            }

            $this->stamp($challan, $data);
            $this->labour->assertComplete($challan, 'loading_', 'loading');
            $this->post($challan->fresh());

            return $challan->fresh();
        });
    }

    /** বাতিল বা সম্পাদনায় — এখনো খোলা দাখিলা থাকলে উল্টো সারি ([[DeliveryChallanService::unpost()]]-এর একই পাহারা) */
    public function undo(DeliveryChallan $challan, Carbon $date, string $reason, ?string $paperNo = null): void
    {
        $lastReversal = LedgerEntry::query()
            ->where('source_type', self::SOURCE.':reversal')
            ->where('source_id', $challan->id)
            ->max('id');

        $open = LedgerEntry::query()
            ->where('source_type', self::SOURCE)
            ->where('source_id', $challan->id)
            ->when($lastReversal !== null, fn ($q) => $q->where('id', '>', $lastReversal))
            ->exists();

        if (! $open) {
            return;
        }

        $this->posting->reverse(
            sourceType: self::SOURCE,
            sourceId: (int) $challan->id,
            reversalDate: $date,
            reason: $reason,
            documentNo: $paperNo,
        );
    }
}
