<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Modules\Accounts\Models\Account;
use Illuminate\Support\Facades\DB;

/**
 * নতুন কোম্পানির প্রমিত হিসাব-ছক।
 *
 * কেন কোডে, ডাটাবেজ সিডারে নয়: ছকটা প্রতিটা নতুন কোম্পানির জন্য বসাতে
 * হবে — ডেমো ডাটার সাথে নয়, কোম্পানি তৈরির সাথে। সিডারে রাখলে আসল
 * গ্রাহকের কোম্পানি খালি ছক নিয়ে শুরু করত, আর তখন প্রথম বিক্রয়টাই
 * "প্রাপ্য হিসাব" খুঁজে না পেয়ে আটকে যেত।
 *
 * ছকটা বাংলাদেশের পরিবেশক ও খুচরা ব্যবসার জন্য — ভ্যাট, MFS, দোকান ভাড়া,
 * জ্বালানি। সব কোম্পানির সব খাত লাগবে না, আর যেগুলো লাগবে না সেগুলো
 * নিষ্ক্রিয় করা যায়। কম দিয়ে শুরু করে প্রতিটা কোম্পানিকে নিজের ছক
 * বানাতে বলার চেয়ে এটা ভালো: হিসাবের ছক ভুল হলে বাকি সব ভুল হয়, আর
 * ছোট ব্যবসায় সেটা ধরার মতো কেউ থাকে না।
 *
 * SYSTEM চিহ্নিত খাতগুলো কোড ধরে খোঁজা হয় — বিক্রয় পোস্ট করার সময়
 * "প্রাপ্য হিসাব" কোথায় বসবে সেটা নাম দেখে নয়, কোড দেখে ঠিক হয়।
 */
final class StandardChart
{
    /** যে খাতগুলো অন্য মডিউল কোড ধরে খোঁজে — মুছা বা ধরন বদলানো যায় না। */
    public const CASH_IN_HAND = '1101';

    public const BANK_AND_MFS = '1102';

    public const RECEIVABLE = '1110';

    public const INVENTORY = '1120';

    public const PAYABLE = '2110';

    public const VAT_PAYABLE = '2120';

    /**
     * প্রাপ্ত মাল, বিল আসেনি।
     *
     * ট্রাক আসে সোমবার, বিল আসে বৃহস্পতিবার। ওই তিন দিন মালটা গুদামে আছে —
     * বেচা যাচ্ছে, তার দাম আছে — অথচ কাগজে কোনো দায় নেই। বিলের দিনে হিসাব
     * বসালে ওই তিন দিন ব্যালেন্স শিট মিথ্যা বলত: সম্পদ বেড়েছে, দায় বাড়েনি,
     * আর পার্থক্যটা নীরবে মুনাফা হয়ে দেখাত।
     *
     * তাই মাল বুঝে নেওয়ার দিনই দায়টা এখানে বসে, আর বিল এলে এখান থেকে সরে
     * সরবরাহকারীর নামে যায়। খাতটা শূন্য না হলে হয় বিল বাকি, নয় কেউ বিল
     * ছাড়াই মাল নামিয়েছে — দুটোই জানা দরকার।
     */
    public const GOODS_RECEIVED_NOT_INVOICED = '2160';

    public const RETAINED_EARNINGS = '3300';

    public const SALES = '4100';

    public const COST_OF_GOODS_SOLD = '5100';

    /**
     * ক্রয়মূল্যের পার্থক্য।
     *
     * সরবরাহকারী প্রায়ই চালানের চেয়ে অন্য দরে বিল পাঠান। মাল নেওয়ার দিন
     * মজুদে যে দাম বসেছিল সেটাই মালের আসল দাম — বিলের দর আলাদা হলে
     * পার্থক্যটা মজুদে ঢোকানো যায় না, নাহলে গুদামের একই মালের দুই রকম
     * দাম হয়ে যেত।
     *
     * তাই পার্থক্যটা খরচ, আর আলাদা খাতে — খাতটা দেখলেই বোঝা যায় কোন
     * সরবরাহকারী দর নিয়ে নিয়মিত এদিক-ওদিক করছেন।
     */
    public const PURCHASE_PRICE_VARIANCE = '5150';

