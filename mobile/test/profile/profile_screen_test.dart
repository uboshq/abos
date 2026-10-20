import 'dart:async';
import 'dart:io';
import 'dart:typed_data';

import 'package:abos_mobile/core/permissions/permission_rationale.dart';
import 'package:abos_mobile/core/profile/face_image.dart';
import 'package:abos_mobile/core/profile/face_picker.dart';
import 'package:abos_mobile/core/profile/profile_api.dart';
import 'package:abos_mobile/features/profile/profile_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:image/image.dart' as img;
import 'package:image_picker/image_picker.dart';

import '../support/main_screens.dart' show setScreenSize;
import 'profile_fakes.dart';
import 'profile_host.dart';

/// ⭐ "প্রোফাইল ও ছবি" (ছবির মেনুর প্রথম সারি; অ্যাপ ০.৪.৪১, a6, ২০ অক্টোবর ২০২৬)।
///
/// <p>দাবি: পর্দা খুললে কোনো অনুমতি বা ছবি-বাছাই নয় — ক্যামেরা কেবল বোতাম চাপলে, আগে বাংলা ব্যাখ্যা; বাছা ছবি বর্গ হয়ে যায়,
/// কত গেল দেখায়, হলে নতুন মুখ সবখানে ([myFaceProvider]); ⛔ বাছা ফাইল ফোনে থাকে না (সফল বা ব্যর্থ); সার্ভারের "না" বাংলায়;
/// সরানোর আগে জিজ্ঞেস; ⭐ পুরনো সার্ভারে "সার্ভার আপডেট হয়নি" আর বোতাম বন্ধ; পর্দা-পাঠকে বাংলা, ৪৮dp, ২০০% লেখা, অন্ধকার।
void main() {
  final fakeJpeg = Uint8List.fromList([0xFF, 0xD8, 0xFF, 0xE0, 9, 9, 0xFF, 0xD9]);
  Future<Uint8List> fakeShrink(Uint8List _) async => fakeJpeg;

  /// ফোনের ক্যাশে image_picker-এর রাখা ফাইলের মতো একটা সত্যিকারের ফাইল
  File pickedFile() {
    final dir = Directory.systemTemp.createTempSync('abos-face-');
    addTearDown(() {
      if (dir.existsSync()) dir.deleteSync(recursive: true);
    });
    final image = img.Image(width: 640, height: 480);
    img.fill(image, color: img.ColorRgb8(30, 120, 200));
    return File('${dir.path}/image_picker_123.jpg')..writeAsBytesSync(img.encodeJpg(image));
  }

  /// ছবি-বাছাইয়ের ভান — কবে কোনটা চাওয়া হলো মনে রাখে
  final asked = <ImageSource>[];
  setUp(asked.clear);

  FacePicker picker({File? file, bool granted = true}) => FacePicker(
        raw: (source) async {
          asked.add(source);
          return file == null ? null : XFile(file.path);
        },
        isGranted: (_) async => granted,
      );

  /// ⓘ ListView কেবল দেখা অংশ বানায় — নিচের সারি আগে টেনে আনা
  Future<void> reach(WidgetTester tester, Key key) async {
    await tester.scrollUntilVisible(find.byKey(key), 80,
        scrollable: find.descendant(of: find.byKey(const ValueKey('profile-screen')), matching: find.byType(Scrollable)).first);
    await tester.pumpAndSettle();
  }

  Future<void> realTap(WidgetTester tester, Finder target) async {
    await tester.runAsync(() async {
      await tester.tap(target);
      for (var i = 0; i < 20; i++) {
        await Future<void>.delayed(const Duration(milliseconds: 20));
      }
    });
    await tester.pumpAndSettle();
  }

  testWidgets('opening the screen asks nothing; the camera explains and asks only after its own tap', (tester) async {
    final api = FakeProfileApi();
    final c = profileContainer();
    await tester.pumpWidget(profileHost(c, ProfileScreen(api: api, picker: picker(granted: false), shrink: fakeShrink)));
    await tester.pumpAndSettle();

    expect(asked, isEmpty, reason: '⛔ পর্দা খুলতেই ছবি-বাছাই বা ক্যামেরা');
    expect(find.byKey(const Key('permission-rationale-camera')), findsNothing, reason: '⛔ পর্দা খুলতেই অনুমতির প্রশ্ন');

    await tester.tap(find.byKey(const ValueKey('profile-gallery')));
    await tester.pumpAndSettle();
    expect(asked, [ImageSource.gallery]);
    expect(find.byKey(const Key('permission-rationale-camera')), findsNothing, reason: 'গ্যালারি = ফোনের photo picker, অনুমতি লাগে না');

    await tester.tap(find.byKey(const ValueKey('profile-camera')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('permission-rationale-camera')), findsOneWidget);
    expect(find.textContaining('প্রোফাইলের ছবি'), findsOneWidget, reason: 'কেন ক্যামেরা — এই পর্দার কারণটাও বলা');
    await tester.tap(find.byKey(const Key('permission-rationale-no')));
    await tester.pumpAndSettle();
    expect(asked, [ImageSource.gallery], reason: '"এখন না" বললে ক্যামেরা খোলেই না');

    await tester.tap(find.byKey(const ValueKey('profile-camera')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('permission-rationale-ok')));
    await tester.pumpAndSettle();
    expect(asked, [ImageSource.gallery, ImageSource.camera]);
    expect(api.uploads, isEmpty, reason: 'কিছু বাছা হয়নি — কিছু যায়নি');
  });

  testWidgets('a picked photo goes as the squared JPEG, the face changes everywhere, and the picked file is gone', (tester) async {
    final file = pickedFile();
    final api = FakeProfileApi();
    final c = profileContainer(face: oldFace);
    await tester.pumpWidget(profileHost(c, ProfileScreen(api: api, picker: picker(file: file), shrink: fakeShrink)));
    await tester.pumpAndSettle();

    await realTap(tester, find.byKey(const ValueKey('profile-gallery')));

    expect(api.uploads, [fakeJpeg], reason: 'পাঠানো হলো বর্গ-করা JPEG, বাছা ফাইলটা নয়');
    expect(c.read(myFaceProvider), newFace, reason: 'নতুন মুখ সবখানে — হোম এটা শোনে');
    expect(find.text('ছবি বদলানো হলো — ওয়েবেও এই মুখ দেখাবে।'), findsOneWidget);
    expect(file.existsSync(), isFalse, reason: '⛔ বাছা ছবি ফোনের ক্যাশে রয়ে গেল');
    final face = tester.widget<Image>(find.byKey(const ValueKey('profile-photo')));
    expect((face.image as ResizeImage).imageProvider, const FaceImage(newFace), reason: 'পর্দার মুখও টোকেনসহ নতুনটা');
  });

  testWidgets('with the real squaring the server gets a 512 square JPEG', (tester) async {
    final file = pickedFile();
    final api = FakeProfileApi();
    final c = profileContainer();
    await tester.pumpWidget(profileHost(c, ProfileScreen(api: api, picker: picker(file: file))));
    await tester.pumpAndSettle();
    await tester.runAsync(() async {
      await tester.tap(find.byKey(const ValueKey('profile-gallery')));
      for (var i = 0; i < 100 && api.uploads.isEmpty; i++) {
        await Future<void>.delayed(const Duration(milliseconds: 50));
      }
    });
    await tester.pumpAndSettle();
    final sent = img.decodeJpg(api.uploads.single)!;
    expect((sent.width, sent.height), (480, 480), reason: '৬৪০×৪৮০ → ছোট দিকের বর্গ, বড় করা নয়');
    expect(file.existsSync(), isFalse);
  });

  testWidgets('a refused photo (422) shows the server\'s Bengali sentence; the face stays; the picked file is gone too', (tester) async {
    final file = pickedFile();
    final api = FakeProfileApi(uploadAnswer: (_) async => throw const PhotoRefused('ফোন থেকে কেবল JPG বা PNG ছবি দেওয়া যায়।'));
    final c = profileContainer(face: oldFace);
    await tester.pumpWidget(profileHost(c, ProfileScreen(api: api, picker: picker(file: file), shrink: fakeShrink)));
    await tester.pumpAndSettle();

    await realTap(tester, find.byKey(const ValueKey('profile-camera')));

    expect(find.text('ফোন থেকে কেবল JPG বা PNG ছবি দেওয়া যায়।'), findsOneWidget);
    expect(c.read(myFaceProvider), oldFace);
    expect(file.existsSync(), isFalse, reason: '⛔ ফেরানো ছবিও ফোনে থেকে গেল');
    expect(tester.widget<FilledButton>(find.byKey(const ValueKey('profile-camera'))).onPressed, isNotNull, reason: 'আবার চেষ্টা করা যায়');
  });

  testWidgets('a picture that cannot be read says so in Bengali and sends nothing', (tester) async {
    final file = pickedFile();
    final api = FakeProfileApi();
    final c = profileContainer();
    await tester.pumpWidget(profileHost(
        c, ProfileScreen(api: api, picker: picker(file: file), shrink: (_) async => throw const FormatException('x'))));
    await tester.pumpAndSettle();
    await realTap(tester, find.byKey(const ValueKey('profile-gallery')));
    expect(find.text('ছবিটা পড়া গেল না — আরেকটা ছবি বাছুন।'), findsOneWidget);
    expect(api.uploads, isEmpty);
    expect(file.existsSync(), isFalse);
  });

  testWidgets('while it goes: progress in English digits, said in Bengali, and the buttons wait', (tester) async {
    final file = pickedFile();
    final gate = Completer<PhotoChange>();
    final api = FakeProfileApi(uploadAnswer: (_) => gate.future);
    final c = profileContainer();
    await tester.pumpWidget(profileHost(c, ProfileScreen(api: api, picker: picker(file: file), shrink: fakeShrink)));
    await tester.pumpAndSettle();
    await tester.runAsync(() async {
      await tester.tap(find.byKey(const ValueKey('profile-gallery')));
      for (var i = 0; i < 50 && api.uploads.isEmpty; i++) {
        await Future<void>.delayed(const Duration(milliseconds: 20));
      }
    });
    await tester.pump();
    expect(find.text('ছবি পাঠানো হচ্ছে… 45%'), findsOneWidget);
    final handle = tester.ensureSemantics();
    await tester.pump();
    expect(find.bySemanticsLabel('ছবি পাঠানো হচ্ছে, 45 শতাংশ'), findsOneWidget);
    handle.dispose();
    expect(tester.widget<FilledButton>(find.byKey(const ValueKey('profile-camera'))).onPressed, isNull, reason: 'পাঠানোর মাঝে আরেকটা নয়');

    await tester.runAsync(() async {
      gate.complete(const PhotoChange(photoUrl: newFace));
      await Future<void>.delayed(const Duration(milliseconds: 50));
    });
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('profile-progress')), findsNothing);
    expect(c.read(myFaceProvider), newFace);
  });

  testWidgets('removing asks first: "না" keeps the face, "সরান" removes it everywhere', (tester) async {
    final api = FakeProfileApi();
    final c = profileContainer(face: oldFace);
    await tester.pumpWidget(profileHost(c, ProfileScreen(api: api, picker: picker(), faceOf: (_) => MemoryImage(onePixelPng))));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('profile-remove')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('profile-remove-confirm')), findsOneWidget);
    expect(find.text('ছবিটা সরাবেন?'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('profile-remove-no')));
    await tester.pumpAndSettle();
    expect(api.removals, 0);
    expect(c.read(myFaceProvider), oldFace);

    await tester.tap(find.byKey(const ValueKey('profile-remove')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('profile-remove-yes')));
    await tester.pumpAndSettle();
    expect(api.removals, 1);
    expect(c.read(myFaceProvider), isNull);
    expect(find.byKey(const ValueKey('profile-initial')), findsOneWidget, reason: 'মুখের জায়গায় নামের প্রথম অক্ষর');
    expect(find.text('ছবি সরানো হলো।'), findsOneWidget);
    expect(tester.widget<OutlinedButton>(find.byKey(const ValueKey('profile-remove'))).onPressed, isNull, reason: 'সরানোর কিছু নেই');
  });

  testWidgets('my details show as on the web: name, designation, company · branch, email', (tester) async {
    final c = profileContainer();
    await tester.pumpWidget(profileHost(c, ProfileScreen(api: FakeProfileApi(), picker: picker())));
    await tester.pumpAndSettle();
    expect(find.text('আল-আমিন শুভ'), findsWidgets);
    expect(find.byKey(const ValueKey('profile-designation')), findsOneWidget);
    expect(find.text('ADI Wholesale · সব শাখা'), findsOneWidget);
    expect(find.text('alamin@adi.test'), findsOneWidget);
  });

  testWidgets('an old server: a Bengali "server not updated" note, the three buttons off, my details still there', (tester) async {
    final api = FakeProfileApi(doors: false);
    final c = profileContainer(face: oldFace);
    await tester.pumpWidget(profileHost(c, ProfileScreen(api: api, picker: picker(), faceOf: (_) => MemoryImage(onePixelPng))));
    await tester.pumpAndSettle();
    expect(api.doorChecks, 1);
    expect(find.byKey(const ValueKey('profile-server-old')), findsOneWidget);
    expect(find.textContaining('সার্ভার আপডেট হয়নি'), findsOneWidget);
    for (final key in ['profile-camera', 'profile-gallery']) {
      expect(tester.widget<ButtonStyleButton>(find.byKey(ValueKey(key))).onPressed, isNull, reason: key);
    }
    expect(tester.widget<ButtonStyleButton>(find.byKey(const ValueKey('profile-remove'))).onPressed, isNull);
    expect(find.text('alamin@adi.test'), findsOneWidget);
  });

  testWidgets('no signal at open is not "old server": the buttons stay on', (tester) async {
    final c = profileContainer();
    await tester.pumpWidget(profileHost(c, ProfileScreen(api: FakeProfileApi(doors: null), picker: picker())));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('profile-server-old')), findsNothing);
    expect(tester.widget<FilledButton>(find.byKey(const ValueKey('profile-camera'))).onPressed, isNotNull);
  });

  testWidgets('"আমার ফোনগুলো" opens from here', (tester) async {
    var opened = 0;
    final c = profileContainer();
    await tester.pumpWidget(profileHost(c, ProfileScreen(api: FakeProfileApi(), picker: picker(), openPhones: () => opened++)));
    await tester.pumpAndSettle();
    await reach(tester, const ValueKey('profile-phones'));
    await tester.tap(find.byKey(const ValueKey('profile-phones')));
    expect(opened, 1);
  });

  group('accessibility', () {
    for (final dark in [false, true]) {
      testWidgets('TalkBack hears Bengali, every target is 48dp, text has contrast — ${dark ? 'dark' : 'light'}', (tester) async {
        final handle = tester.ensureSemantics();
        final c = profileContainer(face: oldFace);
        await tester.pumpWidget(
            profileHost(c, ProfileScreen(api: FakeProfileApi(), picker: picker(), faceOf: (_) => MemoryImage(onePixelPng)), dark: dark));
        await tester.pumpAndSettle();

        expect(find.bySemanticsLabel('ক্যামেরায় তুলুন'), findsOneWidget);
        expect(find.bySemanticsLabel('গ্যালারি থেকে'), findsOneWidget);
        expect(find.bySemanticsLabel('ছবি সরান'), findsOneWidget);
        expect(find.bySemanticsLabel('আপনার ছবি'), findsOneWidget);
        expect(find.bySemanticsLabel(RegExp(r'^[A-Za-z ]+$')), findsNothing, reason: '⛔ ইংরেজি পর্দা-পাঠ');
        await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
        await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
        await expectLater(tester, meetsGuideline(textContrastGuideline));
        if (dark) {
          final bg = tester.widget<Scaffold>(find.byType(Scaffold).first);
          expect(Theme.of(tester.element(find.byType(ListView).first)).brightness, Brightness.dark);
          expect(bg.backgroundColor, isNull, reason: 'পট থিমের, বাঁধা নয়');
        }
        handle.dispose();
      });
    }

    testWidgets('without a face TalkBack says there is none', (tester) async {
      final handle = tester.ensureSemantics();
      final c = profileContainer();
      await tester.pumpWidget(profileHost(c, ProfileScreen(api: FakeProfileApi(), picker: picker())));
      await tester.pumpAndSettle();
      expect(find.bySemanticsLabel('আপনার ছবি নেই — নামের প্রথম অক্ষর দেখানো হচ্ছে'), findsOneWidget);
      handle.dispose();
    });

    for (final width in [320.0, 360.0]) {
      testWidgets('200% text at ${width.toInt()}dp: nothing overflows, every button still reachable', (tester) async {
        setScreenSize(tester, Size(width, 720));
        final c = profileContainer(face: oldFace);
        await tester.pumpWidget(profileHost(
            c, ProfileScreen(api: FakeProfileApi(doors: false), picker: picker(), faceOf: (_) => MemoryImage(onePixelPng)),
            scale: 2));
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull);
        for (final key in ['profile-camera', 'profile-gallery', 'profile-remove', 'profile-phones']) {
          await reach(tester, ValueKey(key));
          expect(tester.getSize(find.byKey(ValueKey(key))).height, greaterThanOrEqualTo(48), reason: key);
        }
        expect(tester.takeException(), isNull);
      });
    }
  });

  test('the camera reason names the profile photo too', () {
    expect(PermissionRationale.cameraBody, contains('প্রোফাইলের ছবি'));
    final plist = File('ios/Runner/Info.plist').readAsStringSync();
    expect(plist, contains('<key>NSCameraUsageDescription</key>'));
    expect(plist, contains('<key>NSPhotoLibraryUsageDescription</key>'));
    expect(RegExp(r'<key>NSCameraUsageDescription</key>\s*<string>[^<]*[ঀ-৿]').hasMatch(plist), isTrue, reason: 'বাংলায়');
  });
}
