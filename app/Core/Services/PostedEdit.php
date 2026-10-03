<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\RevisableDocument;
use App\Core\Support\CompanyContext;
use App\Models\FinancialYear;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * পোস্ট হওয়া কাগজ কে, কখন বদলাতে পারেন — মালিকের নিয়ম, ৩ অক্টোবর ২০২৬।
 *
 * *"মাস ক্লোজ না হওয়া পর্যন্ত সুপার অ্যাডমিন সব পোস্টেড কাগজ এডিট করতে পারবেন — ১০০%, কিছুই বাদ নয়।
 * মাস ক্লোজের পরে আর নয়, যদি না মাসটা খুলে দেন; এডিট করে আবার লক করবেন।"*
 *
 * ── ⭐ চারটা প্রশ্ন, প্রতিটার নিজের বার্তা ─────────────────────────────────
 *   ১ · কাগজটা পোস্ট হয়েছে তো?                 না হলে — খসড়া সাধারণ পথেই বদলায়
 *   ২ · যে কোম্পানিতে কাজ চলছে, কাগজ সেখানকার?   না হলে — খাতা ভুল কোম্পানিতে বসত
 *   ৩ · ইনি **এই কাগজের কোম্পানিতে** সুপার অ্যাডমিন? না হলে — অন্য কোম্পানির সুপার অ্যাডমিনও নন
 *   ৪ · কাগজের মাস খোলা (বা এখন খুলে রাখা)?        না হলে — মাসের নাম, আর "আগে মাসটা খুলুন"
 *
 * ── ⛔ মাসের প্রশ্নটা কখনো বাদ যায় না ────────────────────────────────────
 * সমন্বয়কের সিদ্ধান্ত, ৩ অক্টোবর ২০২৬: কাউন্টারের নিজের নিয়মে (গেট পাসের আগে, [[SaleEditor]])
 * যিনি সম্পাদনা করেন, তাঁর জন্য ৩ নম্বর প্রশ্নটা বদলায় — কিন্তু ৪ নম্বরটা নয়। ⓘ তাই
 * [[RevisionKeeper]] ১, ২, ৪ সবসময় নিজে ডাকে, আর কেবল ৩ নম্বরের জায়গায় ডাকনেওয়ালার নিয়ম বসতে পারে।
 * তালা খোলার একমাত্র পথ মাসটা খোলা ([[PeriodLockController::reopen()]]) — কারণসহ, অডিটসহ।
 */
final class PostedEdit
{
    public function __construct(
        private readonly Ownership $ownership,
    ) {}

    /** সুপার অ্যাডমিনের নিয়মে পুরোটা — চারটা প্রশ্নই। */
    public function assertMay(Model&RevisableDocument $document, User $user): void
    {
        $this->assertPosted($document);
        $this->assertSameCompany($document);
        $this->assertSuperAdmin($document, $user);
        $this->assertPeriodOpen($document);
    }

    public function assertPosted(Model&RevisableDocument $document): void
    {
        if (! $document->isPostedForRevision()) {
            throw ValidationException::withMessages([
                'edit' => __('revision.not_posted', ['no' => $document->revisionNumber()]),
            ]);
        }
    }

    /**
     * ⚠️ খাতার ইঞ্জিন সারি বসায় **চলতি প্রসঙ্গের** কোম্পানিতে ([[PostingEngine::post()]])।
     * ⛔ প্রসঙ্গ আর কাগজ আলাদা কোম্পানির হলে উল্টো সারি এক খাতায় আর নতুন সারি আরেক খাতায় বসত।
     */
    public function assertSameCompany(Model&RevisableDocument $document): void
    {
        if ((int) $document->getAttribute('company_id') !== (int) CompanyContext::id()) {
            throw ValidationException::withMessages([
                'edit' => __('revision.other_company', ['no' => $document->revisionNumber()]),
            ]);
        }
    }

    /**
     * ⭐ সুপার অ্যাডমিন **এই কাগজের কোম্পানিতে** — রোল কোম্পানির ভেতরে বাঁধা ([[Ownership::isOwnerIn()]])।
     *
     * ⛔ `hasRole()` নয়: teams-এ ওটা চলতি প্রসঙ্গের দল দেখে, কাগজের কোম্পানি নয় — আর অন্য
     * কোম্পানির সুপার অ্যাডমিন প্রসঙ্গ বদলে এখানে এলে ভুল উত্তর পেতেন। নিষ্ক্রিয় ব্যবহারকারী বা
     * কোম্পানিতে প্রবেশ কেড়ে নেওয়া মানুষও এখানে থামেন — একই প্রশ্ন, একই উত্তর।
     */
    public function assertSuperAdmin(Model&RevisableDocument $document, User $user): void
    {
        if (! $this->ownership->isOwnerIn($user, (int) $document->getAttribute('company_id'))) {
            throw ValidationException::withMessages([
                'edit' => __('revision.not_super_admin', ['no' => $document->revisionNumber()]),
            ]);
        }
    }

    /**
     * কাগজের মাস খোলা কি না — "খোলা" মানে তালা নেই: কখনো বন্ধ হয়নি, বা খুলে রাখা আছে।
     *
     * ⓘ পেছনের জানালা ([[OpenPeriod::assertOpen()]]-এর দিনের সীমা) এখানে দেখা হয় না — ওটা খাতার
     * ইঞ্জিন নিজেই দেখে, উল্টানো আর আবার বসানোর মুখে, আর সুপার অ্যাডমিনের ওটা ডিঙানোর অনুমতি আছে।
     * এখানে কেবল মাসের তালা আর অর্থবছর: দুইটাই "ছাপা হয়ে যাওয়া হিসাব" রক্ষা করে।
     */
    public function assertPeriodOpen(Model&RevisableDocument $document): void
    {
        $date = $document->revisionDate();
        $no = $document->revisionNumber();

        if ($date === null) {
            throw ValidationException::withMessages(['edit' => __('revision.no_date', ['no' => $no])]);
        }

        /*
         * ⓘ প্রতিবার নতুন বস্তু — [[OpenPeriod]] এক অনুরোধে মাসের উত্তর জমিয়ে রাখে, আর একই অনুরোধে
         * মাস খোলা বা বন্ধ হলে জমানো উত্তরটা পুরনো হত। ⛔ ইনজেক্ট করা একটা বস্তু রাখলে ঠিক সেটাই হত।
         */
        $lock = app(OpenPeriod::class)->lockOn($date->format('Y-m-d'));

        if ($lock !== null) {
            throw ValidationException::withMessages([
                'edit' => __('revision.month_closed', ['no' => $no, 'month' => $lock->label()]),
            ]);
        }

        $year = FinancialYear::forDate($date->format('Y-m-d'));

        if ($year !== null && $year->is_closed) {
            throw ValidationException::withMessages([
                'edit' => __('revision.year_closed', ['no' => $no, 'year' => $year->name]),
            ]);
        }
    }
}
