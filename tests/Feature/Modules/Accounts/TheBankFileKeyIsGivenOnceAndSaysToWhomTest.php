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
 * ⭐ রায় লগের ফাইল থেকে (fe, ২৫ অক্টোবর): পরীক্ষার নিজের single-চ্যানেল ফাইল, শুরুতে খালি, শেষে মোছা — লাইভে যা লেখা হয়, ঠিক
 * সেই পথে। ⓘ MessageLogged-এর শোনা লাইন কেবল ⛔ বার্তায় ছাপা, তুলনার জন্য — Mac-এ শোনা পথ কিছুই পায়নি (cb-ma-5s, ৫t), কারণ অজানা।
 * ⓘ Log-এর স্পাই নয় — নতুন ফাইলে phpstan লাল করে।
 */
final class TheBankFileKeyIsGivenOnceAndSaysToWhomTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'accounts.voucher.bank_file';

    /** @var list<string> */
    private array $heard = [];

    private string $file = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        // ⓘ পরীক্ষার নিজের লগ ফাইল — নতুন চ্যানেলের নাম, তাই লগার নতুন করে বানায় আর ঠিক এই ফাইলে লেখে
        $this->file = storage_path('logs/claim-bank-file-key-'.getmypid().'.log');
        @unlink($this->file);
        config([
            'logging.channels.claim_bank_file_key' => ['driver' => 'single', 'path' => $this->file, 'level' => 'debug', 'replace_placeholders' => true],
            'logging.default' => 'claim_bank_file_key',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    /** ⓘ শোনা — কেবল তুলনার জন্য, রায় ফাইল থেকে */
    private function listen(): void
    {
        $this->heard = [];
        $this->app['events']->listen(MessageLogged::class, function (MessageLogged $logged): void {
            $this->heard[] = $logged->message;
        });
    }

    /** ফাইলে যা লেখা হয়েছে — খালি করা থেকে এখন পর্যন্ত */
    private function written(): string
    {
        clearstatcache(true, $this->file);

        return is_file($this->file) ? (string) file_get_contents($this->file) : '';
    }

    private function why(string $what): string
    {
        return $what.' — ফাইলে: '.json_encode(trim($this->written()), JSON_UNESCAPED_UNICODE)
            .' — শোনা: '.json_encode(array_values(array_unique($this->heard)), JSON_UNESCAPED_UNICODE);
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
        $this->assertSame('', $this->written(), 'দৃশ্যটাই ঠিক নয় — পরীক্ষার লগ ফাইল শুরুতে খালি নয়');

        $migration = require base_path('app/Modules/Accounts/Database/Migrations/2027_04_04_100000_a_bank_file_has_its_own_key.php');
        $this->listen();
        $migration->up();

        $this->assertSame($payers->pluck('id')->sort()->values()->all(), $this->holders(), '⛔ পরিশোধ পাকা করে এমন প্রতিটা ভূমিকা, আর কেবল তারাই, চাবি পায়নি');
        foreach ($payers as $role) {
            $line = sprintf('Bank file key given: company=%s role=%s team_id=%s', (string) ($role->code ?? '?'), (string) $role->name, (string) $role->company_id);
            $this->assertStringContainsString($line, $this->written(), $this->why('⛔ লগে কোম্পানি, ভূমিকা আর team id-র লাইন নেই: '.$line));
        }
        $summary = sprintf('Bank file key: %d role(s) given accounts.voucher.bank_file (those that post payments)', $payers->count());
        $this->assertStringContainsString($summary, $this->written(), $this->why('⛔ লগে সারাংশের লাইন নেই, বা গোনা ভুল'));

        $keys = DB::table('permissions')->where('name', self::KEY)->count();
        $pairs = DB::table('role_has_permissions')->orderBy('permission_id')->orderBy('role_id')->get()->map(fn ($r) => $r->permission_id.':'.$r->role_id)->all();
        $before = strlen($this->written());
        $this->heard = [];

        try {
            $migration->up();
        } catch (Throwable $e) {
            $this->fail('⛔ দ্বিতীয়বার চালাতে আবার দিতে গেল: '.$e->getMessage());
        }

        $again = substr($this->written(), $before);
        $this->assertSame($keys, DB::table('permissions')->where('name', self::KEY)->count(), '⛔ দ্বিতীয়বার চালাতে চাবি আবার বানাল');
        $this->assertSame($pairs, DB::table('role_has_permissions')->orderBy('permission_id')->orderBy('role_id')->get()->map(fn ($r) => $r->permission_id.':'.$r->role_id)->all(),
            '⛔ দ্বিতীয়বার চালাতে ভূমিকা↔চাবির জোড়া বদলাল');
        $this->assertStringNotContainsString('Bank file key given:', $again, $this->why('⛔ দ্বিতীয়বার চালাতে আবার "দিল" লেখা হলো'));
        $this->assertStringContainsString('Bank file key: 0 role(s) given accounts.voucher.bank_file (those that post payments)', $again,
            $this->why('⛔ দ্বিতীয় চালানোর সারাংশ ০ বলে না'));
    }

    /** @return list<int> */
    private function holders(): array
    {
        return DB::table('role_has_permissions')->whereIn('permission_id', DB::table('permissions')->where('name', self::KEY)->select('id'))
            ->orderBy('role_id')->pluck('role_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }
}
