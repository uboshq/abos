import 'dart:io';
import 'dart:isolate';

import 'package:abos_mobile/core/sync_engine/queue_lock.dart';
import 'package:abos_mobile/core/sync_engine/queue_opening.dart';
import 'package:abos_mobile/features/sync/sync_status_screen.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hive/hive.dart';

/// ⛔ অফলাইন অর্ডার হারাত — খোলা অ্যাপ আর পেছনের সিঙ্ক একসাথে একই সারির বাক্স খুলত, খুলতে না পারলে অ্যাপ বাক্সটা মুছে নতুন
/// বানাত (মোবাইল অ্যাপ নিরীক্ষা, fe, ১৮ অক্টোবর ২০২৬; [[QueueLock]], [[QueueOpening]])।
///
/// দাবি:
///  - অন্যজন (জীবিত) বাক্স ধরে থাকলে: "busy", বাক্স খোলাই হয় না, সারি ডিস্কে অক্ষত;
///  - মালিকের isolate মরে গেলে পুরনো নাম সরিয়ে তালা নেওয়া যায়;
///  - তালা হাতে বাক্স সত্যিই ভাঙা হলে: ফাইলটা আলাদা নামে রাখা (বাইট হুবহু), নতুন বাক্স খোলে — কখনো মোছা নয়;
///  - ছাড়া তালা পরের জন পান; আর পর্দার প্রতিটা বার্তা বলে "মোছা হয়নি"।
void main() {
  const box = 'abos_sync_queue';
  late Directory dir;

  setUp(() async {
    dir = await Directory.systemTemp.createTemp('abos_queue_lock_');
    Hive.init(dir.path);
  });

  tearDown(() async {
    await Hive.close();
    if (await dir.exists()) await dir.delete(recursive: true);
  });

  Future<Box<Map>> plainOpen(String name) => Hive.openBox<Map>(name);

  QueueOpening opening(QueueLock lock, {Future<Box<Map>> Function(String)? open}) =>
      QueueOpening(boxName: box, lock: lock, directory: () async => dir.path, open: open ?? plainOpen);

  test('while a live owner holds the queue, the other side opens nothing and deletes nothing', () async {
    // ⓘ সারিতে একটা না-পাঠানো অর্ডার, ডিস্কে
    final queue = await Hive.openBox<Map>(box);
    await queue.add({'status': 'PENDING', 'payloadJson': '{"order":"O-1"}'});
    await queue.close();

    final names = FakePortNames();
    final owner = ReceivePort();
    owner.listen((m) {
      if (m is SendPort) m.send(true); // ⓘ জীবিত মালিক — "আছি"
    });
    names.register(owner.sendPort, QueueLock.defaultName);

    var opened = 0;
    final result = await opening(QueueLock(names: names), open: (n) {
      opened++;
      return plainOpen(n);
    }).run();

    expect(result.trouble, QueueTrouble.busy);
    expect(result.box, isNull);
    expect(opened, 0, reason: '⛔ অন্যজন ধরে থাকতেও বাক্স খোলার চেষ্টা হলো');

    final again = await Hive.openBox<Map>(box);
    expect(again.values.single['payloadJson'], '{"order":"O-1"}', reason: '⛔ না-পাঠানো অর্ডার হারাল');
    owner.close();
  });

  test('a lock left by an owner that is gone is taken over', () async {
    final names = FakePortNames();
    final dead = ReceivePort();
    names.register(dead.sendPort, QueueLock.defaultName);
    dead.close(); // ⓘ মালিকের isolate নেই — নামটা রয়ে গেছে, উত্তর আসে না

    final lock = QueueLock(names: names);
    expect(await lock.acquire(ping: const Duration(milliseconds: 100)), isTrue, reason: '⛔ মৃত মালিকের নামে সিঙ্ক চিরকাল আটকে থাকত');
    expect(lock.held, isTrue);
  });

  test('a box that really is broken is kept aside byte for byte and a fresh one opens — never deleted', () async {
    final file = File('${dir.path}${Platform.pathSeparator}$box.hive');
    final bytes = List<int>.generate(64, (i) => (i * 7) % 256);
    await file.writeAsBytes(bytes);

    var calls = 0;
    final result = await opening(QueueLock(names: FakePortNames()), open: (n) {
      calls++;
      if (calls == 1) throw const FormatException('corrupt queue');
      return plainOpen(n);
    }).run();

    expect(result.trouble, QueueTrouble.quarantined);
    expect(result.box, isNotNull, reason: 'নতুন বাক্সে সিঙ্ক চলার কথা');
    expect(result.keptAt, isNotNull);
    expect(await File(result.keptAt!).readAsBytes(), bytes, reason: '⛔ ভাঙা ফাইলটা রাখা হয়নি, বা বদলে গেছে — উদ্ধারের পথ নেই');
  });

  test('when the box cannot even be kept aside, nothing on disk changes', () async {
    final result = await QueueOpening(
      boxName: box,
      lock: QueueLock(names: FakePortNames()),
      directory: () async => null,
      open: (_) => throw const FormatException('corrupt queue'),
    ).run();

    expect(result.trouble, QueueTrouble.broken);
    expect(result.box, isNull);
  });

  test('a released lock lets the next side in, and a held one keeps it out', () async {
    final names = FakePortNames();
    final app = QueueLock(names: names);
    final background = QueueLock(names: names);

    expect(await app.acquire(), isTrue);
    // ⓘ ২০০ms-এর ping আর নয় — পুরো দৌড়ের চাপে একবার "দুজনেই পেলেন" হয়েছিল (ec, ২০ অক্টোবর ২০২৬); এখন ডিফল্ট ping আর পরপর দুইবার,
    //    আর জীবিত মালিক উত্তর দেন নিয়ন্ত্রণ-পোর্টে (নিচের "ব্যস্ত মালিক" দাবি)
    expect(await background.acquire(), isFalse, reason: '⛔ দুজনেই বাক্স পেলেন');

    app.release();
    expect(await background.acquire(), isTrue);
  });

  test('every message to the person says nothing was deleted, or how to recover', () {
    for (final trouble in QueueTrouble.values) {
      final text = queueTroubleText(trouble);
      expect(text, isNotEmpty);
      expect(text.contains('মোছা হয়নি') || text.contains('ফোনেই রাখা আছে'), isTrue, reason: '${trouble.name}: "$text"');
    }
  });

  // ── ২০ অক্টোবর ২০২৬: ব্যস্ত মালিক মৃত নন ([[QueueLock._alive]]) ─────────────────────────────────────────────────────────────

  test('a live owner that is busy, not dead, keeps the lock — the other side asks the isolate itself, not its event loop', () async {
    // ⓘ সত্যিকারের আরেকটা isolate তালা নেয়, তারপর ৬ সেকেন্ড event loop বন্ধ রেখে ঘোরে — পুরনো "আছেন?" তখন কখনো উত্তর পেত না
    final name = 'abos_test_busy_owner_${DateTime.now().microsecondsSinceEpoch}';
    final ready = ReceivePort();
    final owner = await Isolate.spawn(_holdThenSpin, <Object>[ready.sendPort, name]);
    try {
      expect(await ready.first.timeout(const Duration(seconds: 15)), isTrue, reason: 'প্রস্তুতিটাই ভুল — অন্য isolate তালা পায়নি');

      final other = QueueLock(name: name);
      final got = await other.acquire(ping: const Duration(milliseconds: 300), misses: 2);

      expect(got, isFalse, reason: '⛔ ব্যস্ত (জীবিত) মালিককে মৃত ধরে তালা কেড়ে নেওয়া হল — একই বাক্সে দুই লেখক');
      expect(other.held, isFalse);
    } finally {
      owner.kill(priority: Isolate.immediate);
      ready.close();
      const IsolatePortNames()
        ..remove(name)
        ..remove('$name.isolate');
    }
  });

  test('an owner whose lock was taken knows it before writing, and its release leaves the new owner alone', () async {
    final names = FakePortNames();
    final first = QueueLock(names: names);
    expect(await first.acquire(), isTrue);
    expect(first.stillMine, isTrue);

    // ⓘ কেউ কেড়ে নিল — নামের খাতায় এখন অন্যের পোর্ট
    final taker = ReceivePort();
    names.remove(QueueLock.defaultName);
    names.register(taker.sendPort, QueueLock.defaultName);

    expect(first.held, isTrue, reason: 'প্রস্তুতিটাই ভুল');
    expect(first.stillMine, isFalse, reason: '⛔ তালা হারিয়েও মালিক ভাবেন তিনিই — লেখা চলত, দুই লেখক');

    first.release();
    expect(names.lookup(QueueLock.defaultName), taker.sendPort, reason: '⛔ পুরনো মালিক ছাড়তে গিয়ে নতুন মালিকের নাম মুছে দিলেন');
    taker.close();
  });
}

/// অন্য isolate-এ: তালা নেওয়া, "পেয়েছি" জানানো, তারপর ৬ সেকেন্ড event loop না ছেড়ে ঘোরা (ব্যস্ত, কিন্তু জীবিত)
Future<void> _holdThenSpin(List<Object> args) async {
  final ready = args[0] as SendPort;
  final lock = QueueLock(name: args[1] as String);
  ready.send(await lock.acquire());
  final until = DateTime.now().add(const Duration(seconds: 6));
  var spins = 0;
  while (DateTime.now().isBefore(until)) {
    spins++;
  }
  lock.release();
  if (spins < 0) ready.send(false);
}

/// [IsolateNameServer]-এর একই নিয়ম — একটা নাম একবারই বসে — একটা isolate-এর ভিতরে
class FakePortNames implements PortNames {
  final Map<String, SendPort> _ports = {};

  @override
  bool register(SendPort port, String name) {
    if (_ports.containsKey(name)) return false;
    _ports[name] = port;
    return true;
  }

  @override
  SendPort? lookup(String name) => _ports[name];

  @override
  bool remove(String name) => _ports.remove(name) != null;
}
