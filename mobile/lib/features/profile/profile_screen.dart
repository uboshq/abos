import 'look_screen.dart';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/auth/auth_state.dart';
import '../../core/auth/me_features.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/navigation/top_bar/top_bar_context.dart';
import '../../core/profile/face_image.dart';
import '../../core/profile/face_picker.dart';
import '../../core/profile/profile_api.dart';
import '../../core/profile/square_face.dart';
import '../../core/router/profile_routes.dart';
import '../../core/theme/app_look.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/theme/look_widgets.dart';
import '../../core/locale/l10n.dart';
import '../../core/locale/app_locale.dart';

/// ⭐ "প্রোফাইল ও ছবি" — নিজের তথ্য আর নিজের মুখ বদল (ছবির মেনুর প্রথম সারি; মালিকের অনুমোদিত নমুনা, পাতা ২; অ্যাপ ০.৪.৪১,
/// a6, ২০ অক্টোবর ২০২৬)।
///
/// <p>তিন বোতাম: "ক্যামেরায় তুলুন", "গ্যালারি থেকে", "ছবি সরান" (আগে জিজ্ঞেস)। বাছা ছবি ফোনেই মাঝখান থেকে বর্গ, ৫১২ বিন্দুর
/// JPEG, EXIF ছাড়া ([squareFace]); পাঠানোর সময় কত গেল তা দেখায়; সার্ভারের "না" বাংলায়। হলে নতুন মুখ সাথে সাথে সবখানে
/// ([myFaceProvider] → হোম → ওপরের বার, ছবির মেনু, "আমি")।
///
/// <p>⛔ অনুমতি কেবল বোতাম চাপলে ([FacePicker]) — পর্দা খুললে নয়। ⛔ বাছা ফাইলটা ফোনে থাকে না: পড়ার পরেই মুছে দেওয়া হয়,
/// পাঠানো সফল হোক বা না হোক।
///
/// <p>⭐ কোন অংশ দেখাবে তা `/me`-র `features` বলে ([meFeaturesProvider]): `profile_photo` false (পুরনো সার্ভার) → ছবির পুরো অংশ
/// নেই — বোতাম, কথা, কিছুই না, বন্ধ বোতামও না; নিজের তথ্য (নাম, পদবি, কোম্পানি · শাখা, ইমেইল) তবু দেখায়। `my_phones` false →
/// "আমার ফোনগুলো" সারি নেই। ⓘ পর্দা খুলতে সার্ভারে কোনো ডাক নেই।
class ProfileScreen extends ConsumerStatefulWidget {
  const ProfileScreen({
    super.key,
    this.api = const ServerProfileApi(),
    this.picker = const FacePicker(),
    this.shrink = squareFace,
    this.faceOf,
    this.openPhones,
  });

  final ProfileApi api;
  final FacePicker picker;

  /// বাছা ছবি → পাঠানোর JPEG; পরীক্ষার পথ
  final Future<Uint8List> Function(Uint8List source) shrink;

  /// ঠিকানা → ছবি; পরীক্ষার পথ, না দিলে টোকেনসহ [FaceImage]
  final ImageProvider Function(String url)? faceOf;

  /// "আমার ফোনগুলো" খোলা; না দিলে রাউটার
  final VoidCallback? openPhones;

  static String get title => AppLocale.l10n.profileTitlePhoto;
  static String get plainTitle => AppLocale.l10n.profileTitlePlain;

