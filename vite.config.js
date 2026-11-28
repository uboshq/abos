import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // ⭐ CSS ভাগ করা — সবার base.css + app.css, মাঝে কেবল নিজের রূপ আর নিজের পাতা ([[PageCss]]; abos-b1, ২৮ নভেম্বর ২০২৬)।
            //   ⛔ এখানে নতুন CSS যোগ করলে PageCss-এও নাম লিখুন — EveryPageCssIsBuiltTest দুটো তালিকা মেলায়।
            input: [
                'resources/css/base.css',
                'resources/css/abos-look.css', 'resources/css/blue-look.css', 'resources/css/fiori-look.css', 'resources/css/dynamic-look.css',
                'resources/css/pages/counter.css', 'resources/css/copilot-page.css', 'resources/css/trade-voucher.css',
                'resources/css/app.css',
                'resources/js/app.js', 'resources/js/messenger-page.js', 'resources/js/meeting-page.js', 'resources/js/mail-page.js', 'resources/js/mail-admin.js', 'resources/js/mail-badge.js'],
            refresh: true,
            // ফন্ট প্লাগিন সরানো হয়েছে: এটা Bunny থেকে Instrument Sans নামাত,
            // যা আমাদের ব্র্যান্ড ফন্ট নয় এবং বাংলা অক্ষরও নেই। Poppins ও
            // Hind Siliguri public/fonts-এ নিজেদের সার্ভারে রাখা (সেকশন ১৭.৪)।
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
