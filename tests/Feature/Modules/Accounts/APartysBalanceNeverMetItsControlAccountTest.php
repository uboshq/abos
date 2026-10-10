<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Integrity\IntegrityFinding;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Integrity\AccountsChecks;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * পক্ষের খাতার যোগ আর নিয়ন্ত্রণ খাতের জের কেউ মেলাত না — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (হিসাব, ঠিক ৫)।
 *
 * ⭐ খাতা যাচাইয়ের পর্দায় নতুন সারি ([[AccountsChecks::partiesAddUpToTheirControlAccount()]]): প্রতিটা পক্ষ-রাখা খাতে মোট
 * জের, পক্ষগুলোর যোগ, ফারাক। ⓘ কেবল পড়ে — খাতা একটুও বদলায় না।
 */
final class APartysBalanceNeverMetItsControlAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_line_with_no_party_on_a_control_account_is_listed_with_its_difference(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $root = StandardChart::find(StandardChart::RECEIVABLE);
        $receivable = $root->is_group
            ? Account::query()->postable()->whereKey($root->selfAndDescendants()->pluck('id'))->orderBy('code')->firstOrFail()
            : $root;
        $cash = app(CashTillService::class)->ensurePrimaryTill()->account_id;
        $customer = Customer::query()->orderBy('id')->firstOrFail();

        $mine = fn (): array => array_values(array_filter(
            AccountsChecks::partiesAddUpToTheirControlAccount()->run(),
            fn (IntegrityFinding $f) => str_starts_with($f->what, $receivable->code.' '),
        ));

        // ⓘ পক্ষের নামে বসা সারি — মেলে
        app(PostingEngine::class)->post(sourceType: 'test:party-control', sourceId: 1, trxDate: now(), lines: [
            ['account_id' => $receivable->id, 'debit' => '900', 'party_type' => 'customer', 'party_id' => $customer->id],
            ['account_id' => $cash, 'credit' => '900'],
        ]);
        $this->assertSame([], $mine(), 'পক্ষের নামে বসা সারিতেই অমিল দেখাল।');

        // ⛔ পক্ষ ছাড়া ৫০০ — খাতের জের বাড়ে, কারো বকেয়া নয়
        app(PostingEngine::class)->post(sourceType: 'test:party-control', sourceId: 2, trxDate: now(), lines: [
            ['account_id' => $receivable->id, 'debit' => '500'],
            ['account_id' => $cash, 'credit' => '500'],
        ]);

        $rows = LedgerEntry::query()->count();
        $found = $mine();

        $this->assertCount(1, $found, '⛔ পক্ষ ছাড়া সারি থাকতেও নিয়ন্ত্রণ খাত আর পক্ষের যোগের অমিল ধরা পড়ল না।');
        $this->assertStringContainsString('500.00', $found[0]->detail, 'ফারাকটা অঙ্কে বলা নেই।');
        $this->assertSame($rows, LedgerEntry::query()->count(), '⛔ যাচাই খাতায় কিছু লিখল।');

        // ⓘ পর্দায় নামসহ
        $this->get(route('accounts.integrity'))->assertOk()->assertSee(__('accounts::integrity.party_control'))->assertSee($receivable->code);
    }
}
