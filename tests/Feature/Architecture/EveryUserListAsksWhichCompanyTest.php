<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Models\Company;
use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * যে তালিকা মানুষ দেখায়, সে কোন কোম্পানির তা জিজ্ঞেস করে।
 *
 * ── ⛔ কেন এই ফাইলটা আছে, ৬ সেপ্টেম্বর ২০২৬ ──────────────────────────
 * একটা গভীর নিরীক্ষায় **তিনটা প্রমাণিত টেন্যান্ট-ফাঁক** বেরিয়েছে, আর
 * তিনটাই **একই অনুপস্থিত লাইন**:
 *
 *     UserController          কোম্পানি ৪৬-এ দাঁড়িয়ে ৪৫-এর ব্যবহারকারী
 *     LoginHistoryController  অন্য কোম্পানির ৫০টা লগইনের সারি
 *     SalesTargetService      অন্য কোম্পানির বিক্রয়কর্মী স্কোরবোর্ডে
 *
 * ⚠️ আর সঠিক ছাঁচটা রিপোতে **পাঁচ জায়গায় আগে থেকেই ছিল** — CashTill,
 * MoneyTransfer, Location, CounterApproval, ApprovalFlow:
 *
 *     ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
 *
 * ⭐ অর্থাৎ এটা **নীতির অভাব নয়, পৌঁছানোর অভাব**। ⓘ আর ঠিক সেজন্যই
 * সারাই তিনটার চেয়ে এই পাহারাটা বেশি জরুরি: সারাই আজকের তিনটা বন্ধ
 * করে, পাহারা **চতুর্থটা জন্মাতে দেয় না**।
 *
 * ── কেন এই চারটা মডেল ────────────────────────────────────────────────
 * এগুলোর কোনোটাই [[BaseEntity]]-র গ্লোবাল স্কোপ পায় না — হয় তারা
 * `companies` পিভটে ঝোলে (User), নয় নিজের `company_id` রাখে
 * (LoginAttempt), নয় কোম্পানি-নিরপেক্ষ (Role, Company)। ⚠️ অর্থাৎ
 * ছাঁকনিটা **হাতে বসাতে হয়**, আর হাতের কাজ ভুলে যাওয়া যায়।
 *
 * ── ⚠️ CLAUDE.md-তে এটা রুচির কথা নয় ────────────────────────────────
 * *"বহু-টেন্যান্ট বলেই টেন্যান্ট বিচ্ছিন্নতা সুবিধা নয়, **আইনি
 * বাধ্যবাধকতা**।"*
 */
class EveryUserListAsksWhichCompanyTest extends TestCase
{
    /**
     * যেসব মডেলের তালিকা কোম্পানি ধরে ছাঁকতেই হবে।
     *
     * ⓘ মানটা কেবল পড়ার জন্য — কোন ছাঁকনিটা ঐ মডেলে সঠিক।
     *
     * @var array<class-string, string>
     */
    private const MUST_ASK = [
        User::class => "whereHas('companies')",
        LoginAttempt::class => "where('company_id')",
    ];

    /**
     * ⚠️ কেবল যেখানে **মানুষ একটা তালিকা দেখে** — কন্ট্রোলার ও ড্যাশবোর্ড।
     *
     * ── ⛔ কেন সীমানাটা সংকুচিত ──────────────────────────────────────
     * প্রথম খসড়ায় পুরো `app/` দেখা হত, আর সে **৪০টা জায়গা** দেখাল।
     * ⓘ তার বেশিরভাগই সৎ:
     *
     *     কনসোল কমান্ড      ইচ্ছে করেই সব কোম্পানি ঘোরে (BooksCheck, SyncChart)
     *     CredentialCheck   লগইনের আগে, তখন কোম্পানিই বাছা হয়নি
     *     LoginLock         পরিচয় ধরে তালা — বিশ্বজনীন হওয়াই নিরাপত্তা
     *     PermissionSyncer  রোল আজ কোম্পানি-নিরপেক্ষ (spatie teams বন্ধ)
     *
     * ⚠️ **যে পাহারা ৪০টা নাম দেয়, মানুষ তাতে ছাড় যোগ করতে শেখে** — আর
     * তখন ছাড়ের তালিকাটাই পাহারার জায়গা নিয়ে নেয়।
     *
     * ⭐ আসল ঝুঁকিটা সংকীর্ণ: **একজন ব্যবহারকারীকে অন্যদের তালিকা দেখানো
     * হচ্ছে**। সেটা ঘটে কন্ট্রোলারে আর ড্যাশবোর্ডে, কনসোলে নয়।
     *
     * ⓘ `Role` ও `Company` ইচ্ছে করে বাদ: আজ ওগুলো সত্যিই কোম্পানি-নিরপেক্ষ।
     * ⚠️ spatie teams চালু হলে (মালিকের অনুমোদিত ধাপ ৩) `Role` এখানে
     * **যোগ করতে হবে** — কথাটা এখানে লেখা রইল যাতে ভুলে না যাই।
     *
     * @var list<string>
     */
    private const WHERE_PEOPLE_LOOK = [
        'Http/Controllers',
        'Dashboard',
    ];

