import 'package:abos_mobile/core/update/app_version_check.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ হালনাগাদে কী নতুন — মালিক, ২ অক্টোবর ২০২৬: "ki ki update holo ta dekhabe"।
/// সার্ভারের নোট (ANDROID_NOTE_BN, এক লাইনের) `|`-এ ভাগ করা — ব্যানারে এক লাইনে এক জিনিস।
void main() {
  AppRelease withNote(Map<String, dynamic>? note) => AppRelease.fromJson({
        'versionCode': 11,
        'versionName': '0.4.6',
        'url': 'https://example.com/a.apk',
        'apkSha256': 'a' * 64,
        'sizeBytes': 10,
        'minimumCode': 1,
        if (note != null) 'note': note,
      });

  test('a note splits into one line per change, blanks dropped', () {
    final release = withNote({
      'bn': 'গ্রাহকের তালিকা ফিরল | হালনাগাদে কী নতুন দেখায় ||  কাগজ স্ক্যান ',
      'en': 'Customers back | What is new | Paper scan',
    });
    expect(release.whatsNew, ['গ্রাহকের তালিকা ফিরল', 'হালনাগাদে কী নতুন দেখায়', 'কাগজ স্ক্যান']);
  });

  test('a note written on several lines works the same', () {
    expect(withNote({'bn': 'এক\nদুই', 'en': 'one\ntwo'}).whatsNew, ['এক', 'দুই']);
  });

  test('no note, no list — never a blank bullet', () {
    expect(withNote(null).whatsNew, isEmpty);
  });
}
