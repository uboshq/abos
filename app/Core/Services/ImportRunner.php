<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\Importer;
use App\Core\Contracts\RefusesAPartialImport;
use App\Core\Module\ModuleRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CSV পড়া, যাচাই করা, তারপর বসানো।
 *
 * কোন কোন জিনিস আনা যায় তা এখানে লেখা নেই — প্রতিটা মডিউল নিজের
 * `module.php`-তে ঘোষণা করে (সেকশন ১৯.৭)। তাই Inventory যেদিন আসবে,
 * সেদিন এই ফাইলটা ছুঁতে হবে না।
 */
final class ImportRunner
{
    /**
     * একবারে কত সারি।
     *
     * সীমা না থাকলে কেউ দশ হাজার সারির ফাইল দিয়ে সার্ভারটা আটকে দিত,
     * আর অফিসের মেশিনে সেটা মানে সবার কাজ বন্ধ। সীমা ছাড়ালে চুপচাপ কেটে
     * দেওয়া হয় না — বলা হয়, কারণ কাটলে ব্যবহারকারী ভাবতেন সব ঢুকেছে।
     */
    public const MAX_ROWS = 2000;

    public function __construct(private readonly ModuleRegistry $registry) {}

    /**
     * সব মডিউলের ঘোষিত ইমপোর্টার।
     *
     * @return array<string, class-string<Importer>>
     */
    public function available(): array
    {
        $all = [];

        foreach ($this->registry->all() as $module) {
            foreach ($module->imports as $key => $class) {
                $all[$key] = $class;
            }
        }

        return $all;
    }

    /**
     * @return class-string<Importer>
     */
    public function importerFor(string $key): string
    {
        $all = $this->available();

        if (! isset($all[$key])) {
            throw new RuntimeException("No importer registered for '{$key}'.");
        }

        return $all[$key];
    }

    /**
     * ফাইলটা পড়ে প্রতিটা সারি যাচাই — কিছু সেভ না করে।
     *
     * @return array{rows: list<array{line: int, data: array<string, string>, errors: list<string>}>, ok: int, bad: int, truncated: bool}
     */
    public function check(string $key, UploadedFile $file): array
    {
        $class = $this->importerFor($key);
        $importer = app($class);

        $parsed = $this->read($file, $class::columns());

        $rows = [];
        $ok = 0;
        $bad = 0;

        foreach ($parsed['rows'] as $line => $data) {
            $errors = $this->missingRequired($class::columns(), $data);

            if ($errors === []) {
                $errors = $importer->check($data);
            }

            $errors === [] ? $ok++ : $bad++;

            $rows[] = ['line' => $line, 'data' => $data, 'errors' => $errors];
        }

        return ['rows' => $rows, 'ok' => $ok, 'bad' => $bad, 'truncated' => $parsed['truncated']];
    }

    /**
     * ঠিক সারিগুলো বসানো।
     *
     * ভুল সারিগুলো বাদ যায়, পুরো ফাইল নয়। তিনশো সারির মধ্যে দুইটা ভুল
     * থাকলে বাকি ২৯৮টা আটকে রাখার কোনো কারণ নেই — ব্যবহারকারী ওই দুইটা
     * ঠিক করে আবার পাঠাবেন।
     *
     * প্রতিটা সারি নিজের ট্রানজেকশনে: একটা সারি ভাঙলে আগের সারিগুলো
     * ফিরিয়ে নেওয়ার মানে হয় না, আর পুরোটা এক ট্রানজেকশনে রাখলে দুই
     * হাজার সারির লক অনেকক্ষণ ধরে থাকত।
     *
     * ── একটা ব্যতিক্রম, আর কেন ───────────────────────────────────────
     * ইমপোর্টার [[RefusesAPartialImport]] ঘোষণা করলে উপরের যুক্তিটা
     * উল্টে যায়: সেখানে ফাইলটা তালিকা নয়, **দলিল**। খোলার জেরের অর্ধেক
     * বসলে বই নীরবে ভুল হয়ে যায়, আর কোন সারিগুলো ঢুকেছিল সেটা পরে বের
     * করাই আসল কষ্ট। তাই ওদের জন্য **সব, নয়তো কিছুই না**।
     *
     * @return array{imported: int, failed: list<array{line: int, error: string}>, refused?: string}
     */
    public function run(string $key, UploadedFile $file): array
    {
        $class = $this->importerFor($key);
        $importer = app($class);

        $parsed = $this->read($file, $class::columns());

        if ($importer instanceof RefusesAPartialImport) {
            return $this->runAllOrNothing($importer, $class, $parsed['rows']);
        }

        $imported = 0;
        $failed = [];

        foreach ($parsed['rows'] as $line => $data) {
            $errors = $this->missingRequired($class::columns(), $data);

            if ($errors === []) {
                $errors = $importer->check($data);
            }

            if ($errors !== []) {
                $failed[] = ['line' => $line, 'error' => implode(' · ', $errors)];

                continue;
            }

            try {
                DB::transaction(fn () => $importer->import($data));
                $imported++;
            } catch (\Throwable $e) {
                $failed[] = ['line' => $line, 'error' => $e->getMessage()];
            }
        }

        return ['imported' => $imported, 'failed' => $failed];
    }

