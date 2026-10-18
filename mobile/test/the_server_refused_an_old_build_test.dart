import 'dart:convert';
import 'dart:typed_data';

import 'package:abos_mobile/core/api_client/api_client.dart';
import 'package:abos_mobile/core/api_client/retired_build.dart';
import 'package:abos_mobile/core/config/app_config.dart';
import 'package:abos_mobile/core/update/app_version_check.dart';
import 'package:abos_mobile/features/update/update_gate.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_secure_storage.dart';

/// ⭐ সার্ভার ৪২৬ দিলে আপডেটের দেয়াল — পূর্ণ নিরীক্ষা §৯ ⚠️৯, ১৮ অক্টোবর ২০২৬ (সার্ভারের RefuseRetiredAppBuilds)।
///
/// <p>⛔ আগে সর্বনিম্ন সংস্করণ কেবল ফোনের নিজের পরীক্ষা, আর সার্ভার কোড জানতই না।
///
/// <p>⭐ দাবি: প্রতিটা অনুরোধে বিল্ডের ছোট কোড; ৪২৬ এলে দেয়াল, নিজের পরীক্ষা "ঠিক আছে" বললেও; নতুনটা কোথায় জানা না গেলে
/// দেয়াল নয় (নামানোর বোতাম ছাড়া দেয়াল মানুষকে আটকে রাখত)।
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  const release = AppRelease(
    versionCode: 60,
    versionName: '0.4.60',
    url: 'https://erp.adi.com.bd/app/abos-arm64.apk',
    apkSha256: '3f5a0c1d2e4b6a79880f1e2d3c4b5a69788796a5b4c3d2e1f00112233445c09e',
    sizeBytes: 74213888,
    minimumCode: 45,
  );

  test('every request says its build code, and a 426 is counted', () async {
    FakeSecureStorage.install();
    final real = ApiClient.dio.httpClientAdapter;
    final server = _Server();
    ApiClient.dio.httpClientAdapter = server;
    addTearDown(() => ApiClient.dio.httpClientAdapter = real);

    final before = RetiredBuild.refusals.value;
    await expectLater(ApiClient.dio.post<Map<String, dynamic>>('/notifications/read-all'), throwsA(isA<DioException>()));

    expect(server.code, '${AppConfig.appVersionCode}', reason: '⛔ সার্ভার বিল্ডের কোড জানল না');
    expect(RetiredBuild.refusals.value, before + 1, reason: '⛔ ৪২৬ কেউ শুনল না');
  });

  testWidgets('a 426 raises the wall even when the phone\'s own check said fine', (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: UpdateGate(
        check: () async => UpdateStatus.fine,
        fetch: () async => release,
        child: const Text('APP'),
      ),
    ));
    await tester.pumpAndSettle();
    expect(find.text('APP'), findsOneWidget);

    RetiredBuild.noteRefusal();
    await tester.pumpAndSettle();
    expect(find.text('এই সংস্করণটি আর ব্যবহার করা যাবে না'), findsOneWidget, reason: '⛔ সার্ভার ফেরাল, অথচ অ্যাপ চলল');
    expect(find.text('APP'), findsNothing);
  });

  testWidgets('no wall when the newest build cannot be found — it would have no download button', (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: UpdateGate(
        check: () async => UpdateStatus.fine,
        fetch: () async => throw DioException(requestOptions: RequestOptions(path: '/app/version')),
        child: const Text('APP'),
      ),
    ));
    await tester.pumpAndSettle();

    RetiredBuild.noteRefusal();
    await tester.pumpAndSettle();
    expect(find.text('APP'), findsOneWidget);
  });
}

class _Server implements HttpClientAdapter {
  String? code;

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? stream, Future<void>? cancelFuture) async {
    code = options.headers['X-App-Code'] as String?;
    return ResponseBody.fromString(jsonEncode({'code': 'app_update_required', 'minimumCode': 45}), 426, headers: {
      Headers.contentTypeHeader: [Headers.jsonContentType],
    });
  }

  @override
  void close({bool force = false}) {}
}
