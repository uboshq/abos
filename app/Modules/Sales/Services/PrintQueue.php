<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Modules\Sales\Models\PrintJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * রসিদের সারি — কাগজটা ছাপা হলো কি না, আর কতবার।
 *
 * ── কেন এটা একটা "কিউ" নামে, অথচ কোনো ডেমন নেই ───────────────────────
 * ছাপা হয় ব্রাউজারে: সার্ভার PDF বানায়, ব্রাউজার ছাপে। সার্ভারের পক্ষে
 * কাউন্টারের প্রিন্টারে পৌঁছানোর কোনো পথ নেই, তাই "পরে নিজে থেকে ছেপে
 * দেবে" বলে কিছু হয় না — আর সেটা ভান করলে ক্যাশিয়ার অপেক্ষা করতেন
 * এমন কিছুর জন্য যা কখনো ঘটত না।
 *
 * সারিটা তাই মানুষের জন্য: কোন কাগজগুলো এখনো বেরোয়নি তার তালিকা,
 * প্রিন্টার ঠিক হলে যেখান থেকে আবার চাপা যায়। কাজটা যন্ত্রের নয়,
 * মনে রাখার।
 *
 * ── কেন গোনাটা এখানে ────────────────────────────────────────────────
 * দ্বিতীয়বার ছাপা কাগজে DUPLICATE বসতে হবে, নাহলে একই বিলের দুইটা
 * একরকম কাগজ ঘোরে আর কোনটা আসল তা বলার উপায় থাকে না।
 */
final class PrintQueue
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * কাগজটা সারিতে তোলা — বিক্রয় শেষ হওয়ার মুহূর্তে।
     *
     * একই কাগজ দ্বিতীয়বার তুললে নতুন সারি হয় না; আগেরটাই ফেরে, তার
     * গোনা নিয়ে। নাহলে "কতবার ছাপা হলো" প্রশ্নের উত্তর সারি গুনে
     * বের করতে হত, আর ব্যর্থ চেষ্টাগুলোও গোনা হয়ে যেত।
     */
    public function queue(string $type, int $id, string $paper, ?string $documentNo = null): PrintJob
    {
        return DB::transaction(fn () => PrintJob::query()->firstOrCreate(
            [
                'document_type' => $type,
                'document_id' => $id,
                'paper' => $paper,
            ],
            [
                'company_id' => CompanyContext::id(),
                'branch_id' => CompanyContext::branchId(),
                'document_no' => $documentNo,
                'status' => PrintJob::WAITING,
                'printed_count' => 0,
                'created_by' => auth()->id(),
            ],
        ));
    }

    /**
     * আর কি ছাপা যাবে — নাকি সীমা পেরিয়ে গেছে।
     *
     * ── কেন সীমাটা লাগে ─────────────────────────────────────────────
     * গোনা ও DUPLICATE ছাপ আগেই ছিল, কিন্তু দুইটাই **নিষ্ক্রিয়**: তারা
     * বলে দেয় কাগজটা দ্বিতীয়বার ছাপা, কেউ আটকায় না। কর্মী চাইলে
     * বিশবার ছাপতে পারতেন, আর প্রতিটাতেই DUPLICATE বসত — যেটা কেউ
     * পড়ে না, কারণ সব কপিতেই লেখা।
     *
     * ── কেন ডিফল্টে সীমা নেই ────────────────────────────────────────
     * শূন্য মানে অসীম, আর সেটাই ডিফল্ট। চালু ব্যবস্থায় হঠাৎ একটা সীমা
     * বসালে যিনি রোজ তিনটা কপি ছাপেন তাঁর কাজ কাল সকালে থেমে যেত, আর
     * তিনি ভাবতেন আপগ্রেডে কিছু ভেঙেছে। সংখ্যাটা মালিকের সিদ্ধান্ত —
     * এক ডিপোর "যথেষ্ট" আরেকটার "কম"।
     *
     * ── কেন অনুমতি দিয়ে ছাড়ানো যায় ─────────────────────────────────
     * প্রিন্টার কাগজ চিবিয়ে ফেলে, কালি ফুরিয়ে যায়, ক্রেতা কপি হারান।
     * সীমাটা যদি কেউই ছাড়াতে না পারে, তবে বাস্তবে ওই কাগজটা আর
     * কোনোদিন ছাপা যেত না — আর তখন কেউ বিলটা বাতিল করে নতুন বিল
     * কাটতেন, যেটা অনেক বেশি ক্ষতিকর।
     */
    public function mayPrint(PrintJob $job): bool
    {
        $limit = (int) $this->settings->get('sales.reprint_limit', 0);

        if ($limit <= 0 || $job->printed_count < $limit) {
            return true;
        }

        return auth()->user()?->can('sales.reprint.override') === true;
    }

    /**
     * সীমা পেরোলে থামাও।
     *
     * বার্তায় সংখ্যাটা থাকে, কারণ "আর ছাপা যাবে না" পড়ে কেউ বোঝে না
     * কতবার হয়েছে বা কার কাছে গেলে হবে।
     */
    public function assertMayPrint(PrintJob $job): void
    {
        if ($this->mayPrint($job)) {
            return;
        }

        throw ValidationException::withMessages([
            'print' => __('sales::validation.reprint_limit_reached', [
                'no' => $job->document_no ?: '',
                'count' => $job->printed_count,
            ]),
        ]);
    }

    /**
     * কাগজটা সত্যিই বেরিয়েছে — গোনা এক বাড়ে।
     *
     * ছাপার অনুরোধ সফল হলেই ডাকা হয়, PDF তৈরি হওয়ার পর। আগে ডাকলে
     * ব্যর্থ চেষ্টাও গোনা হত, আর দ্বিতীয় সত্যিকারের কাগজে DUPLICATE
     * বসত না — অথচ প্রথমটা কখনো বেরোয়ইনি।
     */
    public function printed(PrintJob $job): PrintJob
    {
        $job->update([
            'status' => PrintJob::PRINTED,
            'printed_count' => $job->printed_count + 1,
            'printed_at' => now(),
            'failure' => null,
        ]);

        return $job->fresh();
    }

    /** চেষ্টা ব্যর্থ — কারণসহ, কারণ কাগজ ফুরানো আর প্রিন্টার বন্ধ এক নয়। */
    public function failed(PrintJob $job, string $reason): PrintJob
    {
        $job->update([
            'status' => PrintJob::FAILED,
            'failure' => mb_substr($reason, 0, 255),
        ]);

        return $job->fresh();
    }

    /**
     * এখনো যেগুলো বেরোয়নি — কাউন্টারের পর্দায় দেখানোর জন্য।
     *
     * @return Collection<int, PrintJob>
     */
    public function pending()
    {
        return PrintJob::query()
            ->waiting()
            ->orderBy('id')
            ->get();
    }
}
