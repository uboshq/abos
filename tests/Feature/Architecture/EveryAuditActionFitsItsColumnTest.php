<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ⛔ নিরীক্ষার কাজের নাম ঘরের চেয়ে লম্বা হলে পুরো অনুরোধটাই ৫০০ দেয় (fe, ২০ অক্টোবর ২০২৬): পাওনা-দেনা নিশ্চিতকরণের চিঠির
 * `balance_confirmation_printed` (২৮) `audit_trails.action`-এ (২৪) ধরত না, আর প্রতিটা চিঠি ছাপার মুহূর্তে থেমে যেত — দাবি HTTP
 * দরজায় ছিল বলে Mac-এ ধরা পড়ল, পড়ে দেখে নয়।
 *
 * <p>এই পাহারা `app/`-এর প্রতিটা `recordAction(…, 'নাম')` আর `->record(…, 'নাম')` খোঁজে — নাম লেখা হোক বা ধ্রুবকে
 * (`self::X`, `Class::X`, ফাইলের `use` ধরে) — আর ঘরের দৈর্ঘ্যের সাথে মেলায়। ⛔ দৈর্ঘ্য schema থেকে পড়া, সংখ্যা এখানে লেখা নেই:
 * ঘর বড় হলে পাহারা নিজেই মানে। যে ধ্রুবক খুঁজে পাওয়া যায় না সেটাও লাল — চুপচাপ বাদ পড়লে পাহারা মিথ্যা নিশ্চয়তা দিত।
 */
final class EveryAuditActionFitsItsColumnTest extends TestCase
{
    use RefreshDatabase;

    private const CALL = '/(?:recordAction|->record)\(\s*[^,()]+(?:\([^()]*\))?,\s*(?:\'([^\']+)\'|([A-Za-z_\\\\]+)::([A-Z0-9_]+))/';

    public function test_every_audit_action_fits_audit_trails_action(): void
    {
        $limit = $this->columnLength();
        [$names, $unresolved] = $this->actions();

        $tooLong = array_values(array_filter(array_map(
            fn (array $a) => mb_strlen($a['name']) > $limit ? $a['file'].' — '.$a['name'].' ('.mb_strlen($a['name']).')' : null,
            $names,
        )));

        $this->assertSame([], $unresolved, "এই নিরীক্ষার নামগুলো খুঁজে পাওয়া গেল না — ধ্রুবকটা কোথায়?\n".implode("\n", $unresolved));
        $this->assertSame([], $tooLong, "এই নামগুলো audit_trails.action-এর ({$limit} অক্ষর) চেয়ে লম্বা — ছাপার/বদলের মুহূর্তে ৫০০ দেবে:\n".implode("\n", $tooLong));
    }

    /** ⓘ পাহারাটা সত্যিই পড়ছে কি না — এক অক্ষর বেশি নাম ধরে, আর অনেক নাম খুঁজে পায় */
    public function test_the_sweep_reads_the_real_limit_and_the_real_names(): void
    {
        $limit = $this->columnLength();
        $this->assertGreaterThanOrEqual(16, $limit, 'প্রস্তুতিটাই ভুল — schema থেকে দৈর্ঘ্য এল না');
        $this->assertTrue(mb_strlen(str_repeat('x', $limit + 1)) > $limit);

        [$names] = $this->actions();
        $this->assertGreaterThan(30, count($names), '⛔ খোঁজা নিরীক্ষার নাম খুঁজেই পাচ্ছে না');
        $this->assertContains('balance_letter_printed', array_column($names, 'name'), '⛔ ধ্রুবকে রাখা নাম (BalanceConfirmation::ACTION) ধরা হয়নি');
    }

    private function columnLength(): int
    {
        $column = collect(Schema::getColumns('audit_trails'))->firstWhere('name', 'action');
        $this->assertNotNull($column, 'audit_trails.action নেই');
        $this->assertMatchesRegularExpression('/\((\d+)\)/', (string) $column['type'], 'audit_trails.action-এর দৈর্ঘ্য পড়া গেল না: '.$column['type']);
        preg_match('/\((\d+)\)/', (string) $column['type'], $m);

        return (int) $m[1];
    }

    /** @return array{0: list<array{file: string, name: string}>, 1: list<string>} */
    private function actions(): array
    {
        $names = [];
        $unresolved = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (! str_contains($source, 'recordAction(') && ! str_contains($source, '->record(')) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));

            preg_match_all(self::CALL, $source, $calls, PREG_SET_ORDER);
            foreach ($calls as $call) {
                if (($call[1] ?? '') !== '') {
                    $names[] = ['file' => $rel, 'name' => $call[1]];

                    continue;
                }
                $value = $this->constantIn($source, $call[2], $call[3]);
                $value === null ? $unresolved[] = $rel.' — '.$call[2].'::'.$call[3] : $names[] = ['file' => $rel, 'name' => $value];
            }
        }

        return [$names, $unresolved];
    }

    private function constantIn(string $source, string $class, string $name): ?string
    {
        preg_match('/^namespace\s+([^;]+);/m', $source, $ns);
        preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|trait|enum)\s+(\w+)/m', $source, $own);
        $namespace = $ns[1] ?? '';

        if (in_array($class, ['self', 'static'], true)) {
            $fqcn = $namespace.'\\'.($own[1] ?? '');
        } elseif (str_starts_with($class, '\\')) {
            $fqcn = ltrim($class, '\\');
        } elseif (preg_match('/^use\s+([^;\s]+\\\\'.preg_quote($class, '/').');/m', $source, $use)) {
            $fqcn = $use[1];
        } else {
            $fqcn = $namespace.'\\'.$class;
        }

        $value = defined($fqcn.'::'.$name) ? constant($fqcn.'::'.$name) : null;

        return is_string($value) ? $value : null;
    }
}
