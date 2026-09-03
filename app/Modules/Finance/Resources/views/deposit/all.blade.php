{{--
    সব জমা — তিন ইস্যুকারী একসাথে।

    ── কেন এই পাতাটা আলাদা ─────────────────────────────────────────────
    জমার নিজের পাতাগুলো একটা ইস্যুকারী ধরে চলে (ব্যাংক · সঞ্চয়পত্র ·
    বন্ড), কারণ **নতুন জমা খোলার ফর্মটা ইস্যুকারী-নির্দিষ্ট**: ধরনের
    তালিকাও ওখান থেকেই আসে।

    ⚠️ কিন্তু অর্থের ড্যাশবোর্ডের "জমা" টালিটা **তিনটার যোগফল** দেখায়।
    তিনটার একটাকে দরজা বানালে সংখ্যাটা এক জায়গায় দেখাত আর ক্লিক করলে
    অন্য জায়গায় নামত — আর সেটা দরজা না থাকার চেয়ে খারাপ, কারণ **ভুল
    দরজা থাকলে মানুষ ভুল সংখ্যাটা বিশ্বাস করেন**।

    ⓘ **এটা পড়ার পাতা** — খোলার কোনো ফর্ম নেই। নতুন জমা খুলতে হলে
    ইস্যুকারীর নিজের পাতায়, আর প্রতিটা সারি সেখানেই নামায়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::menu.deposits_all') }}</x-slot:title>

    <div class="shell py-6">
        <x-ui.page-header :title="__('finance::menu.deposits_all')"
                          :subtitle="__('finance::message.deposits_all_hint')" />

        <section data-boxed
                 class="mt-5 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <x-ui.table
                :empty="__('finance::message.no_deposit_yet')"
                :rows="$deposits"
                :columns="[
                    ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '9rem'],

                    /*
                     * ইস্যুকারীর কলামটা এখানে বাড়তি — তিনটা মিশে আছে,
                     * তাই কোনটা কার সেটা না বললে তালিকাটা পড়া যায় না।
                     */
                    ['key' => 'issuer', 'label' => __('finance::field.issuer'), 'width' => '9rem',
                     /*
                      * ⓘ নামগুলো আগে থেকেই `menu.deposit_*`-এ আছে —
                      * নতুন কী বানানো হয়নি। `national_savings`-এর কী
                      * `deposit_savings`, তাই ওই একটা ব্যতিক্রম।
                      */
                     'render' => fn ($d) => __('finance::menu.deposit_'
                         .($d->kind->issuer === 'national_savings' ? 'savings' : $d->kind->issuer))],

                    ['key' => 'kind', 'label' => __('finance::field.deposit_kind'),
                     'render' => fn ($d) => $d->kind->name()],
                    ['key' => 'institution', 'label' => __('finance::field.institution'),
                     'render' => fn ($d) => view('finance::deposit.partials.where', ['deposit' => $d])],
                    ['key' => 'held_by', 'label' => __('finance::field.held_by'), 'width' => '9rem',
                     'render' => fn ($d) => view('finance::deposit.partials.holder', ['deposit' => $d])],
                    ['key' => 'principal', 'label' => __('finance::field.principal'), 'numeric' => true,
                     'width' => '11rem',
                     'render' => fn ($d) => view('ui.amount-link', [
                         'value' => $d->principal,
                         'href' => route('accounts.coa.show', $d->account_id).'#transactions',
                     ])],
                    ['key' => 'matures_on', 'label' => __('finance::field.matures_on'), 'width' => '11rem',
                     'render' => fn ($d) => view('finance::deposit.partials.maturity', ['deposit' => $d])],

                    /*
                     * ⓘ ইস্যুকারীটা সারি থেকেই নেওয়া (`kind->issuer`), বাইরে
                     * থেকে দেওয়া নয় — এই পাতায় একটামাত্র ইস্যুকারী বলে
                     * কিছু নেই।
                     */
                    ['key' => 'do', 'label' => '', 'width' => '7rem',
                     'render' => fn ($d) => view('finance::deposit.partials.open-it',
                         ['deposit' => $d, 'issuer' => $d->kind->issuer])],
                ]" />

            <x-ui.pager :rows="$deposits" />
        </section>
    </div>
</x-layouts.app>
