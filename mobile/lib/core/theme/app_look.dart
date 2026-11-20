import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'glass_wallpaper.dart';

/// ⭐ অ্যাপের রূপ — মালিক, ২০ নভেম্বর ২০২৬ ("ok", নমুনা BshQzFsr আর BvqYK2tZ; তাঁর NEXUS নমুনা ২-এর "খ"):
///
/// * [abos] — আগের চেহারা ("ক্লাসিক"), একটুও বদলায় না।
/// * [glass] — iOS 26 Liquid Glass: পেছনে ধীরে নড়া রঙের ছোপ, সব অংশ স্বচ্ছ কাচ, ভাসমান কাচের ট্যাব-ক্যাপসুল আর খোঁজার কাচের বল।
/// * [expressive] — Material 3 Expressive: ভাসমান গাঢ় টুলবার আর বড় চারকোনা ＋, আলাদা আলাদা আকারের ঘর, ঢেউয়ের লক্ষ্য-রেখা,
///   জোড়া বোতামের দল, মোটা-চওড়া অঙ্ক।
///
/// <p>⭐ পর্দার নাম (মালিক, ২০ নভেম্বর ২০২৬ "ok"): [expressive] = "ম্যাটেরিয়াল", [glass] = "লিকুইড গ্লাস", [abos] = "ক্লাসিক" —
/// এই ক্রমে; "ABOS" আর রূপের নাম নয়। ⛔ ভেতরের নাম ([AppLook.name], ফোনে রাখা মান) বদলায় না, যাতে আগের বাছাই পড়া যায়।
/// ⭐ শুরুর রূপ ম্যাটেরিয়াল ([defaultLook]), আর ০.৪.৬১-এর প্রথম চালুতে একবার সবাই ম্যাটেরিয়ালে ([defaultAppliedKey]) —
/// ওয়েবের একই নিয়ম (নতুন রূপ সবার জন্য), তারপর যার যা বাছাই তা-ই থাকে।
enum AppLook { abos, glass, expressive }

/// রূপ, কাচের ঝাপসা আর পেছনের রঙের নড়াচড়া — মানুষের নিজের পছন্দ, ফোনে থাকে, লগআউটেও মোছে না ([ThemeChoices]-এর একই নিয়ম)।
class AppLooks {
  AppLooks({Future<String?> Function(String key)? read, Future<void> Function(String key, String value)? write})
      : _read = read ?? ((key) => _store.read(key: key)),
        _write = write ?? ((key, value) => _store.write(key: key, value: value));

  static AppLooks instance = AppLooks();

  static const String lookKey = 'abos_app_look';
  static const String blurKey = 'abos_glass_blur';
  static const String motionKey = 'abos_glass_motion';
  static const String wallpaperKey = 'abos_glass_wallpaper';

  /// ০.৪.৬১-এর একবারের বদল হয়ে গেছে — এটা থাকলে আর কখনো নয়
  static const String defaultAppliedKey = 'look_default_v0461_applied';

  /// ⭐ শুরুর রূপ — ম্যাটেরিয়াল (মালিক, ২০ নভেম্বর ২০২৬ "ok")
  static const AppLook defaultLook = AppLook.expressive;

  /// ⭐ কাচের ঝাপসার শুরু — "মাঝারি" (মালিকের নমুনার পাঁচ ধাপের তৃতীয়)
  static const int defaultBlur = 40;

  /// এক চাপের পাঁচ ধাপ: স্বচ্ছ · হালকা · মাঝারি · ঘন · ঘষা কাচ
  static const List<int> presets = [0, 20, 40, 70, 100];

  static const FlutterSecureStorage _store = FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
  );

  final Future<String?> Function(String key) _read;
  final Future<void> Function(String key, String value) _write;

  final ValueNotifier<AppLook> look = ValueNotifier(AppLook.abos);

  /// 0 = আয়নার মতো স্বচ্ছ কাচ, 100 = ঘষা কাচ
  final ValueNotifier<int> blur = ValueNotifier(defaultBlur);

  /// পেছনের রঙের ছোপ ধীরে নড়বে কি না (ফোনের "কম নড়াচড়া" চালু থাকলে এমনিতেই থামে)
  final ValueNotifier<bool> motion = ValueNotifier(true);

  /// ⭐ Liquid Glass-এর পটভূমি (মালিক, ২০ নভেম্বর ২০২৬) — শুরুতে আগের রঙিন বল
  final ValueNotifier<GlassWallpaper> wallpaper = ValueNotifier(GlassWallpaper.balls);

  /// শুরুতে একবার, প্রথম ফ্রেমের আগে — পড়া না গেলে শুরুর মান, কখনো ছোড়ে না।
  /// ⭐ ০.৪.৬১-এর প্রথম চালুতে একবার সবাই ম্যাটেরিয়ালে; চিহ্ন লেখা হয়, পরের চালু থেকে নিজের বাছাই থাকে।
  /// ⓘ [load]-এর আগের মান ([look]-এর শুরু) আগের মতো [AppLook.abos] — অ্যাপ প্রথম ফ্রেমের আগেই [load] ডাকে (main.dart);
  /// কেবল একা পর্দার টেস্ট [load] ছাড়া চলে, সেগুলোর ছবি তাই বদলায় না
  Future<void> load() async {
    if (await _quietRead(defaultAppliedKey) == null) {
      await _quietWrite(lookKey, defaultLook.name);
      await _quietWrite(defaultAppliedKey, '1');
      look.value = defaultLook;
    } else {
      look.value = parseLook(await _quietRead(lookKey));
    }
    blur.value = parseBlur(await _quietRead(blurKey));
    motion.value = (await _quietRead(motionKey)) != 'off';
    wallpaper.value = parseWallpaper(await _quietRead(wallpaperKey));
  }

  Future<void> chooseWallpaper(GlassWallpaper value) async {
    wallpaper.value = value;
    await _quietWrite(wallpaperKey, value.name);
  }

  static GlassWallpaper parseWallpaper(String? stored) =>
      GlassWallpaper.values.where((w) => w.name == stored).firstOrNull ?? GlassWallpaper.balls;

  Future<void> chooseLook(AppLook value) async {
    look.value = value;
    await _quietWrite(lookKey, value.name);
  }

  /// দাগ টানার সময় প্রতিটা ধাপে ডাকা হয় — মান সাথে সাথে খাটে, আর ফোনে লেখা হয়
  Future<void> chooseBlur(int value) async {
    blur.value = value.clamp(0, 100);
    await _quietWrite(blurKey, '${blur.value}');
  }

  Future<void> chooseMotion(bool on) async {
    motion.value = on;
    await _quietWrite(motionKey, on ? 'on' : 'off');
  }

  static AppLook parseLook(String? stored) =>
      AppLook.values.where((l) => l.name == stored).firstOrNull ?? defaultLook;

  static int parseBlur(String? stored) => (int.tryParse(stored ?? '') ?? defaultBlur).clamp(0, 100);

  Future<String?> _quietRead(String key) async {
    try {
      return await _read(key);
    } catch (_) {
      return null;
    }
  }

  Future<void> _quietWrite(String key, String value) async {
    try {
      await _write(key, value);
    } catch (_) {
      // ⓘ লেখা গেল না — এই চালুতে বাছাই খাটে, পরের চালুতে শুরুর মান; ভাঙার কিছু নয়
    }
  }
}
