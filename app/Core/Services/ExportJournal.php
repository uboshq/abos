<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Support\CompanyContext;
use App\Models\ExportLog;

/**
 * রপ্তানির খাতা — কে কোন তালিকা নামিয়ে নিয়ে গেল।
 *
 * ── কেন এটা দরকার ───────────────────────────────────────────────────
 * ABOS ক্রয়মূল্য ও মুনাফা যত্ন করে ঢেকেছে — কলাম ধরে, তিন পথেই। কিন্তু
 * যাঁর অনুমতি আছে তিনি পুরো তালিকাটা এক ক্লিকে নামিয়ে নিতে পারেন, আর
 * সেই ফাইলটা কোথায় গেল তার কোনো রেকর্ড ছিল না।
 *
 * আজ পর্যন্ত ঝুঁকিটা তাত্ত্বিক ছিল, কারণ রিপোর্টের রপ্তানি আসলে কাজই
 * করত না। সেটা সারানোর পর ঝুঁকিটা বাস্তব হলো।
 *
 * ── খাতাটা নীরবে ব্যর্থ হয় ──────────────────────────────────────────
 * খাতা লিখতে গিয়ে কিছু ভাঙলে ফাইলটা আটকে দেওয়া হয় না। কারণ: রপ্তানি
 * ব্যবহারকারীর কাজ, খাতাটা আমাদের রেকর্ড। খাতার জন্য কারও কাজ থামানো
 * ভুল বিনিময় — আর ভাঙা খাতা নিয়ে সে জানতেও পারবে না কী করবে।
 *
 * তবু চুপচাপ গিলে ফেলা হয় না: `report()` দিয়ে লগে যায়, তাই ব্যাপারটা
 * ঘটলে খুঁজে বের করা যায়।
 */
class ExportJournal
{
    /**
     * একটা রপ্তানি হয়ে গেল।
     *
     * ── কেন ছাঁকনিগুলো এখানেই তোলা হয় ───────────────────────────────
     * অনুরোধের প্যারামিটারগুলোই ছাঁকনি — পর্দাটা যা দিয়ে তালিকা তৈরি
     * করেছে। কন্ট্রোলারকে আলাদা করে বলতে বললে বাইশ জায়গায় বাইশবার
     * লিখতে হত, আর একটায় ভুলে গেলে ওই পর্দার খাতা অর্ধেক সত্যি বলত।
     */
    public function wrote(int $rowCount): ?ExportLog
    {
        $companyId = CompanyContext::id();

        if ($companyId === null) {
            return null;
        }

        try {
            $user = auth()->user();

            return ExportLog::create([
                'company_id' => $companyId,
                'branch_id' => CompanyContext::branchId(),
                'user_id' => $user?->getKey(),

                /*
                 * নামটা আলাদা করেও জমা।
                 *
                 * যিনি ফাইলটা নিয়ে গেছেন তাঁকে সরিয়ে দিয়ে চিহ্নটাও
                 * মুছে ফেলা যাবে না — সেটাই খাতাটার পুরো মানে।
                 */
                'user_name' => $user?->name,
                'route' => (string) (request()->route()?->getName() ?? request()->path()),
                'title' => $this->title(),
                'filters' => $this->filters(),
                'row_count' => $rowCount,
                'ip_address' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 255) ?: null,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * পর্দার শিরোনাম — যা মানুষ চিনবে।
     *
     * রুটের নাম (`sales.report.show`) খাতায় থাকে বটে, কিন্তু ওটা পড়ে
     * হিসাবরক্ষক বুঝবেন না কোন রিপোর্ট গেছে। পাতাটার `<title>` থেকেই
     * নেওয়া হয়, কারণ সেটা প্রতিটা পর্দায় আছে আর মানুষের ভাষায়।
     */
    private function title(): ?string
    {
        $slug = request()->route()?->parameter('slug');

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    /**
     * কোন ছাঁকনিতে।
     *
     * `export` নিজে বাদ — ওটা ছাঁকনি নয়, "ফাইল চাই" বলার উপায়। রাখলে
     * প্রতিটা সারিতে `export: csv` বসে থাকত, আর আসল ছাঁকনিগুলো ওই
     * শব্দটার পাশে হারিয়ে যেত।
     *
     * @return array<string, mixed>
     */
    private function filters(): array
    {
        $query = request()->query();

        unset($query['export'], $query['page']);

        return $query;
    }
}
