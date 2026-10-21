import 'package:abos_mobile/core/auth/me_features.dart';
import 'package:abos_mobile/core/auth/session_profile.dart';
import 'package:abos_mobile/core/router/profile_routes.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ `/me`-র `features` — সার্ভার ছবি বদল আর "আমার ফোনগুলো" জানে কি না (a6/me-photo-devices, ২০ অক্টোবর ২০২৬)।
///
/// <p>দাবি: `features` না থাকলে (পুরনো সার্ভার) দুটোই false; কেবল সত্যিকারের `true` মানে হ্যাঁ; মেনুর (a4) সাহায্যকারীরা
/// নাম আর সারি সেই অনুযায়ী দেয়, Riverpod না থাকলে কিছুই প্রতিশ্রুতি দেয় না।
void main() {
  Map<String, dynamic> me([Object? features]) => {
        'company': {'public_id': 'c1', 'code': 'ADI', 'name': 'ADI Wholesale'},
        'branch': {'public_id': 'b1', 'code': 'MMS', 'name': 'ময়মনসিংহ'},
        if (features != null) 'features': features,
      };

  test('an old server sends no features: both are off', () {
    expect(SessionProfile.fromJson(me()).features, MeFeatures.none);
    expect(MeFeatures.none.profilePhoto, isFalse);
    expect(MeFeatures.none.myPhones, isFalse);
    expect(MeFeatures.fromJson('yes'), MeFeatures.none);
  });

  test('a new server\'s features are read as they are', () {
    expect(SessionProfile.fromJson(me({'profile_photo': true, 'my_phones': true})).features,
        const MeFeatures(profilePhoto: true, myPhones: true));
    expect(SessionProfile.fromJson(me({'profile_photo': true, 'my_phones': false})).features, const MeFeatures(profilePhoto: true));
    expect(SessionProfile.fromJson(me({'my_phones': true})).features, const MeFeatures(myPhones: true));
  });

  test('only a real true is yes — "1", 1 or "true" are not', () {
    expect(MeFeatures.fromJson(const {'profile_photo': 1, 'my_phones': 'true'}), MeFeatures.none);
    expect(MeFeatures.fromJson(const {'profile_photo': '1'}), MeFeatures.none);
  });

  test('the menu helpers: the row name and whether "আমার ফোনগুলো" shows', () {
    expect(ProfileRoutes.profileTitle(const MeFeatures(profilePhoto: true)), 'প্রোফাইল ও ছবি বদল');
    expect(ProfileRoutes.profileTitle(MeFeatures.none), 'আমার প্রোফাইল');
    expect(ProfileRoutes.showsPhones(const MeFeatures(myPhones: true)), isTrue);
    expect(ProfileRoutes.showsPhones(const MeFeatures(profilePhoto: true)), isFalse);
  });

  testWidgets('the menu reads features from its context; without Riverpod or before /me it promises nothing', (tester) async {
    late MeFeatures seen;
    Widget probe() => Builder(builder: (context) {
          seen = ProfileRoutes.featuresOf(context);
          return const SizedBox();
        });

    await tester.pumpWidget(MaterialApp(home: probe()));
    expect(seen, MeFeatures.none, reason: 'Riverpod নেই (a4-এর একা মেনুর টেস্ট)');

    final c = ProviderContainer();
    addTearDown(c.dispose);
    await tester.pumpWidget(UncontrolledProviderScope(container: c, child: MaterialApp(home: probe())));
    expect(seen, MeFeatures.none, reason: '/me এখনো আসেনি');

    c.read(meFeaturesProvider.notifier).state = const MeFeatures(profilePhoto: true, myPhones: true);
    await tester.pumpWidget(UncontrolledProviderScope(container: c, child: MaterialApp(home: Builder(builder: (context) {
      seen = ProfileRoutes.featuresOf(context);
      return const Text('x');
    }))));
    expect(seen, const MeFeatures(profilePhoto: true, myPhones: true));
  });
}
