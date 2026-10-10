<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\DocumentStatus;
use App\Core\Support\StatusTone;
use Illuminate\Support\Facades\Blade;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ⭐ একই অবস্থা, একই রং — সব পর্দায় (পাতা সাজানোর পরিকল্পনা ধাপ ১, ১০ অক্টোবর ২০২৬: *"অবস্থার চিপ: একটাই চিপ, একটাই
 * রঙের মানে"*; [[StatusTone]])।
 *
 * ⓘ ছত্রিশটার মতো জায়গায় নিজের নিজের "অবস্থা → রং" তালিকা (ব্লেডে, অবস্থা-শ্রেণির `tone()`-এ)। পাহারাটা সবগুলো পড়ে —
 * ধ্রুবকের আসল মান বের করে (`self::`, `use` করা শ্রেণি, enum) — আর যে অবস্থার মানে সব জায়গায় এক (খসড়া, নিশ্চিত,
 * বাতিল, ফেরানো, মেয়াদ শেষ, বন্ধ), তার রং মানচিত্রের সাথে না মিললে লাল।
 *
 * ⓘ ছাড় গোনা, নীরব নয়: KNOWN-এ প্রতিটা কারণসহ; নতুন অমিল এলে লাল, পুরনোটা ঠিক হলেও লাল (তালিকা থেকে তুলতে হয়)।
 */
final class EveryStatusWearsOneColourTest extends TestCase
{
    private const TONES = 'success|danger|pending|info|draft|warning|inventory|muted';

    /** @var array<string, string> `ফাইল|অবস্থা|রং` => কেন মেনে নেওয়া */
    private const KNOWN = [
        // ⓘ অনুমোদনের অনুরোধ "বাতিল" মানে চাওয়া মানুষ নিজেই তুলে নিয়েছেন — ভুল বা ক্ষতি নয়, তাই ধূসর (লাল "ফেরানো"-র থেকে আলাদা থাকা দরকার)
        'app/Modules/Approval/Resources/views/inbox/partials/status.blade.php|cancelled|draft' => 'অনুরোধ তুলে নেওয়া — ভুল নয়',
        'app/Modules/Approval/Resources/views/inbox/show.blade.php|cancelled|draft' => 'অনুরোধ তুলে নেওয়া — ভুল নয়',
        // ⓘ চালানের যাত্রাপথের নিজের ন'টা রঙের দল (SaleTracking::COLOURS) — ব্যাজের রং নয়; "draft" এখানে দলের নাম, মিলটা কাকতালীয়
        'app/Modules/Sales/Services/SaleTracking.php|cancelled|draft' => 'যাত্রাপথের রঙের দল, ব্যাজ নয়',
    ];

    public function test_no_screen_paints_a_shared_status_another_colour(): void
    {
        $found = $this->mismatches();
        $new = array_values(array_diff(array_keys($found), array_keys(self::KNOWN)));

        $this->assertSame([], $new, implode("\n", [
            'এই অবস্থাগুলোর রং মানচিত্রের ([[StatusTone::MAP]]) সাথে মেলে না — একই কথা দুই পর্দায় দুই রঙে:',
            ...$new,
            '',
            'রংটা StatusTone থেকে নিন; সত্যিই আলাদা মানে হলে KNOWN-এ কারণসহ লিখুন।',
        ]));
    }

    public function test_the_list_of_exceptions_has_no_stale_rows(): void
    {
        $stale = array_values(array_diff(array_keys(self::KNOWN), array_keys($this->mismatches())));

        $this->assertSame([], $stale, "এই ছাড়গুলো আর দরকার নেই — তুলে দিন:\n".implode("\n", $stale));
    }

    /** পাহারাটা সত্যিই ধরে — ধ্রুবক, `self::`, লেখা শব্দ আর enum-এর মান, চার রকমেই */
    public function test_the_guard_reads_constants_self_and_plain_words(): void
    {
        $source = <<<'PHP'
            <?php
            namespace Fake;
            use App\Core\Support\DocumentStatus;
            use App\Modules\Promotion\Support\PromotionStatus;
            final class Thing {
                public const GONE = 'cancelled';
                public function tone(string $s): string {
                    return match ($s) {
                        DocumentStatus::CONFIRMED => 'info',
                        self::GONE => 'draft',
                        'rejected', 'opened' => 'pending',
                        DocumentStatus::DRAFT => 'draft',
                        PromotionStatus::EXPIRED => 'info',
                    };
                }
            }
            PHP;

        $this->assertSame(
            ['fake|confirmed|info', 'fake|cancelled|draft', 'fake|rejected|pending', 'fake|expired|info'],
            array_keys($this->scan('fake', $source, ['Fake\\Thing::GONE' => 'cancelled'])),
        );
    }

    public function test_the_shared_badge_reads_the_one_map(): void
    {
        foreach (StatusTone::MAP as $status => $tone) {
            $html = Blade::render('<x-ui.status-badge :status="$s" />', ['s' => $status]);
            $this->assertStringContainsString('--color-badge-'.$tone.'-bg', $html, "⛔ {$status} চিপে মানচিত্রের রং ({$tone}) নেই।");
        }

        $this->assertSame('success', StatusTone::of(DocumentStatus::CONFIRMED));
        $this->assertSame('draft', StatusTone::of('something-new'));
    }

    /** @return array<string, true> */
    private function mismatches(): array
    {
        $found = [];

        foreach ((new Finder)->files()->in([base_path('app'), base_path('resources/views')])->name('*.php') as $file) {
            $rel = str_replace('\\', '/', substr($file->getRealPath(), strlen(base_path()) + 1));
            $found += $this->scan($rel, $file->getContents());
        }

        return $found;
    }

    /**
     * @param  array<string, string>  $fakeConstants  কেবল নিজের-পরীক্ষার নকল শ্রেণির জন্য
     * @return array<string, true>
     */
    private function scan(string $rel, string $source, array $fakeConstants = []): array
    {
        $namespace = preg_match('/^namespace\s+([\w\\\\]+);/m', $source, $m) ? $m[1] : '';
        $class = preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|enum)\s+(\w+)/m', $source, $m) ? $m[1] : '';
        $uses = [];
        preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?;/m', $source, $all, PREG_SET_ORDER);
        foreach ($all as $u) {
            $uses[$u[2] ?? substr(strrchr('\\'.$u[1], '\\'), 1)] = $u[1];
        }

        $key = '(?:\\\\?[\w\\\\]+::[A-Z][A-Z0-9_]*|\'[a-z_]+\')';
        preg_match_all('/('.$key.'(?:\s*,\s*'.$key.')*)\s*=>\s*\'('.self::TONES.')\'/', $source, $hits, PREG_SET_ORDER);

        $out = [];
        foreach ($hits as [, $keys, $tone]) {
            foreach (preg_split('/\s*,\s*/', $keys) as $k) {
                $value = $this->valueOf($k, $namespace, $class, $uses, $fakeConstants);

                if (is_string($value) && isset(StatusTone::MAP[$value]) && StatusTone::MAP[$value] !== $tone) {
                    $out["{$rel}|{$value}|{$tone}"] = true;
                }
            }
        }

        return $out;
    }

    /** @param  array<string, string>  $uses */
    private function valueOf(string $k, string $namespace, string $class, array $uses, array $fake): mixed
    {
        if ($k[0] === "'") {
            return trim($k, "'");
        }

        [$owner, $const] = explode('::', ltrim($k, '\\'));
        $fqcn = match (true) {
            $owner === 'self' || $owner === 'static' => ($namespace !== '' ? $namespace.'\\' : '').$class,
            str_contains($owner, '\\') => $owner,
            isset($uses[$owner]) => $uses[$owner],
            default => ($namespace !== '' ? $namespace.'\\' : '').$owner,
        };

        if (isset($fake[$fqcn.'::'.$const])) {
            return $fake[$fqcn.'::'.$const];
        }

        if (! defined($fqcn.'::'.$const)) {
            return null;
        }

        $value = constant($fqcn.'::'.$const);

        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}
