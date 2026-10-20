import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/navigation/nav_state.dart';

import 'bar_harness.dart';

/// ⭐ ছবি-টেস্ট — ৪ ভূমিকার নিচের বার × ৩২০/৩৬০/৪১২dp ফোন আর ট্যাবলেট (৮০০dp, খাড়া বার) × আলো আর অন্ধকার
/// (বারের নকশা ৩, অংশ ৬ আর ৭)। আসল ফন্টে আঁকা: লাতিনে Roboto, বাংলায় Shonar Bangla, আইকনে Material Symbols।
///
/// <p>ছবি `test/navigation/goldens/bottom_<ভূমিকা>_<মাপ>_<light|dark>.png`। চেহারা ইচ্ছে করে বদলালে `--update-goldens`।
void main() {
  setUpAll(loadRealFonts);

  const sizes = {
    '320': Size(320, 640),
    '360': Size(360, 800),
    '412': Size(412, 915),
    'tablet': Size(800, 1280),
  };

  // প্রতিটা ভূমিকা তার নিজের ঘর বাছা অবস্থায় — লাল গোনাসহ, যেখানে অনুমোদন আছে
  const opened = {
    'owner': '/home',
    'sr': '/home/collections',
    'depot': '/home/challans',
    'accounts': '/home/vouchers',
  };

  for (final role in rolePermissions.keys) {
    for (final MapEntry(key: size, value: dp) in sizes.entries) {
      for (final dark in [false, true]) {
        final name = 'bottom_${role}_${size}_${dark ? 'dark' : 'light'}';
        testWidgets(name, (tester) async {
          // ⓘ আসল ছায়া — নইলে টেস্ট ছায়ার জায়গায় কালো রেখা আঁকে
          debugDisableShadows = false;
          try {
          await setScreen(tester, dp, ratio: 2);
          await tester.pumpWidget(barApp(
            barRouter(initial: opened[role]!),
            role: role,
            dark: dark,
            mayCollect: role == 'sr',
            badges: const NavBadges(pending: 5),
            themed: realFontTheme,
          ));
          await tester.pumpAndSettle();
          await expectLater(find.byType(MaterialApp), matchesGoldenFile('goldens/$name.png'));
          } finally {
            debugDisableShadows = true;
          }
        });
      }
    }
  }
}
