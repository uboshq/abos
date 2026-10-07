{{--
    সই হয়েছে, শেষ হয়নি — দুই পথ (লাইভ DRF-0008, fe, ৭ অক্টোবর ২০২৬; [[HeldCounterSaleFinisher]])।

    ⓘ "আবার চেষ্টা" — বাধা সরলে (ধরুন ফ্রির দেয়াল তোলা) একই finisher পাকা করে। "খসড়ায় ফেরান" — কাউন্টারের খসড়া হয়ে
    খোলে, বদলে আবার পাঠানো যায়; কেবল বানানেওয়ালা বা মালিক (সেবাই থামায়)।
--}}
<div class="flex flex-wrap gap-2" data-signed-actions>
    <form method="POST" action="{{ route('sales.direct.signed_retry', $draft) }}">
        @csrf
        <x-ui.button type="submit" tone="primary" data-signed-retry>{{ __('sales::auto_finish.retry') }}</x-ui.button>
    </form>
    <form method="POST" action="{{ route('sales.direct.signed_return', $draft) }}">
        @csrf
        <x-ui.button type="submit" tone="secondary" data-signed-return>{{ __('sales::auto_finish.return') }}</x-ui.button>
    </form>
</div>