  @override
  ConsumerState<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends ConsumerState<ProfileScreen> {
  bool _busy = false;
  double? _progress;
  String? _error;

  Future<void> _take(ImageSource source) async {
    setState(() => _error = null);
    final shot = await widget.picker.pick(context, source);
    if (shot == null || !mounted) {
      if (shot != null) await _forget(shot);
      return;
    }
    Uint8List face;
    try {
      face = await widget.shrink(await shot.readAsBytes());
    } catch (_) {
      if (mounted) setState(() => _error = context.l10n.profilePhotoUnreadable);
      return;
    } finally {
      // ⛔ বাছা ছবি ফোনের ক্যাশে পড়ে থাকে না — বর্গ বানানো হলেই মোছা, পাঠানো সফল হোক বা না হোক
      await _forget(shot);
    }
    await _upload(face);
  }

  static Future<void> _forget(XFile shot) async {
    try {
      final file = File(shot.path);
      if (await file.exists()) await file.delete();
    } catch (_) {
      // ⓘ মোছা না গেলে (অন্য অ্যাপের ফাইল) — ফোনের নিজের ক্যাশ-পরিষ্কার দেখবে; পাঠানো থামে না
    }
  }

  Future<void> _upload(Uint8List face) async {
    if (!mounted) return;
    setState(() {
      _busy = true;
      _progress = 0;
      _error = null;
    });
    try {
      final change = await widget.api.uploadPhoto(face, onProgress: (sent) {
        if (mounted) setState(() => _progress = sent);
      });
      ref.read(myFaceProvider.notifier).state = change.photoUrl;
      if (mounted) _say(context.l10n.profilePhotoChanged);
    } on PhotoRefused catch (e) {
      if (mounted) setState(() => _error = e.message);
    } on ProfileDoorsMissing {
      if (mounted) {
        setState(() => _error = ProfileDoorsMissing.message);
      }
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.profilePhotoUploadFailed));
    } finally {
      if (mounted) {
        setState(() {
          _busy = false;
          _progress = null;
        });
      }
    }
  }

  Future<void> _remove() async {
    final yes = await showDialog<bool>(
      context: context,
      builder: (dialog) => AlertDialog(
        key: const ValueKey('profile-remove-confirm'),
        icon: const Icon(Symbols.no_photography),
        title: Text(dialog.l10n.profilePhotoRemoveTitle),
        content: Text(dialog.l10n.profilePhotoRemoveBody),
        actions: [
          TextButton(
            key: const ValueKey('profile-remove-no'),
            onPressed: () => Navigator.of(dialog).pop(false),
            child: Text(dialog.l10n.profilePhotoKeep),
          ),
          FilledButton(
            key: const ValueKey('profile-remove-yes'),
            style: FilledButton.styleFrom(
              backgroundColor: Theme.of(dialog).colorScheme.error,
              foregroundColor: Theme.of(dialog).colorScheme.onError,
            ),
            onPressed: () => Navigator.of(dialog).pop(true),
            child: Text(dialog.l10n.profilePhotoRemoveConfirm),
          ),
        ],
      ),
    );
    if (yes != true || !mounted) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.api.removePhoto();
      ref.read(myFaceProvider.notifier).state = null;
      if (mounted) _say(context.l10n.profilePhotoRemoved);
    } on ProfileDoorsMissing {
      if (mounted) {
        setState(() => _error = ProfileDoorsMissing.message);
      }
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.profilePhotoRemoveFailed));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _say(String text) {
    if (!mounted) return;
    ScaffoldMessenger.maybeOf(context)
      ?..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(content: Text(text)));
  }

  void _openPhones() {
    final open = widget.openPhones;
    if (open != null) return open();
    GoRouter.maybeOf(context)?.push('/home/${ProfileRoutes.phones}');
  }

  @override
  Widget build(BuildContext context) {
    final user = ref.watch(authStateProvider).user;
    final top = ref.watch(topBarContextProvider);
    final url = ref.watch(myFaceProvider);
    final cs = Theme.of(context).colorScheme;
    final p = context.palette;
    final name = user?.name ?? '';
    final where = [top?.company ?? '', top?.branch ?? ''].where((s) => s.trim().isNotEmpty).join(' · ');
    final designation = top?.designation;
    final face = url == null ? null : (widget.faceOf ?? _faceOf)(url);
    final features = ref.watch(meFeaturesProvider) ?? MeFeatures.none;
    final percent = ((_progress ?? 0) * 100).round();

    final look = context.look;
    final tiles = <Widget>[
      ListTile(
        key: const ValueKey('profile-look'),
        minVerticalPadding: 12,
        leading: const Icon(Symbols.palette),
        title: Text(context.l10n.lookTitle),
        subtitle: Text(context.l10n.lookProfileSubtitle),
        trailing: const Icon(Symbols.chevron_right),
        onTap: () => LookScreen.open(context),
      ),
      if (features.myPhones)
        ListTile(
          key: const ValueKey('profile-phones'),
          minVerticalPadding: 12,
          leading: const Icon(Symbols.devices),
          title: Text(context.l10n.profilePhonesTitle),
          subtitle: Text(context.l10n.profilePhonesSubtitle),
          trailing: const Icon(Symbols.chevron_right),
          onTap: _openPhones,
        ),
      // ⭐ অফিসের SMS ফোন — কেবল যাঁর হাতে চাবি (আজ মালিক); বাকি সবার প্রোফাইলে সারিটাই নেই
      // ⛔ play বিল্ডে কখনো নয় ([[ProfileRoutes.smsGatewayInThisBuild]])
      if (ProfileRoutes.smsGatewayInThisBuild && (user?.can('notification.sms_gateway') ?? false))
        ListTile(
          key: const ValueKey('profile-sms-gateway'),
          minVerticalPadding: 12,
          leading: const Icon(Symbols.sms),
          title: Text(context.l10n.smsGatewayTitle),
          subtitle: Text(context.l10n.smsGatewaySubtitle),
          trailing: const Icon(Symbols.chevron_right),
          onTap: () => GoRouter.maybeOf(context)?.push('/home/${ProfileRoutes.smsGateway}'),
        ),
    ];

    return Scaffold(
      // ⓘ ছবি বদল না থাকলে নামেও তার প্রতিশ্রুতি নয়
      appBar: AbosScreenBar(
          title: features.profilePhoto ? context.l10n.profileTitlePhoto : context.l10n.profileTitlePlain,
          module: context.l10n.profileModuleMe),
      body: ListView(
        key: const ValueKey('profile-screen'),
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          Center(child: _Face(name: name, image: face)),
          const SizedBox(height: AppSpacing.md),
          Text(name,
              key: const ValueKey('profile-name'),
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700)),
          if (designation != null && designation.trim().isNotEmpty)
            Text(designation.trim(),
                key: const ValueKey('profile-designation'), textAlign: TextAlign.center, style: TextStyle(color: p.onSurfaceMuted)),
          if (where.isNotEmpty)
            Text(where, key: const ValueKey('profile-where'), textAlign: TextAlign.center, style: TextStyle(color: p.onSurfaceMuted)),
          if (user != null && user.email.isNotEmpty)
            Text(user.email, key: const ValueKey('profile-email'), textAlign: TextAlign.center, style: TextStyle(color: p.onSurfaceMuted)),
          const SizedBox(height: AppSpacing.lg),
          // ⛔ সার্ভার ছবি বদল না জানলে এই পুরো অংশই নেই — বোতাম, কথা কিছুই না
          if (features.profilePhoto) ...[
            if (_progress != null)
              Semantics(
                key: const ValueKey('profile-progress'),
                liveRegion: true,
                label: context.l10n.profilePhotoSendingSpoken(percent),
                child: ExcludeSemantics(
                  child: Padding(
                    padding: const EdgeInsets.only(bottom: AppSpacing.md),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                      LinearProgressIndicator(value: _progress == 0 ? null : _progress),
                      const SizedBox(height: AppSpacing.xs),
                      Text(context.l10n.profilePhotoSending(percent), textAlign: TextAlign.center),
                    ]),
                  ),
                ),
              ),
            if (_error != null)
              Semantics(
                liveRegion: true,
                child: Padding(
                  padding: const EdgeInsets.only(bottom: AppSpacing.md),
                  child: Text(_error!,
                      key: const ValueKey('profile-photo-error'),
                      textAlign: TextAlign.center,
                      style: TextStyle(color: cs.error, fontWeight: FontWeight.w600)),
                ),
              ),
            _Wide(
              child: FilledButton.icon(
                key: const ValueKey('profile-camera'),
                onPressed: _busy ? null : () => _take(ImageSource.camera),
                icon: const Icon(Symbols.photo_camera),
                label: Text(context.l10n.profilePhotoCamera),
              ),
            ),
            _Wide(
              child: FilledButton.tonalIcon(
                key: const ValueKey('profile-gallery'),
                onPressed: _busy ? null : () => _take(ImageSource.gallery),
                icon: const Icon(Symbols.photo_library),
                label: Text(context.l10n.profilePhotoGallery),
              ),
            ),
            _Wide(
              child: OutlinedButton.icon(
                key: const ValueKey('profile-remove'),
                style: OutlinedButton.styleFrom(foregroundColor: cs.error),
                onPressed: _busy || url == null ? null : _remove,
                icon: const Icon(Symbols.no_photography),
                label: Text(context.l10n.profilePhotoRemove),
              ),
            ),
            Padding(
              padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
              child: Text(
                context.l10n.profilePhotoPrivacy,
                textAlign: TextAlign.center,
                style: TextStyle(color: p.onSurfaceMuted, fontSize: 13),
              ),
            ),
          ],
          // ⭐ চেহারা — ABOS · Liquid Glass · Material 3, আর কাচের ঝাপসা (0.4.58; মালিক, ২০ নভেম্বর ২০২৬ "ok")
          // ⭐ ০.৪.৬০ — ABOS-এ সারিগুলো আগের মতো রেখায় ভাগ; নতুন দুই রূপে এক দলে ([LookGroup]: কাচের প্যানেল / জোড়া কার্ড)
          if (look == AppLook.abos)
            for (final tile in tiles) ...[const Divider(height: AppSpacing.lg), tile]
          else ...[
            const SizedBox(height: AppSpacing.sm),
            LookGroup(children: [for (final tile in tiles) look == AppLook.expressive ? Card(child: tile) : tile]),
          ],
        ],
      ),
    );
  }

  static ImageProvider _faceOf(String url) => ResizeImage(FaceImage(url), width: 360, policy: ResizeImagePolicy.fit);
}

