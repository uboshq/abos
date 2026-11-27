{{--
    ⭐ কাগজ থেকে মেইল — বোতাম (মেইল মডিউল, নমুনা v4 "ok"): মেইলের ট্যাবে লেখা খোলে, কাগজের PDF নিজে বসে।
    ⓘ কাগজের পাতা কেবল `@includeIf('mail::partials.paper-button', …)` ডাকে — মেইল মডিউল না থাকলে কিছুই আঁকা হয় না।
    @param string $kind  invoice | challan | order | receipt | voucher | purchase_order
    @param int    $id
    @param string $to    প্রাপকের ঠিকানা (ঐচ্ছিক)
--}}
@if (\Illuminate\Support\Facades\Route::has('mail.index') && auth()->user()?->can('mail.use'))
    <x-ui.button tone="secondary" target="abos-mail" data-mail-paper
                 :href="route('mail.index', array_filter(['counter' => 1, 'compose' => 1, 'paper' => $kind, 'id' => $id, 'to' => $to ?? null]))">
        <x-ui.icon name="mail" :size="16" :drawn="true" /> {{ __('mail::field.compose.send') }}
    </x-ui.button>
@endif
