<?php

declare(strict_types=1);

namespace App\Modules\Customer\Services;

use App\Core\Support\CompanyContext;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerConduct;
use App\Modules\Customer\Support\ConductType;
use Illuminate\Validation\ValidationException;

/**
 * পার্টির আচরণ লেখা ও নামানো — মোছা কখনো নয়।
 *
 * Status বলে কারবার করা যাবে কিনা; আচরণ বলে কেমন কারবার। দুইটা আলাদা
 * প্রশ্ন, তাই আলাদা সার্ভিস।
 */
final class ConductService
{
    /**
     * একটা আচরণ লেখা।
     *
     * ── দুইটা নিয়ম এখানেই ───────────────────────────────────────────
     * `recorded_by` সবসময় বসে (বেনামি লাল পতাকা প্রশ্নের অতীত), আর
     * `OTHER` বাছলে নোট বাধ্যতামূলক — নাহলে বাঁধা তালিকাটা মুক্ত লেখার
     * পিছনের দরজা হয়ে যেত।
     */
    public function record(Customer $customer, string $type, ?string $note = null): CustomerConduct
    {
        if (! ConductType::isValid($type)) {
            throw ValidationException::withMessages([
                'type' => __('customer::conduct.invalid_type'),
            ]);
        }

        if (ConductType::requiresNote($type) && blank($note)) {
            throw ValidationException::withMessages([
                'note' => __('customer::conduct.note_required'),
            ]);
        }

        return CustomerConduct::create([
            'company_id' => CompanyContext::id(),
            'customer_id' => $customer->id,
            'type' => $type,
            'note' => filled($note) ? trim((string) $note) : null,
            'is_active' => true,
            'recorded_by' => auth()->id(),
            'recorded_at' => now(),
        ]);
    }

    /**
     * পতাকা নামানো — সারিটা থাকে, শুধু আর চলমান নয়।
     *
     * মোছা হয় না: "আগে দেরি করত, এখন ঠিক" — এই কথাটাই হারিয়ে যেত।
     * ইতিমধ্যে নামানো থাকলে কিছুই হয় না (idempotent)।
     */
    public function retire(CustomerConduct $conduct): CustomerConduct
    {
        if (! $conduct->is_active) {
            return $conduct;
        }

        $conduct->forceFill([
            'is_active' => false,
            'retired_by' => auth()->id(),
            'retired_at' => now(),
        ])->save();

        return $conduct->fresh();
    }
}
