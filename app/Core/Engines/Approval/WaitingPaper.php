<?php

declare(strict_types=1);

namespace App\Core\Engines\Approval;

use App\Core\Contracts\IsAMasterRecord;
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
 * ([[ui.paper-row-menu]], [[approval.edit-button]])। ⓘ মাস্টার রেকর্ড ([[IsAMasterRecord]]) এর বাইরে — সেগুলো কাগজ নয়;
 * মডিউল নিজের মডেলে ঘোষণা করে, কোর কোনো মডিউলের নাম জানে না ([[BoundariesTest]])।
 */
final class WaitingPaper
{
    /**
     * কাগজটা এখন কারও সইয়ের অপেক্ষায় কি না — মাস্টার হলে কখনো নয়।
     *
     * ⓘ জমা রাখা নয়, প্রতিবার তাজা: একই অনুরোধে সই বা ফেরতের পরে উত্তর বদলায়, আর ডাক আসে কেবল সম্পাদনা-খোলা সারি থেকে।
     */
    public function waits(Model $paper): bool
    {
        if (! $paper->exists || $paper instanceof IsAMasterRecord) {
            return false;
        }

        return Approval::query()
            ->where('approvable_type', $paper->getMorphClass())
            ->where('approvable_id', $paper->getKey())
            ->where('status', Approval::PENDING)
            ->exists();
    }

    /**
     * তালিকার সারির জন্য একই প্রশ্ন — এক অনুরোধে এক ধরনের কাগজের জন্য একটাই কোয়েরি ([[ui.paper-row-menu]])।
     *
     * ⛔ Mac run of e88be43a3 (TheBillListShowsTheSuppliersOwnNumberTest): তালিকার প্রতিটা খসড়া সারি [[waits()]] ডাকত — দুই বিলে
     *   ৪৫টা কোয়েরি, ছয় বিলে ৪৯টা। ⭐ এখন প্রথম সারিতে এই ধরনের সব অপেক্ষার কাগজের id একবারে, বাকি সারি সেখান থেকে।
     * ⓘ মনে রাখা কেবল এই অনুরোধের ভিতরে — অনুরোধের বস্তু ধরে ([[WeakMap]]), তাই পরের অনুরোধ (পরীক্ষায় একই প্রসেসেও) নতুন করে পড়ে।
     *   দরজার পাহারা আর পাতার বোতাম তবু তাজা [[waits()]] ডাকে — এটা কেবল তালিকা আঁকার জন্য।
     */
    public function waitsInList(Model $paper): bool
    {
        if (! $paper->exists || $paper instanceof IsAMasterRecord) {
            return false;
        }

        $request = request();
        self::$listed ??= new \WeakMap;
        $known = self::$listed[$request] ?? [];
        $type = $paper->getMorphClass();

        if (! array_key_exists($type, $known)) {
            $known[$type] = array_fill_keys(
                Approval::query()
                    ->where('approvable_type', $type)
                    ->where('status', Approval::PENDING)
                    ->pluck('approvable_id')
                    ->map(fn ($id): int => (int) $id)
                    ->all(),
                true,
            );
            self::$listed[$request] = $known;
        }

        return isset($known[$type][(int) $paper->getKey()]);
    }

    /** @var \WeakMap<\Illuminate\Http\Request, array<string, array<int, true>>>|null */
    private static ?\WeakMap $listed = null;
}
