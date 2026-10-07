<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Imports\ProductImporter;
use App\Modules\Inventory\Models\Product;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * এক গ্রুপের আমদানি সব শাখায় ছড়াত — মালিক, ৫ অক্টোবর ২০২৬।
 *
 * ── ⛔ যা ঘটেছিল ─────────────────────────────────────────────────────
 * ADI-তে SL-Super Group শাখায় ৮৩টা পণ্য আমদানি হলো, তারপর SL-Lion Group-এ লায়নের তালিকা — আর লায়ন শাখায়
 * সুপারের ৮৩টা পণ্যও দেখা গেল। আমদানি শাখা ছুঁত না, আর শাখার সারি ছাড়া পণ্য **সব শাখার**
 * ([[Product::scopeSoldInViewedBranch()]])।
 *
 * দাবি — একই মানুষ, একই আমদানিকারক:
 *  · হেডারে শাখা ক বাছা → আমদানির পণ্য কেবল শাখা ক-এর, শাখা খ-এর তালিকায় নেই।
 *  · তারপর শাখা খ বাছা → তার পণ্য কেবল খ-এর।
 *  · "সব শাখা" দেখার সময় আমদানি → আগের মতোই সব শাখার।
 */
final class TheImportSpreadOneGroupsGoodsToEveryBranchTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    public function test_an_import_under_one_branch_belongs_to_that_branch_only(): void
    {
        [$a, $b] = $this->twoBranches();

        $this->viewBranch($a);
        $super = $this->import('IMP-SUPER-1');

        $this->viewBranch($b);
        $lion = $this->import('IMP-LION-1');

        $this->assertSame([$a->id], $super->branches()->pluck('branches.id')->map(fn ($id) => (int) $id)->all(),
            '⛔ শাখা ক-তে আমদানি, অথচ পণ্যটা শাখা ক-এর নামে বসেনি।');
        $this->assertSame([$b->id], $lion->branches()->pluck('branches.id')->map(fn ($id) => (int) $id)->all(),
            '⛔ শাখা খ-তে আমদানি, অথচ পণ্যটা শাখা খ-এর নামে বসেনি।');

        // ⭐ মালিকের অভিযোগটাই: খ-এর তালিকায় ক-এর পণ্য নেই, ক-এর তালিকায় খ-এর নেই
        $this->viewBranch($b);
        $inB = Product::query()->soldInViewedBranch()->pluck('code')->all();
        $this->assertContains('IMP-LION-1', $inB);
        $this->assertNotContains('IMP-SUPER-1', $inB, '⛔ সুপারের পণ্য লায়ন শাখায় দেখাচ্ছে।');

        $this->viewBranch($a);
        $inA = Product::query()->soldInViewedBranch()->pluck('code')->all();
        $this->assertContains('IMP-SUPER-1', $inA);
        $this->assertNotContains('IMP-LION-1', $inA, '⛔ লায়নের পণ্য সুপার শাখায় দেখাচ্ছে।');
    }

    public function test_an_import_while_viewing_all_branches_stays_for_every_branch(): void
    {
        [$a, $b] = $this->twoBranches();

        $this->owner->forceFill(['view_all_branches' => true])->save();
        CompanyContext::set($this->company->id, $a->id);
        $all = $this->import('IMP-ALL-1');

        $this->assertSame(0, $all->branches()->count(), '"সব শাখা" দেখার সময় আমদানি এক শাখায় আটকে গেছে।');

        $this->viewBranch($b);
        $this->assertContains('IMP-ALL-1', Product::query()->soldInViewedBranch()->pluck('code')->all());
    }

    /**
     * ⭐ একই নাম, আলাদা শাখা — মালিক, ৫ অক্টোবর ২০২৬: "এই ব্যবসায় আলাদা আলাদা শাখা, আলাদা পণ্য" (খ)।
     * ADI-তে Gold-এর ৬১টা আর Lion-এর ৪২টা পণ্য আটকেছিল, কারণ নামগুলো Super-এর পণ্যের সাথে হুবহু এক।
     *
     * দাবি — একই মানুষ, একই নাম:
     *  · শাখা ক-তে "Lexus Box", তারপর শাখা খ-তে "Lexus Box" → দুটোই বসে, নিজের নিজের শাখায়।
     *  · শাখা ক-তে আবার "Lexus Box" → থামে (একই শাখায় নকল আগের মতোই ধরা পড়ে)।
     *  · সব শাখার পুরনো পণ্যের নামে কোনো শাখায় নতুন পণ্য → থামে (সেটা ঐ শাখাতেও বিক্রি হয়)।
     */
    public function test_the_same_name_is_a_different_product_in_another_branch_but_not_in_the_same_one(): void
    {
        [$a, $b] = $this->twoBranches();

        $this->viewBranch($a);
        $this->import('IMP-NAME-A', 'Lexus Box- 180 gm');

        $this->viewBranch($b);
        $this->import('IMP-NAME-B', 'Lexus Box- 180 gm');

        $this->assertSame(2, Product::query()->where('name_en', 'Lexus Box- 180 gm')->count(),
            '⛔ শাখা খ-এর পণ্য শাখা ক-এর একই নামের পণ্যে আটকে গেছে।');

        $this->viewBranch($a);
        $this->assertThrows(fn () => $this->import('IMP-NAME-A2', 'Lexus Box- 180 gm'),
            \Illuminate\Validation\ValidationException::class);

        // ⓘ সব শাখার পুরনো পণ্য (শাখার সারি নেই) প্রতিটা শাখার সাথেই মেলে
        $this->owner->forceFill(['view_all_branches' => true])->save();
        CompanyContext::set($this->company->id, $a->id);
        $this->import('IMP-NAME-ALL', 'Everywhere Biscuit');

        $this->viewBranch($b);
        $this->assertThrows(fn () => $this->import('IMP-NAME-ALL-B', 'Everywhere Biscuit'),
            \Illuminate\Validation\ValidationException::class);
    }

    /** @return array{0: Branch, 1: Branch} */
    private function twoBranches(): array
    {
        $branches = Branch::query()->where('company_id', $this->company->id)->orderBy('id')->get();

        if ($branches->count() < 2) {
            $branches->push(Branch::query()->create([
                'company_id' => $this->company->id, 'code' => 'IMP-B2', 'name_en' => 'Import branch two',
            ]));
        }

        return [$branches[0], $branches[1]];
    }

    private function viewBranch(Branch $branch): void
    {
        $this->owner->forceFill(['view_all_branches' => false, 'current_branch_id' => $branch->id])->save();
        CompanyContext::set($this->company->id, $branch->id);
    }

    private function import(string $code, ?string $name = null): Product
    {
        app(ProductImporter::class)->import([
            'code' => $code, 'name_en' => $name ?? 'Import '.$code, 'name_bn' => '', 'barcode' => '',
            'brand' => '', 'category' => '', 'unit' => '', 'tax' => '',
            'purchase_price' => '', 'sale_price' => '', 'reorder_level' => '',
        ]);

        return Product::query()->where('code', $code)->firstOrFail();
    }
}
