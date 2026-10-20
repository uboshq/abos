import 'dart:async';

import 'package:flutter/widgets.dart';

/// ⭐ শুরু দুই ভাগে — প্রথম ছবির আগে কেবল যা প্রথম পর্দার লাগে, বাকিটা পরে (ec, অ্যাপ এন্টারপ্রাইজ মান, ১৯ অক্টোবর ২০২৬)।
///
/// <p>⛔ আগে main() প্রথম ফ্রেমের আগে অপেক্ষা করত WorkManager-এর পটভূমির কাজ বসানোর (প্ল্যাটফর্মের কয়েকটা ডাক) আর
/// Firebase চালু হওয়ার — দুইটার কোনোটাই প্রথম পর্দা আঁকতে লাগে না, অথচ সস্তা ফোনে Firebase-এর শুরুই সবচেয়ে ভারী। এখন:
///   · আগে ([beforeFirstFrame]): Hive-এর পথ, অফলাইন সারি, রেফারেন্স ক্যাশ, তালার সময় আর সংরক্ষিত সেশন — রাউটারের প্রথম প্রশ্ন "কে ঢুকে
///     আছেন" আর প্রথম পর্দার তালিকা (গ্রাহক, পণ্য) সরাসরি এগুলো পড়ে, তাই এগুলো আগেই লাগে। ⓘ কোনো নেটের ডাক নেই।
///   · পরে ([afterFirstFrame]): পটভূমির সিঙ্ক বসানো, Firebase আর বার্তার টোকেন, হোম-স্ক্রিনের উইজেট ভরা।
///
/// <p>ⓘ ধাপগুলো একটার পর একটা, একসাথে নয়: অফলাইন সারি আর রেফারেন্স ক্যাশ দুইটাই প্রথমবার একই এনক্রিপশন চাবি বানাতে পারে —
/// একসাথে চালালে দুইটা আলাদা চাবি তৈরি হতে পারত ([[HiveEncryptionKey]])।
class StartupSteps {
  const StartupSteps({
    required this.storage,
    required this.queue,
    required this.referenceCache,
    required this.lockDelay,
    required this.restoreSession,
    required this.backgroundSync,
    required this.push,
    required this.signedInExtras,
    required this.afterFrame,
  });

  /// Hive-এর পথ
  final Future<void> Function() storage;

  /// অফলাইন সারি
  final Future<void> Function() queue;

  /// রেফারেন্স ক্যাশ — প্রথম পর্দার তালিকা সরাসরি পড়ে
  final Future<void> Function() referenceCache;

  /// অ্যাপ-তালার সময় — প্রথম পর্দার তালা এটা পড়ে ([[LockDelay]])
  final Future<void> Function() lockDelay;

  /// সংরক্ষিত সেশন — সত্যি হলে কেউ ঢুকে আছেন
  final Future<bool> Function() restoreSession;

  /// পটভূমির সিঙ্ক বসানো (WorkManager)
  final Future<void> Function() backgroundSync;

  /// Firebase আর বার্তা — [signedIn] হলে টোকেন জমাও
  final Future<void> Function(bool signedIn) push;

  /// ঢুকে থাকলে: হোম-স্ক্রিনের উইজেট ইত্যাদি
  final Future<void> Function() signedInExtras;

  /// প্রথম ফ্রেমের ঠিক পরে, সাথে সাথে: আটকে থাকা ফ্রেমের খোঁজ আর জমা ক্র্যাশ পাঠানো ([[CrashReporter]])
  final void Function() afterFrame;
}

class Startup {
  const Startup(this.steps);

  final StartupSteps steps;

  /// প্রথম ছবির আগে — ফেরে কেউ ঢুকে আছেন কি না
  Future<bool> beforeFirstFrame() async {
    await steps.storage();
    await steps.queue();
    await steps.referenceCache();
    await steps.lockDelay();
    return steps.restoreSession();
  }

  /// প্রথম ছবির পরে — ⛔ কিছুই ছোড়ে না, কোনোটা না উঠলে বাকিগুলো তবু চলে
  Future<void> afterFirstFrame({required bool signedIn}) async {
    await _quietly(() async => steps.afterFrame());
    await _quietly(steps.backgroundSync);
    await _quietly(() => steps.push(signedIn));
    if (signedIn) await _quietly(steps.signedInExtras);
  }

  /// পুরো শুরু: আগের ভাগ, তারপর [run] (runApp), তারপর প্রথম ফ্রেম আঁকা হলে পরের ভাগ
  Future<void> boot(Widget Function(bool signedIn) app, {void Function(Widget app)? run}) async {
    final signedIn = await beforeFirstFrame();
    (run ?? runApp)(app(signedIn));
    WidgetsBinding.instance.addPostFrameCallback((_) {
      unawaited(afterFirstFrame(signedIn: signedIn));
    });
  }

  static Future<void> _quietly(Future<void> Function() step) async {
    try {
      await step();
    } catch (e) {
      debugPrint('ABOS startup: a deferred step failed ($e)');
    }
  }
}
