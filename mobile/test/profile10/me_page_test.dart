import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/auth/me_features.dart';
import 'package:abos_mobile/core/profile/face_image.dart';
import 'package:abos_mobile/core/profile/me_profile_api.dart';
import '../fakes/fake_me_profile_api.dart';
import 'package:abos_mobile/core/theme/app_look.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/theme/glass.dart';
import 'package:abos_mobile/features/profile/me_page.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../profile/profile_fakes.dart';
import '../support/main_screens.dart' show setScreenSize;

/// ⭐ Facebook-ধাঁচের "আমি" পাতা (আইটেম ১০; মালিকের অনুমোদিত নমুনা "ক", ২০ নভেম্বর ২০২৬) — দশ অংশ, পুরনো সার্ভারের পথ,
/// মলাটের বোতাম, "শীঘ্রই", রূপ বদল, আর তিন রূপে দুই ফোনের মাপে কিছুই উপচে পড়ে না।
void main() {
  late AppLooks saved;
  setUp(() {
    saved = AppLooks.instance;
    final box = <String, String>{};
    AppLooks.instance = AppLooks(read: (k) async => box[k], write: (k, v) async => box[k] = v);
  });
  tearDown(() => AppLooks.instance = saved);

  const all = MeFeatures(profilePhoto: true, myPhones: true, profilePage: true, coverPhoto: true);

  final fallback = MeProfile.fallback(
    name: 'শুভ আহমেদ',
    designation: 'মালিক',
    company: 'ADI Corporation',
    branch: 'TD শাখা',
    email: 'shuvo@example.com',
  );

  ({ProviderContainer c, FakeMeProfileApi me}) container(MeFeatures features) {
    final me = FakeMeProfileApi();
    final c = ProviderContainer(overrides: [
      authStateProvider.overrideWith((ref) => SignedIn()),
      meProfileApiProvider.overrideWithValue(me),
    ]);
    addTearDown(c.dispose);
    c.read(meFeaturesProvider.notifier).state = features;
    c.read(myFaceProvider.notifier).state = null;
    return (c: c, me: me);
  }

  MePage page({bool security = true}) => MePage(
        fallback: fallback,
        profileApi: FakeProfileApi(),
        now: () => DateTime(2026, 11, 20, 12),
        onEditProfile: () {},
        onSeeAllRecent: security ? () {} : null,
        onPassword: security ? () {} : null,
        passwordChangedAt: DateTime(2026, 10, 9, 12),
        onAppLockToggle: security ? (_) {} : null,
        onLockDelay: security ? () {} : null,
      );

  Widget host(ProviderContainer c, Widget body, AppLook look) {
    AppLooks.instance.look.value = look;
    final theme = switch (look) {
      AppLook.glass => AppTheme.glass(GlassSpec.of(40)),
      AppLook.expressive => AppTheme.expressive(),
      AppLook.abos => AppTheme.light(),
    };
    return UncontrolledProviderScope(
      container: c,
      child: MaterialApp(
        debugShowCheckedModeBanner: false,
        theme: theme,
        home: Scaffold(backgroundColor: look == AppLook.glass ? const Color(0xFF3A0C4F) : null, body: body),
      ),
    );
  }

  Finder key(String k) => find.byKey(ValueKey(k));

  testWidgets('all ten sections render from the sample data', (tester) async {
    setScreenSize(tester, const Size(412, 4200));
    final (:c, :me) = container(all);
    await tester.pumpWidget(host(c, page(), AppLook.expressive));
    await tester.pumpAndSettle();

    expect(me.calls, contains('profile'));
    // ১. মলাট আর তার বোতাম
    expect(key('me-cover'), findsOneWidget);
    expect(find.text('মলাট বদলান'), findsOneWidget);
    // ২. বড় গোল ছবি আর ক্যামেরা
    expect(key('me-initial'), findsOneWidget);
    expect(key('me-photo-change'), findsOneWidget);
    // ৩. নাম, পদবি, কোম্পানি · শাখা
    expect(find.text('শুভ আহমেদ'), findsOneWidget);
    expect(find.text('মালিক'), findsOneWidget);
    expect(find.text('ADI Corporation · TD শাখা'), findsOneWidget);
    // ৪. বোতাম
    expect(find.text('প্রোফাইল ঠিক করুন'), findsOneWidget);
    expect(find.text('বার্তা'), findsOneWidget);
    expect(find.text('কল'), findsOneWidget);
    expect(find.text('শীঘ্রই'), findsNWidgets(2));
    // ৫. এই মাসে আমার কাজ — তিনটা ঘর
    expect(find.text('এই মাসে আমার কাজ'), findsOneWidget);
    expect(find.text('নভেম্বর 2026'), findsOneWidget);
    expect(find.text('৳1,24,50,000'), findsOneWidget);
    expect(find.text('86'), findsOneWidget);
    expect(find.text('312'), findsOneWidget);
    // ৬. পরিচয় — ছয় সারি
    for (final text in ['পরিচয়', '01711-234567', 'shuvo@example.com', 'EMP-0001', '01 জানুয়ারি 2019', 'মালিক — সব অনুমতি', 'TD সহ সব শাখা']) {
      expect(find.text(text), findsOneWidget, reason: text);
    }
    // ৭. সাম্প্রতিক কাজ — চারটা, সময় নমুনার মতো
    expect(find.text('আমার সাম্প্রতিক কাজ'), findsOneWidget);
    expect(find.text('সব দেখুন'), findsOneWidget);
    for (final no in ['RCV-0142', 'JV-0318', 'SO-1027', 'PAY-0089']) {
      expect(key('me-recent-$no'), findsOneWidget, reason: no);
    }
    expect(find.text('2 ঘণ্টা আগে'), findsOneWidget);
    expect(find.text('5 ঘণ্টা আগে'), findsOneWidget);
    expect(find.text('গতকাল'), findsOneWidget);
    expect(find.text('2 দিন আগে'), findsOneWidget);
    // ৮. আমার ফোনগুলো
    expect(find.text('আমার ফোনগুলো'), findsOneWidget);
    expect(find.text('বাদ দিন'), findsNWidgets(2));
    expect(find.text('এই ফোন'), findsOneWidget);
    // ৯. নিরাপত্তা
    expect(find.text('নিরাপত্তা'), findsOneWidget);
    expect(find.text('পাসওয়ার্ড'), findsOneWidget);
    expect(find.text('শেষ বদল 42 দিন আগে'), findsOneWidget);
    expect(find.text('আঙুলের ছাপ ও পিন'), findsOneWidget);
    expect(find.byType(Switch), findsOneWidget);
    expect(find.text('কতক্ষণ পরে তালা'), findsOneWidget);
    expect(find.text('5 মিনিট'), findsOneWidget);
    // ১০. চেহারা
    expect(find.text('চেহারা'), findsOneWidget);
    for (final name in ['স্মার্ট', 'গ্লাস', 'ক্লাসিক']) {
      expect(find.text(name), findsOneWidget, reason: name);
    }
  });

  testWidgets('a null callback hides its security row; none at all hides the section', (tester) async {
    setScreenSize(tester, const Size(412, 4200));
    final (:c, me: _) = container(all);
    await tester.pumpWidget(host(c, page(security: false), AppLook.abos));
    await tester.pumpAndSettle();
    expect(key('me-security'), findsNothing);
    expect(find.text('সব দেখুন'), findsNothing);
  });

  testWidgets('old server: drawn from the fallback, no month, about or recent, and nothing asked', (tester) async {
    setScreenSize(tester, const Size(412, 4200));
    final (:c, :me) = container(const MeFeatures(profilePhoto: true, myPhones: true));
    await tester.pumpWidget(host(c, page(), AppLook.abos));
    await tester.pumpAndSettle();

    expect(me.calls, isNot(contains('profile')), reason: '⛔ পুরনো সার্ভারে /me/profile ডাকা নয়');
    expect(find.text('শুভ আহমেদ'), findsOneWidget);
    expect(find.text('ADI Corporation · TD শাখা'), findsOneWidget);
    expect(key('me-month'), findsNothing);
    expect(key('me-about'), findsNothing);
    expect(key('me-recent'), findsNothing);
    expect(key('me-phones'), findsOneWidget);
    expect(key('me-look'), findsOneWidget);
    expect(key('me-cover-change'), findsNothing, reason: 'cover_photo false');
  });

  testWidgets('cover_photo false: no "change cover" button; the cover is the colour gradient', (tester) async {
    setScreenSize(tester, const Size(412, 4200));
    final (:c, me: _) = container(const MeFeatures(profilePhoto: true, myPhones: true, profilePage: true));
    await tester.pumpWidget(host(c, page(), AppLook.glass));
    await tester.pumpAndSettle();
    expect(key('me-cover'), findsOneWidget);
    expect(key('me-cover-colour'), findsOneWidget);
    expect(key('me-cover-change'), findsNothing);
    expect(find.text('মলাট বদলান'), findsNothing);
    expect(key('me-month'), findsOneWidget);
  });

  testWidgets('Message and Call only say "coming soon"', (tester) async {
    setScreenSize(tester, const Size(412, 915));
    final (:c, me: _) = container(all);
    await tester.pumpWidget(host(c, page(), AppLook.abos));
    await tester.pumpAndSettle();

    await tester.tap(key('me-message'));
    await tester.pump();
    expect(find.text('শীঘ্রই আসছে'), findsOneWidget);
    await tester.pumpAndSettle(const Duration(seconds: 5));

    await tester.tap(key('me-call'));
    await tester.pump();
    expect(find.text('শীঘ্রই আসছে'), findsOneWidget);
  });

  testWidgets('the look segment switches the app look', (tester) async {
    setScreenSize(tester, const Size(412, 4200));
    final (:c, me: _) = container(all);
    await tester.pumpWidget(host(c, page(), AppLook.abos));
    await tester.pumpAndSettle();

    await tester.tap(find.text('গ্লাস'));
    await tester.pumpAndSettle();
    expect(AppLooks.instance.look.value, AppLook.glass);

    await tester.tap(find.text('স্মার্ট'));
    await tester.pumpAndSettle();
    expect(AppLooks.instance.look.value, AppLook.expressive);

    await tester.tap(find.text('ক্লাসিক'));
    await tester.pumpAndSettle();
    expect(AppLooks.instance.look.value, AppLook.abos);
  });

  for (final look in AppLook.values) {
    for (final size in const [Size(360, 800), Size(412, 915)]) {
      testWidgets('${look.name} at ${size.width.toInt()}×${size.height.toInt()} draws top to bottom without overflow', (tester) async {
        setScreenSize(tester, size);
        final (:c, me: _) = container(all);
        await tester.pumpWidget(host(c, page(), look));
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull);
        await tester.dragUntilVisible(key('me-look'), find.byKey(const ValueKey('me-page-10')), const Offset(0, -250));
        await tester.pumpAndSettle();
        expect(key('me-look'), findsOneWidget);
        expect(tester.takeException(), isNull);
      });
    }
  }
}
