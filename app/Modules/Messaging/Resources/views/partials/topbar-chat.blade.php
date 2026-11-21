{{--
    ⭐ টপবারের চ্যাট — ঘণ্টার ঠিক আগে, বারের অন্য আইকনের মতোই সাদামাটা, সোনালি (বার্তার ওয়েব নমুনা M1UddRxK v7)।
    ⓘ না-পড়া বার্তার সবুজ ব্যাজ হালকা জিজ্ঞাসায় বসে ([[messenger-call.js]] — `messaging.x.unread`, ১৫ সেকেন্ডে একবার)।
    ⓘ বার্তার পাতায় থাকলে চিহ্ন ভরাট, ব্যাজ নেই — নমুনার মতো।
    ⛔ কেবল তিন ABOS রূপে আর `messaging.use` চাবিতে ([[MessengerChrome::on()]]) — SAP ও Dynamic-এ এটা আঁকাই হয় না।
--}}
@php
    $onMessages = request()->routeIs('messaging.index');
@endphp
<a href="{{ route('messaging.index') }}"
   data-msgr-topbar
   @if ($onMessages) data-msgr-here aria-current="page" @endif
   class="relative flex size-(--spacing-touch) items-center justify-center rounded-(--radius-field)
          transition-colors hover:bg-(--color-surface-hover)"
   title="{{ __('messaging::call.topbar.title') }}"
   aria-label="{{ __('messaging::call.topbar.title') }}">
    <span class="msgr-topbar__glyph grid size-8 place-items-center" data-topbar-icon="chat">
        <x-ui.icon :name="$onMessages ? 'chat_fill' : 'chat'" :size="26" />
    </span>
    <span class="msgr-topbar__badge" data-msgr-badge hidden></span>
</a>
