import 'package:crypto/crypto.dart';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api_client/api_client.dart';
import '../api_client/once_key.dart';
import 'profile_api.dart';

/// ⭐ Facebook-ধাঁচের "আমি" পাতা আর ≡ মেনুর তথ্য (আইটেম ১০; মালিকের অনুমোদিত নমুনা "ক", ২০ নভেম্বর ২০২৬)।
///
/// <p>সার্ভারের দিক abos-a1-এর (ওয়েবের প্রোফাইলের সাথে একই API, একই মলাট-ঘর)। চুক্তি, `/api/v1`-এর নিচে:
/// <ul>
///   <li>`GET /me/profile` → নাম, পদবি, কোম্পানি · শাখা, ছবি, মলাট, পরিচয়, এই মাসের কাজের সংখ্যা (পদ অনুযায়ী, সার্ভারেই সাজানো),
///       শেষ ৪টা কাজ</li>
///   <li>`POST /me/cover` (multipart `cover`, JPEG) → `{cover_url}`; `DELETE /me/cover` → ২০৪ (মলাট না থাকলে কোম্পানির রঙের ঢাল)</li>
///   <li>`GET /me/today` → অনুমোদনের অপেক্ষা, না-পড়া নোটিশ, দিন বন্ধ বাকি</li>
/// </ul>
/// <p>⛔ পুরনো সার্ভারে দরজা নেই (৪০৪/৪০৫) → [ProfileDoorsMissing]; পাতা তখন `/me`-র যা জানা তা দিয়েই আঁকে ([MeProfile.fallback])।
abstract interface class MeProfileApi {
  Future<MeProfile> profile();

  Future<String?> uploadCover(Uint8List jpeg, {void Function(double sent)? onProgress});

  Future<void> removeCover();

  Future<MeToday> today();
}

/// এই মাসের একটা সংখ্যা — লেখা সার্ভারেই সাজানো (৳, কমা, %), অ্যাপ কেবল দেখায়
@immutable
class MeStat {
  const MeStat({required this.key, required this.label, required this.value, required this.kind, this.icon = '', this.tone});

  final String key;
  final String label;
  final String value;

  /// রং আর চিহ্ন বাছার জন্য: sales · approvals · vouchers · orders · target · deposits (অচেনা হলে সাধারণ)
  final String kind;

  /// সার্ভারের চিহ্ন: tag · approve · doc · cart · chart · deposit
  final String icon;

  /// ছোট চিহ্ন-ঘরের রং (`#RRGGBB`); null হলে [kind] ধরে
  final String? tone;

  factory MeStat.fromJson(Map<String, dynamic> j) => MeStat(
        key: '${j['key'] ?? ''}',
        label: '${j['label'] ?? ''}',
        value: '${j['value'] ?? ''}',
        kind: '${j['kind'] ?? ''}',
        icon: '${j['icon'] ?? ''}',
        tone: j['tone'] is String && RegExp(r'^#[0-9a-fA-F]{6}$').hasMatch(j['tone'] as String) ? j['tone'] as String : null,
      );
}

/// শেষ কাজের একটা সারি — "RCV-0142 অনুমোদন দিয়েছেন · ৳2,45,000 · টাকা প্রাপ্তি · 2 ঘণ্টা আগে"
@immutable
class MeRecent {
  const MeRecent({required this.no, required this.action, required this.summary, required this.at, required this.kind, this.url});

  final String no;
  final String action;
  final String summary;
  final DateTime? at;

  /// receipt · journal · sales_order · payment · … (রং আর চিহ্ন; ফেরত পাঠানো হলে `returned`)
  final String kind;

  /// সার্ভারের ওয়েব-পথ (abos-a1: web path) — অ্যাপ [WebInside]-এর মতো খোলে; null মানে খোলার পাতা নেই
  final String? url;

  factory MeRecent.fromJson(Map<String, dynamic> j) => MeRecent(
        no: '${j['no'] ?? ''}',
        action: '${j['action'] ?? ''}',
        summary: '${j['summary'] ?? ''}',
        at: DateTime.tryParse('${j['at'] ?? ''}')?.toLocal(),
        kind: '${j['kind'] ?? ''}',
        url: j['url'] is String && (j['url'] as String).isNotEmpty ? j['url'] as String : null,
      );
}

/// "পরিচয়" — যা নেই তা সারিতেই আসে না
@immutable
class MeAbout {
  const MeAbout({this.phone, this.email, this.employeeNo, this.joinedOn, this.role, this.branches});

  final String? phone;
  final String? email;
  final String? employeeNo;
  final DateTime? joinedOn;
  final String? role;
  final String? branches;

  static String? _s(Object? v) => v == null || '$v'.trim().isEmpty ? null : '$v'.trim();

  factory MeAbout.fromJson(Object? raw) {
    if (raw is! Map) return const MeAbout();
    return MeAbout(
      phone: _s(raw['phone']),
      email: _s(raw['email']),
      employeeNo: _s(raw['employee_no']),
      joinedOn: DateTime.tryParse('${raw['joined_on'] ?? ''}'),
      role: _s(raw['role']),
      branches: _s(raw['branches']),
    );
  }
}

