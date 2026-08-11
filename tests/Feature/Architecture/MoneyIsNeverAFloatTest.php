<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

/**
 * টাকা কখনো float নয় — প্ল্যান WP-0.2, §৯.৪-এর সাত নম্বর।
 *
 * ── কেন এটাই সবচেয়ে জরুরি পাহারা ─────────────────────────────────────
 * `0.1 + 0.2 === 0.3` মিথ্যা, প্রতিটা ভাষায়, প্রতিটা যন্ত্রে। ব্যবসার
 * হিসাবে এর ফল একরকম দেখতে হয় সবসময়: **একটা ট্রায়াল ব্যালেন্স যেখানে
 * ডেবিট আর ক্রেডিটের পার্থক্য ০.০১ টাকা।**
 *
 * ওই এক পয়সাটা কোনো এন্ট্রির ভুল নয়, তাই খুঁজে পাওয়া যায় না। হিসাবরক্ষক
 * সারাদিন খোঁজেন, শেষে "সমন্বয়" নামে একটা এন্ট্রি দিয়ে মিলিয়ে দেন — আর
 * তখন খাতাটা মিলল ঠিকই, কিন্তু সত্যি বলা বন্ধ করল।
 *
 * তাই ABOS-এ টাকা DECIMAL(18,4), আর অঙ্ক bcmath দিয়ে। এই পরীক্ষাটা
 * দুইটা দিক থেকে সেটা পাহারা দেয়:
 *
 *   ১. ডাটাবেজে কোথাও float/double কলাম নেই
 *   ২. প্রতিটা decimal কলাম মডেলে decimal হিসেবেই ফেরে
 *
 * দ্বিতীয়টা ছাড়া প্রথমটা যথেষ্ট নয়: কলামটা DECIMAL হলেও PHP-তে cast না
 * থাকলে PDO ওটা string দেয় আর কেউ `(float)` করে ফেললে ক্ষতিটা ঠিক
 * একই — শুধু ডাটাবেজের বদলে মেমোরিতে।
 */
class MoneyIsNeverAFloatTest extends TestCase
{
    use RefreshDatabase;

    /**
     * যেসব decimal কলাম টাকা নয়।
     *
     * পরিমাণ ও হারও DECIMAL, আর ওগুলোতেও cast থাকা উচিত — তাই তালিকাটা
     * খালি। কোনোদিন সত্যিই ব্যতিক্রম লাগলে কারণসহ এখানে লিখতে হবে,
     * আর সেটাই উদ্দেশ্য: ব্যতিক্রমটা একটা সিদ্ধান্ত হোক, অভ্যাস নয়।
     *
     * @var array<string, string> "table.column" => কারণ
     */
    private const NOT_MONEY = [];

    /**
     * ডাটাবেজে একটাও ভাসমান সংখ্যা নেই।
     *
     * মাইগ্রেশনের কোড না পড়ে সত্যিকারের স্কিমা দেখা হয় — কারণ প্রশ্নটা
     * "কেউ কী লিখেছিল" নয়, "টেবিলে আসলে কী আছে"। একটা মাইগ্রেশন পরে
     * আরেকটা কলাম বদলে দিলে কোড পড়ে সেটা ধরা যেত না।
     */
    public function test_no_column_anywhere_is_a_floating_point_number(): void
    {
        $floats = [];

        foreach ($this->columns() as $column) {
            if (in_array(strtolower($column->data_type), ['float', 'double', 'real'], true)) {
                $floats[] = "{$column->table_name}.{$column->column_name} ({$column->data_type})";
            }
        }

        sort($floats);

        $this->assertSame([], $floats, implode("\n", [
            'এই কলামগুলো ভাসমান সংখ্যা। টাকা হলে একদিন ট্রায়াল ব্যালেন্সে',
            'এক পয়সার পার্থক্য আসবে, আর সেটা কোনো এন্ট্রি ধরে খুঁজে পাওয়া যাবে না।',
            'DECIMAL(18,4) ব্যবহার করুন।',
            ...$floats,
        ]));
    }

