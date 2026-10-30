<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Accounts\Integrity\AccountsChecks;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ খাতায় লেখার পথ মান ছকের খাত নেয় কেবল [[StandardChart::accountFor()]] দিয়ে — fe-র পাহারা, ২৩ অক্টোবর ২০২৬।
 *
 * <p>লাইভে UB-তে ১১৩৫ আর ১১৫১-এ ব্যাংক বসা ছিল, আর কোড ধরে খোঁজা পথগুলো ব্যাংকটাই পেত। এখন [[StandardChart::find()]] এমন
 * খাতে null দেয় — কিন্তু যে লেখার পথ null পেয়ে চুপচাপ লাইন বাদ দেয়, সে ভুল খাতের বদলে অর্ধেক জাবেদা বসায় (ভাড়া, মালের
 * দাম শাখা বদলে)। তাই খাতায় লেখে এমন ফাইলে (PostingEngine ছোঁয়) কোড ধরে খাত খোঁজা নিষেধ; খোঁজা মানেই accountFor, যা না পেলে
 * থামে। ⓘ নিচের তালিকা কেবল শুধু-পড়ার বা খাত-নয় এমন খোঁজা, আর অন্য সেশনের হাতে থাকা পথ — প্রতিটার কারণসহ; তালিকা কেবল কমে।
 */
