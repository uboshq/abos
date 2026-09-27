# hp2-র QA টুল

লাইভ পরীক্ষা আর রিপোর্টের জন্য hp2 যে script-গুলো বানিয়েছে। প্রথমবার: `npm install`
(playwright-core আর marked নামবে; Chromium আসে `~/AppData/Local/ms-playwright` থেকে)।

| ফাইল | কাজ |
|---|---|
| `qa.js` | প্রতিটা ভূমিকার জন্য আলাদা browser profile-এ লাইভ সাইট চালানো। `node qa.js <profile> <command> ...`। সাহায্যকারী এজেন্টরা এটাই ব্যবহার করে, কারণ Playwright MCP-র browser একটাই। |
| `update-mock.js` | মোবাইলের নিজে-আপডেট পরীক্ষার নকল `/app/version`। `node update-mock.js <good|wrong-sha|wrong-size|broken> [port]`। বাকি সব অনুরোধ লাইভে যায়। |
| `make-report-pdf.js` | `C:\ABOS\reports`-এর markdown রিপোর্টগুলো থেকে এক PDF। Chromium দিয়ে, কারণ reportlab বাংলা যুক্তাক্ষর জোড়ে না। |
| `check-report.js` | PDF-এর HTML print mode-এ screenshot নিয়ে চোখে দেখার জন্য। |

Git Bash-এ `MSYS_NO_PATHCONV=1` দিয়ে চালান, আর URL সবসময় পুরো `https://` দিয়ে লিখুন।
পাসওয়ার্ড কোনো ফাইলে লেখা নেই, লেখা হবেও না।
