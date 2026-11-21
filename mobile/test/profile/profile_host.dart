import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/auth/me_features.dart';
import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/navigation/top_bar/top_bar_context.dart';
import 'package:abos_mobile/core/profile/face_image.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'profile_fakes.dart';

/// হোম যা লেখে — কোম্পানি, শাখা, পদবি (নমুনার নাম)। ⓘ "সব শাখা" অ্যাপের নিজের কথা (হোম ARB-এর homeAllBranches বসায়),
/// তাই চলতি ভাষায়; পদবি সার্ভারের, সার্ভার মানুষের ভাষায় পাঠায় — ইংরেজিতে "Owner"। ⛔ আগে বাঁধা বাংলা: ইংরেজির ছবিতে
/// "ADI · সব শাখা" (phones_360_light_en, ছবি-নিরীক্ষা, ২২ অক্টোবর ২০২৬)
TopBarContext topBar() => TopBarContext(
      company: 'ADI Wholesale',
      companyCode: 'ADI',
      branch: AppLocale.l10n.homeAllBranches,
      designation: AppLocale.isEnglish() ? 'Owner' : 'মালিক',
      search: (_) async {},
      openNotifications: (_) async {},
      openMenu: (_, __) async {},
      messages: (_) async {},
    );

/// নতুন সার্ভার — দুটোই আছে
const allFeatures = MeFeatures(profilePhoto: true, myPhones: true);

ProviderContainer profileContainer({SignedIn? auth, String? face, bool withBar = true, MeFeatures? features = allFeatures}) {
  final c = ProviderContainer(overrides: [
    authStateProvider.overrideWith((ref) => auth ?? SignedIn()),
    if (withBar) topBarContextProvider.overrideWith((ref) => topBar()),
  ]);
  c.read(myFaceProvider.notifier).state = face;
  c.read(meFeaturesProvider.notifier).state = features;
  return c;
}

Widget profileHost(
  ProviderContainer c,
  Widget screen, {
  bool dark = false,
  double scale = 1,
  ThemeData Function(ThemeData)? font,
}) {
  final light = AppTheme.light();
  final darkTheme = AppTheme.dark();
  return UncontrolledProviderScope(
    container: c,
    child: MaterialApp(
      debugShowCheckedModeBanner: false,
      theme: font == null ? light : font(light),
      darkTheme: font == null ? darkTheme : font(darkTheme),
      themeMode: dark ? ThemeMode.dark : ThemeMode.light,
      builder: (context, inner) =>
          MediaQuery(data: MediaQuery.of(context).copyWith(textScaler: TextScaler.linear(scale)), child: inner!),
      home: screen,
    ),
  );
}
