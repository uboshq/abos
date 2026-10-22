import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/api_client/api_client.dart';
import 'package:abos_mobile/core/launcher_widgets/hub/widget_hub.dart';
import 'package:abos_mobile/core/launcher_widgets/hub/widget_summary_api.dart';
import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/locale/language_switch.dart';
import 'package:abos_mobile/core/locale/locale_choice.dart';
import 'package:abos_mobile/core/privacy/phone_privacy.dart';

import '../support/fake_secure_storage.dart';

/// ⭐ সার্ভারও মানুষের ভাষা জানে (0.4.43): প্রতিটা ডাকে `Accept-Language`, বাছাই বদলালে `PATCH /me/locale` (পুরনো সার্ভারের ৪০৪
/// চুপচাপ ঠিক), আর হোম-স্ক্রিনের উইজেট `widget_locale` পায় — পেছনের isolate-এও ভাণ্ডার থেকে পড়ে।
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late _Server server;
  late HttpClientAdapter real;

  setUp(() {
    FakeSecureStorage.install();
    real = ApiClient.dio.httpClientAdapter;
    server = _Server();
    ApiClient.dio.httpClientAdapter = server;
    AppLocale.current = AppLocale.locale;
  });
  tearDown(() {
    ApiClient.dio.httpClientAdapter = real;
    AppLocale.current = AppLocale.locale;
    LanguageSwitch.debugShown = null;
    LocaleChoices.instance = LocaleChoices();
  });

  test('every request says the language: bn by default, en once English is the app language', () async {
    await ApiClient.dio.get<void>('/me');
    expect(server.asked.single.language, 'bn');

    AppLocale.current = AppLocale.english;
    await ApiClient.dio.post<void>('/sync/push', data: const {});
    await ApiClient.dio.get<void>('/widgets/summary');
    expect(server.asked.skip(1).map((a) => a.language), ['en', 'en'], reason: '⛔ ভাষা বদলের পরেও সার্ভার বাংলা শুনছে');
  });

  test('the choice is stored on the server: PATCH /me/locale {locale}', () async {
    await saveLocale('en');
    expect(server.asked.single.method, 'PATCH');
    expect(server.asked.single.path, '/me/locale');
    expect(server.asked.single.body, {'locale': 'en'});
  });

  test('an old server without the door (404, 405) is fine and nothing is thrown', () async {
    server.status = 404;
    await saveLocale('bn');
    server.status = 405;
    await saveLocale('bn');
    server.status = 500;
    await saveLocale('bn');
    expect(server.asked, hasLength(3));
  });

  group('home-screen widgets', () {
    final saved = <String, String?>{};
    setUp(() {
      WidgetHub.resetForTest();
      saved.clear();
      WidgetHub.save = (id, value) async => saved[id] = value;
      WidgetHub.setEnabled = (key, on) async {};
      WidgetHub.redraw = (key) async {};
      WidgetHub.refreshAll = () async {};
      WidgetHub.kinds = () => const [];
      WidgetSummaryApi.transport = (etag) async =>
          const WidgetSummaryReply(200, etag: '"e1"', body: {'asOf': '2026-10-20T08:30:00Z', 'widgets': {}});
      PhonePrivacy.amountsReader = () async => true;
    });
    tearDown(WidgetHub.resetForTest);

    test("they carry the person's language, read from storage even in the background", () async {
      LanguageSwitch.debugShown = true;
      LocaleChoices.instance = LocaleChoices(read: () async => 'en', write: (_) async {});
      await WidgetHub.refresh();
      expect(saved['widget_locale'], 'en');
      expect(AppLocale.current, AppLocale.english, reason: 'নথির লেখা আর সারাংশের ডাক এই ভাষায়');
    });

    test('while the switch is hidden they read Bangla', () async {
      // ⓘ ০.৪.৪৫ থেকে ready চালু — লুকানো পথ জোর করে
      LanguageSwitch.debugShown = false;
      LocaleChoices.instance = LocaleChoices(read: () async => 'en', write: (_) async {});
      await WidgetHub.refresh();
      expect(saved['widget_locale'], 'bn');
    });
  });
}

class _Asked {
  _Asked(this.method, this.path, this.language, this.body);
  final String method;
  final String path;
  final String? language;
  final Object? body;
}

class _Server implements HttpClientAdapter {
  final List<_Asked> asked = [];
  int status = 200;

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? stream, Future<void>? cancelFuture) async {
    asked.add(_Asked(options.method.toUpperCase(), options.path, options.headers['Accept-Language'] as String?, options.data));
    return ResponseBody.fromString(jsonEncode(const {}), status, headers: {
      Headers.contentTypeHeader: [Headers.jsonContentType],
    });
  }

  @override
  void close({bool force = false}) {}
}
