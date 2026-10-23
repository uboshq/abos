import 'dart:convert';
import 'dart:typed_data';

import 'package:abos_mobile/core/api_client/api_client.dart';
import 'package:abos_mobile/core/auth/token_storage.dart';
import 'package:abos_mobile/core/books/collection_entry.dart';
import 'package:abos_mobile/core/printing/documents_api.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';

/// ⭐ দুই রকম ৪০৪ আলাদা (fe, ২২ অক্টোবর ২০২৬) — আসল [ServerCollectionEntryApi], কেবল সার্ভারটা নকল (Dio-র অ্যাডাপ্টার)।
///
/// <p>নতুন সার্ভার (a6 d5cc9657f) নাগালের বাইরের গ্রাহকে ইচ্ছে করে ৪০৪ দেয় — গ্রাহক আছে কি না ফাঁস হয় না; তখন গ্রাহক ছাড়া খোলা
/// বিল জিজ্ঞেস করলে ৪২২ ("গ্রাহক লাগবে")। পুরনো সার্ভারে দরজাই নেই — গ্রাহক ছাড়াও ৪০৪, বা ৪০৫।
void main() {
  late HttpClientAdapter realAdapter;
  late _Server server;
  late HiveTestHarness harness;

  setUpAll(() async {
    FakeSecureStorage.install();
    harness = await HiveTestHarness.setUp();
    realAdapter = ApiClient.dio.httpClientAdapter;
  });

  tearDownAll(() async {
    ApiClient.dio.httpClientAdapter = realAdapter;
    await harness.tearDown();
  });

  setUp(() {
    server = _Server();
    ApiClient.dio.httpClientAdapter = server;
  });

  final draft = CollectionDraft(customerId: 'cus-far', amount: '100', date: DateTime(2026, 10, 22), accountId: 'acc-1');

  group('18 · a customer out of reach on the new server', () {
    test('open-bills 404 with a customer, 422 without one: CustomerOutOfReach, not an old server', () async {
      server.withCustomer = 404;
      server.withoutCustomer = 422;
      await expectLater(const ServerCollectionEntryApi().openBills('cus-far'), throwsA(isA<CustomerOutOfReach>()));
      expect(server.paths, ['/sales/collections/open-bills?customer=cus-far', '/sales/collections/open-bills']);
    });

    test('preview 404 on the new server: CustomerOutOfReach too', () async {
      server.preview = 404;
      server.withoutCustomer = 422;
      await expectLater(const ServerCollectionEntryApi().preview(draft), throwsA(isA<CustomerOutOfReach>()));
    });
  });

  group('a6 8006bd78e: preview 422 for the customer, 403 on every door', () {
    test('18 · preview 422 with the customer\'s error ("গ্রাহক চেনা নেই"): CustomerOutOfReach', () async {
      server.preview = 422;
      server.previewErrors = {'customer_id': ['গ্রাহক চেনা নেই']};
      await expectLater(const ServerCollectionEntryApi().preview(draft), throwsA(isA<CustomerOutOfReach>()));
    });

    test('a 422 about something else is not read as the customer (the screen shows the server\'s own text)', () async {
      server.preview = 422;
      server.previewErrors = {'lines': ['ক্রেডিটের সারির চিহ্ন ভুল']};
      await expectLater(const ServerCollectionEntryApi().preview(draft), throwsA(isA<DioException>()));
    });

    test('403 on open-bills, preview and setup: CollectionNotAllowed (a walled SR), never the cache', () async {
      server.withCustomer = 403;
      server.preview = 403;
      server.setup = 403;
      await expectLater(const ServerCollectionEntryApi().openBills('cus-1'), throwsA(isA<CollectionNotAllowed>()));
      await expectLater(const ServerCollectionEntryApi().preview(draft), throwsA(isA<CollectionNotAllowed>()));
      await expectLater(const ServerCollectionEntryApi().setup(), throwsA(isA<CollectionNotAllowed>()));
    });

    test('setup carries the rounding bands as 4-decimal strings, read in paisa', () async {
      server.setupBody = {'accounts': [], 'transfer_modes': [], 'rounding_limit': '10.0000', 'rounding_sign_above': '0.5000'};
      final setup = await const ServerCollectionEntryApi().setup();
      expect([setup.roundingLimit, setup.roundingSignAbove], [1000, 50]);
    });

    /// ⭐ সীমা নিচের দিকে, পয়সায় — সার্ভার bcmath-এ `>` মাপে (Settlement), তাই পুরো পয়সার অঙ্ক p "সীমার বেশি" ঠিক তখন যখন
    /// p > ⌊সীমা × 100⌋। গোল করলে "10.0050"-এ ফোন ৳10.01 ছাড়ত (সার্ভার ফেরায়), "0.5050"-এ ৳0.51-এ সই চাইত না (সার্ভার চায়)।
    test('setup bands with sub-paisa digits are read downwards, as the server compares: "10.0050" → 1000, "0.5050" → 50', () async {
      server.setupBody = {'accounts': [], 'transfer_modes': [], 'rounding_limit': '10.0050', 'rounding_sign_above': '0.5050'};
      final setup = await const ServerCollectionEntryApi().setup();
      expect([setup.roundingLimit, setup.roundingSignAbove], [1000, 50],
          reason: '⛔ সীমা গোল করে পড়া — ফোন আর সার্ভার এক পয়সায় আলাদা কথা বলে');
      expect([CollectionSetup.bandPaisa('0.29'), CollectionSetup.bandPaisa('25'), CollectionSetup.bandPaisa('-1'), CollectionSetup.bandPaisa('x')],
          [29, 2500, null, null]);
    });

    test('17 · the receipt\'s PDF comes through /documents/{type}/{id}/pdf with the sign-in token', () async {
      await TokenStorage.instance.saveTokens(accessToken: 'tok-41', refreshToken: 'r');
      const saved = CollectionSaved(id: 'col-9', receipt: 'https://erp.test/api/v1/documents/Collection/col-9/pdf');
      await DocumentsApi.pdf(type: saved.receiptDoor.type, id: saved.receiptDoor.id);
      expect(server.paths.last, '/documents/Collection/col-9/pdf?paper=a4');
      expect(server.auth.last, 'Bearer tok-41', reason: '⛔ রসিদ টোকেন ছাড়া চাওয়া হলো');
    });
  });

  group('⛔ old server (fe\'s rule) still detected', () {
    test('open-bills 404 with and without a customer: the old server — null, the screen falls back', () async {
      server.withCustomer = 404;
      server.withoutCustomer = 404;
      expect(await const ServerCollectionEntryApi().openBills('cus-1'), isNull);
    });

    test('405 (the route is missing): the old server, without asking again', () async {
      server.withCustomer = 405;
      expect(await const ServerCollectionEntryApi().openBills('cus-1'), isNull);
      expect(server.paths, hasLength(1));
    });

    test('preview 404 on the old server: null — the summary shows without the journal', () async {
      server.preview = 404;
      server.withoutCustomer = 404;
      expect(await const ServerCollectionEntryApi().preview(draft), isNull);
    });

    test('any other failure is not read as either', () async {
      server.withCustomer = 500;
      await expectLater(const ServerCollectionEntryApi().openBills('cus-1'), throwsA(isA<DioException>()));
      expect(server.paths, hasLength(1));
    });
  });
}