@immutable
class MeProfile {
  const MeProfile({
    required this.name,
    this.publicId = '',
    this.initial = '',
    this.companyColour,
    this.designation,
    this.company = '',
    this.branch = '',
    this.photoUrl,
    this.coverUrl,
    this.about = const MeAbout(),
    this.monthLabel = '',
    this.stats = const [],
    this.recent = const [],
    this.complete = true,
  });

  final String name;

  /// সহকর্মীর প্রোফাইলের পথ (`/users/{public_id}/profile`)
  final String publicId;

  /// ছবি না থাকলে বড় গোলে যে অক্ষর
  final String initial;

  /// মলাট না থাকলে ঢালের রং (`#RRGGBB`); null হলে অ্যাপের ব্র্যান্ড-রং
  final String? companyColour;

  final String? designation;
  final String company;
  final String branch;
  final String? photoUrl;
  final String? coverUrl;
  final MeAbout about;
  final String monthLabel;
  final List<MeStat> stats;
  final List<MeRecent> recent;

  /// false = পুরনো সার্ভার, কেবল `/me`-র নাম-পদবি-কোম্পানি (মাসের কাজ, পরিচয়, শেষ কাজ আঁকা হয় না)
  final bool complete;

  /// পুরনো সার্ভারে — অ্যাপের হাতে যা আছে তা দিয়ে
  factory MeProfile.fallback({required String name, String? designation, String company = '', String branch = '', String? photoUrl, String? email}) =>
      MeProfile(
        name: name,
        designation: designation,
        company: company,
        branch: branch,
        photoUrl: photoUrl,
        about: MeAbout(email: email),
        complete: false,
      );

  factory MeProfile.fromJson(Map<String, dynamic> j) {
    final month = j['month'] is Map ? j['month'] as Map : const {};
    List<Map<String, dynamic>> maps(Object? raw) =>
        raw is List ? raw.whereType<Map>().map((m) => Map<String, dynamic>.from(m)).toList() : const [];
    String? s(Object? v) => v == null || '$v'.trim().isEmpty ? null : '$v'.trim();
    return MeProfile(
      name: '${j['name'] ?? ''}',
      publicId: '${j['public_id'] ?? ''}',
      initial: '${j['initial'] ?? ''}',
      companyColour: j['company_colour'] is String && RegExp(r'^#[0-9a-fA-F]{6}$').hasMatch(j['company_colour'] as String)
          ? j['company_colour'] as String
          : null,
      designation: s(j['designation']),
      company: '${j['company'] ?? ''}',
      branch: '${j['branch'] ?? ''}',
      photoUrl: s(j['photo_url']),
      coverUrl: s(j['cover_url']),
      about: MeAbout.fromJson(j['about']),
      monthLabel: '${month['label'] ?? ''}',
      stats: maps(month['stats']).take(3).map(MeStat.fromJson).toList(),
      recent: maps(j['recent']).take(4).map(MeRecent.fromJson).toList(),
    );
  }

  MeProfile withCover(String? url) => MeProfile(
        name: name,
        publicId: publicId,
        initial: initial,
        companyColour: companyColour,
        designation: designation,
        company: company,
        branch: branch,
        photoUrl: photoUrl,
        coverUrl: url,
        about: about,
        monthLabel: monthLabel,
        stats: stats,
        recent: recent,
        complete: complete,
      );
}

/// ≡ মেনুর "আজকের কাজ"
@immutable
class MeToday {
  const MeToday({this.approvalsWaiting = 0, this.unreadNotices = 0, this.dayClosePending = 0});

  final int approvalsWaiting;
  final int unreadNotices;
  final int dayClosePending;

  static int _n(Object? v) => v is num ? v.toInt() : int.tryParse('$v') ?? 0;

  factory MeToday.fromJson(Map<String, dynamic> j) => MeToday(
        approvalsWaiting: _n(j['approvals_waiting']),
        unreadNotices: _n(j['unread_notices']),
        dayClosePending: _n(j['day_close_pending']),
      );
}

class ServerMeProfileApi implements MeProfileApi {
  const ServerMeProfileApi({this.dio});

  final Dio? dio;

  Dio get _dio => dio ?? ApiClient.dio;

  static Object _error(DioException e) {
    final status = e.response?.statusCode;
    if (status == 404 || status == 405) return const ProfileDoorsMissing();
    if (status == 422 || status == 413) {
      return PhotoRefused(ServerProfileApi.refusalOf(e.response?.data) ?? ServerProfileApi.refusedFallback);
    }
    return e;
  }

  @override
  Future<MeProfile> profile() async {
    try {
      final r = await _dio.get<Map<String, dynamic>>('/me/profile', queryParameters: const {'limit': 4});
      return MeProfile.fromJson(r.data ?? const {});
    } on DioException catch (e) {
      throw _error(e);
    }
  }

