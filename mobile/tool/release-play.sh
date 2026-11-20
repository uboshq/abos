#!/bin/bash
# ⭐ Google Play-র রিলিজ — play ফ্লেভার, univer.abos, .aab (২০ নভেম্বর ২০২৬)।
#
# side-এর APK (অফিসের লিংক) বানায় tool/release.sh; এটা কেবল Play Console-এ তোলার .aab। দুটো আলাদা অ্যাপ:
#   side = com.abos.abos_mobile (abos-release.jks, নিজে-আপডেট + SMS ফোন সহ)
#   play = univer.abos (আপলোড-চাবি univer-abos-upload.jks / android/key-play.properties; নিজে-আপডেট আর SMS ফোন নেই)
#
# ⓘ চাবি (key-play.properties / keystore) এই স্ক্রিপ্ট পড়ে না, ছাপে না — Gradle নিজে পড়ে। ফাইল না থাকলে Gradle-ই থামায়।
# ⓘ android/app/google-services.json-এ univer.abos-এর ক্লায়েন্ট না থাকলে Gradle-এর google-services ধাপ থামে —
#   Firebase কনসোলে univer.abos যোগ করে নতুন json বসান।
# ⛔ এই স্ক্রিপ্ট কিছু আপলোড করে না — .aab হাতে Play Console-এ তোলা হয়।
set -euo pipefail
cd "$(dirname "$0")/.."

# ⛔ পুরো টেস্ট সবুজ না হলে বিল্ডই নয় (release.sh-এর একই নিয়ম)
flutter test

VERSION=$(grep -E '^version:' pubspec.yaml | awk '{print $2}')
# ⭐ ঝাপসা + চিহ্ন আলাদা — Play-র চিহ্ন side-এর থেকে আলাদা ফোল্ডারে (একই সংস্করণের দুই বিল্ড)
flutter build appbundle --release --flavor play --dart-define=ABOS_CHANNEL=play --obfuscate --split-debug-info="symbols/$VERSION-play"
echo "চিহ্নের ফাইল: symbols/$VERSION-play — এই সংস্করণের Play-ক্র্যাশ পড়তে রেখে দিন, মুছবেন না"

AAB=build/app/outputs/bundle/playRelease/app-play-release.aab
[ -f "$AAB" ] || { echo "⛔ $AAB পাওয়া গেল না"; exit 1; }
echo "AAB: $AAB"
ls -l "$AAB"
sha256sum "$AAB"

# ⓘ versionCode আর applicationId — bundletool থাকলে .aab-এর ভেতর থেকেই পড়া
BUNDLETOOL=""
if command -v bundletool >/dev/null 2>&1; then
  BUNDLETOOL="bundletool"
elif [ -n "${BUNDLETOOL_JAR:-}" ] && [ -f "$BUNDLETOOL_JAR" ]; then
  BUNDLETOOL="java -jar $BUNDLETOOL_JAR"
fi
if [ -n "$BUNDLETOOL" ]; then
  echo "applicationId: $($BUNDLETOOL dump manifest --bundle="$AAB" --xpath=/manifest/@package)"
  echo "versionCode:   $($BUNDLETOOL dump manifest --bundle="$AAB" --xpath=/manifest/@android:versionCode)"
  echo "versionName:   $($BUNDLETOOL dump manifest --bundle="$AAB" --xpath=/manifest/@android:versionName)"
else
  echo "ⓘ bundletool নেই (PATH-এ বা BUNDLETOOL_JAR) — .aab-এর ভেতর থেকে পড়া গেল না। হিসাবমতো:"
  echo "applicationId: univer.abos"
  echo "versionCode:   $((20000 + $(echo "$VERSION" | sed 's/.*+//')))  (20000 + pubspec-এর +সংখ্যা)"
fi

# ⛔ debug চাবিতে সই হলে থামা — Play নেয় না, আর ভুল চাবির প্রথম আপলোড আপলোড-চাবি হয়ে বসে যেতে পারে
KEYTOOL=""
if [ -n "${JAVA_HOME:-}" ] && [ -x "$JAVA_HOME/bin/keytool" -o -x "$JAVA_HOME/bin/keytool.exe" ]; then
  KEYTOOL="$JAVA_HOME/bin/keytool"
elif command -v keytool >/dev/null 2>&1; then
  KEYTOOL="keytool"
elif [ -x "/c/Program Files/Android/Android Studio/jbr/bin/keytool.exe" ]; then
  KEYTOOL="/c/Program Files/Android/Android Studio/jbr/bin/keytool.exe"
fi
[ -n "$KEYTOOL" ] || { echo "⛔ keytool পাওয়া গেল না — সই যাচাই হলো না, .aab তুলবেন না"; exit 1; }
CERT=$("$KEYTOOL" -printcert -jarfile "$AAB")
echo "$CERT" | grep -E "Owner:|SHA256:" || true
if echo "$CERT" | grep -q "CN=Android Debug"; then
  echo "⛔ .aab debug চাবিতে সই — Play-তে তুলবেন না। android/key-play.properties দেখুন।"
  exit 1
fi
if ! echo "$CERT" | grep -q "Owner:"; then
  echo "⛔ .aab-এ কোনো সই পাওয়া গেল না — Play-তে তুলবেন না।"
  exit 1
fi
echo "✓ আপলোড-চাবিতে সই (debug নয়) — Play Console → ABOS (univer.abos) → Release-এ তুলুন"
