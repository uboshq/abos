<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ⛔ হাতে থাকা কাগজকে তালা দিয়ে আবার পড়া হয় সব শাখা জুড়ে — দেখার শাখার দেয়াল ছাড়া, কোম্পানির দেয়াল রেখে (fe, ১৩ অক্টোবর ২০২৬)।
 *
 * ── কী ভেঙেছিল ──────────────────────────────────────────────────────
 * শাখার দেয়ালওয়ালা মডেল ([[ScopedToUserBranch]]) পড়ে যিনি দেখছেন তাঁর বাছা শাখা দিয়ে। শেষ-সইয়ের শ্রোতা বা অন্য শাখার কাগজে কাজ
 * করা সেবা কাগজটা হাতে পেয়েও `Model::query()->whereKey($x->id)->lockForUpdate()` দিয়ে আবার পড়ত — সইকারী অন্য শাখা দেখালে
 * "No query results", কাজ মাঝপথে থামত (ঋণের তোলা, ডিলারের DO; [[ALoanMovesMoneyOnlyOnceSignedTest]],
 * [[ADealerWritesTheDeliveryOrderAndTheSupervisorSignsItTest]])।
 *
 * ── নিয়ম ────────────────────────────────────────────────────────────
 * তালার বাক্যে শাখার দেয়ালের মডেল, আর চাবি হাতে থাকা মডেলের (`$x->id`, `$x->getKey()`, `$x->foo_id`) → `acrossBranches()` বা
 * `withoutGlobalScope(...)` লাগবে। ⓘ কাগজটা আগেই দেয়াল পেরিয়ে হাতে এসেছে, তাই আবার-পড়া কারও নাগাল বাড়ায় না।
 * ⛔ ফর্ম থেকে আসা আইডির তালিকা (আদায়ের বিল, প্রস্তাবের বিল) এই নিয়মের বাইরে — সেখানে দেয়ালটাই নিরাপত্তা; পাহারা সেগুলো ধরে না,
 * কারণ চাবি `$x->...` নয়।
 */
final class ARowInHandIsLockedAcrossBranchesTest extends TestCase
{
    /**
     * এখনো সারানো হয়নি — ফাইল → এমন বাক্যের সংখ্যা। প্রতিটা সেবার সারাই নিজের সারি এখান থেকে তোলে; শেষে তালিকা খালি।
     *
     * @var array<string, int>
     */
    private const NOT_YET = [
        'app/Modules/Accounts/Services/LoanService.php' => 3,
        'app/Modules/Documents/Services/DocumentLibrary.php' => 1,
        'app/Modules/Documents/Services/DocumentSignatures.php' => 1,
        'app/Modules/Documents/Services/DocumentWorkflow.php' => 1,
        'app/Modules/Inventory/Services/OpeningStockService.php' => 1,
        'app/Modules/Inventory/Services/QualityInspectionService.php' => 2,
        'app/Modules/Inventory/Services/StockCountService.php' => 1,
        'app/Modules/Inventory/Services/StockTransferService.php' => 1,
        'app/Modules/Purchase/Services/PaymentService.php' => 1,
        'app/Modules/Purchase/Services/PurchaseBillService.php' => 1,
        'app/Modules/Purchase/Services/PurchaseReturnService.php' => 2,
        'app/Modules/Sales/Services/CommissionClaimService.php' => 1,
        'app/Modules/Sales/Services/DirectSaleService.php' => 2,
        'app/Modules/Sales/Services/FarePayment.php' => 1,
        'app/Modules/Sales/Services/GatePassService.php' => 1,
        'app/Modules/Sales/Services/HeldCounterSaleFinisher.php' => 1,
        'app/Modules/Sales/Services/SaleEditor.php' => 2,
        'app/Modules/Sales/Services/SalesInvoiceCancellationService.php' => 3,
        'app/Modules/Sales/Services/SalesInvoiceService.php' => 1,
        'app/Modules/Sales/Services/SalesQuotationService.php' => 2,
        'app/Modules/Sales/Services/ShipmentService.php' => 2,
    ];

