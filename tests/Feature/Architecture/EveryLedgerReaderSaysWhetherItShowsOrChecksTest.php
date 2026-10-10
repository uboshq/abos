<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * খাতা পড়ে এমন প্রতিটা ফাইল বলে দেয়: সে **দেখায়**, নাকি **যাচাই করে** — ২৯ সেপ্টেম্বর ২০২৬।
 *
 * ── কেন ─────────────────────────────────────────────────────────────────
 * মালিকের নির্দেশ: হেডারে শাখা বাছলে সব জায়গায় কেবল সেই শাখার ডাটা। ⛔ কিন্তু
 * খাতার সব পাঠে শাখা-ছাঁকনি বসালে টাকার নিয়মগুলো ভুল সিদ্ধান্ত নিত — বাকির
 * সীমা অন্য শাখার বকেয়া না গুনে পার হতে দিত, টিল শূন্যের নিচে নামত, খাতার
 * সিল অর্ধেক সারি দেখে "অক্ষত" বলত।
 *
 * ⭐ তাই দুই তালিকা:
 *   SHOWS  — পর্দা, ড্যাশবোর্ড, ফোনের দেখানো: **দেখার শাখা** মানবে
 *            ([[DataScope::viewBranchIds()]] / [[DataScope::viewsOneBranch()]])
 *   CHECKS — যাচাই, পোস্টিং, অখণ্ডতা, মাইগ্রেশন আর মূল উপকরণ: **গোটা কোম্পানি**,
 *            দেখার শাখা কখনো নয়
 *
 * ⛔ নতুন কোনো ফাইল খাতা পড়তে শুরু করলে তাকে এখানে উঠতে হয় — নইলে লাল। সিদ্ধান্তটা
 * এড়ানো যায় না, আর সেটাই এই দাবির কাজ।
 *
 * ⓘ রিপোর্টগুলো (`/Reports/`) এখানে নেই: তাদের পাহারা [[ReportEngine::branchWall()]]
 * আর [[EveryReportStandsBehindTheBranchWallTest]]।
 */
final class EveryLedgerReaderSaysWhetherItShowsOrChecksTest extends TestCase
{
    // ⓘ `balanceInView()` — দেখার জের, ভেতরে খাতা পড়ে ([[Account::balanceInView()]], অডিট ⛔১১, ৬ অক্টোবর ২০২৬)
    private const PATTERN = '/LedgerEntry::query|table\(\'ledger_entries|LedgerBalances|->balanceOn\(|->balanceInView\(/';

