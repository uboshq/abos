<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Module\ModuleDefinition;
use App\Core\Module\ModuleRegistry;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * নতুন মডিউল কোর কোড না ছুঁয়েই যোগ হয় — প্ল্যান সেকশন ১৯।
 *
 * এখানকার বেশিরভাগ টেস্ট ভুল module.php নিয়ে, কারণ ভুলটা বুট-টাইমে ধরা
 * পড়া দরকার। ছয় মাস পরে একটা ফাঁকা মেনু দেখে খোঁজা শুরু করলে অনেক দেরি।
 */
class ModuleRegistryTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = storage_path('framework/testing/modules-'.uniqid());
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->tmp);
        parent::tearDown();
    }

    private function writeModule(string $folder, array $definition): void
    {
        $dir = $this->tmp.DIRECTORY_SEPARATOR.$folder;
        mkdir($dir, 0777, true);
        file_put_contents($dir.DIRECTORY_SEPARATOR.'module.php', '<?php return '.var_export($definition, true).';');
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path.DIRECTORY_SEPARATOR.$entry;
            is_dir($full) ? $this->deleteTree($full) : unlink($full);
        }

        rmdir($path);
    }

    public function test_the_real_modules_folder_loads(): void
    {
        $registry = app(ModuleRegistry::class);

        $this->assertTrue($registry->has('accounts'));
        $this->assertTrue($registry->has('customer'));
    }

    public function test_a_module_is_found_just_by_being_a_folder_with_a_module_file(): void
    {
        $this->writeModule('Payroll', [
            'code' => 'payroll',
            'name' => ['en' => 'Payroll', 'bn' => 'বেতন'],
            'depends_on' => [],
        ]);

        $registry = new ModuleRegistry($this->tmp);

        // কোথাও 'payroll' লেখা হয়নি — শুধু ফোল্ডারটা রাখা হয়েছে।
        $this->assertTrue($registry->has('payroll'));
        $this->assertSame('Payroll', $registry->get('payroll')->name['en']);
    }

    public function test_a_folder_without_a_module_file_is_simply_not_a_module_yet(): void
    {
        mkdir($this->tmp.DIRECTORY_SEPARATOR.'HalfBuilt');

        $registry = new ModuleRegistry($this->tmp);

        // ফোল্ডার আগে বানিয়ে পরে module.php লেখা স্বাভাবিক ক্রম — এতে
        // অ্যাপ্লিকেশন ভেঙে পড়া উচিত নয়।
        $this->assertSame([], $registry->all());
    }

    public function test_dependencies_come_before_the_modules_that_need_them(): void
    {
        $this->writeModule('Sales', [
            'code' => 'sales',
            'name' => ['en' => 'Sales', 'bn' => 'বিক্রয়'],
            'depends_on' => ['customer', 'accounts'],
        ]);
        $this->writeModule('Customer', [
            'code' => 'customer',
            'name' => ['en' => 'Customer', 'bn' => 'গ্রাহক'],
            'depends_on' => ['accounts'],
        ]);
        $this->writeModule('Accounts', [
            'code' => 'accounts',
            'name' => ['en' => 'Accounts', 'bn' => 'হিসাব'],
            'depends_on' => [],
        ]);

        $order = array_keys((new ModuleRegistry($this->tmp))->all());

        // Sales-এর মাইগ্রেশন Accounts-এর টেবিলের আগে চললে ফরেন কি বসবে না।
        $this->assertSame(['accounts', 'customer', 'sales'], $order);
    }

    public function test_a_dependency_cycle_is_caught_instead_of_looping_forever(): void
    {
        $this->writeModule('A', ['code' => 'a', 'name' => ['en' => 'A', 'bn' => 'ক'], 'depends_on' => ['b']]);
        $this->writeModule('B', ['code' => 'b', 'name' => ['en' => 'B', 'bn' => 'খ'], 'depends_on' => ['a']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Circular module dependency/');

        (new ModuleRegistry($this->tmp))->all();
    }

    public function test_a_missing_dependency_names_itself(): void
    {
        $this->writeModule('Sales', [
            'code' => 'sales',
            'name' => ['en' => 'Sales', 'bn' => 'বিক্রয়'],
            'depends_on' => ['inventory'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/inventory.*was not found/');

        (new ModuleRegistry($this->tmp))->all();
    }

    public function test_two_modules_cannot_claim_the_same_code(): void
    {
        $this->writeModule('SalesOne', ['code' => 'sales', 'name' => ['en' => 'Sales', 'bn' => 'বিক্রয়']]);
        $this->writeModule('SalesTwo', ['code' => 'sales', 'name' => ['en' => 'Sales 2', 'bn' => 'বিক্রয় ২']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("/Two modules claim the code 'sales'/");

        (new ModuleRegistry($this->tmp))->all();
    }

    public function test_a_module_without_both_languages_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/needs both 'en' and 'bn'/");

        // নিয়ম ৯ — এক ভাষা থাকলে অন্যটায় চুপচাপ ফলব্যাক হত আর কেউ টের পেত না।
        ModuleDefinition::fromArray(
            ['code' => 'sales', 'name' => ['en' => 'Sales']],
            'test/module.php',
            'App\\Modules\\Sales',
        );
    }

    public function test_a_permission_must_be_prefixed_with_its_module(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be prefixed/');

        // দুইটা মডিউল 'view' নামে অনুমতি দিলে একটার অনুমতি অন্যটার দরজা খুলে দিত।
        ModuleDefinition::fromArray(
            [
                'code' => 'sales',
                'name' => ['en' => 'Sales', 'bn' => 'বিক্রয়'],
                'permissions' => ['view'],
            ],
            'test/module.php',
            'App\\Modules\\Sales',
        );
    }

    public function test_an_unknown_menu_group_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown menu group/');

        // সব মডিউলে একই ছয়-ভাগ প্যাটার্ন (সেকশন ১৫.২) — সপ্তম ভাগ মানে
        // ব্যবহারকারীকে প্রতিটা মডিউল আলাদা করে শিখতে হবে।
        ModuleDefinition::fromArray(
            [
                'code' => 'sales',
                'name' => ['en' => 'Sales', 'bn' => 'বিক্রয়'],
                'menu' => ['favourites' => []],
            ],
            'test/module.php',
            'App\\Modules\\Sales',
        );
    }

    public function test_the_module_code_must_be_snake_case(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/lowercase snake_case/');

        ModuleDefinition::fromArray(
            ['code' => 'SalesModule', 'name' => ['en' => 'Sales', 'bn' => 'বিক্রয়']],
            'test/module.php',
            'App\\Modules\\Sales',
        );
    }

    public function test_a_module_shows_its_name_in_the_readers_language(): void
    {
        $definition = ModuleDefinition::fromArray(
            ['code' => 'sales', 'name' => ['en' => 'Sales', 'bn' => 'বিক্রয়']],
            'test/module.php',
            'App\\Modules\\Sales',
        );

        $this->assertSame('বিক্রয়', $definition->label('bn'));
        $this->assertSame('Sales', $definition->label('en'));
    }
}
