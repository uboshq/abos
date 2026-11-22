import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// ⭐ সার্ভার কোন নতুন কাজ জানে — `/me`-র `features` (a6/me-photo-devices, ২০ অক্টোবর ২০২৬):
/// `{profile_photo: bool, my_phones: bool}`।
///
/// <p>⛔ পুরনো সার্ভার `features` পাঠায় না → দুটোই false: ছবি বদলের অংশ আর "আমার ফোনগুলো" দেখায়ই না (বন্ধ বোতাম নয়)।
/// ⓘ কেবল `true` মানে হ্যাঁ — "1", "yes" বা ভাঙা মান না।
@immutable
class MeFeatures {
  const MeFeatures(
      {this.profilePhoto = false, this.myPhones = false, this.profilePage = false, this.coverPhoto = false, this.messaging = false, this.calls = false});

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

  /// ⭐ বার্তা (বার্তা ও কল, প্রথম ধাপ; ২১ নভেম্বর ২০২৬) — উপরের বারের বার্তা-বোতাম আর তালিকা, "আমি"-র "বার্তা", কাগজের
  /// "বার্তায় পাঠান"; না থাকলে এগুলো দেখায়ই না
  final bool messaging;

  /// ⭐ একজনের সাথে ভয়েস/ভিডিও কল — "আমি"-র "কল" আর চ্যাটের কল-বোতাম; না থাকলে দেখায়ই না
  final bool calls;

  factory MeFeatures.fromJson(Object? raw) {
    if (raw is! Map) return none;
    return MeFeatures(
      profilePhoto: raw['profile_photo'] == true,
      myPhones: raw['my_phones'] == true,
      profilePage: raw['profile_page'] == true,
      coverPhoto: raw['cover_photo'] == true,
      messaging: raw['messaging'] == true,
      calls: raw['calls'] == true,
    );
  }

  @override
  bool operator ==(Object other) =>
      other is MeFeatures &&
      other.profilePhoto == profilePhoto &&
      other.myPhones == myPhones &&
      other.profilePage == profilePage &&
      other.coverPhoto == coverPhoto &&
      other.messaging == messaging &&
      other.calls == calls;

  @override
  int get hashCode => Object.hash(profilePhoto, myPhones, profilePage, coverPhoto, messaging, calls);

  @override
  String toString() => 'MeFeatures(profilePhoto: $profilePhoto, myPhones: $myPhones, profilePage: $profilePage, coverPhoto: $coverPhoto, messaging: $messaging, calls: $calls)';
}

/// হোম `/me` পেলেই লেখে; null = `/me` এখনো আসেনি (যেমন ঠান্ডা শুরুতে সরাসরি লিংক)
final meFeaturesProvider = StateProvider<MeFeatures?>((ref) => null);

/// ⭐ বার্তা চালু কি — Riverpod-ছাড়া পর্দা থেকেও (কাগজের শিট, সারির ⋯); গাছে ProviderScope না থাকলে বা `/me` না এলে false
bool messagingOn(BuildContext context) {
  if (context.getElementForInheritedWidgetOfExactType<UncontrolledProviderScope>() == null) return false;
  return ProviderScope.containerOf(context, listen: false).read(meFeaturesProvider)?.messaging ?? false;
}

/// ⭐ কল চালু কি — চ্যাটের কল/ভিডিও বোতাম; [messagingOn]-এর মতোই, `/me` না এলে false
bool callsOn(BuildContext context) {
  if (context.getElementForInheritedWidgetOfExactType<UncontrolledProviderScope>() == null) return false;
  return ProviderScope.containerOf(context, listen: false).read(meFeaturesProvider)?.calls ?? false;
}