    /**
     * সব বসে, নয়তো একটাও না।
     *
     * ── দুই ধাপ, আর কেন একটা নয় ──────────────────────────────────────
     * প্রথমে **গোটা ফাইলটা যাচাই**, কিছু না লিখে। তারপর, সব ঠিক থাকলে,
     * একটাই ট্রানজেকশনে বসানো।
     *
     * ধাপ দুইটা আলাদা রাখার একটাই কারণ, আর সেটা ব্যবহারকারীর: যাচাই
     * থামিয়ে দিলে তিনি **প্রথম** ভুলটা জানতেন, তারপর সেটা শুধরে আবার
     * পাঠাতেন, আর দ্বিতীয়টা জানতেন। পাঁচটা ভুলের ফাইল মানে পাঁচবার।
     * এখানে পুরো তালিকাটা একবারেই আসে — ঠিক যেমন শুকনো দৌড়
     * ([[ImportRunner::check()]]) দেয়।
     *
     * ⚠️ লেখার ধাপে কেউ ব্যর্থ হলে (যাচাই যা ধরতে পারে না — লক, বিদেশি
     * চাবি, একই সাথে অন্য কেউ) **পুরোটা ফিরে যায়**। তখন `imported` শূন্য,
     * আর ব্যর্থ সারিটার নাম বলা থাকে।
     *
     * @param  array<int, array<string, string>>  $rows
     * @param  class-string<Importer>  $class
     * @return array{imported: int, failed: list<array{line: int, error: string}>, refused?: string}
     */
    private function runAllOrNothing(
        Importer&RefusesAPartialImport $importer,
        string $class,
        array $rows,
    ): array {
        $failed = [];

        foreach ($rows as $line => $data) {
            $errors = $this->missingRequired($class::columns(), $data);

            if ($errors === []) {
                $errors = $importer->check($data);
            }

            if ($errors !== []) {
                $failed[] = ['line' => $line, 'error' => implode(' · ', $errors)];
            }
        }

        if ($failed !== []) {
            return ['imported' => 0, 'failed' => $failed, 'refused' => $importer->refusalNotice()];
        }

        try {
            $imported = DB::transaction(function () use ($importer, $rows): int {
                $done = 0;

                foreach ($rows as $data) {
                    $importer->import($data);
                    $done++;
                }

                return $done;
            });
        } catch (\Throwable $e) {
            /*
             * একটা সারি ভাঙল, তাই ট্রানজেকশনটা নিজেই সব ফিরিয়ে নিয়েছে।
             * কোন সারিতে ভাঙল সেটা আর জানা যায় না — লুপটা ভেতরে, আর
             * গণনাটাও ফিরে গেছে। ⚠️ তাই বার্তায় সারির নম্বর দাবি করা
             * হয় না: ভুল নম্বর বলার চেয়ে না বলা ভালো।
             */
            return [
                'imported' => 0,
                'failed' => [['line' => 0, 'error' => $e->getMessage()]],
                'refused' => $importer->refusalNotice(),
            ];
        }

        return ['imported' => $imported, 'failed' => []];
    }

    /**
     * নমুনা ফাইল — যে ক্রমে কলাম, সেই ক্রমেই।
     */
    public function template(string $key): string
    {
        $columns = $this->importerFor($key)::columns();

        $header = array_map(fn (string $name) => $name, array_keys($columns));

        // BOM — নাহলে Excel বাংলা লেখা ভেঙে দেখায়, আর ব্যবহারকারী ভাবেন
        // ফাইলটাই নষ্ট
        return "\u{FEFF}".implode(',', $header)."\n";
    }

    /**
     * @param  array<string, array{label: string, required: bool}>  $columns
     * @return array{rows: array<int, array<string, string>>, truncated: bool}
     */
    private function read(UploadedFile $file, array $columns): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            throw new RuntimeException('The uploaded file could not be opened.');
        }

        try {
            $header = fgetcsv($handle);

            if ($header === false) {
                throw new RuntimeException(__('core.import.empty_file'));
            }

            // Excel-এর BOM প্রথম কলামের নামে লেগে থাকে, আর তখন কলামটা
            // "নেই" বলে ধরা পড়ত
            $header = array_map(
                fn ($h) => trim(str_replace("\u{FEFF}", '', (string) $h)),
                $header,
            );

            $rows = [];
            $line = 1;
            $truncated = false;

            while (($values = fgetcsv($handle)) !== false) {
                $line++;

                // পুরো খালি সারি বাদ — Excel প্রায়ই ফাইলের শেষে
                // কয়েকটা খালি সারি রেখে দেয়
                if (count(array_filter($values, fn ($v) => trim((string) $v) !== '')) === 0) {
                    continue;
                }

                if (count($rows) >= self::MAX_ROWS) {
                    $truncated = true;
                    break;
                }

                $data = [];

                foreach ($columns as $name => $spec) {
                    $index = array_search($name, $header, true);
                    $data[$name] = $index === false ? '' : trim((string) ($values[$index] ?? ''));
                }

                $rows[$line] = $data;
            }

            return ['rows' => $rows, 'truncated' => $truncated];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<string, array{label: string, required: bool}>  $columns
     * @param  array<string, string>  $data
     * @return list<string>
     */
    private function missingRequired(array $columns, array $data): array
    {
        $errors = [];

        foreach ($columns as $name => $spec) {
            if (($spec['required'] ?? false) && ($data[$name] ?? '') === '') {
                $errors[] = __('core.import.missing_column', ['column' => $name]);
            }
        }

        return $errors;
    }
}
