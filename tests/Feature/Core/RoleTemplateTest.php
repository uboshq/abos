<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\PermissionSyncer;
use App\Core\Services\RoleTemplateRegistry;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ডিফল্ট রোল-টেমপ্লেট — নতুন ইনস্টলে রোলগুলো ঠিক অনুমতি নিয়ে বসে, আর
 * একবার বসার পর ক্রেতার হাতে (তালা নয়)।
 *
 * সবচেয়ে জরুরি তিনটা: (১) মাঠকর্মী কোনোদিন মজুদ দেখে না; (২) রোলগুলো
 * সত্যিই তৈরি হয় (নাহলে মোবাইল অচল); (৩) ক্রেতা রোল সাজানোর পর পরের sync
 * সেটা মুছে দেয় না।
 */
class RoleTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // DemoSeeder নিজেই PermissionSyncer::sync() ডাকে → টেমপ্লেট-রোল বসে
        $this->seed(DemoSeeder::class);
    }

    private function role(string $name): ?Role
    {
        return Role::query()->where('name', $name)->where('guard_name', 'web')->first();
    }

    /** ★ চারটা টেমপ্লেট-রোল বসে, আর তাদের cross-module অনুমতি জোড়া লাগে। */
    public function test_the_template_roles_land_with_their_permissions(): void
    {
        $sr = $this->role('Field Sales');
        $this->assertNotNull($sr, 'SR রোল বসেনি — মোবাইল বিক্রয় অচল হত।');
        $this->assertTrue($sr->hasPermissionTo('sales.order.create'));       // Sales থেকে
        $this->assertTrue($sr->hasPermissionTo('inventory.product.view'));   // Inventory থেকে
        $this->assertTrue($sr->hasPermissionTo('hr.attendance.self'));       // Hr থেকে
        $this->assertTrue($sr->hasPermissionTo('customer.create'));          // Customer থেকে

        $this->assertTrue($this->role('Warehouse')->hasPermissionTo('inventory.stock.view'));
        $this->assertTrue($this->role('HR')->hasPermissionTo('hr.employee.view'));
        $this->assertTrue($this->role('Manager')->hasPermissionTo('approval.decide'));
    }

    /** ★ মাঠকর্মী কোনোদিন মজুদ দেখে না — মালিকের স্থায়ী নিয়ম। */
    public function test_the_sr_role_never_sees_stock(): void
    {
        $sr = $this->role('Field Sales');

        $this->assertFalse($sr->hasPermissionTo('inventory.stock.view'), 'SR মজুদ দেখতে পাচ্ছে — মালিকের নিয়ম ভাঙছে।');
        $this->assertFalse($sr->hasPermissionTo('inventory.cost.view'), 'SR ক্রয়মূল্য দেখতে পাচ্ছে।');
    }

    /** ⛔ Delivery রোল এখন বসে না — পণ্যে "পৌঁছেছি" অ্যাকশন নেই (কাগজে কারণ)। */
    public function test_the_delivery_role_is_not_created_yet(): void
    {
        $this->assertNull($this->role('Delivery'), 'Delivery রোল বসেছে — অথচ ডেলিভারি নিশ্চিত করার কাজটাই পণ্যে নেই।');
        $this->assertNotContains('Delivery', app(RoleTemplateRegistry::class)->declaredRoles());
    }

    /** ★ ক্রেতা রোল সাজানোর পর পরের sync সেটা মুছে দেয় না — শুরুর সারি, তালা নয়। */
    public function test_an_edited_role_is_not_clobbered_by_a_later_sync(): void
    {
        $sr = $this->role('Field Sales');
        // ক্রেতা একটা অনুমতি তুলে নিলেন
        $sr->revokePermissionTo('customer.create');
        $this->assertFalse($sr->fresh()->hasPermissionTo('customer.create'));

        // পরের deploy আবার sync চালায়
        app(PermissionSyncer::class)->sync();

        // রোলটা আগে থেকেই ছিল, তাই টেমপ্লেট আর ছোঁয়নি — ক্রেতার বদল টিকে আছে
        $this->assertFalse(
            $this->role('Field Sales')->hasPermissionTo('customer.create'),
            'পরের sync ক্রেতার তুলে নেওয়া অনুমতি ফিরিয়ে দিয়েছে — টেমপ্লেট তালা হয়ে গেছে।',
        );
    }

    /** owner টেমপ্লেটে নেই — সে সবসময় সব পায় (keepOwnerComplete)। */
    public function test_owner_is_not_a_template_and_keeps_everything(): void
    {
        $this->assertNotContains('owner', app(RoleTemplateRegistry::class)->declaredRoles());
        $this->assertTrue($this->role('owner')->hasPermissionTo('inventory.stock.view'));
    }
}
