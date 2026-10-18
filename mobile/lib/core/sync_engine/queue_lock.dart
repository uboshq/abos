import 'dart:async';
import 'dart:isolate';
import 'dart:ui' show IsolateNameServer;

/// ⛔ অফলাইন সারির বাক্স একসাথে একজনই খোলে — খোলা অ্যাপ, নাকি পেছনের সিঙ্ক (মোবাইল অ্যাপ নিরীক্ষা, fe, ১৮ অক্টোবর ২০২৬;
/// [[QueueOpening]])।
///
/// <p>⚠️ পেছনের সিঙ্ক (workmanager) নিজের isolate-এ চলে, আর একই সময়ে খোলা অ্যাপও একই Hive বাক্স খুলত। Hive ফাইলে তালা দেয়, তাই
/// দ্বিতীয়জন খুলতে পারত না — আর অ্যাপ সেটাকে "ভাঙা বাক্স" ধরে মুছে নতুন বানাত: না পাঠানো অর্ডার চুপচাপ উধাও।
///
/// <p>ⓘ কেন নামের পোর্ট, ফাইল-তালা নয়: Dart-এর ফাইল-তালা প্রক্রিয়ার (POSIX `fcntl`), isolate-এর নয় — একই প্রক্রিয়ার দুই isolate
/// দুজনেই "পেলাম" শুনত। [IsolateNameServer] প্রক্রিয়া জুড়ে একটাই, আর একটা নাম একবারই বসে — সেটাই তালা। মালিক মারা গেলে নামটা
/// থেকে যেতে পারে, তাই অন্যজন আগে "আছেন?" জিজ্ঞেস করেন ([_alive]); উত্তর না এলে নামটা পুরনো, সরিয়ে নিজে নেন।
class QueueLock {
  QueueLock({PortNames? names, this.name = defaultName}) : _names = names ?? const IsolatePortNames();

  static const String defaultName = 'abos_sync_queue_owner';

  final PortNames _names;
  final String name;
  ReceivePort? _port;

  /// এই isolate এখন তালার মালিক কি না
  bool get held => _port != null;

  /// তালা নেওয়া — [wait] পর্যন্ত চেষ্টা; জীবিত মালিক থাকলে আর সময় পেরোলে `false` (কিছুই খোলা হয় না, কিছুই মোছা হয় না)।
  Future<bool> acquire({
    Duration wait = Duration.zero,
    Duration ping = const Duration(milliseconds: 800),
    Duration pause = const Duration(milliseconds: 250),
  }) async {
    if (held) return true;
    final deadline = DateTime.now().add(wait);

    // ⓘ সীমা — নাম বসানো আর খোঁজার মাঝে অন্যজন ঢুকে গেলে আবার; চিরকাল ঘোরা নয়
    for (var round = 0; round < 1000; round++) {
      final port = ReceivePort();
      bool registered;
      try {
        registered = _names.register(port.sendPort, name);
      } catch (_) {
        // ⓘ নামের খাতা এই প্ল্যাটফর্মে নেই — মেলানোর উপায় নেই, তাই আগের মতো খোলা (তবু কখনো মোছা নয়, [[QueueOpening]])
        registered = true;
      }
      if (registered) {
        _port = port;
        // ⓘ "আছেন?" — উত্তর পাঠানোর পোর্ট আসে, "আছি" যায়
        port.listen((message) {
          if (message is SendPort) message.send(true);
        });
        return true;
      }
      port.close();

      final holder = _names.lookup(name);
      if (holder != null && !await _alive(holder, ping)) {
        // ⓘ মালিকের isolate নেই — নামটা পুরনো; সরিয়ে আবার চেষ্টা
        _names.remove(name);
        continue;
      }

      if (!DateTime.now().isBefore(deadline)) return false;
      await Future<void>.delayed(pause);
    }

    return false;
  }

  /// তালা ছাড়া — কেবল নিজের হলে
  void release() {
    final port = _port;
    if (port == null) return;
    _names.remove(name);
    port.close();
    _port = null;
  }

  static Future<bool> _alive(SendPort holder, Duration ping) async {
    final reply = ReceivePort();
    try {
      holder.send(reply.sendPort);
      await reply.first.timeout(ping);
      return true;
    } on TimeoutException {
      return false;
    } finally {
      reply.close();
    }
  }
}

/// নামের পোর্টের খাতা — আসলে [IsolateNameServer]; পরীক্ষায় অন্য isolate সাজানোর জন্য বদলানো যায়।
abstract class PortNames {
  bool register(SendPort port, String name);
  SendPort? lookup(String name);
  bool remove(String name);
}

class IsolatePortNames implements PortNames {
  const IsolatePortNames();

  @override
  bool register(SendPort port, String name) => IsolateNameServer.registerPortWithName(port, name);

  @override
  SendPort? lookup(String name) => IsolateNameServer.lookupPortByName(name);

  @override
  bool remove(String name) => IsolateNameServer.removePortNameMapping(name);
}
