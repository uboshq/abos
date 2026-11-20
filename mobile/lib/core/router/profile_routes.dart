import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../auth/me_features.dart';
import '../config/app_channel.dart';
import '../locale/app_locale.dart';

/// ⭐ প্রোফাইল মেনুর দুই পর্দার পথ (অ্যাপ ০.৪.৪১; মালিকের অনুমোদিত পুরো-পাতার নমুনা, পাতা ২: ছবির মেনুতে
/// "প্রোফাইল ও ছবি বদল" আর "আমার ফোনগুলো"; a6, ২০ অক্টোবর ২০২৬)।
class ProfileRoutes {
  const ProfileRoutes._();

  /// `/home/` এর পরের অংশ — হোমের `openPath` এটাই নেয়
  static const String profile = 'me/profile';
  static const String phones = 'me/phones';
  // ⭐ অফিসের SMS ফোন (নমুনা ধাপ ২) — কেবল যাঁর হাতে notification.sms_gateway
  static const String smsGateway = 'me/sms-gateway';

  /// ⛔ Google Play-র বিল্ডে অফিসের SMS ফোন নেই — Play SEND_SMS দেয় কেবল ডিফল্ট SMS অ্যাপকে (২০ নভেম্বর ২০২৬)।
  /// play-এ প্রোফাইলের সারি নেই, পথটাও নেই; ম্যানিফেস্টে অনুমতি আর সেবাও সরানো (android/app/src/play/AndroidManifest.xml)।
  static bool get smsGatewayInThisBuild => !AppChannel.isPlay;

  /// ⭐ এই সার্ভারে কী আছে — মেনু (a4) এখান থেকে পড়ে; `/me` না এলে বা Riverpod না থাকলে (একা মেনুর টেস্ট) [MeFeatures.none]
  static MeFeatures featuresOf(BuildContext context) {
    try {
      return ProviderScope.containerOf(context, listen: false).read(meFeaturesProvider) ?? MeFeatures.none;
    } catch (_) {
      return MeFeatures.none;
    }
  }

  /// মেনুর প্রথম সারির নাম — ছবি বদল থাকলে "প্রোফাইল ও ছবি বদল", নইলে "আমার প্রোফাইল" (কোনো প্রতিশ্রুতি নয়)
  static String profileTitle(MeFeatures features) =>
      features.profilePhoto ? AppLocale.l10n.profileMenuProfileAndPhoto : AppLocale.l10n.profileMenuMyProfile;

  /// "আমার ফোনগুলো" সারি দেখাবে কি না
  static bool showsPhones(MeFeatures features) => features.myPhones;

  /// ⭐ ছবির মেনুর সারি থেকে সরাসরি — মেনু বন্ধ করে "আমার ফোনগুলো" খোলে। ⓘ মেনুর ফাইলটা a4-এর
  /// (`lib/core/navigation/top_bar/avatar_menu.dart`); এর জন্য সেখানে একটা সারিই লাগে, নতুন [AvatarChoice] নয়।
  static void openPhonesFromMenu(BuildContext menu) {
    final router = GoRouter.maybeOf(menu);
    Navigator.of(menu).pop();
    router?.push('/home/$phones');
  }
}
