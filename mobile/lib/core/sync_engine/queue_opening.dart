import 'dart:io';

import 'package:hive/hive.dart';

import '../storage/hive_encryption.dart';
import 'queue_lock.dart';

/// সারির বাক্স খোলার সমস্যা — সিঙ্কের পর্দা মানুষকে বলে ([[SyncEngine.trouble]])।
enum QueueTrouble {
  /// অন্যজন (পেছনের সিঙ্ক বা খোলা অ্যাপ) এখন বাক্সটা ধরে আছেন — একটু পরে আবার চেষ্টা; কিছুই মোছা হয়নি
  busy,

  /// চাবি পড়া যায়নি — বাক্স ভাঙা নয়, রাখা আছে; পরের চালানে আবার
  keyUnavailable,

  /// বাক্স খোলেনি — ফাইলটা আলাদা করে রাখা হয়েছে ([QueueOpened.keptAt]), নতুন বাক্সে সিঙ্ক চলছে
  quarantined,

  /// বাক্স খোলেনি, আলাদা করেও রাখা গেল না — ফাইল যেখানে ছিল সেখানেই, সিঙ্ক বন্ধ
  broken,
}

class QueueOpened {
  const QueueOpened(this.box, this.trouble, {this.keptAt});

  final Box<Map>? box;
  final QueueTrouble? trouble;

  /// আলাদা করে রাখা ভাঙা ফাইলের পথ — পরে উদ্ধারের জন্য
  final String? keptAt;
}

/// ⛔ সারির বাক্স খোলা — কখনো মুছে নয় (মোবাইল অ্যাপ নিরীক্ষা, fe, ১৮ অক্টোবর ২০২৬; [[QueueLock]])।
///
/// <p>ক্রম: আগে তালা ([QueueLock]) — না পেলে [QueueTrouble.busy], ডিস্ক ছোঁয়াই হয় না। তালা হাতে খুলতে না পারলে সমস্যাটা আসল
/// (অন্য কেউ ধরে নেই): ফাইলটা নতুন নামে সরিয়ে রাখা হয় (`…broken-<সময়>.hive`), তারপর নতুন বাক্স। ⚠️ আগে এখানে
/// `Hive.deleteBoxFromDisk` ছিল — অন্যজন বাক্স ধরে থাকলেও "ভাঙা" ধরে মুছে দিত।
class QueueOpening {
  QueueOpening({
    required this.boxName,
    required this.lock,
    required this.directory,
    Future<Box<Map>> Function(String name)? open,
  }) : _open = open ?? HiveEncryptionKey.openBox<Map>;

  final String boxName;
  final QueueLock lock;

  /// Hive-এর ফাইলগুলো যে ফোল্ডারে — ভাঙা ফাইল সরানোর জন্য; না জানা গেলে সরানো হয় না, মোছাও নয়
  final Future<String?> Function() directory;

  final Future<Box<Map>> Function(String name) _open;

  Future<QueueOpened> run({Duration wait = Duration.zero}) async {
    if (!await lock.acquire(wait: wait)) {
      return const QueueOpened(null, QueueTrouble.busy);
    }

    try {
      return QueueOpened(await _open(boxName), null);
    } on HiveKeyUnavailable {
      lock.release();
      return const QueueOpened(null, QueueTrouble.keyUnavailable);
    } catch (_) {
      final kept = await _keepAside();
      if (kept == null) {
        lock.release();
        return const QueueOpened(null, QueueTrouble.broken);
      }

      try {
        return QueueOpened(await _open(boxName), QueueTrouble.quarantined, keptAt: kept);
      } catch (_) {
        lock.release();
        return QueueOpened(null, QueueTrouble.broken, keptAt: kept);
      }
    }
  }

  /// ভাঙা ফাইলটা নতুন নামে — একই ফোল্ডারে, যাতে পরে উদ্ধার করা যায়। সরানো গেলে নতুন পথ, নইলে null (তখন কিছুই বদলায় না)।
  Future<String?> _keepAside() async {
    final dir = await directory();
    if (dir == null) return null;

    final file = File('$dir${Platform.pathSeparator}$boxName.hive');
    if (!await file.exists()) return null;

    final stamp = DateTime.now().toUtc().toIso8601String().replaceAll(RegExp(r'[^0-9]'), '');
    final kept = '$dir${Platform.pathSeparator}$boxName.broken-$stamp.hive';

    try {
      await file.rename(kept);
      // ⓘ পুরনো তালা-ফাইল নতুন বাক্সকে আটকাত — এটা কেবল Hive-এর চিহ্ন, তথ্য নয়
      final lockFile = File('$dir${Platform.pathSeparator}$boxName.lock');
      if (await lockFile.exists()) await lockFile.delete();
      return kept;
    } catch (_) {
      return null;
    }
  }
}