/// সার্ভারের নকল — খোলা বিল আর প্রিভিউর দরজা, ঠিক করে দেওয়া অবস্থার কোডে
class _Server implements HttpClientAdapter {
  int withCustomer = 200;
  int withoutCustomer = 422;
  int preview = 200;
  int setup = 200;
  Map<String, dynamic> previewErrors = const {};
  Map<String, dynamic> setupBody = const {'accounts': []};
  final List<String> paths = [];
  final List<String?> auth = [];

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? stream, Future<void>? cancelFuture) async {
    final query = options.uri.query;
    paths.add(query.isEmpty ? options.path : '${options.path}?$query');
    auth.add(options.headers['Authorization']?.toString());
    if (options.path.startsWith('/documents/')) {
      return ResponseBody.fromBytes(const [37, 80, 68, 70], 200, headers: {
        Headers.contentTypeHeader: ['application/pdf'],
      });
    }
    final code = switch (options.path) {
      '/sales/collections/preview' => preview,
      '/sales/collections/setup' => setup,
      _ => options.queryParameters.containsKey('customer') ? withCustomer : withoutCustomer,
    };
    final ok = options.path == '/sales/collections/setup' ? setupBody : {'due': '0', 'items': [], 'lines': []};
    return ResponseBody.fromString(
      jsonEncode(code == 200 ? ok : {'message': 'x', if (previewErrors.isNotEmpty) 'errors': previewErrors}),
      code,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}