    public const DISCOUNT_GIVEN = '5300';

    /*
     * বেতনের দুইটা খাত — খরচ ও দায়।
     *
     * ধ্রুবক হিসেবে রাখা, কারণ বেতনের রান কোড ধরে এগুলো খোঁজে: যে
     * খাতে হিসাব-খাত বসানো নেই তার আয় এখানে আর কর্তন ওখানে যায়।
     * নাম ধরে খুঁজলে "Salary & Wages" নাম বদলানোর দিনে বেতন পোস্ট
     * করা বন্ধ হয়ে যেত।
     */
    public const SALARY_EXPENSE = '5201';

    public const SALARY_PAYABLE = '2130';

    public const PROVIDENT_FUND_PAYABLE = '2131';

    public const EMPLOYEE_ADVANCE = '1131';

    /** @var list<string> */
    public const SYSTEM_CODES = [
        self::CASH_IN_HAND, self::BANK_AND_MFS, self::RECEIVABLE, self::INVENTORY,
        self::PAYABLE, self::VAT_PAYABLE, self::GOODS_RECEIVED_NOT_INVOICED,
        self::RETAINED_EARNINGS,
        self::SALES, self::COST_OF_GOODS_SOLD, self::PURCHASE_PRICE_VARIANCE,
        self::DISCOUNT_GIVEN,
        self::SALARY_EXPENSE, self::SALARY_PAYABLE,
        self::PROVIDENT_FUND_PAYABLE, self::EMPLOYEE_ADVANCE,
    ];

    public function __construct(private readonly AccountService $accounts) {}

    /**
     * ছকটা বসানো। আগে বসানো থাকলে কিছুই হয় না।
     *
     * @return int কতগুলো খাত তৈরি হল
     */
    public function install(): int
    {
        $this->accounts->assertCompanyContext();

        if (Account::query()->exists()) {
            return 0;
        }

        return DB::transaction(function () {
            $created = 0;
            $byCode = [];

            foreach ($this->definition() as $row) {
                [$code, $en, $bn, $type, $parentCode, $isGroup, $flags] = $row;

                $account = $this->accounts->create([
                    'code' => $code,
                    'name_en' => $en,
                    'name_bn' => $bn,
                    'type' => $type,
                    'parent_id' => $parentCode === null ? null : $byCode[$parentCode]->id,
                    'is_group' => $isGroup,
                    'is_cash' => (bool) ($flags['cash'] ?? false),
                    'is_bank' => (bool) ($flags['bank'] ?? false),
                    'nature' => $flags['nature'] ?? Account::defaultNatureFor($type),
                ]);

                if (in_array($code, self::SYSTEM_CODES, true)) {
                    $this->accounts->markAsSystem($account);
                }

                $byCode[$code] = $account;
                $created++;
            }

            return $created;
        });
    }

    /** কোড দিয়ে একটা খাত — না পেলে null, কারণ কোম্পানি ছকটা বদলাতে পারে। */
    public static function find(string $code): ?Account
    {
        return Account::query()->where('code', $code)->first();
    }