final class AWritingPathTakesItsChartAccountThroughAccountForTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{0: string, 1: string, 2: string}> ফাইল, লাইনের টুকরো, কারণ */
    private const ALLOWED = [
        ['app/Console/Commands/HandLoanParty.php', "\$q->where('code', \$code)", 'কোম্পানির কোড, খাতের নয়'],
        ['app/Console/Commands/HandLoanParty.php', '$head = StandardChart::find(StandardChart::HAND_LOAN);', 'শুধু পড়া: হাতধারের খাতগুলো চেনা, null হলে খালি তালিকা'],
        ['app/Modules/Accounts/Services/OpeningBalanceService.php', '$capital = StandardChart::find(StandardChart::OWNER_CAPITAL);', 'শুধু পড়া: পক্ষ লাগবে কিনা ঠিক করা'],
        ['app/Modules/Accounts/Services/OpeningBalanceService.php', 'return StandardChart::find(StandardChart::OWNER_CAPITAL) ?? StandardChart::accountFor(', 'ইচ্ছার বিকল্প: ৩১০০ না পেলে ৩৩০০, যা accountFor-এ থামে'],
        ['app/Modules/Accounts/Services/VoucherService.php', 'return StandardChart::find($code)', 'cb-র হাতে (chargeAccount) — accountFor-এ আনবে'],
        ['app/Modules/Accounts/Services/VoucherService.php', 'StandardChart::find(StandardChart::HAND_LOAN)?->selfAndDescendants()', 'শুধু পড়া: হাতধারের খাত চেনা'],
        ['app/Modules/Accounts/Services/VoucherService.php', "->whereIn('code', [StandardChart::BANK_CHARGES, StandardChart::MFS_CHARGES])", 'শুধু পড়া: বেছে নেওয়া খাত চার্জের কিনা চেনা'],
        ['app/Modules/Accounts/Services/VoucherService.php', 'foreach (StandardChart::find($code)?->selfAndDescendants() ?? [] as $account) {', 'শুধু পড়া: খাতের পরিবার চেনা'],
        ['app/Modules/Accounts/Services/YearEndService.php', 'StandardChart::find(StandardChart::RETAINED_EARNINGS)?->id)),', 'শুধু পড়া: আগাম দেখায় ৩৩০০-এর সারি বাদ'],
        ['app/Modules/Hr/Services/PayrollService.php', '$account = StandardChart::find($code);', 'খসড়ার আগে-ভরা খাত; নিশ্চিতে accountFor থামে'],
        ['app/Modules/Inventory/Services/StockTransferService.php', "->where('code', 'HOLD-TRN')", 'কারণের কোড, খাতের নয়'],
        ['app/Modules/Purchase/Services/PaymentService.php', "->whereIn('code', \$money)->exists();", 'cb-র হাতে — টাকার খাত চেনা money_kind দিয়ে আনবে'],
        ['app/Modules/Purchase/Services/PaymentService.php', "\$account = Account::query()->postable()->where('code', \$code)->first();", 'cb-র হাতে (account()) — accountFor-এ আনবে'],
        ['app/Modules/Sales/Services/CollectionService.php', "->whereIn('code', StandardChart::MONEY_PARENTS)", 'a6-এর হাতে — টাকার খাত চেনা'],
        ['app/Modules/Sales/Services/DirectSaleService.php', "PaymentMethod::query()->where('code', \$legacy)", 'টাকা দেওয়ার পদ্ধতির কোড, খাতের নয়'],
    ];

    public function test_no_writing_path_finds_its_account_by_code_without_account_for(): void
    {
        $found = [];
        $used = [];

        foreach ($this->writingFiles() as $file => $lines) {
            foreach ($lines as $number => $line) {
                if (! preg_match("/StandardChart::find\\(|(?:where|firstWhere|whereIn)\\(\\s*'code'/", $line)) {
                    continue;
                }

                $allowed = null;
                foreach (self::ALLOWED as $i => [$path, $needle]) {
                    if ($path === $file && str_contains($line, $needle)) {
                        $allowed = $i;
                    }
                }

                if ($allowed === null) {
                    $found[] = $file.':'.($number + 1).'  '.trim($line);
                } else {
                    $used[$allowed] = true;
                }
            }
        }

        $this->assertSame([], $found, "⛔ খাতায় লেখার পথ কোড ধরে খাত খুঁজছে accountFor ছাড়া — null পেলে ভুল খাতে বা অর্ধেক জাবেদায় যাবে:\n".implode("\n", $found));

        $stale = array_values(array_map(fn (array $a) => $a[0].'  '.$a[1], array_diff_key(self::ALLOWED, $used)));
        $this->assertSame([], $stale, "তালিকার এই ছাড় আর লাগে না — সরাও, তালিকা কেবল কমে:\n".implode("\n", $stale));
    }

    /** ⭐ ডেমোর প্রতিটা কোম্পানিতে ছক বসালে মান ছকের প্রতিটা কোডে তার নিজের খাত — লাইভে একই পরখ খাতার পরখে চলে */
    public function test_every_demo_company_holds_the_chart_s_meaning(): void
    {
        $this->seed(DemoSeeder::class);
        $strangers = [];

        foreach (Company::query()->orderBy('id')->get() as $company) {
            CompanyContext::forCompany((int) $company->id, function () use ($company, &$strangers) {
                app(StandardChart::class)->install();
                foreach (AccountsChecks::everyStandardCodeHoldsItsAccount()->run() as $finding) {
                    $strangers[] = $company->code.': '.$finding->what.' — '.$finding->detail;
                }
            });
        }

        $this->assertSame([], $strangers, "⛔ ডেমোর ছকেই মান ছকের কোডে অন্য খাত:\n".implode("\n", $strangers));
    }

    /**
     * খাতায় লেখে এমন ফাইল (PostingEngine ছোঁয়), মন্তব্য বাদ দিয়ে — লাইন নম্বর আগের মতো।
     *
     * @return array<string, list<string>>
     */
    private function writingFiles(): array
    {
        $out = [];
        $root = base_path('app');

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $code = (string) preg_replace_callback('#/\*.*?\*/#s', fn ($m) => str_repeat("\n", substr_count($m[0], "\n")), (string) file_get_contents($file->getPathname()));
            $code = (string) preg_replace('#//[^\n]*#', '', $code);

            if (! str_contains($code, 'PostingEngine')) {
                continue;
            }

            $relative = 'app/'.str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $out[$relative] = explode("\n", $code);
        }

        ksort($out);

        return $out;
    }
}
