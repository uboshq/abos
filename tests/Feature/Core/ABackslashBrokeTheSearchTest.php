<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Search\SearchEngine;
use App\Core\Engines\Search\SearchHit;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⓘ খোঁজার লেখায় `\` পালানো হত না — ec, Core লাইন-নিরীক্ষা, ১৫ অক্টোবর ২০২৬ ([[SearchEngine::matchesIn()]])।
 *
 * ⓘ MySQL-এর LIKE-এ `\` পালানোর অক্ষর: "Back\Slash" লিখলে `\S` হত সাধারণ "S", নামটা মিলত না; `%` আর `_` আগের মতোই আক্ষরিক।
 */
final class ABackslashBrokeTheSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_backslash_percent_and_underscore_are_searched_as_written(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        Customer::query()->create(['company_id' => $company->id, 'code' => 'BKS-1', 'name_en' => 'Back\\Slash Store', 'is_active' => true]);
        Customer::query()->create(['company_id' => $company->id, 'code' => 'BKS-2', 'name_en' => 'BackXSlash Store', 'is_active' => true]);

        $found = fn (string $term) => array_map(
            fn (SearchHit $hit) => $hit->documentNo,
            array_values(array_filter(app(SearchEngine::class)->search($term, $owner), fn (SearchHit $hit) => $hit->slug === 'customer')),
        );

        $this->assertSame(['BKS-1'], $found('Back\\Slash'), '⛔ "\\" লেখা নামটা খোঁজায় এল না (বা অন্যটাও এল)।');
        $this->assertSame([], $found('Back_Slash'), '⛔ "_" যেকোনো অক্ষরে মিলল — আক্ষরিক থাকার কথা।');
        $this->assertSame([], $found('Back%Slash'), '⛔ "%" যেকোনো লেখায় মিলল — আক্ষরিক থাকার কথা।');
    }
}