    /**
     * প্রতিটা decimal কলাম মডেলেও decimal।
     *
     * ── কেন cast না থাকলে সমস্যা ─────────────────────────────────────
     * cast ছাড়া মানটা string হয়ে আসে ("1200.0000")। বেশিরভাগ জায়গায়
     * সেটা ঠিকই চলে, কারণ bcmath string-ই চায়। কিন্তু একটা জায়গায়
     * কেউ `+` লিখলে PHP নিজে থেকেই float বানিয়ে ফেলে — আর তখন ভুলটা
     * নীরবে ঢোকে, ঠিক যে দুইটা সংখ্যা যোগ করা হচ্ছিল সেখানেই।
     *
     * cast থাকলে মানটা সবসময় নির্দিষ্ট দশমিক ঘরের string হয়ে ফেরে,
     * আর তুলনা ও যোগ দুইটাই অনুমান করা যায়।
     */
    public function test_every_decimal_column_is_cast_as_a_decimal_on_its_model(): void
    {
        $tables = [];

        foreach ($this->columns() as $column) {
            if (strtolower($column->data_type) === 'decimal') {
                $tables[$column->table_name][] = $column->column_name;
            }
        }

        $uncast = [];
        $modelled = 0;

        foreach ($this->models() as $model) {
            $table = $model->getTable();

            if (! isset($tables[$table])) {
                continue;
            }

            $modelled++;
            $casts = $model->getCasts();

            foreach ($tables[$table] as $column) {
                if (array_key_exists("{$table}.{$column}", self::NOT_MONEY)) {
                    continue;
                }

                $cast = $casts[$column] ?? null;

                if ($cast === null || ! str_starts_with((string) $cast, 'decimal:')) {
                    $uncast[] = $table.'.'.$column
                        .'  ('.$model::class.' — '.($cast === null ? 'কোনো cast নেই' : "cast: {$cast}").')';
                }
            }
        }

        sort($uncast);

        $this->assertGreaterThan(10, $modelled,
            'মডেলগুলোই পড়া হয়নি — এই পরীক্ষাটা তখন কিছুই দেখছে না।');

        $this->assertSame([], $uncast, implode("\n", [
            'এই কলামগুলো ডাটাবেজে DECIMAL, কিন্তু মডেলে decimal নয়।',
            "মান string হয়ে ফেরে, আর কেউ '+' লিখলেই PHP float বানিয়ে ফেলে।",
            "মডেলের \$casts-এ 'decimal:4' যোগ করুন।",
            ...$uncast,
        ]));
    }

    /**
     * চলতি স্কিমার সব কলাম — অ্যাপের নিজের টেবিলগুলোর।
     *
     * @return list<object>
     */
    private function columns(): array
    {
        $columns = DB::select(
            'SELECT table_name, column_name, data_type
               FROM information_schema.columns
              WHERE table_schema = DATABASE()
              ORDER BY table_name, column_name'
        );

        /*
         * MySQL-এর ড্রাইভারভেদে কলামের নাম বড়/ছোট হাতের হয়, তাই
         * নামগুলো এখানেই এক করে নেওয়া — নাহলে পরীক্ষাটা এক যন্ত্রে
         * চলত আর অন্যটায় নীরবে খালি তালিকা দেখাত।
         */
        return array_map(function (object $row): object {
            $values = array_change_key_case((array) $row);

            return (object) [
                'table_name' => $values['table_name'],
                'column_name' => $values['column_name'],
                'data_type' => $values['data_type'],
            ];
        }, $columns);
    }

    /**
     * অ্যাপের প্রতিটা Eloquent মডেল — হাতে লেখা তালিকা নয়।
     *
     * @return list<Model>
     */
    private function models(): array
    {
        $models = [];

        foreach ([app_path('Models'), ...glob(app_path('Modules').DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'Models') ?: []] as $directory) {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*.php') ?: [] as $file) {
                $class = $this->classFor($file);

                if ($class === null || ! is_subclass_of($class, Model::class)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);

                if ($reflection->isAbstract()) {
                    continue;
                }

                $models[] = new $class;
            }
        }

        return $models;
    }

    /** ফাইলের পথ থেকে ক্লাসের পুরো নাম — namespace লাইনটা পড়ে। */
    private function classFor(string $file): ?string
    {
        $source = file_get_contents($file);

        if ($source === false || preg_match('/^namespace\s+([^;]+);/m', $source, $matches) !== 1) {
            return null;
        }

        $class = trim($matches[1]).'\\'.basename($file, '.php');

        return class_exists($class) ? $class : null;
    }
}
