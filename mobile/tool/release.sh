#!/bin/bash
# সই-করা রিলিজ — পুরো টেস্ট সবুজ না হলে বিল্ডই হয় না (২ অক্টোবর ২০২৬)।
#
# ⛔ কেন এই ফাইল: 0.4.3+8 বেরিয়েছিল app_config.dart-এ appVersionCode = 7 নিয়ে — প্রতিটা ফোন চিরকাল
# "আপডেট আছে" বলছিল। test/app_version_test.dart ঠিক এই ভুলটাই ধরে, কিন্তু বিল্ডের আগে পুরো টেস্ট চালানো
# হয়নি, কেবল নতুন টেস্টগুলো। নিয়মটা লেখা ছিল (docs/মোবাইল অ্যাপ রিলিজ করার নিয়ম.md), মানা হয়নি —
# তাই নিয়মটা এখন হাতে নয়, এখানে।
#
# ⓘ চাবি (key.properties / keystore) এই স্ক্রিপ্ট পড়ে না, ছাপে না — Gradle নিজে পড়ে।
set -euo pipefail
cd "$(dirname "$0")/.."

flutter test

# ⭐ কোড ঝাপসা — OWASP MASVS-RESILIENCE-3 (অ্যাপের নিরাপত্তা, ১৯ অক্টোবর ২০২৬): APK খুলে শ্রেণি আর মেথডের নাম পড়ে ভেতরের
# নিয়ম (দাম, সীমা, দরজা) বোঝা কঠিন হয়। ⓘ চিহ্নের ফাইল ছাড়া ঝাপসা stack পড়া যায় না — তাই প্রতি সংস্করণের চিহ্ন
# `symbols/<সংস্করণ>/`-এ থাকে (git-এর বাইরে, .gitignore)। ক্র্যাশের খবর পড়তে:
#   flutter symbolize -i stack.txt -d symbols/<সংস্করণ>/app.android-arm64.symbols
VERSION=$(grep -E '^version:' pubspec.yaml | awk '{print $2}')
# ⭐ side ফ্লেভার (২০ নভেম্বর ২০২৬) — অফিসের লিংকের APK, com.abos.abos_mobile, নিজে-আপডেট আর SMS ফোন সহ।
# Google Play-র .aab আলাদা: tool/release-play.sh। ⓘ ABOS_CHANNEL না দিলে side — তবু স্পষ্ট লেখা।
flutter build apk --release --flavor side --dart-define=ABOS_CHANNEL=side --obfuscate --split-debug-info="symbols/$VERSION"
echo "চিহ্নের ফাইল: symbols/$VERSION — এই সংস্করণের ক্র্যাশ পড়তে রেখে দিন, মুছবেন না"

# ⓘ ফ্লেভারের পর থেকে APK-র নাম app-side-release.apk (আগে app-release.apk)
APK=build/app/outputs/flutter-apk/app-side-release.apk
SDK="${ANDROID_SDK_ROOT:-/c/Android/Sdk}"
BT=$(ls -d "$SDK"/build-tools/* | sort -V | tail -1)
"$BT"/apksigner.bat verify --print-certs "$APK" | grep "SHA-256"
"$BT"/aapt.exe dump badging "$APK" | head -1
sha256sum "$APK"
# ⭐ সার্ভারের .env-এর তিন লাইন, ঠিক যেমন বসাতে হবে — হাতে লিখলে একটা অক্ষর ভুলেই প্রতিটা ফোন "মেলেনি" বলে
# (অ্যাপ রিলিজ নিরীক্ষা, ২০ অক্টোবর ২০২৬; app/Http/Controllers/Api/AppVersionController.php)
echo "ANDROID_APK_SHA256=$(sha256sum "$APK" | cut -d' ' -f1)"
echo "ANDROID_APK_SIZE_BYTES=$(stat -c %s "$APK")"
echo "ANDROID_VERSION_CODE=$(grep -E '^version:' pubspec.yaml | sed 's/.*+//')"
