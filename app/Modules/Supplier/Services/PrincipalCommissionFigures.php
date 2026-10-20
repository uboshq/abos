<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Services;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\ViewedBranch;
use App\Http\Middleware\RefuseSwitchedOffScreens;
use App\Modules\Supplier\Dashboard\SupplierDashboard;
use App\Modules\Supplier\Reports\PrincipalCommission;
use App\Modules\Supplier\Reports\PrincipalCommissionReport;

/**
 * ⭐ প্রিন্সিপালের কমিশন ফোনের তিন জায়গায় — আজকের পাতার `principals` আর তার মোট, অ্যাপের পর্দা আর হোম-স্ক্রিনের উইজেট
 * (মালিক, fe-র মারফত, ২০ অক্টোবর ২০২৬: *"app e dekhate hobe"*; চুক্তি `pc/CONTRACT.md`, সংশোধন ১)।
 *
 * ⓘ নিজের হিসাব নয়: সরবরাহকারীর ড্যাশবোর্ডের বাক্স যে রিপোর্টটা চালায় ([[SupplierDashboard::principals()]]) — একই চাবি,
 * একই ছাঁকনি (হেডারে বাছা শাখা, [[ViewedBranch::one()]]), একই সারির সংখ্যা ([[CARD_ROWS]]) — সেটাই চালানো হয়, আর সারিগুলো
 * [[PrincipalCommission::rows()]]-এর। ⛔ তাই ফোন আর ওয়েবের বাক্স কখনো দুই অঙ্ক বলে না; শাখার দেয়ালও রিপোর্টের
 * ([[ReportEngine::branchWall()]]) — শাখায় আটকানো মানুষ কেবল নিজের শাখার প্রিন্সিপাল পান।
 *
 * ⭐ সারির ঘরের নাম আজকের পাতার পুরনো `principals`-এর হুবহু (০.৪.৪০-এর অ্যাপ সেটাই পড়ে) — তিন জায়গা এক নির্মাতা থেকে, তাই
 * কখনো আলাদা হয় না; নতুন কেবল `supplierId` আর `branch` (সরবরাহকারীর পাশে শাখা — দলের নিয়ম)।
 * ⭐ টাকা চার ঘরের দশমিক লেখা, bcmath-এ ("845210.3500") — ফোন কেবল সাজায়। ⛔ float কোথাও নয়।
 * ⭐ মোট = সারিগুলোর bcmath যোগফল — ওয়েবের বাক্সের সর্বমোটের একই নিয়ম (মালিক, ১৯ অক্টোবর ২০২৬): জের চিহ্নসহ যোগ, তাই দুই
 * দিকের সারি থাকলে নিট দিকটাই; কমিশনের লেখা বাক্সের একই সহায়কে ([[SupplierDashboard::earned()]])।
 */
final class PrincipalCommissionFigures
{
    /** ওয়েবের বাক্স রিপোর্টের প্রথম পাতায় যত সারি নেয় ([[SupplierDashboard::principals()]]) — একই, যাতে মোটও একই */
    public const CARD_ROWS = 50;

    /** উইজেটের সারির ছাদ — দলের নিয়ম "সারি সর্বোচ্চ ৮টা" (app-widgets-ভিত্তি) */
    public const WIDGET_ROWS = 8;

    /** যে ঘরগুলো মোটে যোগ হয় */
    private const SUMMED = ['inflow', 'commission', 'share', 'paid', 'balance'];

    public function __construct(private readonly ReportEngine $reports) {}

    /**
     * সারি আর মোট — `month` খালি হলে প্রতিটা প্রিন্সিপালের চলতি চক্র (ওয়েবের একই ডিফল্ট)।
     *
     * @return array{rows: list<array<string, mixed>>, total: array<string, string>}
     */
    public function figures(?string $month = null): array
    {
        $found = $this->reports->run(
            PrincipalCommissionReport::KEY,
            ['branch_id' => ViewedBranch::one(), 'month' => $month],
            1,
            self::CARD_ROWS,
        )->rows;

        $rows = array_map(fn (array $r): array => self::row($r), $found);

        return ['rows' => $rows, 'total' => self::total($rows)];
    }

    /**
     * ⛔ ওয়েবে পর্দাটা বন্ধ (কোম্পানির বা শাখার সুইচ) — ফোনের পর্দাও বন্ধ, একই উত্তরদাতা ([[RefuseSwitchedOffScreens::refuses()]])।
     */
    public static function screenOff(): bool
    {
        return app(RefuseSwitchedOffScreens::class)->refuses('supplier.report.show', ['slug' => 'principal-commission']);
    }

    /**
     * এক সারি — রিপোর্টের সারি থেকে, নতুন কোনো হিসাব নয়; আজকের পাতার পুরনো `principals`-এর ঘরগুলো হুবহু।
     *
     * @param  array<string, mixed>  $r  রিপোর্টের সারি
     * @return array<string, mixed>
     */
    public static function row(array $r): array
    {
        return [
            'supplierId' => (int) $r['supplier_id'],
            // ⭐ সংক্ষিপ্ত নাম, না থাকলে নাম — কোড নয় (মালিক, ৬ অক্টোবর ২০২৬; রিপোর্টের সারিতেই)
            'name' => (string) $r['supplier_name'],
            // ⭐ সরবরাহকারীর পাশে শাখা — প্রিন্সিপাল মানেই একটা শাখা (দলের নিয়ম, ১৯ অক্টোবর ২০২৬)
            'branch' => (string) $r['branch_name'],
            'period' => (string) $r['period'],
            // ⭐ ওয়েবের বাক্সের "সময়কাল: 26/09/2026 – আজ পর্যন্ত" — অঙ্কগুলো চক্রের শুরু থেকে আজ পর্যন্ত
            'periodFrom' => (string) $r['period_from'],
            'periodTo' => (string) $r['period_to'],
            'periodSoFar' => PrincipalCommission::soFar((string) $r['period_from'], (string) $r['period_to']),
            'basisRate' => (string) $r['basis_rate'],
            'inflow' => self::money($r['inflow']),
            'commission' => self::money($r['commission']),
            // ⭐ ওয়েবের বাক্সের লেখাই — ঋণাত্মক হলে "লোকসান ৳… — কেনা দামের নিচে বিক্রি", খালি বিয়োগ নয় (৬ অক্টোবর ২০২৬)
            'commissionLabel' => SupplierDashboard::earned((string) $r['commission']),
            'share' => self::money($r['share']),
            'paid' => self::money($r['paid']),
            'balance' => self::money($r['balance']),
        ];
    }

    /**
     * মোট — সারিগুলোর bcmath যোগফল (জের চিহ্নসহ), সারির একই নাম; কমিশনের লেখা বাক্সের সর্বমোটের একই সহায়কে।
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, string>
     */
    public static function total(array $rows): array
    {
        $total = [];

        foreach (self::SUMMED as $key) {
            $total[$key] = array_reduce($rows, fn (string $c, array $r): string => bcadd($c, (string) $r[$key], 4), '0.0000');
        }

        return [
            'inflow' => $total['inflow'],
            'commission' => $total['commission'],
            'commissionLabel' => SupplierDashboard::earned($total['commission']),
            'share' => $total['share'],
            'paid' => $total['paid'],
            'balance' => $total['balance'],
        ];
    }

    /** টাকা — চার ঘরের দশমিক লেখা */
    private static function money(mixed $value): string
    {
        return bcadd((string) ($value ?? '0'), '0', 4);
    }
}
