import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import postcss from 'postcss';

/*
 * ⭐ ১৯২০-এর এক মাপ (মালিক, ২৮ নভেম্বর ২০২৬): ABOS-এর পাতা সবসময় ১৯২০ চওড়ায় সাজানো, জুমে পুরোটা একসাথে বড়-ছোট হয়, কিছু ভাঙে না।
 * পাতা `zoom` পায় (layouts/app-এর ছোট স্ক্রিপ্ট), কিন্তু vh/vw আসল জানালা মাপে — তাই বানানো CSS-এর প্রতিটা vh/vw এখানে
 * `calc(N * var(--fit-vh, 1vh))` হয়। ভেরিয়েবল না থাকলে (বাকি পাতা, নকল রূপ, ফোন) হুবহু আগের মান — কিছুই বদলায় না।
 */
const UNIT = /(^|[^\w.-])(-?\d*\.?\d+)(d|s|l)?(vh|vw)\b/g;
const fitUnits = () => ({
    name: 'abos-fit-units',
    apply: 'build',
    async generateBundle(_, bundle) {
        for (const file of Object.values(bundle)) {
            if (file.type !== 'asset' || ! file.fileName.endsWith('.css')) continue;
            const root = postcss.parse(String(file.source));
            root.walkDecls((d) => {
                if (d.prop.startsWith('--fit-') || ! /\d(d|s|l)?v[hw]\b/.test(d.value)) return;
                d.value = d.value.replace(UNIT, (m, pre, n, kind, unit) => pre + 'calc(' + n + ' * var(--fit-' + unit + ', 1' + (kind || '') + unit + '))');
            });
            file.source = root.toString();
        }
    },
});

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/messenger-page.js', 'resources/js/meeting-page.js', 'resources/js/mail-page.js', 'resources/js/mail-admin.js', 'resources/js/mail-badge.js'],
            refresh: true,
            // ফন্ট প্লাগিন সরানো হয়েছে: এটা Bunny থেকে Instrument Sans নামাত,
            // যা আমাদের ব্র্যান্ড ফন্ট নয় এবং বাংলা অক্ষরও নেই। Poppins ও
            // Hind Siliguri public/fonts-এ নিজেদের সার্ভারে রাখা (সেকশন ১৭.৪)।
        }),
        tailwindcss(),
        fitUnits(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