    /**
     * ⚠️ ছাড় — প্রতিটার পাশে **কারণ**, আর কারণটাই শর্ত।
     *
     * ⛔ কারণ ছাড়া ছাড় মানে ছয় মাস পরে কেউ নিজের ফাইলটার নাম এখানে
     * যোগ করে দেবেন, আর পাহারাটা অলংকার হয়ে যাবে। ⓘ নাম যোগ করা সহজ;
     * একটা সৎ কারণ লেখা কঠিন — সেই কাঠিন্যটাই এখানে পাহারা।
     *
     * @var array<string, string>
     */
    private const EXEMPT = [
        'app/Http/Controllers/Auth/LoginController.php' =>
            'লগইনের মুহূর্তে কোনো কোম্পানি বাছাই হয়নি — ছাঁকনির প্রসঙ্গই নেই',

        'app/Http/Controllers/WorkspaceController.php' =>
            'কোম্পানি সুইচার — ব্যবহারকারীর **নিজের** কোম্পানিগুলো দেখায়, '
            .'আর `switchCompany()` নিজে অধিকার যাচাই করে',

        'app/Modules/Approval/Http/Controllers/ApprovalInboxController.php' =>
            'একটাই `findOrFail($chosen)`, আর তার **আগেই** '
            .'`abort_unless(isset($signers[$chosen]), 404)` — আর `theSigners()` '
            .'কোম্পানি ধরে ছাঁকে (whereHas companies)। ⓘ অর্থাৎ id-টা একটা '
            .'কোম্পানি-স্কোপড তালিকার সাথে মিলিয়ে দেখা হয়, তারপর তোলা হয়। '
            .'⚠️ যাচাই করা হয়েছে কোড পড়ে, মন্তব্য পড়ে নয়।',

        'app/Console/Commands/SyncPermissions.php' =>
            'রোল ও পারমিশন আজ কোম্পানি-নিরপেক্ষ (spatie teams বন্ধ); '
            .'⚠️ teams চালু হলে এই ছাড়টা **তুলে ফেলতে হবে**',
    ];

