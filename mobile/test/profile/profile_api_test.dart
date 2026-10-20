import 'dart:convert';
import 'dart:typed_data';

import 'package:abos_mobile/core/api_client/api_client.dart';
import 'package:abos_mobile/core/api_client/once_key.dart';
import 'package:abos_mobile/core/profile/profile_api.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

import '../support/fake_secure_storage.dart';
import 'profile_fakes.dart';

/// ⭐ প্রোফাইল মেনুর সার্ভার-দরজা, ফোনের দিক (a6/me-photo-devices-এর আকার হুবহু; অ্যাপ ০.৪.৪১, a6, ২০ অক্টোবর ২০২৬)।
///
/// <p>দাবি: ছবি যায় multipart `photo` নামে, JPEG হয়ে; ৪২২-এর বাংলা বাক্য (`errors.photo[0]`) পর্দায় যায়, ইংরেজিটা নয়; ফোনের
/// তালিকা পাতা ধরে (`page`, `next_page`), ভাঙা সারি বাদ; বের করায় ২০৪ = হলো, ৪০৪ = আগেই নেই; ⭐ পুরনো সার্ভারে (দরজা নেই,
/// ৪০৪) ছবি আর তালিকা [ProfileDoorsMissing] — "সার্ভার আপডেট হয়নি"।
void main() {
  late StubServer server;
  late HttpClientAdapter real;

  setUp(() {
    FakeSecureStorage.install();
    OnceKey.forgetAll();
    real = ApiClient.dio.httpClientAdapter;
  });
  tearDown(() => ApiClient.dio.httpClientAdapter = real);

  void serve(ResponseBody Function(RequestOptions o, List<int> body) answer) {
    server = StubServer(answer);
    ApiClient.dio.httpClientAdapter = server;
  }

  const api = ServerProfileApi();
  final jpeg = Uint8List.fromList([0xFF, 0xD8, 0xFF, 1, 2, 3, 0xFF, 0xD9]);

  group('my photo', () {
    test('goes as multipart field "photo", a JPEG, to POST /me/photo, with progress, and returns the new address', () async {
      serve((o, _) => json({'photo_url': 'https://erp.adi.com.bd/api/v1/users/U-1/photo?v=ab12', 'updated_at': '2026-10-20T10:00:00+06:00'}, 200));
      final progress = <double>[];
      final change = await api.uploadPhoto(jpeg, onProgress: progress.add);

      final sent = server.seen.single;
      expect(sent.method, 'POST');
      expect(sent.path, '/me/photo');
      expect('${sent.headers['content-type']}', startsWith('multipart/form-data; boundary='));
      final body = latin1.decode(server.bodies.single);
      expect(body, contains('name="photo"; filename="face.jpg"'));
      expect(body, contains('content-type: image/jpeg'));
      expect(sent.headers['Idempotency-Key'], isNotNull, reason: 'একবারের কাজ — দুবার চাপলে বা উত্তর হারালে একবারই');
      expect(progress, isNotEmpty);
      expect(progress.last, 1.0);
      expect(change.photoUrl, 'https://erp.adi.com.bd/api/v1/users/U-1/photo?v=ab12');
      expect(change.updatedAt, DateTime.parse('2026-10-20T10:00:00+06:00'));
    });

    test('a 422 carries the server\'s own Bengali sentence from errors.photo[0]', () async {
      serve((o, _) => json({
            'message': 'The photo field must be a file of type: image/jpeg, image/png.',
            'errors': {
              'photo': ['ফোন থেকে কেবল JPG বা PNG ছবি দেওয়া যায়।']
            }
          }, 422));
      await expectLater(
          api.uploadPhoto(jpeg),
          throwsA(isA<PhotoRefused>().having((e) => e.message, 'message', 'ফোন থেকে কেবল JPG বা PNG ছবি দেওয়া যায়।')));
    });

    test('an English 422 never reaches the shop\'s phone — the phone\'s own Bengali sentence instead', () async {
      serve((o, _) => json({
            'message': 'The photo failed to upload.',
            'errors': {
              'photo': ['The photo failed to upload.']
            }
          }, 422));
      await expectLater(api.uploadPhoto(jpeg),
          throwsA(isA<PhotoRefused>().having((e) => e.message, 'message', ServerProfileApi.refusedFallback)));
    });

    test('too large (413) says so in Bengali', () async {
      serve((o, _) => json(null, 413));
      await expectLater(api.uploadPhoto(jpeg), throwsA(isA<PhotoRefused>().having((e) => e.message, 'm', contains('৫ MB'))));
    });

    test('removing is DELETE /me/photo; 204 is done', () async {
      serve((o, _) => json(null, 204));
      await api.removePhoto();
      expect(server.seen.single.method, 'DELETE');
      expect(server.seen.single.path, '/me/photo');
    });

    test('an old server (no photo door, 404) reads as "server not updated", both ways', () async {
      serve((o, _) => json({'message': 'The route api/v1/me/photo could not be found.'}, 404));
      await expectLater(api.uploadPhoto(jpeg), throwsA(isA<ProfileDoorsMissing>()));
      await expectLater(api.removePhoto(), throwsA(isA<ProfileDoorsMissing>()));
      expect(ProfileDoorsMissing.message, contains('সার্ভার আপডেট হয়নি'));
    });
  });

  group('my phones', () {
    test('a page is read whole: id, name, platform, version, times in local time, current, next_page', () async {
      serve((o, _) => json({
            'data': [
              {
                'id': '0123456789abcdef0123456789abcdef',
                'name': 'Android ফোন · 0.4.40 · #0123',
                'platform': 'android',
                'app_version': '0.4.40',
                'last_used_at': '2026-10-20T04:00:00+00:00',
                'created_at': '2026-10-01T04:00:00+00:00',
                'current': true,
              },
            ],
            'next_page': 2,
          }, 200));
      final page = await api.phones(1);
      expect(server.seen.single.path, '/me/devices');
      expect(server.seen.single.queryParameters, {'page': 1});
      final p = page.rows.single;
      expect(p.id, '0123456789abcdef0123456789abcdef');
      expect(p.name, 'Android ফোন · 0.4.40 · #0123');
      expect(p.platform, 'android');
      expect(p.appVersion, '0.4.40');
      expect(p.current, isTrue);
      expect(p.lastUsedAt, DateTime.utc(2026, 10, 20, 4).toLocal());
      expect(p.lastUsedAt!.isUtc, isFalse, reason: 'পর্দায় ফোনের নিজের সময়ে');
      expect(page.nextPage, 2);
    });

    test('the last page has next_page null; a row without an id is dropped; missing fields do not break the row', () async {
      final page = PhonePage.fromJson({
        'data': [
          {'name': 'id ছাড়া'},
          'not a row',
          {'id': 'abc'},
        ],
        'next_page': null,
      });
      expect(page.rows.map((r) => r.id), ['abc']);
      expect(page.rows.single.name, 'ফোন');
      expect(page.rows.single.current, isFalse);
      expect(page.rows.single.lastUsedAt, isNull);
      expect(page.nextPage, isNull);
      expect(PhonePage.fromJson(const {}).rows, isEmpty, reason: 'খালি উত্তরে ভেঙে পড়ে না');
    });

    test('signing a phone out is DELETE /me/devices/{id}: 204 done, 404 already gone', () async {
      var status = 204;
      serve((o, _) => json(null, status));
      expect(await api.signOutPhone('0123456789abcdef0123456789abcdef'), isTrue);
      expect(server.seen.last.method, 'DELETE');
      expect(server.seen.last.path, '/me/devices/0123456789abcdef0123456789abcdef');
      status = 404;
      expect(await api.signOutPhone('0123456789abcdef0123456789abcdef'), isFalse);
    });

    test('an old server (no list door) reads as "server not updated"; doorsOpen says false, true or unknown', () async {
      var status = 404;
      serve((o, _) => status == 0
          ? throw DioException(requestOptions: o, type: DioExceptionType.connectionError)
          : json(status == 200 ? {'data': [], 'next_page': null} : {'message': 'Not Found'}, status));
      await expectLater(api.phones(1), throwsA(isA<ProfileDoorsMissing>()));
      expect(await api.doorsOpen(), isFalse);
      status = 200;
      expect(await api.doorsOpen(), isTrue);
      status = 0;
      expect(await api.doorsOpen(), isNull, reason: 'নেট নেই — জানা নেই, "পুরনো সার্ভার" বলা ভুল হত');
    });
  });
}
