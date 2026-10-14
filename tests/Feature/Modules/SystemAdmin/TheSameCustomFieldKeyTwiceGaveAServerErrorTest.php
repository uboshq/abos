<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\CustomFieldService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * একই নিজস্ব ঘর দুবার বানাতে গেলে ৫০০ — সিস্টেম-অ্যাডমিন লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ টেবিলে (কোম্পানি, জিনিস, চাবি) অনন্য, কিন্তু [[CustomFieldController::store()]] সেটা দেখত না — দ্বিতীয়বার "সংরক্ষণ"
 * চাপলেই ডাটাবেজের ব্যতিক্রম। ⓘ অন্য জিনিসে একই চাবি চলে — দাবি সেটাও দেখে, যাতে বেশি বন্ধ না হয়।
 */
final class TheSameCustomFieldKeyTwiceGaveAServerErrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_repeated_key_is_a_message_and_another_entity_may_reuse_it(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $entities = array_keys(app(CustomFieldService::class)->entities());
        $this->assertGreaterThanOrEqual(2, count($entities), 'দুইটা জিনিস লাগে — দাবিটা কিছু মাপছে না।');

        $make = fn (string $entity) => $this->post(route('system_admin.custom_field.store'), [
            'entity' => $entity, 'key' => 'trade_licence', 'label_en' => 'Trade licence',
            'label_bn' => 'ট্রেড লাইসেন্স', 'type' => 'text',
        ]);

        $make($entities[0])->assertSessionHasNoErrors();
        $make($entities[0])->assertRedirect()->assertSessionHasErrors('key');
        $make($entities[1])->assertSessionHasNoErrors();

        $this->assertSame(2, CustomField::query()->where('key', 'trade_licence')->count());
    }
}
