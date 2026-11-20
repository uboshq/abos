import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// ⭐ সার্ভার কোন নতুন কাজ জানে — `/me`-র `features` (a6/me-photo-devices, ২০ অক্টোবর ২০২৬):
/// `{profile_photo: bool, my_phones: bool}`।
///
/// <p>⛔ পুরনো সার্ভার `features` পাঠায় না → দুটোই false: ছবি বদলের অংশ আর "আমার ফোনগুলো" দেখায়ই না (বন্ধ বোতাম নয়)।
/// ⓘ কেবল `true` মানে হ্যাঁ — "1", "yes" বা ভাঙা মান না।
@immutable
class MeFeatures {
  const MeFeatures({this.profilePhoto = false, this.myPhones = false, this.profilePage = false, this.coverPhoto = false});

  /// পুরনো সার্ভার — কিছুই নেই
  static const MeFeatures none = MeFeatures();

  /// ছবি তোলা, বদল আর সরানো (`POST/DELETE /me/photo`)
  final bool profilePhoto;

  /// "আমার ফোনগুলো" আর দূর থেকে বের করা (`/me/devices`)
  final bool myPhones;

  /// ⭐ Facebook-ধাঁচের "আমি" পাতার পুরো তথ্য আর ≡ মেনুর "আজকের কাজ" (`GET /me/profile`, `/me/today`; আইটেম ১০, ২০ নভেম্বর ২০২৬)
  final bool profilePage;

  /// মলাট-ছবি বদল (`POST/DELETE /me/cover`); না থাকলে মলাটে কোম্পানির রঙের ঢাল, "মলাট বদলান" বোতাম নেই
  final bool coverPhoto;

  factory MeFeatures.fromJson(Object? raw) {
    if (raw is! Map) return none;
    return MeFeatures(
      profilePhoto: raw['profile_photo'] == true,
      myPhones: raw['my_phones'] == true,
      profilePage: raw['profile_page'] == true,
      coverPhoto: raw['cover_photo'] == true,
    );
  }

  @override
  bool operator ==(Object other) =>
      other is MeFeatures &&
      other.profilePhoto == profilePhoto &&
      other.myPhones == myPhones &&
      other.profilePage == profilePage &&
      other.coverPhoto == coverPhoto;

  @override
  int get hashCode => Object.hash(profilePhoto, myPhones, profilePage, coverPhoto);

  @override
  String toString() => 'MeFeatures(profilePhoto: $profilePhoto, myPhones: $myPhones, profilePage: $profilePage, coverPhoto: $coverPhoto)';
}

/// হোম `/me` পেলেই লেখে; null = `/me` এখনো আসেনি (যেমন ঠান্ডা শুরুতে সরাসরি লিংক)
final meFeaturesProvider = StateProvider<MeFeatures?>((ref) => null);
