import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// ⭐ সার্ভার কোন নতুন কাজ জানে — `/me`-র `features` (a6/me-photo-devices, ২০ অক্টোবর ২০২৬):
/// `{profile_photo: bool, my_phones: bool}`।
///
/// <p>⛔ পুরনো সার্ভার `features` পাঠায় না → দুটোই false: ছবি বদলের অংশ আর "আমার ফোনগুলো" দেখায়ই না (বন্ধ বোতাম নয়)।
/// ⓘ কেবল `true` মানে হ্যাঁ — "1", "yes" বা ভাঙা মান না।
@immutable
class MeFeatures {
  const MeFeatures({this.profilePhoto = false, this.myPhones = false});

  /// পুরনো সার্ভার — কিছুই নেই
  static const MeFeatures none = MeFeatures();

  /// ছবি তোলা, বদল আর সরানো (`POST/DELETE /me/photo`)
  final bool profilePhoto;

  /// "আমার ফোনগুলো" আর দূর থেকে বের করা (`/me/devices`)
  final bool myPhones;

  factory MeFeatures.fromJson(Object? raw) {
    if (raw is! Map) return none;
    return MeFeatures(profilePhoto: raw['profile_photo'] == true, myPhones: raw['my_phones'] == true);
  }

  @override
  bool operator ==(Object other) => other is MeFeatures && other.profilePhoto == profilePhoto && other.myPhones == myPhones;

  @override
  int get hashCode => Object.hash(profilePhoto, myPhones);

  @override
  String toString() => 'MeFeatures(profilePhoto: $profilePhoto, myPhones: $myPhones)';
}

/// হোম `/me` পেলেই লেখে; null = `/me` এখনো আসেনি (যেমন ঠান্ডা শুরুতে সরাসরি লিংক)
final meFeaturesProvider = StateProvider<MeFeatures?>((ref) => null);
