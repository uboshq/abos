{{--
    ⭐ SAP রূপে স্পেসের পাতা — যে মডিউলের নিজের ড্যাশবোর্ড নেই (বা তার চাবি নেই), তার পাতায় কেবল মেনুর ভাগ আর টালি
    ([[FioriLaunchpad]]; মালিক, ২০ নভেম্বর ২০২৬)। ⓘ ড্যাশবোর্ড থাকলে সেটাই খোলে, মাথায় একই টালি ([[dashboard.module-v2]])।
    ⛔ কেবল SAP রূপে ডাকা হয় ([[ModuleDashboardController]])।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $space['label'] }}</x-slot:title>

    <x-fiori.sections :sections="\App\Core\Support\FioriLaunchpad::sections($space)" kind="page" />
</x-layouts.app>
