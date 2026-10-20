import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/material.dart';

/// ⭐ সিঙ্কের সৎ অবস্থা — শেষ সফল সিঙ্ক কখন, এখন চলছে কি না, শেষটা ব্যর্থ কি না, পুরনো কি না, নেট আছে কি না
/// (মালিক ১০ অক্টোবর "ক", রিয়েল-টাইম সিঙ্ক; a4-এর পরিকল্পনা সংস্করণ ২ §৪.১ ধাপ ৫; Core App Quality: সৎ অবস্থা দেখানো)।
///
/// <p>ⓘ পর্দা নয়, অবস্থা — নামগুলো a4-এর সাথে ঠিক করা (`lastOkAt`, `running`, `failed`, `stale`, `offline`): মাথার
/// "শেষ সিঙ্ক" ([LastSyncLabel]-কে a4 বসায় আর রং দেয়) আর অফলাইনের পট্টি (a4-এর `OfflineBanner`) এটাই পড়ে।
class SyncStatus extends ChangeNotifier {
  SyncStatus({DateTime Function()? clock}) : _clock = clock ?? DateTime.now;

  static final SyncStatus instance = SyncStatus();

  /// এর বেশি পুরনো হলে [stale] — হলুদ, "টেনে নতুন করুন"
  static const Duration staleAfter = Duration(minutes: 15);

  final DateTime Function() _clock;

  DateTime? _lastOkAt;
  bool _running = false;
  bool _failed = false;
  bool _offline = false;
  StreamSubscription<List<ConnectivityResult>>? _net;

  /// শেষ সফল সিঙ্ক — null মানে এই চালুতে এখনো হয়নি
  DateTime? get lastOkAt => _lastOkAt;
  bool get running => _running;

  /// শেষ গোলটা ব্যর্থ
  bool get failed => _failed;

  /// শেষ সফল সিঙ্ক [staleAfter]-এর বেশি পুরনো, বা এখনো হয়নি
  bool get stale {
    final last = _lastOkAt;
    return last == null || _clock().difference(last) > staleAfter;
  }

  /// ফোনে নেট নেই (ফোনের নিজের কথা — সার্ভারে পৌঁছানোর নিশ্চয়তা নয়; সেটা [failed])
  bool get offline => _offline;

  /// নেটের খবর শোনা — অ্যাপ শুরুতে একবার
  void watchNetwork({Stream<List<ConnectivityResult>>? changes}) {
    if (_net != null) return;
    try {
      _net = (changes ?? Connectivity().onConnectivityChanged).listen((results) {
        final now = results.isEmpty || results.every((r) => r == ConnectivityResult.none);
        if (now != _offline) {
          _offline = now;
          notifyListeners();
        }
      });
    } catch (_) {
      // ⓘ কিছু ফোনে নেটের খবর আসে না — তখন "অফলাইন" দেখানো হয় না, [failed]-ই বলে
    }
  }

  /// একটা গোল ঘিরে — শুরু, তারপর সফল বা ব্যর্থ
  Future<T> track<T>(Future<T> Function() round) async {
    _running = true;
    notifyListeners();
    try {
      final result = await round();
      _lastOkAt = _clock();
      _failed = false;
      return result;
    } catch (_) {
      _failed = true;
      rethrow;
    } finally {
      _running = false;
      notifyListeners();
    }
  }

  @visibleForTesting
  void reset() {
    _lastOkAt = null;
    _running = false;
    _failed = false;
    _offline = false;
    _net?.cancel();
    _net = null;
  }
}

/// ⭐ "শেষ সিঙ্ক: ২ মিনিট আগে" — সাদামাটা লেখা, থিমের রং পড়ে নেয়; কোথায় আর কেমন দেখাবে সেটা a4-এর মাথার নকশা ঠিক করে।
class LastSyncLabel extends StatelessWidget {
  const LastSyncLabel({super.key, this.status, this.now});

  /// Injected in tests — the app's one is [SyncStatus.instance].
  final SyncStatus? status;
  final DateTime Function()? now;

  @override
  Widget build(BuildContext context) {
    final s = status ?? SyncStatus.instance;
    return ListenableBuilder(
      listenable: s,
      builder: (context, _) => Text(
        words(s, (now ?? DateTime.now)()),
        key: const ValueKey('last-sync-label'),
        style: Theme.of(context).textTheme.bodySmall,
      ),
    );
  }

  /// লেখাটা — পরীক্ষা আর মাথার নকশা দুটোই পড়ে
  static String words(SyncStatus s, DateTime now) {
    if (s.running) return 'সিঙ্ক হচ্ছে…';
    if (s.offline) return 'নেট নেই — কাজ জমা থাকছে';
    final last = s.lastOkAt;
    if (last == null) return s.failed ? 'সিঙ্ক হয়নি — টেনে আবার চেষ্টা করুন' : 'এখনো সিঙ্ক হয়নি';
    final ago = now.difference(last);
    final when = ago.inSeconds < 60
        ? 'এইমাত্র'
        : ago.inMinutes < 60
            ? '${_bn(ago.inMinutes)} মিনিট আগে'
            : ago.inHours < 24
                ? '${_bn(ago.inHours)} ঘণ্টা আগে'
                : '${_bn(ago.inDays)} দিন আগে';
    return 'শেষ সিঙ্ক: $when${s.failed ? ' · শেষবার ব্যর্থ' : ''}';
  }

  static String _bn(int n) => n.toString().split('').map((d) => '০১২৩৪৫৬৭৮৯'[int.parse(d)]).join();
}
