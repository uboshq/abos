import 'dart:ui' show PlatformDispatcher;

import 'package:dio/dio.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../api_client/api_client.dart';
import 'app_locale.dart';
import 'language_switch.dart';

/// ⭐ অ্যাপের ভাষা — মানুষের নিজের বাছাই (0.4.43): "ফোনের মতো" (শুরুতে), "বাংলা", "English"।
///
/// <p>[ThemeChoices]-এর হুবহু ছাঁচ: বাছাই ফোনের নিরাপদ ভাণ্ডারে, লগআউটে মোছে না; চাপ দিলেই [current] বদলায়, আর
/// `MaterialApp.locale` ([appLocaleProvider]) সেটা শোনে — অ্যাপ আবার খুলতে হয় না। প্রতিটা বদলে [AppLocale.current] বসে,
/// তাই ApiClient, সময়ের লেখা আর উইজেটও সাথে সাথে মানে।
///
/// <p>⛔ সুইচ বন্ধ ([LanguageSwitch.shown] false) থাকলে বাছাই যা-ই থাকুক অ্যাপ বাংলায় ([effective]) — ইংরেজি ফোনেও আজকের মতো।
enum LocaleChoice { phone, bn, en }

class LocaleChoices {
  LocaleChoices({
    Future<String?> Function()? read,
    Future<void> Function(String value)? write,
    Locale Function()? device,
  })  : _read = read ?? (() => _store.read(key: _key)),
        _write = write ?? ((value) => _store.write(key: _key, value: value)),
        _device = device ?? (() => PlatformDispatcher.instance.locale);

  static LocaleChoices instance = LocaleChoices();

  static const String _key = 'abos_locale_choice';

  static const FlutterSecureStorage _store = FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
  );

  final Future<String?> Function() _read;
  final Future<void> Function(String value) _write;
  final Locale Function() _device;

  /// বাছাই বদলালে আর কে জানবে — হোম-স্ক্রিনের উইজেট ([WidgetHub]) আর সার্ভার ([saveLocale])
  static Future<void> Function(LocaleChoice choice)? onChanged;

  /// ⭐ ভাষা বদল সার্ভারে লেখা শেষ — প্রতিবার এক বাড়ে ([saveLocale]-এর পরে)। মেনুর নাম আসে `/me` থেকে, আর সার্ভার
  /// সেগুলো মানুষের রাখা ভাষায় বলে; তাই হোম এটা শুনে মেনু আবার আনে, লেখার আগে নয় — নইলে নতুন ভাষার পর্দায় পুরনো
  /// ভাষার মেনু থেকে যেত (a4, ২১ অক্টোবর ২০২৬)
  static final ValueNotifier<int> savedOnServer = ValueNotifier(0);

  /// এখনকার বাছাই — [appLocaleProvider] এটা শোনে
  final ValueNotifier<LocaleChoice> current = ValueNotifier(LocaleChoice.phone);

  /// ⭐ যে ভাষায় অ্যাপ আসলে চলে — বাছাই, "ফোনের মতো" হলে ফোনের ভাষা; ⛔ সুইচ বন্ধ থাকলে সবসময় বাংলা
  Locale get effective => LanguageSwitch.shown ? resolve(current.value, _device()) : AppLocale.locale;

  /// "ফোনের মতো": ফোন বাংলায় হলে বাংলা, অন্য যেকোনো ভাষায় ইংরেজি
  static Locale resolve(LocaleChoice choice, Locale device) => switch (choice) {
        LocaleChoice.bn => AppLocale.locale,
        LocaleChoice.en => AppLocale.english,
        LocaleChoice.phone => device.languageCode == 'bn' ? AppLocale.locale : AppLocale.english,
      };

  /// [AppLocale.current]-কে এখনকার সাথে মেলানো — বাছাই, পড়া বা ফোনের ভাষা বদলের পরে
  void sync() => AppLocale.current = effective;

  /// শুরুতে একবার, প্রথম ফ্রেমের আগে (কেবল ফোনের ভাণ্ডার পড়া) — পড়া না গেলে "ফোনের মতো", কখনো ছোড়ে না
  Future<void> load() async {
    try {
      current.value = parse(await _read());
    } catch (_) {
      current.value = LocaleChoice.phone;
    }
    sync();
  }

  /// মানুষের বাছাই — সাথে সাথে খাটে, তারপর রাখা হয়, তারপর উইজেট আর সার্ভার
  Future<void> choose(LocaleChoice choice) async {
    current.value = choice;
    sync();
    try {
      await _write(choice.name);
    } catch (_) {
      // ⓘ লেখা গেল না — এই চালুতে বাছাই খাটে, পরের চালুতে "ফোনের মতো"; ভাঙার কিছু নয়
    }
    try {
      await onChanged?.call(choice);
    } catch (_) {
      // ⓘ উইজেট আর সার্ভার সৌজন্য — অ্যাপের বাছাই থামে না
    }
  }

  static LocaleChoice parse(String? stored) =>
      LocaleChoice.values.where((c) => c.name == stored).firstOrNull ?? LocaleChoice.phone;
}

/// ⭐ `MaterialApp.locale` — [LocaleChoices.instance]-এর বাছাই আর ফোনের ভাষা বদল শোনে; বদলালে পুরো অ্যাপ নতুন ভাষায়
final appLocaleProvider = NotifierProvider<AppLocaleNotifier, Locale>(AppLocaleNotifier.new);

class AppLocaleNotifier extends Notifier<Locale> with WidgetsBindingObserver {
  @override
  Locale build() {
    final choices = LocaleChoices.instance;
    void follow() {
      choices.sync();
      state = AppLocale.current;
    }

    choices.current.addListener(follow);
    WidgetsBinding.instance.addObserver(this);
    ref.onDispose(() {
      choices.current.removeListener(follow);
      WidgetsBinding.instance.removeObserver(this);
    });
    choices.sync();
    return AppLocale.current;
  }

  /// "ফোনের মতো" বাছা থাকলে ফোনের ভাষা বদলালে অ্যাপও
  @override
  void didChangeLocales(List<Locale>? locales) {
    final choices = LocaleChoices.instance;
    choices.sync();
    state = AppLocale.current;
  }
}

/// ⭐ মানুষের ভাষা সার্ভারে — `PATCH /me/locale {locale: "bn"|"en"}`; সার্ভার `users.locale`-এ রাখে, তাই ওয়েব, ইমেইল আর
/// বার্তাও একই ভাষায়। ⓘ পুরনো সার্ভারে দরজা নেই (৪০৪/৪০৫) — চুপচাপ ঠিক আছে; প্রতিটা ডাকে `Accept-Language` তো যায়ই।
/// অন্য ভুল (নেট নেই) ছোড়ে না — পরের বাছাইয়ে আবার।
Future<void> saveLocale(String locale, {Dio? dio}) async {
  try {
    await (dio ?? ApiClient.dio).patch<void>('/me/locale', data: {'locale': locale});
  } on DioException catch (e) {
    final status = e.response?.statusCode;
    if (status == 404 || status == 405) return;
    debugPrint('ABOS locale: not saved on the server ($status)');
  }
}
