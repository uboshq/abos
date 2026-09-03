{{--
    ＋ তৈরি করুন — যা যা এখান থেকে শুরু করা যায়।

    ডান দিকের ঝাঁকের প্রথম জিনিস, কারণ এটাই একমাত্র যেটা কাজ *শুরু* করে;
    বাকিগুলো জানায় বা পর্দা বদলায়।

    <b>তালিকাটা এখানে, স্ক্রিনে নয়।</b> নতুন কিছু তৈরি করার পথ যোগ করতে হলে
    নিচের সারিতে এক লাইন — মডিউল যেদিন আসে সেদিন তার এন্ট্রিও আসে। এতে দুটো
    জিনিস ঠিক থাকে: ক্রমটা সব পর্দায় এক, আর কোনো মডিউল "আমারটা এখানে
    বসাতে ভুলে গেছি" অবস্থায় থাকে না।

    <b>যে রুটের ঠিকানা বানানো যায় না, তার সারিও নেই।</b> প্রতিটা সারির href
    নিচেই তৈরি হয়, আর তৈরি করতে না পারলে সারিটা বাদ যায়। তাই আধা-তৈরি
    মডিউলের এন্ট্রি মেনুতে এসে ৪০৪-এ নিয়ে যায় না। মেনুর মৃত সারি এই
    প্রকল্পে দুবার সরানো হয়েছে; তৃতীয়বার যেন না লাগে।

    <b>তালিকাটা ভরল ৪ সেপ্টেম্বর ২০২৬।</b> এখানে আগে লেখা ছিল "ABOS-এ কোনো
    লেনদেনের মডিউল নেই, তাই তালিকাটা খালি" — লেখার দিন সেটা সত্যি ছিল। কিন্তু
    বিক্রয়, ক্রয়, হিসাব ও মজুদ বসে যাওয়ার পরেও সারিগুলো মন্তব্যের ভেতরেই থেকে
    গিয়েছিল, আর ＋ বোতাম চাপলে রোজ লেখা উঠত <i>"এখনো তৈরি করার কিছু নেই"</i> —
    অথচ ঠিক তখনই ওই মডিউলগুলো দিয়েই সারাদিন কাজ হচ্ছিল।

    <b>খালি অবস্থার বার্তাটা রয়ে গেল, আর ওটা এখনো দরকার</b> — যে ক্রেতা কেবল
    হিসাব কিনবেন, তাঁর কাছে বিক্রয়ের সারিগুলো থাকবে না। "কিছু নেই" জানা আর
    "কাজ করল না" ভাবা এক নয়।
--}}
@php
    /**
     * ['route' => রুটের নাম, 'label' => অনুবাদের চাবি, 'can' => অনুমতি|null]
     *
     * মডিউল এলে এখানেই এক সারি — Sales এলে বিক্রয় চালান, Purchase এলে ক্রয়
     * আদেশ, Accounts এলে ভাউচার।
     */
    $entries = collect([
        /*
         * ক্রমটা কাজের দিনের, বর্ণমালার নয় — কাউন্টারের বিক্রয় সবার
         * উপরে কারণ ওটাই সবচেয়ে বেশিবার চাপা হয়, আর মাস্টার ডেটা
         * (গ্রাহক · সরবরাহকারী · পণ্য) নিচে কারণ ওগুলো রোজ তৈরি হয় না।
         *
         * ⚠️ `can`-এর নামগুলো অনুমান নয়, module.php থেকে মিলিয়ে নেওয়া।
         * ভুল নাম লিখলে `can()` চিরকাল false ফেরত দেয় — সারিটা কারো
         * কাছেই দেখা যেত না, আর কোনো ত্রুটিও আসত না।
         *
         * ⭐ সরাসরি বিক্রয়ের অনুমতি `sales.challan.create`, নিজের নামে
         * নয় — কারণ ওটা এক চাপে চালান ও বিল দুইটাই বানায়, আর মেনুর
         * সারিটাও ঠিক এই অনুমতিই চায়।
         */
        ['route' => 'sales.direct.create', 'label' => 'core.create.direct_sale', 'can' => 'sales.challan.create'],
        ['route' => 'sales.order.create', 'label' => 'core.create.sales_order', 'can' => 'sales.order.create'],
        ['route' => 'sales.invoice.create', 'label' => 'core.create.invoice', 'can' => 'sales.invoice.create'],
        ['route' => 'sales.collection.create', 'label' => 'core.create.collection', 'can' => 'sales.collection.create'],
        ['route' => 'purchase.order.create', 'label' => 'core.create.purchase_order', 'can' => 'purchase.order.create'],
        ['route' => 'purchase.bill.create', 'label' => 'core.create.purchase_bill', 'can' => 'purchase.bill.create'],
        ['route' => 'purchase.payment.create', 'label' => 'core.create.payment', 'can' => 'purchase.payment.create'],
        /*
         * ⭐ ভাউচারের রুটে একটা ধরন লাগে — `/accounts/vouchers/{type}/create`।
         *
         * পাঁচটা ধরনের মধ্যে এখানে কেবল **journal**, কারণ বাকিগুলোতে
         * এই মেনু দিয়েই অন্য পথে পৌঁছানো যায়: receipt = আদায়,
         * payment = প্রদান, contra = টাকা স্থানান্তর। **journal-ই একমাত্র
         * সমন্বয়ের দাখিলা, যার আর কোনো দরজা নেই।**
         */
        ['route' => 'accounts.voucher.create', 'params' => ['type' => 'journal'],
         'label' => 'core.create.voucher', 'can' => 'accounts.voucher.create'],
        ['route' => 'customer.create', 'label' => 'core.create.customer', 'can' => 'customer.create'],
        ['route' => 'supplier.create', 'label' => 'core.create.supplier', 'can' => 'supplier.create'],
        ['route' => 'inventory.product.create', 'label' => 'core.create.product', 'can' => 'inventory.product.create'],
    ])->filter(fn ($e) => ! ($e['can'] ?? null) || auth()->user()?->can($e['can']))

      /*
       * ⚠️ ঠিকানাটা এখানেই বানানো হয়, আর **বানাতে না পারলে সারিটা বাদ**।
       *
       * আগে কেবল `Route::has()` দেখা হত। ওটা যথেষ্ট নয় — নামটা থাকলেই
       * `route()` চলে না: ভাউচারের রুটে একটা `{type}` লাগে, আর প্যারামিটার
       * ছাড়া ডাকলে `route()` **ব্যতিক্রম ছোঁড়ে**। ⓘ ফল হত ভয়াবহ: শেল
       * আঁকা হয় **প্রতিটা পাতায়**, তাই একটা সারির ভুলে **গোটা অ্যাপ ৫০০**।
       *
       * ⭐ ধরা পড়েছে `ComponentTest`-এ, লেখার দিনেই — নাহলে এটা লাইভে যেত।
       * তাই যাচাইটা এখন কাঠামোগত: `Route::has()` নয়, **সত্যিকারের
       * ঠিকানা বানিয়ে দেখা**। নতুন সারি প্যারামিটার চাইলে সে চুপচাপ
       * বাদ পড়বে, অ্যাপ মরবে না।
       */
      ->map(function ($e) {
          try {
              $e['href'] = route($e['route'], $e['params'] ?? []);
          } catch (\Throwable) {
              return null;
          }

          return $e;
      })
      ->filter()
      ->values();
