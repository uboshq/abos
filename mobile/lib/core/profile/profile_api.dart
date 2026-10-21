import 'dart:typed_data';

import 'package:crypto/crypto.dart';
import 'package:dio/dio.dart';

import '../api_client/api_client.dart';
import '../api_client/once_key.dart';
import '../locale/app_locale.dart';

/// ⭐ প্রোফাইল মেনুর সার্ভার-দরজা — নিজের ছবি আর নিজের ফোনগুলো (অ্যাপ ০.৪.৪১; a6, ২০ অক্টোবর ২০২৬)।
///
/// <p>সার্ভারের দিক a6/me-photo-devices শাখায় (MePhotoController, MeDevicesController, UserPhotoController):
/// <ul>
///   <li>`POST /me/photo` (multipart `photo`, JPG/PNG, ≤৫ MB) → ২০০ `{photo_url, updated_at}`; ৪২২ `errors.photo[0]` বাংলায়</li>
///   <li>`DELETE /me/photo` → ২০৪</li>
///   <li>`GET /me/devices?page=n` → `{data: [...], next_page}`; `DELETE /me/devices/{id}` → ২০৪ বা ৪০৪</li>
/// </ul>
///
/// <p>⭐ <b>পুরনো সার্ভার</b>: কোন কাজ আছে তা `/me`-র `features` বলে ([MeFeatures]) — না থাকলে পর্দা ঐ অংশ দেখায়ই না। এখানে
/// শেষ পাহারা: দরজাটা তবু ৪০৪ দিলে (Laravel-এর ইংরেজি "not found") [ProfileDoorsMissing] — "এখনো এই সার্ভারে নেই"। ⛔ ব্যতিক্রম
/// কেবল `DELETE /me/devices/{id}`: তালিকা এসেছে মানে সার্ভার নতুন, তাই সেখানে ৪০৪ = ফোনটা আগেই বের হয়ে গেছে ([signOutPhone]
/// false দেয়)।
abstract interface class ProfileApi {
  /// নিজের ছবি বসানো — [jpeg] ফোনেই ৫১২-র বর্গ করা; [onProgress] ০..১
  Future<PhotoChange> uploadPhoto(Uint8List jpeg, {void Function(double sent)? onProgress});

  /// নিজের ছবি সরানো
  Future<void> removePhoto();

  /// ঢোকা ফোনগুলোর এক পাতা
  Future<PhonePage> phones(int page);

  /// একটা ফোন থেকে বের করে দেওয়া — true হলো (২০৪), false ফোনটা আগেই নেই (৪০৪)
  Future<bool> signOutPhone(String id);
}

/// নতুন ছবির ঠিকানা — `v=` ছাপসহ, তাই আগের ছবি ক্যাশ থেকে আসে না
class PhotoChange {
  const PhotoChange({required this.photoUrl, this.updatedAt});

  /// ⓘ সার্ভার ছবি না রাখতে পারলে null ফেরাতে পারে — তখন মুখের জায়গায় নামের অক্ষর
  final String? photoUrl;
  final DateTime? updatedAt;

  factory PhotoChange.fromJson(Map<String, dynamic> json) => PhotoChange(
        photoUrl: _text(json['photo_url']),
        updatedAt: DateTime.tryParse('${json['updated_at'] ?? ''}'),
      );
}

/// এক ফোন — সার্ভারের এক সারি
class MyPhone {
  const MyPhone({
    required this.id,
    required this.name,
    this.platform,
    this.appVersion,
    this.lastUsedAt,
    this.createdAt,
    this.current = false,
  });

  /// অস্বচ্ছ id (মানুষ + ফোনের HMAC) — কাঁচা deviceId নয়
  final String id;

  /// "Android ফোন · 0.4.40 · #AB12" — সার্ভার বানায়
  final String name;
  final String? platform;
  final String? appVersion;
  final DateTime? lastUsedAt;
  final DateTime? createdAt;

  /// এই ফোনটাই (যে টোকেনে তালিকা চাওয়া হলো)
  final bool current;

  /// ⓘ সারি ভাঙা হলে (id নেই) null — পর্দায় না দেখানোই ভালো, "বের করে দিন" চাপলে কোনো দরজা মিলত না
  static MyPhone? fromJson(Object? raw) {
    if (raw is! Map) return null;
    final id = _text(raw['id']);
    if (id == null) return null;
    return MyPhone(
      id: id,
      name: _text(raw['name']) ?? AppLocale.l10n.profilePhoneFallbackName,
      platform: _text(raw['platform']),
      appVersion: _text(raw['app_version']),
      lastUsedAt: DateTime.tryParse('${raw['last_used_at'] ?? ''}')?.toLocal(),
      createdAt: DateTime.tryParse('${raw['created_at'] ?? ''}')?.toLocal(),
      current: raw['current'] == true,
    );
  }
}

class PhonePage {
  const PhonePage(this.rows, this.nextPage);

  final List<MyPhone> rows;

  /// পরের পাতার নম্বর — null মানে শেষ পাতা
  final int? nextPage;

