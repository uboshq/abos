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
///
/// <p>⛔ ২০ অক্টোবর ২০২৬ (a4 ধরেছেন: ec-র পুরো flutter দৌড়ে ১০৯৭-এর একবার "দুজনেই বাক্স পেলেন"): "আছেন?"-এর উত্তর আসত মালিকের
/// event loop দিয়ে — মালিক বেঁচে আছেন কিন্তু ব্যস্ত (বড় তালিকা আঁকা, JSON ভাঙা, পেছনে পড়ে থাকা অ্যাপ) হলে উত্তর দেরিতে আসত, আর
/// অন্যজন তাঁকে মৃত ধরে তালা কেড়ে নিত — একই বাক্সে দুই লেখক। ⭐ এখন তিন পাহারা:
///   ১. "আছেন?" যায় মালিকের isolate-এর নিয়ন্ত্রণ-পোর্টে, তাৎক্ষণিক অগ্রাধিকারে ([Isolate.ping], [Isolate.immediate]) — VM নিজে উত্তর
///      দেয়, মালিকের Dart কোড যত ব্যস্তই থাকুক; কেবল isolate সত্যিই না থাকলে উত্তর আসে না;
///   ২. কাড়ার আগে পরপর [misses] বার উত্তর না আসা চাই — একবারের দেরি মৃত্যু নয়;
///   ৩. মালিক প্রতিবার লেখার আগে দেখেন তালা এখনো তাঁর কি না ([stillMine]) — তবু কেউ কেড়ে নিলে তিনি থামেন, দুইজন একসাথে লেখেন না।
class QueueLock {
  QueueLock({PortNames? names, this.name = defaultName}) : _names = names ?? const IsolatePortNames();

  static const String defaultName = 'abos_sync_queue_owner';

  /// মালিকের isolate-এর নিয়ন্ত্রণ-পোর্টের নাম — তাৎক্ষণিক "আছেন?"-এর জন্য
  String get _controlName => '$name.isolate';

  final PortNames _names;
  final String name;
  ReceivePort? _port;

  /// এই isolate নিজেকে তালার মালিক জানে কি না (কেউ কেড়ে নিলে [stillMine] বলে)
  bool get held => _port != null;

  /// ⭐ তালা সত্যিই এখনো এই isolate-এর — নামের খাতায় নিজের পোর্টই বসে আছে কি না। লেখার আগে দেখা ([[SyncEngine]])।
  /// ⓘ নামের খাতা না থাকলে (প্ল্যাটফর্মে নেই) মেলানোর উপায় নেই — তখন নিজের ধারণাই মানা।
  bool get stillMine {
    final port = _port;
    if (port == null) return false;
    try {
      final now = _names.lookup(name);
      return now == null || now == port.sendPort;
    } catch (_) {
      return true;
    }
  }

  /// তালা নেওয়া — [wait] পর্যন্ত চেষ্টা; জীবিত মালিক থাকলে আর সময় পেরোলে `false` (কিছুই খোলা হয় না, কিছুই মোছা হয় না)।
  Future<bool> acquire({
    Duration wait = Duration.zero,
    Duration ping = const Duration(milliseconds: 1500),
    int misses = 2,
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
        // ⓘ "আছেন?" — উত্তর পাঠানোর পোর্ট আসে, "আছি" যায় (পুরনো পথ; নিয়ন্ত্রণ-পোর্ট না পেলে)
        port.listen((message) {
          if (message is SendPort) message.send(true);
        });
        try {
          _names.remove(_controlName);
          _names.register(Isolate.current.controlPort, _controlName);
        } catch (_) {}
        return true;
      }
      port.close();

      final holder = _names.lookup(name);
      if (holder != null && !await _aliveAtAll(holder, ping, misses)) {
        // ⓘ পরপর কয়েকবার, তাৎক্ষণিক পোর্টেও উত্তর নেই — মালিকের isolate সত্যিই নেই; নামটা পুরনো, সরিয়ে আবার চেষ্টা
        _names.remove(name);
        _names.remove(_controlName);
        continue;
      }

      if (!DateTime.now().isBefore(deadline)) return false;
      await Future<void>.delayed(pause);
    }

    return false;
  }

  /// তালা ছাড়া — কেবল নিজের হলে; কেউ কেড়ে নিয়ে থাকলে তাঁর নাম ছোঁয়া হয় না
  void release() {
    final port = _port;
    if (port == null) return;
    if (stillMine) {
      _names.remove(name);
      _names.remove(_controlName);
    }
    port.close();
    _port = null;
  }

  /// ⓘ তালা কেড়ে নেওয়া হয়েছে জানার পর — নিজের পুরনো পোর্ট বন্ধ, অন্যের নাম অক্ষত; পরে আবার [acquire]
  void forget() {
    _port?.close();
    _port = null;
  }

  /// পরপর [misses] বার জিজ্ঞেস — একবারও উত্তর এলে জীবিত
  Future<bool> _aliveAtAll(SendPort holder, Duration ping, int misses) async {
    for (var i = 0; i < (misses < 1 ? 1 : misses); i++) {
      if (await _alive(holder, ping)) return true;
    }
    return false;
  }

  /// ⭐ আগে মালিকের isolate-এর নিয়ন্ত্রণ-পোর্টে তাৎক্ষণিক ping (ব্যস্ত হলেও VM উত্তর দেয়); সেটা না থাকলে পুরনো পোর্টে "আছেন?"
  Future<bool> _alive(SendPort holder, Duration ping) async {
    final reply = ReceivePort();
    try {
      SendPort? control;
      try {
        control = _names.lookup(_controlName);
      } catch (_) {}
      if (control != null) {
        Isolate(control).ping(reply.sendPort, response: true, priority: Isolate.immediate);
      } else {
        holder.send(reply.sendPort);
      }
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
