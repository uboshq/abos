import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// ⭐ আলো বা অন্ধকার — মানুষের নিজের বাছাই (মালিকের আদেশ, ২০ অক্টোবর ২০২৬): "ফোনের মতো" (শুরুতে), "আলো", "অন্ধকার"।
///
/// <p>বাছাই ফোনে থাকে, লগআউটেও মোছে না — এটা মানুষের চোখের পছন্দ, কোম্পানির তথ্য নয়। চাপ দিলেই [current] বদলায়, আর
/// `MaterialApp.themeMode` সেটা শোনে, তাই অ্যাপ আবার খুলতে হয় না। হোম-স্ক্রিনের উইজেটও একই বাছাই মানে ([onChanged]-এ
/// উইজেটের ভাণ্ডারে লেখা হয়)।
enum ThemeChoice {
  phone('ফোনের মতো', ThemeMode.system),
  light('আলো', ThemeMode.light),
  dark('অন্ধকার', ThemeMode.dark);

  const ThemeChoice(this.label, this.mode);

  final String label;
  final ThemeMode mode;
}

class ThemeChoices {
  ThemeChoices({Future<String?> Function()? read, Future<void> Function(String value)? write})
      : _read = read ?? (() => _store.read(key: _key)),
        _write = write ?? ((value) => _store.write(key: _key, value: value));

  static ThemeChoices instance = ThemeChoices();

  static const String _key = 'abos_theme_choice';

  static const FlutterSecureStorage _store = FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
  );

  final Future<String?> Function() _read;
  final Future<void> Function(String value) _write;

  /// বাছাই বদলালে আর কে জানবে — হোম-স্ক্রিনের উইজেট ([WidgetHub])
  static Future<void> Function(ThemeChoice choice)? onChanged;

  /// এখনকার বাছাই — `MaterialApp.themeMode` এটা শোনে
  final ValueNotifier<ThemeChoice> current = ValueNotifier(ThemeChoice.phone);

  /// শুরুতে একবার, প্রথম ফ্রেমের আগে (কেবল ফোনের ভাণ্ডার পড়া) — পড়া না গেলে "ফোনের মতো", কখনো ছোড়ে না
  Future<void> load() async {
    try {
      current.value = parse(await _read());
    } catch (_) {
      current.value = ThemeChoice.phone;
    }
  }

  /// মানুষের বাছাই — সাথে সাথে খাটে, তারপর রাখা হয়
  Future<void> choose(ThemeChoice choice) async {
    current.value = choice;
    try {
      await _write(choice.name);
    } catch (_) {
      // ⓘ লেখা গেল না — এই চালুতে বাছাই খাটে, পরের চালুতে "ফোনের মতো"; ভাঙার কিছু নয়
    }
    try {
      await onChanged?.call(choice);
    } catch (_) {
      // ⓘ উইজেট সৌজন্য — অ্যাপের বাছাই থামে না
    }
  }

  static ThemeChoice parse(String? stored) =>
      ThemeChoice.values.where((c) => c.name == stored).firstOrNull ?? ThemeChoice.phone;
}
