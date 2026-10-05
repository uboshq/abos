<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Services\StockTransferService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * একই মানুষ ট্রাক পাঠাতেন আর নিজেই গ্রহণ করতেন — Inventory অডিট ম৪; মালিক, ৫ অক্টোবর ২০২৬ ("এভাবেই করো")।
 *
 * ⭐ কোম্পানির সুইচ `inventory.transfer_two_people` (ডিফল্ট বন্ধ): বন্ধে আজকের মতো; চালুতে যিনি পাঠান তিনি গ্রহণ করেন না;
 * সুপার অ্যাডমিন পারেন, কিন্তু অডিটে লেখা থাকে যে একই মানুষ দুটোই করেছেন; আলাদা মানুষ সবসময় পারেন।
 */
final class OnePersonSentAndReceivedTheSameTruckTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $from;

    private Warehouse $to;

    private Product $product;

    private User $owner;

    private User $clerk;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->clerk = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $this->other = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->from = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->to = Warehouse::query()->whereKeyNot($this->from->id)->where('is_active', true)->orderBy('id')->first()
            ?? Warehouse::query()->create(['code' => 'TRK4', 'name_en' => 'Fourth store', 'is_active' => true, 'branch_id' => $this->from->branch_id]);

        $this->product = Product::query()->create([
            'code' => 'M4-'.mb_substr(md5(microtime()), 0, 8), 'name_en' => 'Two people probe', 'name_bn' => 'দুজনের নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true, 'track_batch' => false,
        ]);
        app(StockService::class)->move(product: $this->product, warehouse: $this->from, sourceType: 'test.opening', sourceId: $this->product->id, floor: '100');
    }

    /** ⓘ একই মানুষ: সুইচ বন্ধে আজকের মতো চলে; চালুতে থামে — দুটোই একই মানুষ দিয়ে, পাশাপাশি */
    public function test_the_same_person_receives_with_the_switch_off_and_is_stopped_with_it_on(): void
    {
        $off = $this->sentBy($this->clerk);
        $this->receiveAs($this->clerk, $off);
        $this->assertSame(DocumentStatus::CLOSED, $off->fresh()->status, '⛔ সুইচ বন্ধেও একই মানুষ গ্রহণ করতে পারলেন না — আজকের আচরণ ভেঙেছে।');

        app(SettingsService::class)->set('inventory.transfer_two_people', true);
        $on = $this->sentBy($this->clerk);

        try {
            $this->receiveAs($this->clerk, $on);
            $this->fail('⛔ সুইচ চালু, তবু যিনি পাঠালেন তিনিই গ্রহণ করলেন।');
        } catch (ValidationException $e) {
            $this->assertStringContainsString((string) $on->document_no, (string) ($e->errors()['status'][0] ?? ''));
        }

        $this->assertSame(DocumentStatus::CONFIRMED, $on->fresh()->status, '⛔ থামা গ্রহণেও অবস্থা বদলেছে।');
    }

    /** ⭐ সুপার অ্যাডমিন চালুতেও পারেন — আর অডিটে দাগ পড়ে */
    public function test_a_super_admin_may_receive_their_own_truck_and_the_audit_says_so(): void
    {
        app(SettingsService::class)->set('inventory.transfer_two_people', true);
        $transfer = $this->sentBy($this->owner);

        $this->receiveAs($this->owner, $transfer);

        $this->assertSame(DocumentStatus::CLOSED, $transfer->fresh()->status);
        $this->assertTrue(AuditTrail::query()->whereIn('auditable_type', [$transfer->getMorphClass(), StockTransfer::class])->where('auditable_id', $transfer->id)
            ->where('action', 'received_by_its_sender')->exists(), '⛔ সুপার অ্যাডমিন নিজের পাঠানো নিজে গ্রহণ করলেন, অডিটে কিছু লেখা নেই।');
    }

    /** ⭐ আলাদা মানুষ চালুতেও পারেন — আর অডিটে কোনো দাগ নেই */
    public function test_a_different_person_receives_with_the_switch_on(): void
    {
        app(SettingsService::class)->set('inventory.transfer_two_people', true);
        $transfer = $this->sentBy($this->clerk);

        $this->receiveAs($this->other, $transfer);

        $this->assertSame(DocumentStatus::CLOSED, $transfer->fresh()->status);
        $this->assertFalse(AuditTrail::query()->where('action', 'received_by_its_sender')->exists());
    }

    private function sentBy(User $user): StockTransfer
    {
        $this->actingAs($user);
        $transfer = app(StockTransferService::class)->create(
            ['from_warehouse_id' => $this->from->id, 'to_warehouse_id' => $this->to->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '5']],
        );

        return app(StockTransferService::class)->dispatch($transfer);
    }

    private function receiveAs(User $user, StockTransfer $transfer): void
    {
        $this->actingAs($user);
        app(StockTransferService::class)->receive($transfer->fresh());
    }
}
