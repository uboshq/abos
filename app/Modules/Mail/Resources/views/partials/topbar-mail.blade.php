{{--
    ⭐ ABOS-এর বারে মেইল — বার্তার পাশে, না-পড়া সংখ্যাসহ (নমুনা v3/v4 "ok": "ABOS-এর বারে না-পড়ার চিহ্ন")।
    ⓘ চাপলে মেইল নিজের ট্যাবে খোলে; সংখ্যা আসে হালকা জিজ্ঞাসায় ([[mail-badge.js]], 3 মিনিটে একবার; সার্ভারে 2 মিনিটের জমা)।
    ⛔ কেবল তিন ABOS রূপে আর `mail.use` চাবিতে, আর চালু মেইলবক্স থাকলে — SAP, Dynamic, Odoo-তে আঁকাই হয় না।
--}}
<a href="{{ route('mail.index', ['counter' => 1]) }}" target="_blank" rel="noopener"
   data-mail-topbar data-unread-url="{{ route('mail.unread') }}"
   class="relative flex size-(--spacing-touch) items-center justify-center rounded-(--radius-field) transition-colors hover:bg-(--color-surface-hover)"
   title="{{ __('mail::field.title') }}" aria-label="{{ __('mail::field.title') }}">
    <span class="grid size-8 place-items-center" data-topbar-icon="mail"><x-ui.icon name="mail" :size="24" :drawn="true" /></span>
    <span class="msgr-topbar__badge" data-mail-badge hidden></span>
</a>
@vite('resources/js/mail-badge.js')