    /** @var array<string, string> দেখায় — দেখার শাখা মানবে (ধাপ খ) */
    private const SHOWS = [
        'app/Modules/Accounts/Dashboard/AccountsWidgets.php' => 'ড্যাশবোর্ডের টাকার ঘর',
        'app/Modules/Customer/Support/CustomerListFilters.php' => 'গ্রাহক তালিকার বকেয়া ও অগ্রিমের ছাঁকনি — দেখার শাখা ধরে',
        'app/Modules/Accounts/Http/Controllers/CashTillController.php' => 'টিলের তালিকা আর জের',
        'app/Modules/Accounts/Http/Controllers/ChartOfAccountsController.php' => 'খাতের তালিকার জের',
        'app/Modules/Accounts/Http/Controllers/FinanceControlController.php' => 'অর্থ-নিয়ন্ত্রণের পর্দা',
        'app/Modules/Accounts/Http/Controllers/MoneyCustodyController.php' => 'টাকা কার হাতে — পর্দা',
        'app/Modules/Accounts/Http/Controllers/VoucherListController.php' => 'ভাউচারের তালিকার অঙ্ক',
        'app/Modules/Accounts/Resources/views/coa/show.blade.php' => 'একটা খাতের পাতা',
        'app/Modules/Accounts/Services/AccountsFacts.php' => 'হিসাবের পর্দার তথ্য',
        'app/Modules/Accounts/Services/BalanceSheetService.php' => 'স্থিতিপত্রের পর্দা',
        'app/Modules/Accounts/Services/GroupLedgerService.php' => 'দলগত খতিয়ানের পর্দা',
        'app/Modules/Customer/Http/Controllers/CustomerController.php' => 'গ্রাহকের পাতার বকেয়া (সীমার পাশে গোটা কোম্পানির বকেয়াও — সমন্বয়কারীর সিদ্ধান্ত)',
        'app/Modules/Customer/Services/CustomerMetrics.php' => 'গ্রাহকের পাতার অঙ্ক',
        'app/Modules/Finance/Http/Controllers/InstitutionController.php' => 'প্রতিষ্ঠানের পাতা',
        'app/Modules/Finance/Services/AccountAnalysis.php' => 'খাত-বিশ্লেষণের পর্দা',
        'app/Modules/Finance/Services/BudgetService.php' => 'বাজেট বনাম আসল — পর্দা',
        'app/Modules/Finance/Services/CarrierAndLabourLedger.php' => 'বাহক আর শ্রমিকের খতিয়ান — পর্দা',
        'app/Modules/Finance/Services/CfoFigures.php' => 'অর্থ-প্রধানের সংখ্যা — পর্দা',
        'app/Modules/Finance/Services/HeadTotals.php' => 'মাথাভিত্তিক যোগফল — পর্দা',
        'app/Modules/Purchase/Http/Controllers/PurchaseApiController.php' => 'ফোনের প্রিন্সিপালের খাতা — ওয়েবের সরবরাহকারীর পাতার একই সারি, দেখার শাখায় (ViewedBranch::narrow)',
        'app/Modules/Finance/Services/InstitutionPosition.php' => 'প্রতিষ্ঠানের এক পাতা — জোড়া হিসাবের জের, হেডারে বাছা শাখায় (ViewedBranch::one())',
        'app/Modules/Inventory/Services/StockFacts.php' => 'মজুদের চার্টের টাকা — ঢোকা আর বেরোনো, কেনা দরে',
        'app/Modules/Sales/Services/RouteMetrics.php' => 'রুটের পর্দার অঙ্ক',
        'app/Modules/Sales/Services/SalesCustomerTrade.php' => 'গ্রাহকের কেনাবেচার পর্দা',
        'app/Modules/Supplier/Dashboard/SupplierWidgets.php' => 'ড্যাশবোর্ডের পাওনা',
        'app/Modules/Supplier/Http/Controllers/SupplierController.php' => 'সরবরাহকারীর পাতার পাওনা',
    ];