/// বড় গোল মুখ — ছবি না থাকলে বা না এলে নামের প্রথম অক্ষর
class _Face extends StatelessWidget {
  const _Face({required this.name, this.image});

  final String name;
  final ImageProvider? image;

  static const double size = 112;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    final letter = name.trim().isEmpty ? '?' : name.trim().characters.first;
    final initial = Center(
      child: Text(letter,
          key: const ValueKey('profile-initial'),
          textScaler: TextScaler.noScaling,
          style: TextStyle(fontSize: 44, fontWeight: FontWeight.w700, color: cs.onPrimaryContainer)),
    );
    return Semantics(
      key: const ValueKey('profile-face'),
      image: true,
      label: image == null ? context.l10n.profileFaceNone : context.l10n.profileFace,
      child: ExcludeSemantics(
        child: Container(
          width: size,
          height: size,
          decoration: BoxDecoration(shape: BoxShape.circle, color: cs.primaryContainer, border: Border.all(color: cs.outlineVariant, width: 2)),
          child: ClipOval(
            child: image == null
                ? initial
                : Image(
                    key: const ValueKey('profile-photo'),
                    image: image!,
                    fit: BoxFit.cover,
                    width: size,
                    height: size,
                    errorBuilder: (_, __, ___) => initial,
                  ),
          ),
        ),
      ),
    );
  }
}

/// পুরো চওড়া, অন্তত ৪৮dp উঁচু (WCAG 2.5.8)
class _Wide extends StatelessWidget {
  const _Wide({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
        child: ConstrainedBox(constraints: const BoxConstraints(minHeight: 48), child: child),
      );
}