    /**
     * ছাঁকনিবিহীন `Model::query()` খোঁজা।
     *
     * ⚠️ পুরো কোয়েরিটা এক লাইনে থাকে না, তাই খোঁজাটা লাইন ধরে নয় —
     * `::query()`-এর পর থেকে বাক্যের শেষ (`;`) পর্যন্ত টুকরোটা দেখা হয়।
     * ⓘ প্রথম চেষ্টায় লাইন ধরে খুঁজেছিলাম আর প্রতিটা multi-line কোয়েরি
     * **ছাঁকনিহীন মনে হত** — একটা পাহারা যা সবাইকে দোষী বলে, সে কাউকে
     * দোষী বলে না।
     */
    public function test_no_user_list_forgets_which_company_it_is_in(): void
    {
        $offenders = [];

        foreach (File::allFiles(base_path('app')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $relative = 'app/'.$relative;

            if (array_key_exists($relative, self::EXEMPT)) {
                continue;
            }

            $looked = false;

            foreach (self::WHERE_PEOPLE_LOOK as $where) {
                if (str_contains($relative, $where)) {
                    $looked = true;
                }
            }

            if (! $looked) {
                continue;
            }

            $code = $file->getContents();

            foreach (self::MUST_ASK as $model => $filter) {
                $short = class_basename($model);

                if (! str_contains($code, $short.'::query()')) {
                    continue;
                }

                foreach ($this->statementsAfter($code, $short.'::query()') as $statement) {
                    if ($this->asksWhichCompany($statement)) {
                        continue;
                    }

                    $offenders[] = $relative.' → '.$short.'::query()  ('.$filter.' লাগে)';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "এই তালিকাগুলো কোন কোম্পানির তা বলে না।\n"
            ."এক কোম্পানির ব্যবহারকারী আরেক কোম্পানির সারি দেখতে পাবেন।\n\n"
            ."সঠিক ছাঁচটা রিপোতে আগে থেকেই আছে:\n"
            ."  ->whereHas('companies', fn (\$q) => \$q->whereKey(CompanyContext::id()))\n"
            ."  ->where('company_id', CompanyContext::id())\n\n"
            .'সত্যিই ছাঁকনির দরকার না থাকলে EXEMPT-এ **কারণসহ** লিখুন।',
        );
    }

    /**
     * ⓘ পাহারাটা অন্ধ নয় — তালিকাটা সত্যিই কিছু দেখে কি না।
     *
     * ⚠️ আজ চারটা মডেলই ছাঁকা হয়ে গেলে উপরের দাবিটা চিরকাল সবুজ থাকত,
     * এমনকি খোঁজার কোডটা ভেঙে গেলেও। ⛔ এই দাবিটা নিশ্চিত করে যে
     * `MUST_ASK`-এর প্রতিটা মডেল অন্তত একটা ফাইলে সত্যিই ব্যবহার হয় —
     * নাহলে পাহারাটা **শূন্য জায়গা পাহারা দিচ্ছে**।
     */
    public function test_the_guard_is_actually_watching_something(): void
    {
        $seen = [];

        foreach (File::allFiles(base_path('app')) as $file) {
            $code = $file->getExtension() === 'php' ? $file->getContents() : '';

            foreach (array_keys(self::MUST_ASK) as $model) {
                if (str_contains($code, class_basename($model).'::query()')) {
                    $seen[$model] = true;
                }
            }
        }

        $unwatched = array_diff(array_keys(self::MUST_ASK), array_keys($seen));

        $this->assertSame(
            [],
            array_values($unwatched),
            'এই মডেলগুলোর কোনো `::query()` কোথাও নেই — পাহারাটা শূন্য জায়গা '
            .'পাহারা দিচ্ছে। তালিকা থেকে বাদ দিন, নয়তো বুঝুন কেন উধাও হলো।',
        );
    }

    /**
     * `::query()`-এর পর থেকে বাক্যের শেষ পর্যন্ত টুকরোগুলো।
     *
     * @return list<string>
     */
    private function statementsAfter(string $code, string $needle): array
    {
        /*
         * ⛔ আগে মন্তব্য ছাঁটা হত না — আর গার্ডটা **মিথ্যা লাল** দিত।
         *
         * ⓘ প্রথম চেষ্টায় `::query()` থেকে প্রথম `;` পর্যন্ত টুকরো নেওয়া হত।
         * ⚠️ কিন্তু কোয়েরির মাঝখানে একটা ব্লক-মন্তব্য থাকলে, আর তার ভিতরে
         * একটা `;` থাকলে (বাংলা লেখায় সহজেই থাকে), টুকরোটা **ওখানেই থেমে
         * যেত** — আর তার নিচের `whereHas()` কখনো দেখাই হত না।
         *
         * ⛔ ধরা পড়েছে নিজের সারাইয়ের উপরেই: `UserController` ছাঁকা ছিল,
         * তবু গার্ড তাকে দোষী বলছিল। ⓘ **যে পাহারা সারানো কোডকে দোষী বলে,
         * সে কিছুদিনে অগ্রাহ্য হয়ে যায়** — আর তখন আসল দোষটাও তার সাথে
         * অগ্রাহ্য হয়।
         *
         * ⭐ তাই মন্তব্যগুলো আগে তুলে ফেলা হয়, তারপর খোঁজা।
         */
        $code = preg_replace('#/\*.*?\*/#s', '', $code) ?? $code;
        $code = preg_replace('#//[^
]*#', '', $code) ?? $code;

        $out = [];
        $from = 0;

        while (($at = strpos($code, $needle, $from)) !== false) {
            $end = strpos($code, ';', $at);
            $out[] = substr($code, $at, $end === false ? 400 : $end - $at);
            $from = $at + strlen($needle);
        }

        return $out;
    }

    /**
     * ⓘ যেকোনো একটা সৎ ছাঁকনি থাকলেই চলে।
     *
     * ⚠️ `firstOrFail()`/`find()` প্রাথমিক কী ধরে — ওগুলো তালিকা নয়,
     * একটা সারি, আর সেখানে অধিকারের প্রশ্নটা আলাদা (policy/404)।
     */
    private function asksWhichCompany(string $statement): bool
    {
        foreach ([
            "whereHas('companies'",
            "where('company_id'",
            'whereKey(',
            'CompanyContext::id()',
            'currentCompany',
        ] as $sign) {
            if (str_contains($statement, $sign)) {
                return true;
            }
        }

        return false;
    }
}
