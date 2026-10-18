import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:path_provider/path_provider.dart';

/// ⭐ নামানো কাগজের ফোল্ডার — বিল, চালান, রিপোর্টের PDF আর দলিলের ফাইল (পূর্ণ নিরীক্ষা §৯ ⚠️১৩, ১৮ অক্টোবর ২০২৬)।
///
/// <p>⛔ আগে এগুলো অ্যাপের documents আর অস্থায়ী ফোল্ডারের গোড়ায় তালা ছাড়া পড়ে থাকত, বেরোনোর পরেও — আগের জনের কাগজ পরের
/// জনের সেশনে থেকে যেত। ⓘ এখন সব এক জায়গায় (`abos-papers`), আর বেরোলে [wipe] দুইটা ফোল্ডারই মোছে।
/// ⚠️ documents ফোল্ডার নিজে মোছা যায় না — Hive-এর বাক্স ওখানেই।
class PaperFiles {
  const PaperFiles._();

  static const String folder = 'abos-papers';

  /// পরীক্ষার জন্য — গোড়ার ফোল্ডার দুইটা
  @visibleForTesting
  static Future<Directory> Function() temporaryRoot = getTemporaryDirectory;
  @visibleForTesting
  static Future<Directory> Function() documentsRoot = getApplicationDocumentsDirectory;

  /// শেয়ার বা খোলার জন্য কিছুক্ষণের কপি
  static Future<Directory> cache() => _inside(temporaryRoot);

  /// মানুষ "ফোনে রাখুন" বললে
  static Future<Directory> kept() => _inside(documentsRoot);

  /// ⛔ বেরোলে — দুইটা ফোল্ডারই, ভিতরের সবকিছুসহ। ⓘ কোনো ভুলে বের হওয়া থামে না
  static Future<void> wipe() async {
    for (final root in [temporaryRoot, documentsRoot]) {
      try {
        final dir = Directory('${(await root()).path}/$folder');
        if (await dir.exists()) await dir.delete(recursive: true);
      } catch (_) {
        // ⓘ ফোল্ডার না পেলে মোছার কিছু নেই
      }
    }
  }

  /// ফাইলের নাম নিরাপদ — পথের চিহ্ন বাদ
  static String safeName(String name) => name.isEmpty ? 'document' : name.replaceAll(RegExp(r'[\/]'), '_');

  static Future<Directory> _inside(Future<Directory> Function() root) async {
    final dir = Directory('${(await root()).path}/$folder');
    if (!await dir.exists()) await dir.create(recursive: true);
    return dir;
  }
}