    /**
     * ইচ্ছা করে ছাড় — ফাইল → [সংখ্যা, কারণ]। প্রতিটা সারি একটা সিদ্ধান্ত, কারণ পাশে।
     *
     * @var array<string, array{int, string}>
     */
    private const EXCUSED = [
        'app/Modules/Restaurant/Services/ProductionService.php' => [1, 'রেস্টুরেন্ট পরে — মালিক, fe মারফত ১৩ অক্টোবর ২০২৬: ২১ অক্টোবরের পরে'],
    ];

    public function test_every_locked_re_read_of_a_row_in_hand_looks_across_branches(): void
    {
        $scoped = $this->scopedModels();
        $this->assertGreaterThan(30, count($scoped), 'দাবির ভিত্তি: শাখার দেয়ালওয়ালা মডেল পাওয়া গেল না।');

        $found = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $count = $this->scopedReReadsIn($file->getContents(), $scoped);

            if ($count > 0) {
                $found['app/'.str_replace('\\', '/', $file->getRelativePathname())] = $count;
            }
        }

        foreach (self::EXCUSED as $path => [$count, $why]) {
            $this->assertNotSame('', $why);
            $this->assertSame($count, $found[$path] ?? 0, "ছাড়ের তালিকা পুরনো: {$path} — সারানো হলে ছাড়টা তুলুন।");
            unset($found[$path]);
        }

        ksort($found);
        $expected = self::NOT_YET;
        ksort($expected);

        $this->assertSame($expected, $found,
            '⛔ হাতে থাকা কাগজের তালা-দেওয়া আবার-পড়া শাখার দেয়াল দিয়ে — `acrossBranches()` লাগবে (সারানো হলে NOT_YET থেকে সারিটা তুলুন)।');
    }

    public function test_the_guard_sees_the_shape_it_hunts(): void
    {
        $scoped = ['Loan' => true];

        $this->assertSame(1, $this->scopedReReadsIn('<?php $loan = Loan::query()->whereKey($movement->loan_id)->lockForUpdate()->firstOrFail();', $scoped));
        $this->assertSame(0, $this->scopedReReadsIn('<?php $loan = Loan::acrossBranches()->whereKey($movement->loan_id)->lockForUpdate()->firstOrFail();', $scoped));
        // ⓘ ফর্ম থেকে আসা আইডি — নিয়মের বাইরে
        $this->assertSame(0, $this->scopedReReadsIn('<?php $rows = Loan::query()->whereKey($ids)->lockForUpdate()->get();', $scoped));
        $this->assertSame(1, $this->scopedReReadsIn('<?php $l = Loan::query()->lockForUpdate()->findOrFail($loan->getKey());', $scoped));
    }

    /** @return array<string, true> */
    private function scopedModels(): array
    {
        $names = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() === 'php' && preg_match('/^\s*use ScopedToUserBranch;/m', $file->getContents())) {
                $names[$file->getFilenameWithoutExtension()] = true;
            }
        }

        return $names;
    }

    /** @param  array<string, true>  $scoped */
    private function scopedReReadsIn(string $source, array $scoped): int
    {
        $count = 0;
        $offset = 0;

        while (($at = strpos($source, 'lockForUpdate()', $offset)) !== false) {
            $offset = $at + 1;
            $start = max((int) strrpos(substr($source, 0, $at), ';'), (int) strrpos(substr($source, 0, $at), '{'), (int) strrpos(substr($source, 0, $at), '}'));
            $end = strpos($source, ';', $at);
            $statement = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', substr($source, $start, ($end === false ? strlen($source) : $end) - $start));

            if (! preg_match('/\\\\?([A-Z][A-Za-z]+)::query\(\)/', $statement, $m) || ! isset($scoped[$m[1]])) {
                continue;
            }

            if (str_contains($statement, 'withoutGlobalScope') || str_contains($statement, 'acrossBranches')) {
                continue;
            }

            if (preg_match('/(whereKey|find|findOrFail)\(\s*\$[a-zA-Z_]+->(id|getKey\(\)|[a-z_]+_id)\s*\)/', $statement)) {
                $count++;
            }
        }

        return $count;
    }
}