@endphp

<div x-data="{ open: false }" class="relative">
    <button type="button"
            @click="open = ! open" @click.outside="open = false"
            @keydown.escape.window="open = false"
            :aria-expanded="open.toString()"
            class="flex min-h-(--spacing-touch) items-center gap-1.5 rounded-(--radius-field)
                   bg-(--color-brand-500) px-3 text-sm font-medium text-white
                   transition-colors hover:brightness-110">
        <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 shrink-0 fill-current">
            <path d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5Z"/>
        </svg>
        <span class="topbar-label hidden sm:inline">{{ __('core.action.create') }}</span>
        <svg viewBox="0 0 24 24" aria-hidden="true" class="size-3 shrink-0 fill-current opacity-70">
            <path d="M7 10l5 5 5-5H7Z"/>
        </svg>
    </button>

    <div x-show="open" x-cloak x-transition.opacity
         class="pops-onto-page absolute end-0 z-30 mt-1 w-56 overflow-hidden rounded-(--radius-card)
                border border-(--color-border) bg-(--color-surface-card) shadow-lg">
        @forelse ($entries as $entry)
            <a href="{{ $entry['href'] }}"
               class="block px-3 py-2 text-sm hover:bg-(--color-surface-hover)">
                {{ __($entry['label']) }}
            </a>
        @empty
            <p class="px-3 py-3 text-sm text-(--color-ink-muted)">
                {{ __('core.create.nothing_yet') }}
            </p>
        @endforelse
    </div>
</div>
