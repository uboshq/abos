import 'dart:io';

import 'package:abos_mobile/core/api_client/api_client.dart';
import 'package:abos_mobile/core/auth/token_storage.dart';
import 'package:abos_mobile/core/navigation/top_bar/abos_top_bar.dart';
import 'package:abos_mobile/core/profile/face_image.dart';
import 'package:abos_mobile/features/home/home_shell.dart' show avatarImage;
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../support/fake_secure_storage.dart';
import 'profile_fakes.dart';

/// ⭐ মুখের ছবি টোকেনসহ — সার্ভারের ছবির দরজা `Authorization: Bearer` চায় (a6/me-photo-devices; অ্যাপ ০.৪.৪১, a6, ২০ অক্টোবর ২০২৬)।
///
/// <p>দাবি: অ্যাপ যেখানেই মুখ দেখায় (ওপরের বার, ছবির মেনু, "আমি", প্রোফাইল) সব [avatarImage] → [FaceImage] → অ্যাপের নিজের
/// ক্লায়েন্ট: নিজের সার্ভারে Bearer যায়, অন্য ঠিকানায় যায় না; ক্যাশের চাবি কেবল ঠিকানা (টোকেন বদলালেও একই ছবি); ছবি না এলে
/// নামের প্রথম অক্ষর। ⛔ গোটা অ্যাপে কাঁচা `NetworkImage` নেই — সেটা pinning আর টোকেন-নবায়নের বাইরে দিয়ে যেত।
void main() {
  late StubServer server;
  late HttpClientAdapter real;

  setUp(() async {
    FakeSecureStorage.install();
    FakeSecureStorage.reset();
    real = ApiClient.dio.httpClientAdapter;
    server = StubServer((o, _) => bytes(onePixelPng));
    ApiClient.dio.httpClientAdapter = server;
    await TokenStorage.instance.saveTokens(accessToken: 'ACCESS-1', refreshToken: 'R-1');
  });
  tearDown(() => ApiClient.dio.httpClientAdapter = real);

  const ours = 'https://erp.adi.com.bd/api/v1/users/U-1/photo?v=ab12';

  test('a face from our own server is asked for with the bearer token, through the app\'s one client', () async {
    final data = await FaceImage.fetchBytes(ours);
    expect(data, onePixelPng);
    final asked = server.seen.single;
    expect(asked.uri.toString(), ours);
    expect(asked.headers['Authorization'], 'Bearer ACCESS-1');
    expect(asked.responseType, ResponseType.bytes);
  });

  test('a face on any other address goes without the token', () async {
    await FaceImage.fetchBytes('https://cdn.example.com/a.jpg');
    expect(server.seen.single.headers.containsKey('Authorization'), isFalse, reason: '⛔ টোকেন অন্যের সার্ভারে');
    expect(FaceImage.sameOrigin(Uri.parse('https://erp.adi.com.bd:8443/x'), Uri.parse('https://erp.adi.com.bd/api/v1')), isFalse);
    expect(FaceImage.sameOrigin(Uri.parse('http://erp.adi.com.bd/x'), Uri.parse('https://erp.adi.com.bd/api/v1')), isFalse);
    expect(FaceImage.sameOrigin(Uri.parse('https://ERP.adi.com.bd/x'), Uri.parse('https://erp.adi.com.bd/api/v1')), isTrue);
  });

  test('the cache key is the address alone — a new token does not fetch the same face again', () {
    expect(const FaceImage(ours), const FaceImage(ours));
    expect(const FaceImage(ours).hashCode, const FaceImage(ours).hashCode);
    expect(const FaceImage(ours), isNot(const FaceImage('$ours&v=new')));
  });

  testWidgets('the header\'s face is drawn from the bytes the app\'s client fetched, with the token', (tester) async {
    final image = avatarImage(ours)!;
    expect(image, isA<ResizeImage>());
    expect((image as ResizeImage).imageProvider, const FaceImage(ours));

    await tester.runAsync(() async {
      await tester.pumpWidget(MaterialApp(home: Center(child: AvatarDisc(name: 'আল-আমিন', image: avatarImage('$ours&t=1'), size: 40))));
      await Future<void>.delayed(const Duration(milliseconds: 200));
    });
    await tester.pump();
    expect(server.seen.single.headers['Authorization'], 'Bearer ACCESS-1');
    final drawn = tester.widget<RawImage>(find.byType(RawImage));
    expect(drawn.image, isNotNull, reason: 'ছবিটাই আঁকা, অক্ষর নয়');
  });

  testWidgets('a face that does not come (404) shows the first letter, no red error', (tester) async {
    server = StubServer((o, _) => json({'message': 'Not Found'}, 404));
    ApiClient.dio.httpClientAdapter = server;
    await tester.runAsync(() async {
      await tester.pumpWidget(MaterialApp(home: Center(child: AvatarDisc(name: 'আল-আমিন', image: avatarImage('$ours&t=2'), size: 40))));
      await Future<void>.delayed(const Duration(milliseconds: 200));
    });
    await tester.pump();
    expect(find.text('আ'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  test('no raw NetworkImage anywhere in the app — every face goes through FaceImage', () {
    final raw = <String>[];
    for (final f in Directory('lib').listSync(recursive: true).whereType<File>().where((f) => f.path.endsWith('.dart'))) {
      final code = f.readAsLinesSync().where((l) => !l.trimLeft().startsWith('//')).join('\n');
      if (RegExp(r'NetworkImage\(|Image\.network\(').hasMatch(code)) raw.add(f.path.replaceAll(r'\', '/'));
    }
    expect(raw, isEmpty, reason: '⛔ pinning আর টোকেন-নবায়ন ছাড়া ছবি আনা');
    expect(File('lib/features/home/home_shell.dart').readAsStringSync(), contains('ResizeImage(FaceImage(url)'));
  });
}
