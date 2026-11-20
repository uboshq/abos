# Handover — Google Play build (univer.abos), 20 Nov 2026

Branch `fe/app-play`, from app 0.4.58 (`e9e8cec98`). Owner decisions:
- new app id **univer.abos**, under the UNIVER BANGLADESH Play account;
- the sideload app stays as it is.

## Done
- **Two Android flavors:**
  - `side`: `com.abos.abos_mobile`, the default. It is the APK on phones today, with self-update and the SMS gateway, and it is unchanged.
  - `play`: `univer.abos`.
  - Both keep the Kotlin namespace `com.abos.abos_mobile` and versionCode `20000 + pubspec build` (0.4.58+63 → 20063).
- **The play manifest** (`android/app/src/play/AndroidManifest.xml`) removes `REQUEST_INSTALL_PACKAGES`, `SEND_SMS`, `FOREGROUND_SERVICE_SPECIAL_USE` and the three SMS components. It keeps `RECORD_AUDIO` (Copilot voice), `FOREGROUND_SERVICE` and `RECEIVE_BOOT_COMPLETED` (WorkManager sync).
- **Dart switch** `--dart-define=ABOS_CHANNEL=play`, in `lib/core/config/app_channel.dart`:
  - the update tile, notice and minimum-version wall open the Play Store page instead of downloading an APK;
  - the install flow refuses;
  - the SMS gateway screens and route are absent.
- **Signing:**
  - `side` uses `android/key.properties` (abos-release.jks), as before;
  - `play` uses `android/key-play.properties` (upload key);
  - each flavor's release build stops if its key file is missing.
- **Scripts:**
  - `tool/release.sh` now builds `--flavor side`; the APK is `build/app/outputs/flutter-apk/app-side-release.apk`.
  - `tool/release-play.sh` builds the `.aab`.
- **Tests:** `flutter analyze` clean, `flutter test -j 2` 2866 passed, including `test/play_channel_test.dart` and `test/android_build_config_test.dart`.

## Blockers (need the owner)
1. **Firebase json.** In the EXISTING ABOS Firebase project:
   - add the Android app `univer.abos`;
   - download the new `google-services.json`, which lists both packages, into `mobile/android/app/`.

   Only the owner can log in to the console. Until then the play build stops at the google-services step. After that:
   - run `flutter build appbundle --flavor play`;
   - check the merged manifest has no SMS or install permission;
   - copy the Play app-signing and upload key SHA-1/SHA-256 into the Firebase app settings.
2. **Upload key.** Create `univer-abos-upload.jks` and `android/key-play.properties` only after the owner's OK (the `keytool` command is in the release doc).
   - ⛔ Never commit either file; both are gitignored.
   - Back up in two places off the machine, with the password in a password manager.
   - Play App Signing holds the app key (Google-managed).
3. **Privacy policy and account-deletion pages** on the web domain (erp.adi.com.bd). Both are mandatory for the listing. Not built yet.
4. **Play Console forms:**
   - data safety, content rating, audience (business), ads (none);
   - app access via a demo-site login with fake data only, never the real business.

## Still to do in code
- **Per-channel version check.** The app's "new version" check reads the server's `/api/v1/app/version`, which describes the sideload APK. Add a play channel on the server (e.g. `ANDROID_PLAY_*` env lines, or `?channel=play`) so Play users aren't told to update before Play has the build.
- A plain `flutter build apk` / `flutter run` now needs `--flavor side`. `default-flavor` is not set in pubspec because iOS has only the Runner scheme.

## Related, same day
App 0.4.58 (side) went live through the in-app update (20063, sha `a7ad46ba…93a0`). The old 0.4.57 APK is backed up on the server in `~/apk-backups/`.