  @override
  Future<String?> uploadCover(Uint8List jpeg, {void Function(double sent)? onProgress}) async {
    final once = OnceKey.of('me-cover:${sha256.convert(jpeg).toString().substring(0, 24)}');
    try {
      final r = await once.send(() => _dio.post<Map<String, dynamic>>(
            '/me/cover',
            data: FormData.fromMap({
              'cover': MultipartFile.fromBytes(jpeg, filename: 'cover.jpg', contentType: DioMediaType('image', 'jpeg')),
            }),
            onSendProgress: onProgress == null
                ? null
                : (sent, total) {
                    if (total > 0) onProgress((sent / total).clamp(0.0, 1.0));
                  },
          ));
      final url = r.data?['cover_url'];
      return url is String && url.isNotEmpty ? url : null;
    } on DioException catch (e) {
      throw _error(e);
    }
  }

  @override
  Future<void> removeCover() async {
    try {
      await OnceKey.of('me-cover-remove').send(() => _dio.delete<void>('/me/cover'));
    } on DioException catch (e) {
      throw _error(e);
    }
  }

  @override
  Future<MeToday> today() async {
    try {
      final r = await _dio.get<Map<String, dynamic>>('/me/today');
      return MeToday.fromJson(r.data ?? const {});
    } on DioException catch (e) {
      throw _error(e);
    }
  }
}

/// ⓘ পরীক্ষা আর সার্ভার আসার আগের নমুনা — নমুনার "শুভ আহমেদ"-এর তথ্য
class FakeMeProfileApi implements MeProfileApi {
  FakeMeProfileApi({MeProfile? profile, this.todayCounts = const MeToday(approvalsWaiting: 3, unreadNotices: 20, dayClosePending: 1)})
      : _profile = profile ?? sample;

  MeProfile _profile;
  final MeToday todayCounts;
  final List<String> calls = [];

  static final MeProfile sample = MeProfile(
    name: 'শুভ আহমেদ',
    publicId: 'u-owner',
    initial: 'শু',
    designation: 'মালিক',
    company: 'ADI Corporation',
    branch: 'TD শাখা',
    about: MeAbout(
      phone: '01711-234567',
      email: 'shuvo@example.com',
      employeeNo: 'EMP-0001',
      joinedOn: DateTime(2019, 1, 1),
      role: 'মালিক — সব অনুমতি',
      branches: 'TD সহ সব শাখা',
    ),
    monthLabel: 'নভেম্বর 2026',
    stats: const [
      MeStat(key: 'sales_total', label: 'মোট বিক্রি', value: '৳1,24,50,000', kind: 'sales', icon: 'tag', tone: '#16a34a'),
      MeStat(key: 'approvals_given', label: 'অনুমোদন দিয়েছেন', value: '86', kind: 'approvals', icon: 'approve', tone: '#e11d48'),
      MeStat(key: 'vouchers_posted', label: 'পোস্ট করা ভাউচার', value: '312', kind: 'vouchers', icon: 'doc', tone: '#2563eb'),
    ],
    recent: [
      MeRecent(no: 'RCV-0142', action: 'অনুমোদন দিয়েছেন', summary: '৳2,45,000 · টাকা প্রাপ্তি', at: DateTime(2026, 11, 20, 10), kind: 'receipt'),
      MeRecent(no: 'JV-0318', action: 'পোস্ট করেছেন', summary: '৳18,600 · জার্নাল ভাউচার', at: DateTime(2026, 11, 20, 7), kind: 'journal'),
      MeRecent(no: 'SO-1027', action: 'অনুমোদন দিয়েছেন', summary: '৳3,12,400 · বিক্রয় আদেশ', at: DateTime(2026, 11, 19, 15), kind: 'sales_order'),
      MeRecent(no: 'PAY-0089', action: 'ফেরত পাঠিয়েছেন', summary: '৳75,000 · টাকা প্রদান — "বিল সংযুক্ত নেই"', at: DateTime(2026, 11, 18, 12), kind: 'returned'),
    ],
  );

  @override
  Future<MeProfile> profile() async {
    calls.add('profile');
    return _profile;
  }

  @override
  Future<String?> uploadCover(Uint8List jpeg, {void Function(double sent)? onProgress}) async {
    calls.add('uploadCover:${jpeg.length}');
    onProgress?.call(1);
    const url = 'https://example.test/cover.jpg';
    _profile = _profile.withCover(url);
    return url;
  }

  @override
  Future<void> removeCover() async {
    calls.add('removeCover');
    _profile = _profile.withCover(null);
  }

  @override
  Future<MeToday> today() async {
    calls.add('today');
    return todayCounts;
  }
}

/// ⭐ পাতা দুটো এখান থেকে পড়ে — পরীক্ষায় [FakeMeProfileApi] দিয়ে বদলানো যায়
final meProfileApiProvider = Provider<MeProfileApi>((ref) => const ServerMeProfileApi());
