import 'dart:convert';
import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/painting.dart';
import 'package:path_provider/path_provider.dart';

/// ⭐ মুখের ছবি ফোনের ডিস্কে — কেবল অ্যাপের নিজের ক্যাশ-ফোল্ডারে, ETag-সহ (fe, ২০ অক্টোবর ২০২৬: MASVS-STORAGE আর নেটের খরচ)।
///
/// <p>⛔ <b>কোথায়</b>: `getApplicationCacheDirectory()/abos-faces` — Android-এ `/data/data/<package>/cache`, iOS-এ অ্যাপের
/// Library/Caches: অ্যাপ ছাড়া কেউ পড়তে পারে না, গ্যালারিতে দেখায় না, ব্যাকআপে যায় না (ব্যাকআপ বন্ধ, ম্যানিফেস্ট)। বাইরের
/// স্টোরেজ বা গ্যালারি কখনো নয়।
///
/// <p>⭐ <b>চাবি</b> = ছবির ঠিকানার sha256 (ঠিকানায় `v=` ছাপ আছে) — ⛔ টোকেন কখনো নয়: না ফাইলের নামে, না ফাইলের ভেতরে।
/// প্রতি মুখে দুই ফাইল: `<চাবি>.face` (বাইট) আর `<চাবি>.etag` (সার্ভারের ETag, যেমন এসেছিল)।
///
/// <p>⭐ <b>সীমা</b>: [maxFaces]টা মুখ বা [maxBytes], যেটা আগে — বেশি হলে সবচেয়ে পুরনো-ব্যবহৃত আগে যায় (LRU; প্রতিটা পড়ায়
/// ফাইলের সময় নতুন হয়)।
///
/// <p>⛔ <b>মোছা</b>: বেরোনো, কোম্পানি বদল আর দূর থেকে বের করা (৪০১, সেশন শেষ) — তিনটাই [WidgetHub.clear] ডাকে, আর সেটাই
/// [wipe] ডাকে; আলাদা কোনো ট্রিগার নয়। মোছার পরে আগে শুরু হওয়া আনা আর কিছু লেখে না ([_generation])।
class FaceCache {
  FaceCache._();

  static final FaceCache instance = FaceCache._();

  static const String folder = 'abos-faces';
  static const int maxFaces = 200;
  static const int maxBytes = 10 * 1024 * 1024;

  /// পরীক্ষার পথ — গোড়ার ফোল্ডার; আসলটা অ্যাপের নিজের ক্যাশ
  @visibleForTesting
  static Future<Directory> Function() root = getApplicationCacheDirectory;

  int _generation = 0;

  /// মোছার পালা — আনা শুরুর সময়ের নম্বর; [put]-এ মেলে না হলে লেখা হয় না
  int get generation => _generation;

  static String keyOf(String url) => sha256.convert(utf8.encode(url)).toString();

  Future<Directory> _dir() async {
    final dir = Directory('${(await root()).path}${Platform.pathSeparator}$folder');
    if (!await dir.exists()) await dir.create(recursive: true);
    return dir;
  }

  File _face(Directory dir, String key) => File('${dir.path}${Platform.pathSeparator}$key.face');
  File _etag(Directory dir, String key) => File('${dir.path}${Platform.pathSeparator}$key.etag');

  /// জমা মুখ আর তার ETag — না থাকলে null। ⓘ পড়লেই "সম্প্রতি ব্যবহৃত" (LRU)
  Future<({Uint8List bytes, String etag})?> get(String url) async {
    try {
      final dir = await _dir();
      final key = keyOf(url);
      final face = _face(dir, key);
      final etag = _etag(dir, key);
      if (!await face.exists() || !await etag.exists()) return null;
      final bytes = await face.readAsBytes();
      final tag = await etag.readAsString();
      if (bytes.isEmpty || tag.isEmpty) return null;
      await touch(url);
      return (bytes: bytes, etag: tag);
    } catch (_) {
      return null;
    }
  }

  /// সদ্য ব্যবহৃত — ফাইলের সময় এখন
  Future<void> touch(String url) async {
    try {
      final dir = await _dir();
      final now = DateTime.now();
      await _face(dir, keyOf(url)).setLastModified(now);
    } catch (_) {}
  }

  /// নতুন মুখ রাখা (সার্ভারের ২০০) — [startedAt] আনা শুরুর [generation]; এর মধ্যে মোছা হলে কিছুই লেখা হয় না
  Future<void> put(String url, Uint8List bytes, String etag, {required int startedAt}) async {
    if (bytes.isEmpty || etag.isEmpty || bytes.length > maxBytes) return;
    try {
      if (startedAt != _generation) return;
      final dir = await _dir();
      final key = keyOf(url);
      await _face(dir, key).writeAsBytes(bytes, flush: true);
      await _etag(dir, key).writeAsString(etag, flush: true);
      if (startedAt != _generation) {
        // ⓘ লেখার মাঝে মোছা শুরু — যা লিখলাম সেটাও যায়
        await wipe();
        return;
      }
      await _trim(dir);
    } catch (_) {}
  }

  /// সীমার বাইরে গেলে সবচেয়ে পুরনো-ব্যবহৃত আগে
  Future<void> _trim(Directory dir) async {
    final faces = <(File, DateTime, int)>[];
    await for (final e in dir.list()) {
      if (e is File && e.path.endsWith('.face')) {
        final stat = await e.stat();
        faces.add((e, stat.modified, stat.size));
      }
    }
    faces.sort((a, b) => a.$2.compareTo(b.$2));
    var total = faces.fold<int>(0, (sum, f) => sum + f.$3);
    var count = faces.length;
    for (final (file, _, size) in faces) {
      if (count <= maxFaces && total <= maxBytes) break;
      final tag = File(file.path.replaceFirst(RegExp(r'\.face$'), '.etag'));
      if (await file.exists()) await file.delete();
      if (await tag.exists()) await tag.delete();
      count--;
      total -= size;
    }
  }

  /// ⛔ সব মুখ — ডিস্কের ফোল্ডার আর মেমরির ছবি দুটোই; কখনো ছোড়ে না (বেরোনো থামে না)
  Future<void> wipe() async {
    _generation++;
    try {
      final dir = Directory('${(await root()).path}${Platform.pathSeparator}$folder');
      if (await dir.exists()) await dir.delete(recursive: true);
    } catch (_) {
      // ⓘ ফোল্ডার নেই বা পথ জানা নেই (পটভূমি/পরীক্ষা) — মোছার কিছু নেই
    }
    try {
      // ⓘ মেমরিতে খোলা মুখও — পরের জন আগের জনের সহকর্মীদের মুখ ক্যাশ থেকে না দেখেন
      PaintingBinding.instance.imageCache
        ..clear()
        ..clearLiveImages();
    } catch (_) {
      // ⓘ পটভূমির isolate-এ ছবির ক্যাশ নেই
    }
  }
}
