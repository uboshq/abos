import 'dart:convert';
import 'dart:typed_data';

import 'package:abos_mobile/core/api_client/api_client.dart';
import 'package:abos_mobile/core/books/collection_entry.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_secure_storage.dart';

/// ⭐ দুই রকম ৪০৪ আলাদা (fe, ২২ অক্টোবর ২০২৬) — আসল [ServerCollectionEntryApi], কেবল সার্ভারটা নকল (Dio-র অ্যাডাপ্টার)।
///
/// <p>নতুন সার্ভার (a6 d5cc9657f) নাগালের বাইরের গ্রাহকে ইচ্ছে করে ৪০৪ দেয় — গ্রাহক আছে কি না ফাঁস হয় না; তখন গ্রাহক ছাড়া খোলা
/// বিল জিজ্ঞেস করলে ৪২২ ("গ্রাহক লাগবে")। পুরনো সার্ভারে দরজাই নেই — গ্রাহক ছাড়াও ৪০৪, বা ৪০৫।
void main() {
  late HttpClientAdapter realAdapter;
  late _Server server;

  setUpAll(() {
    FakeSecureStorage.install();
    realAdapter = ApiClient.dio.httpClientAdapter;
  });

  tearDownAll(() => ApiClient.dio.httpClientAdapter = realAdapter);

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
  final List<String> paths = [];

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? stream, Future<void>? cancelFuture) async {
    final query = options.uri.query;
    paths.add(query.isEmpty ? options.path : '${options.path}?$query');
    final code = switch (options.path) {
      '/sales/collections/preview' => preview,
      _ => options.queryParameters.containsKey('customer') ? withCustomer : withoutCustomer,
    };
    return ResponseBody.fromString(
      jsonEncode(code == 200 ? {'due': '0', 'items': [], 'lines': []} : {'message': 'x'}),
      code,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}
