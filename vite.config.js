import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
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
