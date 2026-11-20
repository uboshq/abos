<?php

declare(strict_types=1);

namespace App\Core\Engines\Approval;

use App\Models\Approval;
use Illuminate\Database\Eloquent\Model;

/**
 * ⛔ সইয়ের অপেক্ষার কাগজ কেউ বদলাতে পারেন না — মালিকের চূড়ান্ত সিদ্ধান্ত, ২০ নভেম্বর ২০২৬ (maker-checker)।
 *
 * ── কী ঘটেছিল ────────────────────────────────────────────────────────
 * সইকারী অপেক্ষার কাগজ বদলাতে পারতেন; বদলে পুরনো অনুরোধ উঠে গিয়ে নতুন অনুরোধ বসত **তাঁর নিজের নামে** — আর নিজের অনুরোধে
 * নিজে সই দেওয়া যায় না, তাই কেউই সই দিতে পারতেন না (লাইভ: মালিক COL-0008 বদলালেন, আটকে গেল)।
 *
 * ── ⭐ এখন ────────────────────────────────────────────────────────────
 * অপেক্ষার (`pending`) অনুরোধ থাকলে কাগজটা কেউ বদলান না — সইকারী সই দেন, সংশোধনে ফেরত পাঠান, বা না বলেন। ফেরত পাঠানো
 * (`returned`) কাগজ লেখক আজকের মতোই বদলান। ⓘ এই নিয়ম ১৪ অক্টোবরের "মালিক বদলে সই দেবেন / সইকারী বিবরণ ঠিক করবেন"
 * ([[ApprovalEngine::adoptSignerEdit()]]) পর্দার পথে বাতিল করে — নতুন সিদ্ধান্ত পুরনোটার উপরে।
 *
 * ⓘ পাহারা দরজায় ([[App\Http\Middleware\AWaitingPaperIsNotEdited]] — প্রতিটা `*.edit`/`*.update`), বোতাম ধূসর পাতায়
 * ([[ui.paper-row-menu]], [[approval.edit-button]])। ⓘ মাস্টার রেকর্ড ([[MASTERS]]) এর বাইরে — সেগুলো কাগজ নয়।
 */
final class WaitingPaper
{
    /**
     * ⓘ কাগজ নয়, মাস্টার — নিজের একটা ঘরের অনুরোধ (বাকির সীমা, ব্যাংক হিসাব …) অপেক্ষায় থাকলেও বাকিটা বদলানো চলে।
     *
     * @var list<class-string>
     */
    public const MASTERS = [
        \App\Modules\Customer\Models\Customer::class,
        \App\Modules\Supplier\Models\Supplier::class,
        \App\Modules\Inventory\Models\Product::class,
        \App\Modules\Accounts\Models\Account::class,
        \App\Modules\Accounts\Models\CashTill::class,
    ];

    /**
     * কাগজটা এখন কারও সইয়ের অপেক্ষায় কি না — মাস্টার হলে কখনো নয়।
     *
     * ⓘ জমা রাখা নয়, প্রতিবার তাজা: একই অনুরোধে সই বা ফেরতের পরে উত্তর বদলায়, আর ডাক আসে কেবল সম্পাদনা-খোলা সারি থেকে।
     */
    public function waits(Model $paper): bool
    {
        if (! $paper->exists || $this->isMaster($paper)) {
            return false;
        }

        return Approval::query()
            ->where('approvable_type', $paper->getMorphClass())
            ->where('approvable_id', $paper->getKey())
            ->where('status', Approval::PENDING)
            ->exists();
    }

    private function isMaster(Model $paper): bool
    {
        foreach (self::MASTERS as $master) {
            if ($paper instanceof $master) {
                return true;
            }
        }

        return false;
    }
}
