import 'dart:typed_data';

import 'package:abos_mobile/core/profile/me_profile_api.dart';

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
