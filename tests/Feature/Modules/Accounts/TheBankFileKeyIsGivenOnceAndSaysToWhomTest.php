<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

/**
 * ⭐ ব্যাংক ফাইলের চাবির মাইগ্রেশন (2027_04_04_100000) — দুইবার চালালেও একবারই দেয়, আর কাকে দিল লগে বলে (fe-র দুই শর্ত, ২৫ অক্টোবর ২০২৬)।
 *
 * দাবি:
 *   ১. যে ভূমিকা পরিশোধ পাকা করে (`accounts.voucher.update`), সে চাবি পায়; প্রতিটার এক লগ লাইন (কোম্পানির কোড, ভূমিকা, team id),
 *      আর একটা সারাংশ লাইন
 *   ২. আবার চালালে কিছু বদলায় না — চাবি একটাই, ভূমিকা↔চাবির জোড়া একই; নতুন দেওয়ার লাইন নেই, সারাংশ বলে ০
 *
 * ⓘ লগ ধরা MessageLogged ঘটনায় — Log-এর স্পাই নতুন ফাইলে phpstan লাল করে।
 */
final class TheBankFileKeyIsGivenOnceAndSaysToWhomTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'accounts.voucher.bank_file';

    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    /** ⓘ লগ শোনা — লগার যে dispatcher-এ ঘটনা ছাড়ে ঠিক সেটাতেই, চালানোর ঠিক আগে */
    private function listen(): void
    {
        $this->lines = [];
        $this->app['events']->listen(MessageLogged::class, function (MessageLogged $logged): void {
            $this->lines[] = $logged->message;
        });
    }

    public function test_the_key_is_given_once_and_the_log_says_to_whom(): void
    {
        // ⓘ মাইগ্রেশনের আগের দশা — চাবির জোড়া নেই (মাইগ্রেশন আর বীজ দুজনেই আগে দিয়ে রেখেছে)
        $keyIds = DB::table('permissions')->where('name', self::KEY)->pluck('id')->all();
        DB::table('role_has_permissions')->whereIn('permission_id', $keyIds)->delete();
        // ⓘ প্রস্তুতি নিজে প্রমাণ করে — চাবির কোনো জোড়া নেই (নাহলে up() কিছুই দিত না, কেবল "0 role(s)" লিখত)
        $this->assertSame([], $this->holders(), 'দৃশ্যটাই ঠিক নয় — চাবির জোড়া মোছা যায়নি');
        $payers = DB::table('role_has_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->join('roles as r', 'r.id', '=', 'rp.role_id')->leftJoin('companies as c', 'c.id', '=', 'r.company_id')
            ->where('p.name', 'accounts.voucher.update')->get(['r.id', 'r.name', 'r.company_id', 'c.code']);
        $this->assertGreaterThan(0, $payers->count(), 'দৃশ্যটাই ঠিক নয় — পরিশোধ পাকা করার কোনো ভূমিকা নেই');

        $migration = require base_path('app/Modules/Accounts/Database/Migrations/2027_04_04_100000_a_bank_file_has_its_own_key.php');
        $this->listen();
        $migration->up();

        $this->assertSame($payers->pluck('id')->sort()->values()->all(), $this->holders(), '⛔ পরিশোধ পাকা করে এমন প্রতিটা ভূমিকা, আর কেবল তারাই, চাবি পায়নি');
        foreach ($payers as $role) {
            $line = sprintf('Bank file key given: company=%s role=%s team_id=%s', (string) ($role->code ?? '?'), (string) $role->name, (string) $role->company_id);
            $this->assertContains($line, $this->lines, '⛔ লগে কোম্পানি, ভূমিকা আর team id-র লাইন নেই: '.$line);
        }
        $this->assertContains(sprintf('Bank file key: %d role(s) given accounts.voucher.bank_file (those that post payments)', $payers->count()), $this->lines,
            '⛔ লগে সারাংশের লাইন নেই, বা গোনা ভুল');

        $keys = DB::table('permissions')->where('name', self::KEY)->count();
        $pairs = DB::table('role_has_permissions')->orderBy('permission_id')->orderBy('role_id')->get()->map(fn ($r) => $r->permission_id.':'.$r->role_id)->all();
        $this->lines = [];

        try {
            $migration->up();
        } catch (Throwable $e) {
            $this->fail('⛔ দ্বিতীয়বার চালাতে আবার দিতে গেল: '.$e->getMessage());
        }

        $this->assertSame($keys, DB::table('permissions')->where('name', self::KEY)->count(), '⛔ দ্বিতীয়বার চালাতে চাবি আবার বানাল');
        $this->assertSame($pairs, DB::table('role_has_permissions')->orderBy('permission_id')->orderBy('role_id')->get()->map(fn ($r) => $r->permission_id.':'.$r->role_id)->all(),
            '⛔ দ্বিতীয়বার চালাতে ভূমিকা↔চাবির জোড়া বদলাল');
        $this->assertSame([], array_values(array_filter($this->lines, fn (string $l) => str_starts_with($l, 'Bank file key given:'))), '⛔ দ্বিতীয়বার চালাতে আবার "দিল" লেখা হলো');
        $this->assertContains('Bank file key: 0 role(s) given accounts.voucher.bank_file (those that post payments)', $this->lines, '⛔ দ্বিতীয় চালানোর সারাংশ ০ বলে না');
    }

    /** @return list<int> */
    private function holders(): array
    {
        return DB::table('role_has_permissions')->whereIn('permission_id', DB::table('permissions')->where('name', self::KEY)->select('id'))
            ->orderBy('role_id')->pluck('role_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }
}
