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
flutter build apk --release

APK=build/app/outputs/flutter-apk/app-release.apk
SDK="${ANDROID_SDK_ROOT:-/c/Android/Sdk}"
BT=$(ls -d "$SDK"/build-tools/* | sort -V | tail -1)
"$BT"/apksigner.bat verify --print-certs "$APK" | grep "SHA-256"
"$BT"/aapt.exe dump badging "$APK" | head -1
sha256sum "$APK"