    /** @var array<string, string> যাচাই করে — গোটা কোম্পানি, দেখার শাখা কখনো নয় */
    private const CHECKS = [
        'app/Modules/Accounts/Database/Migrations/2027_02_18_100000_an_account_says_which_parties_it_holds.php' => 'কোন খাতে আজ পক্ষসহ সারি আছে — খাত কোন পক্ষ রাখে তা আজকের খাতা থেকে; গোটা কোম্পানি, শাখা নয় (অডিট হিসাব ⚠️১২, ৭ অক্টোবর ২০২৬)',
        'app/Modules/Hr/Services/PayrollService.php' => '⛔ বাতিলের আগে বেতন-দেনা কতটা পরিশোধ হয়েছে — রান যত বসিয়েছিল আর খাতায় যত বাকি; গোটা কোম্পানি ধরে, কারণ দেনা এক খাতে আর রান গোটা কোম্পানির (অডিট HR ⚠️৬, ৬ অক্টোবর ২০২৬)',
        'app/Modules/Hr/Support/AdvanceBalance.php' => '⛔ কর্মীর খোলা অগ্রিম — বেতন থেকে কাটার সীমা আর খরচের দাবি মেটানো; কর্মীর নামে, গোটা কোম্পানি ধরে; অগ্রিম মানুষের, শাখার নয় (অডিট HR ⛔৪, ৬ অক্টোবর; মালিকের আদেশ ৭ অক্টোবর ২০২৬)',
        'app/Modules/Accounts/Services/MoneyTransferService.php' => 'স্থানান্তরের আগে বাক্সের সবচেয়ে কম জের — নিজের খাত, গোটা কোম্পানি ধরে; দেখার শাখা খাটলে অন্য শাখার দাখিলা বাদ পড়ে জের ভুল হত (অডিট ম৯, ৫ অক্টোবর ২০২৬)',
        'app/Modules/Finance/Services/OwnerCapital.php' => 'মালিকের শুরুর মূলধন — রেজিস্টার আর ৩১০০-এর জের মেলানো, গোটা কোম্পানি ধরে, শাখা আলাদা করে; দেখার শাখা খাটলে অন্য শাখার ফাঁক অদৃশ্য হত (৫ অক্টোবর ২০২৬)',
        'app/Core/Engines/Posting/PostingEngine.php' => 'খাতায় লেখা — পোস্টিং',
        'app/Core/Security/LedgerChain.php' => 'খাতার সিল — প্রতিটা সারি দেখতেই হয়',
        'app/Console/Commands/HandLoanParty.php' => 'পুরনো হাতধারের ভাউচারে নাম বসানো — একটা কাগজের নিজের খোলা দাখিলা উল্টে আবার বসায়, গোটা কোম্পানি ধরে; দেখার শাখা খাটলে অন্য শাখার সারি বাদ পড়ত (৫ অক্টোবর ২০২৬)',
        'app/Core/Services/RevisionKeeper.php' => 'সংশোধনের আগে-পরের ছবি — একটা কাগজের নিজের খোলা দাখিলা, গোটা কোম্পানি ধরে; দেখার শাখা খাটলে অন্য শাখার সারি "আগে" থেকে বাদ পড়ত (৩ অক্টোবর ২০২৬)',
        'app/Core/Services/LedgerBalances.php' => 'মূল উপকরণ — ডাকার জন ঠিক করে শাখা',
        'app/Models/Company.php' => 'কোম্পানিতে পোস্টিং আছে কি না',
        'app/Modules/Accounts/Services/NoteAccounts.php' => 'নোটের চলতি খাত — পক্ষের খাতা কোন খাতে আছে, গোটা কোম্পানি ধরে (দেখানো নয়, অনুমতির তালিকা)',
        'app/Providers/AppServiceProvider.php' => 'LedgerBalances-এর বাঁধন',
        'app/Modules/Accounts/Database/Migrations/2026_09_09_100000_the_delivery_cost_had_nowhere_to_land.php' => 'তথ্য-সারাইয়ের মাইগ্রেশন',
        'app/Modules/Accounts/Database/Migrations/2026_10_24_100000_one_payable_head_held_three_different_debts.php' => 'তথ্য-সারাইয়ের মাইগ্রেশন',
        'app/Modules/Accounts/Integrity/AccountsChecks.php' => 'অখণ্ডতার পরীক্ষা',
        'app/Modules/Accounts/Services/BankReconciliationService.php' => 'ব্যাংক মেলানো — খাতের পুরো জের, গোটা কোম্পানি ধরে (অডিট গ৬, ৪ অক্টোবর ২০২৬)',
        'app/Modules/Accounts/Models/Account.php' => 'মূল উপকরণ — balanceOn() শাখা নেয়, ডাকার জন ঠিক করে',
        'app/Modules/Accounts/Models/CashTill.php' => 'টিলের জের — টাকা বেরোনোর যাচাইয়ে',
        'app/Modules/Accounts/Models/Loan.php' => 'ঋণের বাকি — কিস্তির নিয়মে',
        'app/Modules/Accounts/Services/CashOnHand.php' => 'টিল শূন্যের নিচে নয়',
        'app/Modules/Accounts/Services/ChequeService.php' => 'চেকের পোস্টিং',
        'app/Modules/Accounts/Services/MonthEndChecklist.php' => 'মাস-শেষের অখণ্ডতা',
        'app/Modules/Accounts/Services/OpeningBalanceService.php' => 'খোলা জের — পোস্টিং',
        'app/Modules/Accounts/Services/YearEndService.php' => 'বছর বন্ধ — পোস্টিং',
        'app/Modules/Customer/Models/Customer.php' => 'মূল উপকরণ — বকেয়া (বাকির সীমা এখান থেকে পড়ে)',
        'app/Modules/Finance/Services/BankCharges.php' => 'ব্যাংক চার্জের পোস্টিং',
        'app/Modules/Finance/Services/BankFacilityService.php' => 'ব্যাংক সুবিধার সীমা',
        'app/Modules/Finance/Services/ProfitDistribution.php' => 'লাভ বণ্টন — পোস্টিং',
        'app/Modules/Customer/Sync/CustomerDueSync.php' => '⛔ ফোনের বকেয়া বাকির সীমার পাশে বসে — বিক্রয়কর্মী সীমা মেনে অর্ডার নেন; হেডারের শাখা ওয়েবের ধারণা, আর জলচিহ্ন শাখা বদলালে ভাঙত (৩০ সেপ্টেম্বর)',
        'app/Modules/Sales/Services/CustomerPapers.php' => '⛔ গ্রাহক-পোর্টাল: দেখছেন গ্রাহক নিজে, কর্মী নয় — পুরো পক্ষের খাতাই নিরাপত্তার নকশা; হেডারের শাখা এখানে নেই (৩০ সেপ্টেম্বর)',
        'app/Modules/Promotion/Services/PromotionReversal.php' => 'উপহার ফেরতে — উপহারের খরচ খাতায় উঠেছিল কি না, তবেই উল্টানো (৪ অক্টোবর ২০২৬); একটা কাগজের নিজের সারি, শাখা নয়',
        'app/Modules/Sales/Services/CreditExposure.php' => '⛔ বাকির সীমা — মালিকের "সীমা পরম", গোটা কোম্পানি',
        'app/Modules/Sales/Services/CustomerTargetService.php' => '⛔ ডিলারের মাসের লক্ষ্য — টাকা আদায় ডিলার ধরে (মালিক, ২ অক্টোবর); হেডারের শাখা বদলালে অর্জন বদলাত, অথচ ডিলারের লক্ষ্য একটাই',
        'app/Modules/Sales/Http/Controllers/SalesPrintController.php' => '⛔ ছাপা বিলে ডিলারের মাসের খাতা আর লক্ষ্য — কাগজ যায় ডিলারের হাতে; কে কোন শাখা বেছে ছাপলেন তাতে বিবরণী বদলালে কাগজটাই মিথ্যা হত (৩ অক্টোবর)',
        'app/Modules/Sales/Services/DeliveryChallanService.php' => 'চালানে বাকির দেয়াল',
        'app/Modules/Sales/Services/SalesReturnService.php' => 'ফেরতের পোস্টিং',
        'app/Modules/Sales/Services/ShiftService.php' => 'কাউন্টারের পালার নগদ মেলানো',
        'app/Modules/Supplier/Models/Supplier.php' => 'মূল উপকরণ — পাওনা',
    ];