    /**
     * ছকটা নিজে।
     *
     * ক্রম গুরুত্বপূর্ণ: বাবা সবসময় সন্তানের আগে, কারণ parent_id
     * আগেরগুলো থেকে খোঁজা হয়।
     *
     * @return list<array{0:string,1:string,2:string,3:string,4:?string,5:bool,6:array<string,mixed>}>
     */
    private function definition(): array
    {
        $A = Account::ASSET;
        $L = Account::LIABILITY;
        $E = Account::EQUITY;
        $I = Account::INCOME;
        $X = Account::EXPENSE;

        return [
            // ── সম্পদ ─────────────────────────────────────────────────
            ['1000', 'Assets', 'সম্পদ', $A, null, true, []],
            ['1100', 'Current Assets', 'চলতি সম্পদ', $A, '1000', true, []],

            // নগদ একটা মাথা, একটা খাত নয়: প্রতিটা মানুষের নিজের ক্যাশ
            // থাকে (নগদ কাউন্টার), আর কার হাতে কত তা আলাদা করে জানা
            // দরকার। CashTill প্রতিটা টিলের জন্য এর নিচে খাত বানায়।
            ['1101', 'Cash in Hand', 'হাতে নগদ', $A, '1100', true, []],

            // ব্যাংক ও বিকাশ/নগদ/রকেট একই মাথার নিচে: MFS হিসাবের
            // দিক থেকে ব্যাংকের মতোই আচরণ করে — জমা, উত্তোলন, বিবরণী।
            ['1102', 'Bank & Mobile Money', 'ব্যাংক ও মোবাইল ব্যাংকিং', $A, '1100', true, []],

            ['1110', 'Accounts Receivable', 'প্রাপ্য হিসাব', $A, '1100', false, []],

            ['1120', 'Inventory', 'মজুদ পণ্য', $A, '1100', false, []],
            ['1130', 'Advance & Prepayments', 'অগ্রিম ও অগ্রিম পরিশোধ', $A, '1100', false, []],

            /*
             * কর্মীর অগ্রিম আলাদা খাতে, সাধারণ অগ্রিমের সাথে নয়।
             *
             * টাকাটা ফেরত আসবে বেতন থেকে কিস্তিতে, আর "কার কাছে কত
             * অগ্রিম পড়ে আছে" প্রশ্নটা বেতনের দিনে ওঠে। ভাড়ার অগ্রিমের
             * সাথে এক খাতে থাকলে ওই প্রশ্নের উত্তর বের করতে প্রতিবার
             * সারিগুলো হাতে বাছতে হত।
             */
            ['1131', 'Advance to Employees', 'কর্মীর অগ্রিম', $A, '1100', false, []],
            ['1140', 'Security Deposits', 'জামানত', $A, '1100', false, []],

            ['1200', 'Fixed Assets', 'স্থায়ী সম্পদ', $A, '1000', true, []],
            ['1201', 'Furniture & Fixtures', 'আসবাবপত্র', $A, '1200', false, []],
            ['1202', 'Vehicles', 'যানবাহন', $A, '1200', false, []],
            ['1203', 'Equipment & Machinery', 'যন্ত্রপাতি', $A, '1200', false, []],
            ['1204', 'Computer & Accessories', 'কম্পিউটার ও সরঞ্জাম', $A, '1200', false, []],

            // সম্পদ, তবু ক্রেডিট প্রকৃতির — সম্পদের বিপরীত খাত। এটাই সেই
            // ব্যতিক্রম যার জন্য nature আলাদা কলাম, ধরন থেকে বের করা নয়।
            ['1290', 'Accumulated Depreciation', 'সঞ্চিত অবচয়', $A, '1200', false, ['nature' => Account::CREDIT]],

            // ── দায় ──────────────────────────────────────────────────
            ['2000', 'Liabilities', 'দায়', $L, null, true, []],
            ['2100', 'Current Liabilities', 'চলতি দায়', $L, '2000', true, []],
            ['2110', 'Accounts Payable', 'প্রদেয় হিসাব', $L, '2100', false, []],
            ['2120', 'VAT Payable', 'প্রদেয় ভ্যাট', $L, '2100', false, []],
            ['2130', 'Salary Payable', 'প্রদেয় বেতন', $L, '2100', false, []],

            /*
             * ভবিষ্য তহবিল প্রদেয় বেতনের সাথে মেশে না।
             *
             * কেটে রাখা টাকাটা কর্মীর, কিন্তু এখনো তহবিলে জমা হয়নি — দুইটা
             * আলাদা দায়। এক খাতে রাখলে "বেতন বাবদ কত বাকি" আর "তহবিলে কত
             * জমা দিতে হবে" দুইটা প্রশ্নের একটাই উত্তর থাকত।
             */
            ['2131', 'Provident Fund Payable', 'প্রদেয় ভবিষ্য তহবিল', $L, '2100', false, []],
            ['2140', 'Expenses Payable', 'প্রদেয় খরচ', $L, '2100', false, []],
            ['2150', 'Advance from Customers', 'গ্রাহকের অগ্রিম', $L, '2100', false, []],
            // মাল এসেছে, বিল আসেনি — ধ্রুবকটার মন্তব্যে কারণ লেখা আছে
            ['2160', 'Goods Received Not Invoiced', 'প্রাপ্ত মাল, বিল আসেনি', $L, '2100', false, []],

            ['2200', 'Long Term Liabilities', 'দীর্ঘমেয়াদি দায়', $L, '2000', true, []],
            ['2210', 'Bank Loan', 'ব্যাংক ঋণ', $L, '2200', false, []],
            ['2220', 'Other Loan', 'অন্যান্য ঋণ', $L, '2200', false, []],

            // ── মূলধন ─────────────────────────────────────────────────
            ['3000', 'Equity', 'মূলধন', $E, null, true, []],
            ['3100', 'Owner Capital', 'মালিকের মূলধন', $E, '3000', false, []],

            // মূলধন, তবু ডেবিট প্রকৃতির — মালিক টাকা তুললে মূলধন কমে
            ['3200', 'Drawings', 'উত্তোলন', $E, '3000', false, ['nature' => Account::DEBIT]],

            ['3300', 'Retained Earnings', 'সঞ্চিত মুনাফা', $E, '3000', false, []],

            // ── আয় ───────────────────────────────────────────────────
            ['4000', 'Income', 'আয়', $I, null, true, []],
            ['4100', 'Sales', 'বিক্রয়', $I, '4000', false, []],
            ['4200', 'Discount Received', 'প্রাপ্ত ছাড়', $I, '4000', false, []],
            ['4300', 'Other Income', 'অন্যান্য আয়', $I, '4000', false, []],

            // ── খরচ ───────────────────────────────────────────────────
            ['5000', 'Expenses', 'খরচ', $X, null, true, []],
            ['5100', 'Cost of Goods Sold', 'বিক্রীত পণ্যের ব্যয়', $X, '5000', false, []],
            // চালানের দর আর বিলের দরের পার্থক্য — ধ্রুবকের মন্তব্যে কারণ
            ['5150', 'Purchase Price Variance', 'ক্রয়মূল্যের পার্থক্য', $X, '5000', false, []],

            ['5200', 'Operating Expenses', 'পরিচালন ব্যয়', $X, '5000', true, []],
            ['5201', 'Salary & Wages', 'বেতন ও মজুরি', $X, '5200', false, []],
            ['5202', 'Rent', 'ভাড়া', $X, '5200', false, []],
            ['5203', 'Electricity & Utilities', 'বিদ্যুৎ ও ইউটিলিটি', $X, '5200', false, []],
            ['5204', 'Fuel & Transport', 'জ্বালানি ও পরিবহন', $X, '5200', false, []],
            ['5205', 'Mobile & Internet', 'মোবাইল ও ইন্টারনেট', $X, '5200', false, []],
            ['5206', 'Repair & Maintenance', 'মেরামত ও রক্ষণাবেক্ষণ', $X, '5200', false, []],
            ['5207', 'Office Supplies', 'অফিস সরঞ্জাম', $X, '5200', false, []],
            ['5208', 'Entertainment', 'আপ্যায়ন', $X, '5200', false, []],
            ['5209', 'Marketing & Promotion', 'বিপণন ও প্রচার', $X, '5200', false, []],
            ['5210', 'Bank Charges', 'ব্যাংক চার্জ', $X, '5200', false, []],
            ['5211', 'Mobile Banking Charges', 'মোবাইল ব্যাংকিং চার্জ', $X, '5200', false, []],
            ['5212', 'Depreciation', 'অবচয়', $X, '5200', false, []],
            ['5213', 'Government Fees & Licences', 'সরকারি ফি ও লাইসেন্স', $X, '5200', false, []],
            ['5299', 'Miscellaneous Expenses', 'বিবিধ খরচ', $X, '5200', false, []],

            ['5300', 'Discount Given', 'প্রদত্ত ছাড়', $X, '5000', false, []],
            ['5400', 'Bad Debt', 'অনাদায়ী পাওনা', $X, '5000', false, []],
        ];
    }
}