  factory PhonePage.fromJson(Map<String, dynamic> json) {
    final next = json['next_page'];
    return PhonePage(
      [for (final r in (json['data'] as List?) ?? const []) MyPhone.fromJson(r)].whereType<MyPhone>().toList(),
      next is int ? next : int.tryParse('${next ?? ''}'),
    );
  }
}

/// ⛔ সার্ভার ছবিটা নিল না (৪২২) — [message] সার্ভারের বাংলা বাক্য, না থাকলে নিজের
class PhotoRefused implements Exception {
  const PhotoRefused(this.message);

  final String message;

  @override
  String toString() => message;
}

/// ⓘ সার্ভার পুরনো — প্রোফাইল মেনুর দরজা নেই
class ProfileDoorsMissing implements Exception {
  const ProfileDoorsMissing();

  static String get title => AppLocale.l10n.profileServerMissingTitle;
  static String get message => AppLocale.l10n.profileServerMissingMessage;

  @override
  String toString() => message;
}

class ServerProfileApi implements ProfileApi {
  const ServerProfileApi({this.dio});

  /// পরীক্ষার পথ; না দিলে অ্যাপের একমাত্র ক্লায়েন্ট (টোকেন, নবায়ন, pinning সব সেখানে)
  final Dio? dio;

  Dio get _dio => dio ?? ApiClient.dio;

  static String get refusedFallback => AppLocale.l10n.profilePhotoRefused;

  @override
  Future<PhotoChange> uploadPhoto(Uint8List jpeg, {void Function(double sent)? onProgress}) async {
    // ⓘ চাবি ছবির ছাপে: একই ছবি আবার পাঠালে একই চাবি, অন্য ছবিতে নতুন — নেট হারিয়ে অন্য ছবি বাছলে পুরনো উত্তর ফিরত না
    final once = OnceKey.of('me-photo:${sha256.convert(jpeg).toString().substring(0, 24)}');
    try {
      final response = await once.send(() => _dio.post<Map<String, dynamic>>(
            '/me/photo',
            data: FormData.fromMap({
              'photo': MultipartFile.fromBytes(jpeg, filename: 'face.jpg', contentType: DioMediaType('image', 'jpeg')),
            }),
            onSendProgress: onProgress == null
                ? null
                : (sent, total) {
                    if (total > 0) onProgress((sent / total).clamp(0.0, 1.0));
                  },
          ));
      return PhotoChange.fromJson(response.data ?? const {});
    } on DioException catch (e) {
      throw _photoError(e);
    }
  }

  @override
  Future<void> removePhoto() async {
    try {
      await OnceKey.of('me-photo-remove').send(() => _dio.delete<void>('/me/photo'));
    } on DioException catch (e) {
      throw _photoError(e);
    }
  }

  /// ৪২২/৪১৩ → [PhotoRefused] (সার্ভারের বাংলা বাক্য আগে), ৪০৪/৪০৫ → [ProfileDoorsMissing], বাকিটা যেমন ছিল
  static Object _photoError(DioException e) {
    final status = e.response?.statusCode;
    if (status == 404 || status == 405) return const ProfileDoorsMissing();
    if (status == 413) return PhotoRefused(AppLocale.l10n.profilePhotoTooLarge);
    if (status == 422) return PhotoRefused(refusalOf(e.response?.data) ?? refusedFallback);
    return e;
  }

  /// ৪২২-এর বাক্য — `errors.photo[0]`, না হলে `message`; ⛔ বাংলায় চললে কেবল বাংলা বাক্য (Laravel-এর ইংরেজি দোকানের ফোনে নয়);
  /// ইংরেজিতে চললে সার্ভারের যে কোনো বাক্য (Accept-Language-এ সার্ভার ইংরেজিতে বলে)
  static String? refusalOf(Object? body) {
    if (body is! Map) return null;
    final errors = body['errors'];
    final photo = errors is Map ? errors['photo'] : null;
    final first = photo is List && photo.isNotEmpty ? '${photo.first}'.trim() : null;
    for (final text in [first, _text(body['message'])]) {
      if (text != null && text.isNotEmpty && (AppLocale.isEnglish() || text.runes.any((r) => r >= 0x0980 && r <= 0x09FF))) return text;
    }
    return null;
  }

  @override
  Future<PhonePage> phones(int page) async {
    try {
      final response = await _dio.get<Map<String, dynamic>>('/me/devices', queryParameters: {'page': page});
      return PhonePage.fromJson(response.data ?? const {});
    } on DioException catch (e) {
      final status = e.response?.statusCode;
      if (status == 404 || status == 405) throw const ProfileDoorsMissing();
      rethrow;
    }
  }

  @override
  Future<bool> signOutPhone(String id) async {
    try {
      await OnceKey.of('me-device-out:$id').send(() => _dio.delete<void>('/me/devices/${Uri.encodeComponent(id)}'));
      return true;
    } on DioException catch (e) {
      if (e.response?.statusCode == 404) return false;
      rethrow;
    }
  }
}

String? _text(Object? v) {
  final s = v?.toString().trim();
  return s == null || s.isEmpty ? null : s;
}
