import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:abos_mobile/core/auth/auth_controller.dart';
import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/profile/profile_api.dart';
import 'package:dio/dio.dart';
import 'package:image/image.dart' as img;

/// সার্ভারের ভান — প্রতিটা অনুরোধ মনে রাখে, উত্তর দেয় [answer] থেকে
class StubServer implements HttpClientAdapter {
  StubServer(this.answer);

  final ResponseBody Function(RequestOptions options, List<int> body) answer;
  final List<RequestOptions> seen = [];
  final List<List<int>> bodies = [];

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    final body = <int>[];
    if (requestStream != null) {
      await for (final chunk in requestStream) {
        body.addAll(chunk);
      }
    }
    seen.add(options);
    bodies.add(body);
    return answer(options, body);
  }

  @override
  void close({bool force = false}) {}
}

ResponseBody json(Object? body, int status) => ResponseBody.fromString(body == null ? '' : jsonEncode(body), status,
    headers: {Headers.contentTypeHeader: [Headers.jsonContentType]});

ResponseBody bytes(List<int> data, {int status = 200, String type = 'image/png'}) =>
    ResponseBody.fromBytes(data, status, headers: {Headers.contentTypeHeader: [type]});

/// ছোট সত্যিকারের PNG — ছবি হিসেবে ভাঙা যায়
final Uint8List onePixelPng = img.encodePng(img.Image(width: 2, height: 2)..clear(img.ColorRgb8(200, 80, 40)));

const newFace = 'https://erp.adi.com.bd/api/v1/users/U-1/photo?v=new';
const oldFace = 'https://erp.adi.com.bd/api/v1/users/U-1/photo?v=old';

const me = AuthUser(id: 'u1', name: 'আল-আমিন শুভ', email: 'alamin@adi.test', roles: ['owner'], permissions: []);

class SignedIn extends AuthController {
  SignedIn([AuthUser user = me]) {
    state = AuthState.signedIn(user);
  }

  int signedOutHere = 0;

  @override
  Future<void> signOutHere() async {
    signedOutHere++;
    state = const AuthState.signedOut();
  }
}

MyPhone phone(String id, {String? name, bool current = false, DateTime? used}) => MyPhone(
      id: id,
      name: name ?? 'Android ফোন · 0.4.40 · #${id.substring(0, 4).toUpperCase()}',
      platform: 'android',
      appVersion: '0.4.40',
      lastUsedAt: used ?? DateTime(2026, 10, 20, 10),
      current: current,
    );

/// পর্দার পরীক্ষার API — কে কী চাইল মনে রাখে
class FakeProfileApi implements ProfileApi {
  FakeProfileApi({
    this.doors = true,
    List<List<MyPhone>>? pages,
    this.uploadAnswer,
    this.signOutAnswer,
  }) : pages = pages ?? [[phone('aaaa1111', current: true), phone('bbbb2222')]];

  /// false = পুরনো সার্ভার: তালিকার দরজা ৪০৪
  bool doors;
  List<List<MyPhone>> pages;
  Future<PhotoChange> Function(Uint8List jpeg)? uploadAnswer;
  Future<bool> Function(String id)? signOutAnswer;

  final List<Uint8List> uploads = [];
  int removals = 0;
  final List<int> pagesAsked = [];
  final List<String> signedOut = [];

  @override
  Future<PhotoChange> uploadPhoto(Uint8List jpeg, {void Function(double sent)? onProgress}) async {
    uploads.add(jpeg);
    onProgress?.call(0.45);
    final change = await (uploadAnswer ?? (_) async => const PhotoChange(photoUrl: newFace))(jpeg);
    onProgress?.call(1);
    return change;
  }

  @override
  Future<void> removePhoto() async => removals++;

  @override
  Future<PhonePage> phones(int page) async {
    pagesAsked.add(page);
    if (!doors) throw const ProfileDoorsMissing();
    final rows = page - 1 < pages.length ? pages[page - 1] : const <MyPhone>[];
    return PhonePage(rows, page < pages.length ? page + 1 : null);
  }

  @override
  Future<bool> signOutPhone(String id) async {
    signedOut.add(id);
    return (signOutAnswer ?? (_) async => true)(id);
  }
}
