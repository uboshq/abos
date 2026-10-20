import 'package:flutter/widgets.dart';
import 'package:go_router/go_router.dart';

/// ⭐ প্রোফাইল মেনুর দুই পর্দার পথ (অ্যাপ ০.৪.৪১; মালিকের অনুমোদিত পুরো-পাতার নমুনা, পাতা ২: ছবির মেনুতে
/// "প্রোফাইল ও ছবি বদল" আর "আমার ফোনগুলো"; a6, ২০ অক্টোবর ২০২৬)।
class ProfileRoutes {
  const ProfileRoutes._();

  /// `/home/` এর পরের অংশ — হোমের `openPath` এটাই নেয়
  static const String profile = 'me/profile';
  static const String phones = 'me/phones';

  /// ⭐ ছবির মেনুর সারি থেকে সরাসরি — মেনু বন্ধ করে "আমার ফোনগুলো" খোলে। ⓘ মেনুর ফাইলটা a4-এর
  /// (`lib/core/navigation/top_bar/avatar_menu.dart`); এর জন্য সেখানে একটা সারিই লাগে, নতুন [AvatarChoice] নয়।
  static void openPhonesFromMenu(BuildContext menu) {
    final router = GoRouter.maybeOf(menu);
    Navigator.of(menu).pop();
    router?.push('/home/$phones');
  }
}
