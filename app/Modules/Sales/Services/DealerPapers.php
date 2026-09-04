<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\CompanyContext;
use App\Models\LedgerEntry;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * ডিলার নিজের যে কাগজগুলো দেখতে পান — আর কেবল নিজেরগুলো।
 *
 * ── কেন একটা আলাদা সেবা ─────────────────────────────────────────────
 * পোর্টালের প্রতিটা পাতা আগে **নিজে** মডেল ডাকত, **নিজে** শাখার
 * ছাঁকনি সরাত, আর **নিজে** পার্টি মেলাত — একই কথা তিন জায়গায় হাতে
 * লেখা। আজ কাজ করত, কিন্তু প্রতিটা নতুন পাতায় ভুলের সুযোগ একটা করে
 * বাড়ত।
 *
 * ⚠️ আর ভুলটা দুই দিকে যায়, **অসমান**:
 *
 *   ছাঁকনি সরাতে ভুলে গেলে   ৫০০ — জোরে ভাঙে, সাথে সাথে ধরা পড়ে
 *   বেশি সরিয়ে ফেললে        ডিলার **অন্যের কাগজ** দেখেন — নীরব, আর
 *                            একবার দেখে ফেললে ফেরানো যায় না
 *
 * ── ⭐ সবচেয়ে জরুরি নিয়ম: কোনো পদ্ধতি "কার" জিজ্ঞেস করে না ──────────
 * এখানে একটাও পদ্ধতি গ্রাহকের আইডি **প্যারামিটার হিসেবে নেয় না**।
 * নিলে একদিন কেউ URL থেকে নেওয়া একটা সংখ্যা পাঠাতেন, আর সেদিন কোনো
 * ত্রুটি আসত না — শুধু একজন ডিলার অন্যের খাতা দেখতেন।
 *
 * **কে, সেটা সবসময় গার্ড থেকে** ([[DealerPapers::dealer()]]), কখনো
 * ডাকার জায়গা থেকে নয়।
 *
 * ── আর একটা ফল: `withoutGlobalScope` আর কোথাও লেখা হয় না ────────────
 * লাইনটা এখন এই একটা ফাইলে, একবার। **যে লাইনটা কেউ ভুলতে পারে,
 * সেটার অস্তিত্বই না থাকা** — এটাই আসল সুরক্ষা, মনে রাখা নয়।
 *
 * ⛔ ── রিপোর্ট ইঞ্জিন এখানে ব্যবহার করা হয় না, ইচ্ছাকৃতভাবে ──────────
 * ইঞ্জিনের রিপোর্টগুলো কোম্পানি ও শাখা ধরে চলে, **পার্টি ধরে নয়** —
 * আর সে রপ্তানি ও ছাপার পথও দেয়। ওখানে একটা প্যারামিটার দিয়ে ছাঁকতে
 * গেলে **প্রতিটা পথে একই ছাঁকনি লাগত**, আর একটা ফসকালে সবাই সবার
 * কাগজ দেখে ফেলতেন।
 */
final class DealerPapers
{
    /**
     * যিনি ঢুকেছেন — আর কেবল তিনিই।
     *
     * ⓘ কোম্পানির প্রসঙ্গটাও এখান থেকেই বসে: ডিলারের "কোম্পানি বাছাই"
     * বলে কিছু নেই, তিনি একটাই কোম্পানির। প্রসঙ্গ না বসালে
     * `BelongsToCompany` ওয়েব অনুরোধে ব্যতিক্রম ছুঁড়ত।
     */
    public function dealer(): Customer
    {
        /** @var Customer|null $dealer */
        $dealer = Auth::guard('portal')->user();

        abort_if($dealer === null, 403);

        CompanyContext::set($dealer->company_id, $dealer->branch_id);

        return $dealer;
    }

    /**
     * নিজের বিলগুলো — নতুনটা আগে।
     *
     * @return Collection<int, SalesInvoice>
     */
    public function invoices(int $limit = 20): Collection
    {
        return $this->mine(SalesInvoice::query())
            ->orderByDesc('trx_date')->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * নিজের খতিয়ানের সারি — একটা সময়ের ভিতরে, পুরনো আগে।
     *
     * ⚠️ ক্রম দুইটা কলাম ধরে, আর দ্বিতীয়টা বাদ দেওয়া যাবে না: একই
     * তারিখে তিনটা সারি থাকলে ডাটাবেস যেকোনো ক্রমে দিতে পারে, আর
     * তখন **প্রতিবার পাতা খুললে চলমান জের আলাদা দেখাত** — অথচ একটা
     * সংখ্যাও বদলায়নি।
     *
     * @return Collection<int, LedgerEntry>
     */
    public function ledgerBetween(string $from, string $to): Collection
    {
        return $this->ledgerRows()
            ->whereDate('trx_date', '>=', $from)
            ->whereDate('trx_date', '<=', $to)
            ->orderBy('trx_date')->orderBy('id')
            ->get();
    }

    /**
     * ছাঁকনির **আগের** সব সারির নিট — খোলার জের।
     *
     * ⚠️ এটা না গুনলে জেরের কলাম শূন্য থেকে শুরু হত, আর ডিলার পড়তেন
     * "আমার কোনো বকেয়া ছিল না" — যা প্রায় সবসময়ই মিথ্যা।
     */
    public function openingBefore(string $from): string
    {
        return (string) ($this->ledgerRows()
            ->whereDate('trx_date', '<', $from)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as net')
            ->value('net') ?? '0');
    }

    /**
     * খতিয়ানের কোয়েরি — দুইটা শর্ত, সবসময় একসাথে।
     *
     * ⚠️ একটা ছাড়া অন্যটা কখনো নয়। `forParty` বাদ পড়লে **অন্য ডিলারের
     * সারি** চলে আসত; শাখার ছাঁকনি না সরালে ডিলার **নিজের অর্ধেক
     * কাগজ** দেখতেন না। দ্বিতীয়টা বিরক্তিকর, প্রথমটা মারাত্মক।
     */
    private function ledgerRows()
    {
        return LedgerEntry::query()
            ->withoutGlobalScope('user-branch')
            ->forParty('customer', (int) $this->dealer()->id);
    }

    /**
     * ⭐ এই একটা পদ্ধতিই "কার" প্রশ্নের একমাত্র উত্তরদাতা।
     *
     * ⓘ ডিলারের কোনো শাখা নেই, তাই কর্মীর শাখা-ছাঁকনি সরাতে হয় —
     * কিন্তু সেই সাথেই পার্টির শর্তটা বসে, **একই লাইনে**। দুইটা আলাদা
     * জায়গায় থাকলে একদিন কেউ প্রথমটা লিখে দ্বিতীয়টা ভুলতেন।
     *
     * @template T of \Illuminate\Database\Eloquent\Builder
     *
     * @param  T  $query
     * @return T
     */
    private function mine($query)
    {
        return $query
            ->withoutGlobalScope('user-branch')
            ->where('customer_id', $this->dealer()->id);
    }
}
