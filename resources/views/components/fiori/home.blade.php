{{-- ⭐ SAP রূপে হোমের লঞ্চপ্যাড — প্রতিটা স্পেস (মডিউল) একটা টালি ([[FioriLaunchpad::home()]]); কেবল SAP রূপে ডাকা হয় ([[workspace.home]]) --}}
<x-fiori.sections :sections="\App\Core\Support\FioriLaunchpad::home($menu ?? [])" kind="home" />
