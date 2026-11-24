<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Services\ListExport;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⭐ তালিকার প্রতিটা ঘর একবারই আঁকা হয়, আর পর্দার একটা বাইটও বদলায় না (২৪ নভেম্বর ২০২৬)।
 *
 * দাবি: ভিউ-ঘর সারিপ্রতি একবার; লেখা, সংখ্যা, HtmlString, ভিউ, null, "0", false/true, খালি আর ফাঁকা-লেখা — আগের ছাঁচের হুবহু HTML, সাধারণ আর
 * কার্ড দুই চেহারায়; রপ্তানির লেখা একই; আসল পণ্যের তালিকায় সারি যত, মেনু তত।
 */
final class AListCellIsDrawnOnceTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cell-once-'.Str::random(8);
        mkdir($this->dir.'/components/ui', 0777, true);
        file_put_contents($this->dir.'/claim-cell.blade.php', '<b>{{ $text }}</b>');
        file_put_contents($this->dir.'/claim-empty.blade.php', "  \n");
        View::addLocation($this->dir);
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Facades\File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_a_view_cell_is_rendered_once_a_row(): void
    {
        $drawn = 0;
        View::composer('claim-cell', function () use (&$drawn): void {
            $drawn++;
        });
        $html = $this->table([['t' => 'x<1>'], ['t' => 'y']], fn ($r) => view('claim-cell', ['text' => $r['t']]));

        $this->assertSame(2, $drawn, '⛔ ঘর দুবার আঁকা হলো।');
        $this->assertStringContainsString('<b>x&lt;1&gt;</b>', $html);
    }

    public function test_every_kind_of_cell_is_byte_for_byte_what_it_was(): void
    {
        $kinds = [
            'text' => 'ক <b>&amp;</b> "x"', 'number' => 12, 'decimal' => '1,200.50', 'float' => 1.5, 'zero' => 0, 'zero text' => '0',
            'null' => null, 'empty' => '', 'spaces' => "  \n\t", 'true' => true, 'false' => false, 'nbsp' => '&nbsp;',
            'html' => new HtmlString('<i>ok</i>'), 'empty html' => new HtmlString(' '),
            'view' => fn () => view('claim-cell', ['text' => 'v']), 'empty view' => fn () => view('claim-empty'),
            'stringable' => str('a < b'),
            'htmlable' => new class implements Htmlable { public function toHtml() { return '<u>h</u>'; } public function __toString(): string { return '<u>h</u>'; } },
        ];
        $rows = array_map(fn ($v) => ['v' => $v], array_values($kinds));
        $render = fn ($r) => $r['v'] instanceof \Closure ? ($r['v'])() : $r['v'];

        $now = [$this->table($rows, $render), $this->table($rows, $render, grid: true)];
        $this->assertSame(6, substr_count($now[0], 'data-empty') , 'null, খালি, ফাঁকা, false, খালি HtmlString, খালি ভিউ');

        // ⓘ আগের ছাঁচ — ঠিক দুই লাইন ফিরিয়ে
        $old = (string) file_get_contents(resource_path('views/components/ui/table.blade.php'));
        $was = str_replace(['$cellHtml($row', '$value->blank'], ['$cell($row', "trim((string) \$value) === ''"], $old, $n);
        $this->assertSame(2, $n, 'প্রস্তুতিটাই ভুল — আগের ছাঁচ বানানো গেল না।');
        file_put_contents($this->dir.'/components/ui/table.blade.php', $was);
        View::getFinder()->prependLocation($this->dir);
        View::getFinder()->flush();
        View::flushState();
        $drawn = 0;
        View::composer('claim-cell', function () use (&$drawn): void {
            $drawn++;
        });

        $before = [$this->table($rows, $render), $this->table($rows, $render, grid: true)];
        $this->assertSame(4, $drawn, 'প্রস্তুতিটাই ভুল — আগের ছাঁচ চলেনি (দুবার আঁকার কথা)।');
        $this->assertSame($before, $now, '⛔ পর্দার HTML বদলে গেছে।');
    }

    public function test_the_export_carries_the_same_words(): void
    {
        $columns = [['key' => 'a', 'label' => 'A', 'render' => fn ($r) => view('claim-cell', ['text' => $r['t']])]];
        request()->query->set('export', 'csv');
        $export = app(ListExport::class);

        if (! $export->wanted()) {
            $this->fail('প্রস্তুতিটাই ভুল — রপ্তানি চাওয়া ধরা হয়নি।');
        }

        Blade::render('<x-ui.table :rows="$rows" :columns="$columns" />', ['rows' => [['t' => 'one'], ['t' => '0']], 'columns' => $columns]);
        $this->assertSame([['one'], ['0']], $export->captured()['values']);
    }

    public function test_the_product_list_draws_as_many_menus_as_rows(): void
    {
        $this->seed(DemoSeeder::class);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $drawn = 0;
        View::composer('inventory::partials.product-actions', function () use (&$drawn): void {
            $drawn++;
        });

        $page = $this->actingAs($owner)->get(route('inventory.product.index'))->assertOk();
        $rows = count($page->viewData('products')->items());
        $this->assertGreaterThan(1, $rows, 'প্রস্তুতিটাই ভুল — পণ্য নেই।');
        $this->assertSame($rows, $drawn);
    }

    private function table(array $rows, \Closure $render, bool $grid = false): string
    {
        return Blade::render('<x-ui.table :rows="$rows" :columns="$columns" :grid="$grid" />', ['rows' => $rows, 'grid' => $grid,
            'columns' => [['key' => 'a', 'label' => 'A', 'render' => $render], ['key' => 'v', 'label' => 'V', 'numeric' => true, 'render' => fn ($r) => '1']]]);
    }
}
