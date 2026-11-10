import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// ⭐ ফোনে বসানোর পর্দায় যে অনুমতিগুলো দেখায়, তার পুরো তালিকা — নতুন একটা এলে এই পরীক্ষা লাল হয়, আর কেন লাগে তা
/// লিখে তবে এখানে যোগ (অ্যাপ রিলিজ নিরীক্ষা, ec, ২০ অক্টোবর ২০২৬)। ⓘ এটা অ্যাপের নিজের ম্যানিফেস্ট; প্লাগইনের ঢোকানোগুলো
/// মেলানোর পরে দেখা যায় (build/app/intermediates/merged_manifests/release) — যেগুলো লাগে না সেগুলো এখানে
/// `tools:node="remove"`।
String _manifest() => File('android/app/src/main/AndroidManifest.xml').readAsStringSync();

/// মন্তব্য বাদ দিয়ে, প্রতিটা `<uses-permission …>` → (নাম, সরানো কিনা)।
Map<String, bool> _permissions() {
  final code = _manifest().replaceAll(RegExp(r'<!--.*?-->', dotAll: true), '');
  return {
    for (final m in RegExp(r'<uses-permission\b([^>]*)>', dotAll: true).allMatches(code))
      RegExp(r'android:name="([^"]+)"').firstMatch(m.group(1)!)!.group(1)!:
          m.group(1)!.contains('tools:node="remove"'),
  };
}

void main() {
  test('the app declares only the permissions it uses', () {
    final declared = {for (final e in _permissions().entries) if (!e.value) e.key};
    expect(declared, {
      'android.permission.BLUETOOTH', // ≤ Android 11, থার্মাল প্রিন্টার
      'android.permission.BLUETOOTH_ADMIN', // ≤ Android 11
      'android.permission.BLUETOOTH_CONNECT', // প্রিন্টারে যুক্ত হওয়া
      'android.permission.BLUETOOTH_SCAN', // প্লাগইন নিজে এটাও দেখে; neverForLocation
      'android.permission.CAMERA', // QR, স্লিপ/রসিদের ছবি
      'android.permission.REQUEST_INSTALL_PACKAGES', // অ্যাপের ভেতর থেকে আপডেট
      'android.permission.USE_BIOMETRIC', // অ্যাপ-তালা
      // ⭐ অফিসের SMS ফোন (নমুনা ধাপ ২) — SEND_SMS চাওয়া হয় কেবল গেটওয়ে চালু করার সময়
      'android.permission.SEND_SMS',
      'android.permission.FOREGROUND_SERVICE',
      'android.permission.FOREGROUND_SERVICE_SPECIAL_USE',
      'android.permission.RECEIVE_BOOT_COMPLETED',
    });
  });

  test('the storage and media permissions open_filex brings are removed', () {
    final removed = {for (final e in _permissions().entries) if (e.value) e.key};
    expect(removed, containsAll(<String>[
      'android.permission.READ_EXTERNAL_STORAGE',
      'android.permission.READ_MEDIA_IMAGES',
      'android.permission.READ_MEDIA_VIDEO',
      'android.permission.READ_MEDIA_AUDIO',
    ]), reason: '⛔ ফোন "আপনার ছবি, ভিডিও, গান পড়বে" বলত — অ্যাপ নিজের ফোল্ডারের বাইরে কিছু খোলে না');
    expect(_manifest(), contains('xmlns:tools="http://schemas.android.com/tools"'));
  });

  test('the two files open_filex opens stay inside the app\'s own folders', () {
    // ⓘ open_filex অনুমতি চায় কেবল অ্যাপের নিজের ফোল্ডারের বাইরের পথে — তাই এই দুইটা ভিতরে থাকলে তবেই সরানো নিরাপদ
    final installer = File('lib/core/update/apk_installer.dart').readAsStringSync();
    expect(installer, contains('getTemporaryDirectory()'));
    final papers = File('lib/core/printing/paper_files.dart').readAsStringSync();
    expect(papers, contains('static Future<Directory> cache() => _inside(temporaryRoot);'));
    final opens = Directory('lib')
        .listSync(recursive: true)
        .whereType<File>()
        .where((f) => f.path.endsWith('.dart') && f.readAsStringSync().contains('OpenFilex.open('))
        .map((f) => f.path.replaceAll('\\', '/'))
        .toSet();
    expect(opens, {'lib/core/update/apk_installer.dart', 'lib/features/web_inside/web_inside_screen.dart'},
        reason: 'নতুন জায়গায় OpenFilex — ফাইলটা অ্যাপের নিজের ফোল্ডারে কিনা দেখুন, নাহলে অনুমতি লাগবে');
  });
}
