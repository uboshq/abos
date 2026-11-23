<?php

declare(strict_types=1);

namespace App\Core\Bulk;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Lang;

/**
 * ⭐ একসাথে কাজের ফল — "n টা হলো · m টা বাদ", আর বাদগুলোর প্রতিটা কেন (মালিক, ১৯ নভেম্বর ২০২৬)।
 *
 * ⓘ কারণ হয় `core.bulk.why.*`-এর একটা চাবি (not_found, not_allowed, not_draft, own_paper …), নয়তো কাগজের নিজের দরজার
 *   লেখা বার্তা (যেমন "ধারের সীমা পেরোবে") — চাবি হলে অনুবাদ, নইলে হুবহু।
 */
final class BulkOutcome
{
    private int $done = 0;

    /** @var list<array{name: string, why: string}> */
    private array $skipped = [];

    public function done(): void
    {
        $this->done++;
    }

    public function skip(string $name, string $why): void
    {
        $this->skipped[] = ['name' => $name, 'why' => $why];
    }

    public function doneCount(): int
    {
        return $this->done;
    }

    /** @return list<array{name: string, why: string}> */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /** ⓘ কারণের চাবি হলে অনুবাদ, নইলে হুবহু */
    public static function reason(string $why): string
    {
        return preg_match('/^[a-z_]+$/', $why) === 1 && Lang::has('core.bulk.why.'.$why)
            ? (string) __('core.bulk.why.'.$why)
            : $why;
    }

    /** "৩টা হলো · ২টা বাদ" — `$verb` কাজের নাম (`core.bulk.done.submit` …) */
    public function summary(string $action): string
    {
        $text = (string) __('core.bulk.result', [
            'done' => $this->done,
            'action' => (string) __('core.bulk.done.'.$action),
            'skipped' => count($this->skipped),
        ]);

        return $text;
    }

    /** কেন বাদ — "INV-0005: খসড়া নয় · INV-0007: অন্য শাখার" */
    public function details(): string
    {
        return implode(' · ', array_map(fn (array $s) => $s['name'].': '.self::reason($s['why']), $this->skipped));
    }

    /** ⓘ ফলের বার্তা কোন সেশন-চাবিতে — তালিকার বারই আঁকে ([[x-ui.bulk-form]]); প্রতিটা পাতা `session('warning')` আঁকে না */
    public const FLASH = 'bulk_result';

    /** ⭐ তালিকায় ফেরা — উপরে সবুজ ফল, বাদ থাকলে হলুদে কেন */
    public function redirect(string $to, string $action): RedirectResponse
    {
        return redirect()->to($to)->with(self::FLASH, [
            'summary' => $this->summary($action),
            'details' => $this->skipped === [] ? null : __('core.bulk.skipped_why').' '.$this->details(),
        ]);
    }
}
