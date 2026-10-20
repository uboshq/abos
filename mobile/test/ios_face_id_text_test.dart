import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// ⭐ iPhone-এ অ্যাপ-তালা (local_auth) Face ID চাইলে Info.plist-এ NSFaceIDUsageDescription লাগে — না থাকলে iOS অ্যাপ বন্ধ
/// করে দেয়। লেখা বাংলায়, কারণসহ (a6, ২০ অক্টোবর ২০২৬)।
void main() {
  test('the iPhone is told in Bangla why the app lock asks for Face ID', () {
    final plist = File('ios/Runner/Info.plist').readAsStringSync();
    final m = RegExp(r'<key>NSFaceIDUsageDescription</key>\s*<string>([^<]+)</string>').firstMatch(plist);
    expect(m, isNotNull, reason: '⛔ Face ID-এর লেখা নেই — iPhone-এ তালা খুললেই অ্যাপ বন্ধ');
    expect(m!.group(1), contains('ABOS'));
    expect(RegExp('[ঀ-৿]').hasMatch(m.group(1)!), isTrue, reason: 'বাংলায়');
  });
}
