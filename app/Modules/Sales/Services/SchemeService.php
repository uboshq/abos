<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\CompanyContext;
use App\Modules\Sales\Models\CommissionRule;
use App\Modules\Sales\Models\Scheme;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * স্কিম আর তার ধাপগুলো — লেখা, বদলানো, চালু করা।
 *
 * ── কেন চালু করাটা আলাদা একটা কাজ ────────────────────────────────────
 * খসড়া অবস্থায় হার বসানো যায়, কিন্তু সেটা কোনো বিলে লাগে না। নাহলে
 * অর্ধেক লেখা স্কিম চালু হয়ে যেত: প্রথম ধাপটা বসানোর পর দ্বিতীয়টা
 * বসানোর আগেই বিল কাটা শুরু হত, আর ওই ফাঁকে কাটা বিলগুলো ভুল হারে
 * কমিশন পেত — যা পরে খুঁজে বের করা প্রায় অসম্ভব।
 */
final class SchemeService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Scheme
    {
        return DB::transaction(fn () => Scheme::query()->create([
            ...$data,
            'company_id' => CompanyContext::id(),
            'branch_id' => CompanyContext::branchId(),
            'status' => Scheme::DRAFT,
            'created_by' => auth()->id(),
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Scheme $scheme, array $data): Scheme
    {
        $this->assertEditable($scheme);

        return DB::transaction(function () use ($scheme, $data) {
            $scheme->update($data);

            return $scheme->fresh();
        });
    }

    /**
     * একটা ধাপ যোগ করা।
     *
     * @param  array<string, mixed>  $data
     */
    public function addRule(Scheme $scheme, array $data): CommissionRule
    {
        $this->assertEditable($scheme);

        return DB::transaction(fn () => CommissionRule::query()->create([
            ...$data,
            'company_id' => CompanyContext::id(),
            'scheme_id' => $scheme->id,
        ]));
    }

    public function removeRule(CommissionRule $rule): void
    {
        $this->assertEditable($rule->scheme);

        $rule->delete();
    }

    /**
     * স্কিমটা চালু করা।
     *
     * ── কেন চালুর আগে পাহারা ────────────────────────────────────────
     * চালু হওয়ার পর স্কিমটা প্রতিটা বিলে টাকা দেয়। ভুলগুলো তখন আর
     * পর্দার ভুল নয় — টাকার ভুল, আর টাকা দেওয়ার পর ফেরত আনতে হয়।
     * তাই যা যাচাই করার, চালুর আগেই।
     */
    public function activate(Scheme $scheme): Scheme
    {
        $rules = $scheme->rules()->orderBy('slab_from')->get();

        if ($rules->isEmpty()) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.scheme_has_no_rule'),
            ]);
        }

        /*
         * প্রতিটা ভূমিকার সিঁড়ির উপরের ধাপটা খোলা থাকতে হবে।
         *
         * ---- কেন এটা চালুর সময় আটকানো হয় ----
         * সব ধাপ বন্ধ থাকলে বছরের সবচেয়ে বড় বিলটা ছকের উপর দিয়ে
         * বেরিয়ে যায় আর শূন্য পায়। ভুলটা নীরব: কেউ অভিযোগ করে না যে
         * "আমার সেরা মাসে কমিশন আসেনি", কারণ ধরে নেওয়া হয় হিসাব ঠিকই
         * আছে। ধরা পড়ে অনেক পরে, আর তখন কয় মাসের হিসাব ভুল সেটা
         * বের করতে হয়।
         */
        $roles = $rules->groupBy('earner_role');

        $closed = $roles->filter(
            fn ($forRole) => $forRole->every(fn (CommissionRule $r) => $r->slab_to !== null),
        )->keys();

        if ($closed->isNotEmpty()) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.scheme_top_band_closed', [
                    'roles' => $closed->implode(', '),
                ]),
            ]);
        }

        $scheme->update(['status' => Scheme::ACTIVE]);

        return $scheme->fresh();
    }

    /**
     * চালু স্কিম থামানো।
     *
     * মুছে ফেলা হয় না: যে বিলগুলো এই স্কিমে কমিশন পেয়েছে সেগুলো
     * থেকে যায়, আর ছয় মাস পরেও প্রশ্নটা ওঠে "এই হারটা কোথা থেকে এল"।
     */
    public function cancel(Scheme $scheme, string $reason): Scheme
    {
        $scheme->update([
            'status' => Scheme::CANCELLED,
            'notes' => trim($scheme->notes."\n".$reason),
        ]);

        return $scheme->fresh();
    }

    /**
     * চালু স্কিমের হার বদলানো যায় না।
     *
     * ── কেন ─────────────────────────────────────────────────────────
     * চালু স্কিমের হার বদলালে **আগের বিলগুলোর কমিশনও বদলে যেত** —
     * ইঞ্জিন হিসাব করে বর্তমান নিয়ম দেখে। গত মাসে যা দেওয়া হয়েছে আর
     * আজ যা হিসাব হচ্ছে, দুইটা আলাদা হয়ে যেত, আর কোনটা ঠিক তা বলার
     * উপায় থাকত না।
     *
     * হার বদলাতে হলে পুরনোটা থামিয়ে নতুন একটা স্কিম — তাতে কোন
     * তারিখ থেকে কোন হার, সেটা কাগজেই লেখা থাকে।
     */
    private function assertEditable(Scheme $scheme): void
    {
        if ($scheme->status === Scheme::ACTIVE) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.scheme_is_live'),
            ]);
        }
    }
}