    public function test_every_ledger_reader_is_declared_and_no_declaration_is_stale(): void
    {
        $found = $this->readers();
        $declared = array_merge(array_keys(self::SHOWS), array_keys(self::CHECKS));

        $this->assertGreaterThan(40, count($found), 'খোঁজাটাই ভেঙেছে — খাতা পড়া ফাইল মাত্র '.count($found).'টা।');

        $undeclared = array_values(array_diff($found, $declared));
        $stale = array_values(array_diff($declared, $found));

        $this->assertSame([], $undeclared, implode("\n", [
            'এই ফাইলগুলো খাতা পড়ে, অথচ বলেনি দেখায় না যাচাই করে:', '', ...$undeclared, '',
            'SHOWS (দেখার শাখা মানবে) বা CHECKS (গোটা কোম্পানি) — একটায় কারণসহ তুলুন।',
        ]));
        $this->assertSame([], $stale, "তালিকায় আছে অথচ আর খাতা পড়ে না:\n".implode("\n", $stale));
    }

    /**
     * দেখায়, কিন্তু ইচ্ছা করে গোটা কোম্পানি — সিদ্ধান্তটা এখানে, কারণসহ।
     *
     * @var array<string, string>
     */
    private const WHOLE_BY_DECISION = [
        'app/Modules/Accounts/Services/GroupLedgerService.php' => 'কয়েক কোম্পানির একসাথে লাভ-ক্ষতি — হেডারের শাখার আইডি কেবল চলতি কোম্পানির, ছাঁকলে বাকি কোম্পানিগুলো শূন্য হত; তবে প্রতিটা কোম্পানিতে মানুষটার নিজের শাখার নাগাল খাটে (পুনঃঅডিট, ৯ অক্টোবর ২০২৬)',
        'app/Modules/Finance/Services/BudgetService.php' => 'বাজেটে শাখা নেই (`fin_budgets`) — এক শাখার খরচ গোটা কোম্পানির বাজেটের পাশে বসলে তুলনাটাই মিথ্যা হত',
    ];

