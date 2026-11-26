<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\ListFilter\ListSort;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ তালিকার "সাজানো" — পুরো বারের অংশ, এক নিয়মে ([[ListSort]], ২৭ নভেম্বর ২০২৬)।
 *
 * দাবি: বাছা চাবির কলামে ক্রম আর শেষে id একই দিকে; অজানা, ফাঁকা বা অ্যারে চাবি প্রথমটায় ফেরে (ঠিকানা দিয়ে কলাম ঢোকে না);
 * আগের ক্রম মোছে; প্রতিটা জানা চাবির দুই ভাষায় লেখা আছে।
 */
final class EveryListSortsTheSameWayTest extends TestCase
{
    private const MAP = [
        'newest' => ['trx_date', 'desc'],
        'oldest' => ['trx_date', 'asc'],
        'amount_high' => [['amount', 'desc'], ['trx_date', 'desc']],
    ];

    public function test_the_chosen_key_orders_and_the_id_follows(): void
    {
        $sql = ListSort::apply(DB::table('vouchers')->orderBy('code'), 'oldest', self::MAP)->toSql();

        $this->assertStringContainsString('order by `trx_date` asc, `id` asc', $sql, '⛔ পুরনো আগে নয়, বা id একই দিকে নয়: '.$sql);
        $this->assertStringNotContainsString('`code`', $sql, '⛔ আগের ক্রম থেকে গেল');

        $two = ListSort::apply(DB::table('vouchers'), new Request(['sort' => 'amount_high']), self::MAP)->toSql();
        $this->assertStringContainsString('order by `amount` desc, `trx_date` desc, `id` desc', $two, '⛔ দুই কলামের ক্রম ভাঙল: '.$two);
    }

    public function test_an_unknown_key_falls_back_to_the_first(): void
    {
        foreach (['amount; drop table vouchers', '', null, ['newest'], 'name_az'] as $bad) {
            $sql = ListSort::apply(DB::table('vouchers'), $bad, self::MAP)->toSql();

            $this->assertStringContainsString('order by `trx_date` desc, `id` desc', $sql, '⛔ অজানা চাবি '.json_encode($bad).' প্রথমটায় ফেরেনি: '.$sql);
        }
    }

    public function test_every_key_has_words_in_both_languages(): void
    {
        foreach (ListSort::KEYS as $key) {
            foreach (['bn', 'en'] as $locale) {
                $text = trans('core.list_sort.'.$key, [], $locale);
                $this->assertNotSame('core.list_sort.'.$key, $text, "⛔ {$locale}: '{$key}'-এর লেখা নেই");
            }
        }

        $this->assertSame(['newest' => trans('core.list_sort.newest'), 'oldest' => trans('core.list_sort.oldest')], ListSort::labels(['newest', 'oldest']));
    }
}