    /**
     * ⭐ ধাপ খ (৩০ সেপ্টেম্বর ২০২৬): প্রতিটা দেখানো পাঠক সত্যিই দেখার শাখা মানে।
     *
     * ⛔ ২৯ সেপ্টেম্বরে তালিকাটা বানানো হয়েছিল, কিন্তু কেউ মাপেনি তালিকার ফাইলগুলো শাখা
     * মানে কি না — ২৪টার ২১টা মানত না, আর মালিক "ময়মনসিংহ" বেছে অন্য শাখার টাকা দেখতেন।
     *
     * ⓘ মাপ: মন্তব্য বাদে কোডে দেখার শাখার কোনো দরজা ডাকা হয় কি না —
     * [[ViewedBranch]], [[DataScope::viewBranchIds()]], [[DataScope::viewsOneBranch()]] বা
     * `inView()`। ⚠️ ডাকা মানেই ঠিক নয়, কিন্তু না-ডাকা মানে নিশ্চিত ভুল; ঠিক-ভুল মাপে
     * [[OneBranchPickedShowsOnlyThatBranchsMoneyTest]]।
     */
    public function test_every_shows_reader_narrows_to_the_viewed_branch(): void
    {
        $blind = [];

        foreach (array_keys(self::SHOWS) as $path) {
            if (array_key_exists($path, self::WHOLE_BY_DECISION)) {
                continue;
            }

            $code = $this->codeOf(base_path($path));

            // ⓘ `balanceInView()` আর `CashTill::visibleBranchIds()` নিজেরাই দেখার শাখা আর নাগাল মানে (DataScope-এর ভেতরে)
            if (preg_match('/ViewedBranch::|viewBranchIds\(|viewsOneBranch\(|->inView\(|->balanceInView\(|visibleBranchIds\(/', $code) !== 1) {
                $blind[] = $path;
            }
        }

        $this->assertSame([], $blind, implode("\n", [
            'এই পর্দাগুলো খাতা দেখায়, অথচ হেডারে বাছা শাখা মানে না:', '', ...$blind, '',
            'ViewedBranch::narrow()/one() দিয়ে ছাঁকুন — বা কারণসহ WHOLE_BY_DECISION-এ তুলুন।',
        ]));
        $this->assertSame([], array_values(array_diff(array_keys(self::WHOLE_BY_DECISION), array_keys(self::SHOWS))),
            'WHOLE_BY_DECISION-এর ফাইল SHOWS-এ নেই — বাসি সিদ্ধান্ত।');
    }

    public function test_no_file_is_both(): void
    {
        $this->assertSame([], array_values(array_intersect(array_keys(self::SHOWS), array_keys(self::CHECKS))));
    }

    /** @return list<string> */
    private function readers(): array
    {
        $root = base_path();
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/app', \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            if (! str_ends_with($path, '.php') || str_contains($path, '/Reports/') || str_contains($path, 'Engines/Report/')) {
                continue;
            }

            // ⚠️ মন্তব্য বাদে — মন্তব্যে কোনো নাম লিখলেই ফাইলটা "পাঠক" হয়ে যেত
            if (preg_match(self::PATTERN, $this->codeOf($file->getPathname())) === 1) {
                $found[] = $path;
            }
        }

        sort($found);

        return $found;
    }

    /** ফাইলের কোড, মন্তব্য ছাড়া। */
    private function codeOf(string $file): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
