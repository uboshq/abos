import 'dart:io';
import 'dart:math' as math;
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/auth/auth_state.dart';
import '../../core/auth/me_features.dart';
import '../../core/locale/l10n.dart';
import '../../core/navigation/deposit_icon.dart';
import '../../core/privacy/lock_delay.dart';
import '../../core/profile/cover_crop.dart';
import '../../core/profile/face_image.dart';
import '../../core/profile/face_picker.dart';
import '../../core/profile/me_profile_api.dart';
import '../../core/profile/profile_api.dart';
import '../../core/profile/square_face.dart';
import '../../core/router/profile_routes.dart';
import '../../core/theme/app_look.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/expressive.dart';
import '../../core/theme/glass.dart';
import '../../core/widgets/day_part.dart' show monthNames;
import 'my_phones_screen.dart' show lastUsedLine;

part 'me_page/me_tokens.dart';
part 'me_page/me_head.dart';
part 'me_page/me_sections.dart';

/// ⭐ Facebook-ধাঁচের "আমি" পাতা (আইটেম ১০; মালিকের অনুমোদিত নমুনা "ক. অ্যাপ: আমি", ২০ নভেম্বর ২০২৬) — নিচের বারের "আমি"
/// ঘরের ভেতরের অংশ; উপরের আর নিচের বার শেলের।
///
/// <p>উপর থেকে নিচে: মলাট (না থাকলে কোম্পানির রঙের ঢাল) · বড় গোল ছবি · নাম, পদবি, কোম্পানি · শাখা · "প্রোফাইল ঠিক করুন",
/// "বার্তা"/"কল" (শীঘ্রই) · এই মাসে আমার কাজ · পরিচয় · আমার সাম্প্রতিক কাজ · আমার ফোনগুলো · নিরাপত্তা · চেহারা।
/// তিন রূপে আঁকা: স্মার্ট ([AppLook.expressive]), গ্লাস ([AppLook.glass]), ক্লাসিক ([AppLook.abos])।
///
/// <p>⛔ পুরনো সার্ভার (`/me`-র `profile_page` false): [fallback] দিয়েই আঁকা — মাসের কাজ, পরিচয় আর শেষ কাজ নেই।
/// `cover_photo` false → "মলাট বদলান" নেই; `profile_photo` false → ছবির ক্যামেরা নেই; `my_phones` false → "আমার ফোনগুলো" নেই।
/// নিরাপত্তার সারি কেবল যার ডাক ([onPassword], [onAppLockToggle], [onLockDelay]) শেল দেয়।
class MePage extends ConsumerStatefulWidget {
  const MePage({
    super.key,
    required this.fallback,
    this.profileApi = const ServerProfileApi(),
    this.picker = const FacePicker(),
    this.shrinkFace = squareFace,
    this.cropCover = coverCrop,
    this.imageOf,
    this.onEditProfile,
    this.onSeeAllRecent,
    this.onOpenRecent,
    this.onPassword,
    this.passwordChangedAt,
    this.appLockOn = true,
    this.onAppLockToggle,
    this.onLockDelay,
    this.signOutHere,
    this.now,
  });

  /// শেলের হাতে যা আছে — [MeProfile.fallback] (নাম, পদবি, কোম্পানি, শাখা, ছবি, ইমেইল); `/me/profile` আসার আগে আর পুরনো সার্ভারে
  final MeProfile fallback;

  /// ছবি বদল আর ফোনের তালিকা — "প্রোফাইল ও ছবি"-র একই দরজা
  final ProfileApi profileApi;
  final FacePicker picker;

  /// বাছা ছবি → মুখের JPEG ([squareFace]); পরীক্ষার পথ
  final Future<Uint8List> Function(Uint8List source) shrinkFace;

  /// বাছা ছবি → মলাটের JPEG ([coverCrop]); পরীক্ষার পথ
  final Future<Uint8List> Function(Uint8List source) cropCover;

  /// ঠিকানা → ছবি; পরীক্ষার পথ, না দিলে টোকেনসহ [FaceImage]
  final ImageProvider Function(String url, {required bool cover})? imageOf;

  /// "প্রোফাইল ঠিক করুন" — না দিলে রাউটারে "প্রোফাইল ও ছবি"
  final VoidCallback? onEditProfile;

  /// "সব দেখুন" — null হলে লেখাটাই নেই
  final VoidCallback? onSeeAllRecent;

  /// শেষ কাজের সারিতে চাপ (যার `url` আছে) — null হলে সারি চাপা যায় না
  final void Function(MeRecent item)? onOpenRecent;

  /// "পাসওয়ার্ড" সারি — null হলে সারি নেই
  final VoidCallback? onPassword;

  /// পাসওয়ার্ড শেষ কবে বদলানো — জানা থাকলে "শেষ বদল N দিন আগে"
  final DateTime? passwordChangedAt;

  /// "আঙুলের ছাপ ও পিন" সুইচের অবস্থা
  final bool appLockOn;

  /// সুইচ বদল — null হলে সারি নেই
  final ValueChanged<bool>? onAppLockToggle;

  /// "কতক্ষণ পরে তালা" — শেলের চার-পছন্দের পাতা খোলে; null হলে সারি নেই
  final VoidCallback? onLockDelay;

  /// এই ফোনটাই বাদ দিলে — না দিলে অ্যাপের নিজের ([AuthController.signOutHere])
  final Future<void> Function()? signOutHere;

  /// ঘড়ি — পরীক্ষার পথ
  final DateTime Function()? now;

  @override
  ConsumerState<MePage> createState() => _MePageState();
}

class _MePageState extends ConsumerState<MePage> {
  MeProfile? _loaded;
  bool _profileAsked = false;

  List<MyPhone>? _phones;
  bool _phonesAsked = false;
  String? _phonesError;
  String? _leaving;

  bool _busy = false;
  double? _progress;
  bool _coverSending = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _follow(ref.read(meFeaturesProvider));
  }

  /// `/me` এলে (বা আগেই এসে থাকলে) যা সার্ভার জানে তা একবার আনা
  void _follow(MeFeatures? features) {
    if (features == null) return;
    if (features.profilePage && !_profileAsked) {
      _profileAsked = true;
      _loadProfile();
    }
    if (features.myPhones && !_phonesAsked) {
      _phonesAsked = true;
      _loadPhones();
    }
  }

  Future<void> _loadProfile() async {
    try {
      final p = await ref.read(meProfileApiProvider).profile();
      if (mounted) setState(() => _loaded = p);
    } catch (_) {
      // ⓘ দরজা নেই বা নেট নেই — শেলের যা জানা তা-ই দেখায় ([MePage.fallback])
    }
  }

  Future<void> _loadPhones() async {
    try {
      final page = await widget.profileApi.phones(1);
      if (mounted) {
        setState(() {
          _phones = page.rows;
          _phonesError = null;
        });
      }
    } on ProfileDoorsMissing {
      if (mounted) setState(() => _phones = const []);
    } catch (e) {
      if (mounted) setState(() => _phonesError = errorMessageFor(e, fallback: context.l10n.profilePhonesLoadFailed));
    }
  }

  MeProfile get _profile => _loaded ?? widget.fallback;

  void _say(String text) {
    if (!mounted) return;
    ScaffoldMessenger.maybeOf(context)
      ?..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(content: Text(text)));
  }

  // ───────────── ছবি আর মলাট ─────────────

  Future<ImageSource?> _askSource() => showModalBottomSheet<ImageSource>(
        context: context,
        builder: (sheet) => SafeArea(
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            ListTile(
              key: const ValueKey('me-source-camera'),
              leading: const Icon(Symbols.photo_camera),
              title: Text(sheet.l10n.profilePhotoCamera),
              onTap: () => Navigator.of(sheet).pop(ImageSource.camera),
            ),
            ListTile(
              key: const ValueKey('me-source-gallery'),
              leading: const Icon(Symbols.photo_library),
              title: Text(sheet.l10n.profilePhotoGallery),
              onTap: () => Navigator.of(sheet).pop(ImageSource.gallery),
            ),
          ]),
        ),
      );

  /// বাছা ছবি → [shape] দিয়ে JPEG; ⛔ বাছা ফাইল পড়ার পরেই মোছা (প্রোফাইল পাতার একই নিয়ম)
  Future<Uint8List?> _pickAndShape(Future<Uint8List> Function(Uint8List) shape) async {
    final source = await _askSource();
    if (source == null || !mounted) return null;
    setState(() => _error = null);
    final shot = await widget.picker.pick(context, source);
    if (shot == null || !mounted) {
      if (shot != null) await _forget(shot);
      return null;
    }
    try {
      return await shape(await shot.readAsBytes());
    } catch (_) {
      if (mounted) setState(() => _error = context.l10n.profilePhotoUnreadable);
      return null;
    } finally {
      await _forget(shot);
    }
  }

  static Future<void> _forget(XFile shot) async {
    try {
      final file = File(shot.path);
      if (await file.exists()) await file.delete();
    } catch (_) {
      // ⓘ মোছা না গেলে ফোনের নিজের ক্যাশ-পরিষ্কার দেখবে; পাঠানো থামে না
    }
  }

  Future<void> _changePhoto() async {
    final face = await _pickAndShape(widget.shrinkFace);
    if (face == null || !mounted) return;
    await _send(cover: false, () async {
      final change = await widget.profileApi.uploadPhoto(face, onProgress: _onProgress);
      ref.read(myFaceProvider.notifier).state = change.photoUrl;
      if (mounted) _say(context.l10n.profilePhotoChanged);
    });
  }

  Future<void> _changeCover() async {
    final jpeg = await _pickAndShape(widget.cropCover);
    if (jpeg == null || !mounted) return;
    await _send(cover: true, () async {
      final url = await ref.read(meProfileApiProvider).uploadCover(jpeg, onProgress: _onProgress);
      if (mounted) {
        setState(() => _loaded = _profile.withCover(url));
        _say(context.l10n.mePageCoverChanged);
      }
    });
  }

  void _onProgress(double sent) {
    if (mounted) setState(() => _progress = sent);
  }

  Future<void> _send(Future<void> Function() job, {required bool cover}) async {
    setState(() {
      _busy = true;
      _progress = 0;
      _coverSending = cover;
      _error = null;
    });
    try {
      await job();
    } on PhotoRefused catch (e) {
      if (mounted) setState(() => _error = e.message);
    } on ProfileDoorsMissing {
      if (mounted) setState(() => _error = ProfileDoorsMissing.message);
    } catch (e) {
      if (mounted) {
        setState(() => _error =
            errorMessageFor(e, fallback: cover ? context.l10n.mePageCoverFailed : context.l10n.profilePhotoUploadFailed));
      }
    } finally {
      if (mounted) {
        setState(() {
          _busy = false;
          _progress = null;
        });
      }
    }
  }

  // ───────────── ফোন বাদ ─────────────

  Future<void> _removePhone(MyPhone phone) async {
    final yes = await showDialog<bool>(
      context: context,
      builder: (dialog) => AlertDialog(
        key: const ValueKey('me-phone-out-confirm'),
        icon: const Icon(Symbols.logout),
        title: Text(phone.current ? dialog.l10n.profilePhonesSignOutHereTitle : dialog.l10n.profilePhonesSignOutOtherTitle),
        content: Text(phone.current
            ? dialog.l10n.profilePhonesSignOutHereBody(phone.name)
            : dialog.l10n.profilePhonesSignOutOtherBody(phone.name)),
        actions: [
          TextButton(
            key: const ValueKey('me-phone-out-no'),
            onPressed: () => Navigator.of(dialog).pop(false),
            child: Text(dialog.l10n.profilePhonesKeep),
          ),
          FilledButton(
            key: const ValueKey('me-phone-out-yes'),
            style: FilledButton.styleFrom(
              backgroundColor: Theme.of(dialog).colorScheme.error,
              foregroundColor: Theme.of(dialog).colorScheme.onError,
            ),
            onPressed: () => Navigator.of(dialog).pop(true),
            child: Text(dialog.l10n.profilePhonesSignOut),
          ),
        ],
      ),
    );
    if (yes != true || !mounted) return;
    setState(() => _leaving = phone.id);
    try {
      final done = await widget.profileApi.signOutPhone(phone.id);
      if (!mounted) return;
      if (!done) {
        _say(context.l10n.profilePhonesAlreadyOut(phone.name));
        await _loadPhones();
        return;
      }
      setState(() => _phones = [...?_phones?.where((r) => r.id != phone.id)]);
      if (phone.current) {
        // ⛔ সার্ভার এই ফোনের টোকেন মুছেছে — অ্যাপও বের হয় ("আমার ফোনগুলো"-র একই নিয়ম)
        await (widget.signOutHere ?? () => ref.read(authStateProvider.notifier).signOutHere())();
        return;
      }
      _say(context.l10n.profilePhonesSignedOut(phone.name));
    } catch (e) {
      _say(errorMessageFor(e, fallback: context.l10n.profilePhonesSignOutFailed));
    } finally {
      if (mounted) setState(() => _leaving = null);
    }
  }

  void _editProfile() {
    final open = widget.onEditProfile;
    if (open != null) return open();
    GoRouter.maybeOf(context)?.push('/home/${ProfileRoutes.profile}');
  }

  // ───────────── আঁকা ─────────────

  @override
  Widget build(BuildContext context) {
    ref.listen<MeFeatures?>(meFeaturesProvider, (_, next) => _follow(next));
    final features = ref.watch(meFeaturesProvider) ?? MeFeatures.none;
    final face = ref.watch(myFaceProvider);
    return ListenableBuilder(
      listenable: Listenable.merge([AppLooks.instance.look, AppLooks.instance.blur]),
      builder: (context, _) {
        final t = _Tok.of(context, AppLooks.instance.look.value);
        final l = context.l10n;
        final p = _profile;
        final full = p.complete && _loaded != null;
        final photo = face ?? p.photoUrl;
        final percent = ((_progress ?? 0) * 100).round();

        final children = <Widget>[
          _Head(
            tok: t,
            profile: p,
            photo: photo == null ? null : _image(photo, cover: false),
            cover: p.coverUrl == null ? null : _image(p.coverUrl!, cover: true),
            onCover: features.coverPhoto ? (_busy ? () {} : _changeCover) : null,
            onPhoto: features.profilePhoto ? (_busy ? () {} : _changePhoto) : null,
          ),
          _Buttons(tok: t, onEdit: _editProfile, onSoon: () => _say(l.mePageSoonSnack)),
          if (_progress != null)
            Semantics(
              key: const ValueKey('me-progress'),
              liveRegion: true,
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 10, 16, 0),
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  LinearProgressIndicator(value: _progress == 0 ? null : _progress, color: t.acc),
                  const SizedBox(height: 4),
                  Text(_coverSending ? l.mePageCoverSending(percent) : l.profilePhotoSending(percent),
                      textAlign: TextAlign.center, style: TextStyle(color: t.mut, fontSize: 12.5)),
                ]),
              ),
            ),
          if (_error != null)
            Semantics(
              liveRegion: true,
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 10, 16, 0),
                child: Text(_error!,
                    key: const ValueKey('me-photo-error'),
                    textAlign: TextAlign.center,
                    style: TextStyle(color: t.danger, fontWeight: FontWeight.w600)),
              ),
            ),
          if (full && p.stats.isNotEmpty)
            _Sec(
              key: const ValueKey('me-month'),
              tok: t,
              plain: true,
              title: l.mePageMonthTitle,
              trailing: p.monthLabel.isEmpty ? null : Text(p.monthLabel),
              child: _Stats(tok: t, stats: p.stats),
            ),
          if (full && _aboutRows(context, t, p.about).isNotEmpty)
            _Sec(
              key: const ValueKey('me-about'),
              tok: t,
              plain: true,
              title: l.mePageAboutTitle,
              child: _Rows(tok: t, rows: _aboutRows(context, t, p.about)),
            ),
          if (full && p.recent.isNotEmpty)
            _Sec(
              key: const ValueKey('me-recent'),
              tok: t,
              title: l.mePageRecentTitle,
              trailing: widget.onSeeAllRecent == null
                  ? null
                  : InkWell(
                      key: const ValueKey('me-recent-all'),
                      onTap: widget.onSeeAllRecent,
                      child: Padding(padding: const EdgeInsets.symmetric(vertical: 4), child: Text(l.mePageSeeAll)),
                    ),
              child: _Timeline(
                tok: t,
                items: p.recent,
                now: (widget.now ?? DateTime.now)(),
                onOpen: widget.onOpenRecent,
              ),
            ),
          if (features.myPhones)
            _Sec(
              key: const ValueKey('me-phones'),
              tok: t,
              plain: true,
              title: l.profilePhonesTitle,
              child: _phonesBody(context, t),
            ),
          if (widget.onPassword != null || widget.onAppLockToggle != null || widget.onLockDelay != null)
            _Sec(
              key: const ValueKey('me-security'),
              tok: t,
              plain: true,
              title: l.mePageSecurityTitle,
              child: _Rows(tok: t, rows: _securityRows(context, t)),
            ),
          _Sec(
            key: const ValueKey('me-look'),
            tok: t,
            icon: Symbols.palette,
            title: l.lookTitle,
            child: _LookChoice(tok: t),
          ),
        ];

        return DefaultTextStyle.merge(
          style: TextStyle(color: t.ink),
          child: ListView(
            key: const ValueKey('me-page-10'),
            padding: EdgeInsets.only(bottom: MediaQuery.paddingOf(context).bottom + 18),
            children: children,
          ),
        );
      },
    );
  }

  ImageProvider _image(String url, {required bool cover}) {
    final custom = widget.imageOf;
    if (custom != null) return custom(url, cover: cover);
    return ResizeImage(FaceImage(url), width: cover ? 1500 : 360, policy: ResizeImagePolicy.fit);
  }

  List<_RowData> _aboutRows(BuildContext context, _Tok t, MeAbout a) {
    final l = context.l10n;
    final joined = a.joinedOn;
    return [
      if (a.phone != null) _RowData(icon: Symbols.call, label: l.mePageAboutPhone, value: a.phone!),
      if (a.email != null) _RowData(icon: Symbols.mail, label: l.mePageAboutEmail, value: a.email!),
      if (a.employeeNo != null) _RowData(icon: Symbols.badge, label: l.mePageAboutEmployeeNo, value: a.employeeNo!),
      if (joined != null)
        _RowData(
          icon: Symbols.calendar_month,
          label: l.mePageAboutJoined,
          value: '${joined.day.toString().padLeft(2, '0')} ${monthNames(l)[joined.month - 1]} ${joined.year}',
        ),
      if (a.role != null) _RowData(icon: Symbols.verified_user, label: l.mePageAboutRole, value: a.role!),
      if (a.branches != null) _RowData(icon: Symbols.apartment, label: l.mePageAboutBranches, value: a.branches!),
    ];
  }

  List<_RowData> _securityRows(BuildContext context, _Tok t) {
    final l = context.l10n;
    final changed = widget.passwordChangedAt;
    final days = changed == null ? null : math.max(0, (widget.now ?? DateTime.now)().difference(changed).inDays);
    return [
      if (widget.onPassword != null)
        _RowData(
          key: const ValueKey('me-password'),
          icon: Symbols.lock,
          value: l.mePagePassword,
          hint: days == null ? null : l.mePagePasswordChanged(days, '$days'),
          end: Icon(Symbols.chevron_right, size: 18, color: t.mut),
          onTap: widget.onPassword,
        ),
      if (widget.onAppLockToggle != null)
        _RowData(
          key: const ValueKey('me-app-lock'),
          icon: Symbols.fingerprint,
          value: l.mePageAppLock,
          hint: l.mePageAppLockHint,
          end: _LookSwitch(tok: t, value: widget.appLockOn, onChanged: widget.onAppLockToggle!, label: l.mePageAppLock),
        ),
      if (widget.onLockDelay != null)
        _RowData(
          key: const ValueKey('me-lock-delay'),
          icon: Symbols.timer,
          value: l.mePageLockDelay,
          hint: l.mePageLockDelayHint,
          end: ValueListenableBuilder<Duration>(
            valueListenable: LockDelay.instance.current,
            builder: (context, delay, _) {
              final m = delay.inMinutes;
              // ⓘ অঙ্ক ইংরেজিতে — পর্দার অঙ্কের নিয়ম (মালিক, ১৬ নভেম্বর ২০২৬; নমুনার "5 মিনিট")
              final text = m == 0 ? context.l10n.lockDelayNow : context.l10n.lockDelayMinutes(m, '$m');
              return Row(mainAxisSize: MainAxisSize.min, children: [
                Text(text, key: const ValueKey('me-lock-delay-value'), style: TextStyle(color: t.mut, fontSize: 12.5)),
                const SizedBox(width: 3),
                Icon(Symbols.chevron_right, size: 16, color: t.mut),
              ]);
            },
          ),
          onTap: widget.onLockDelay,
        ),
    ];
  }

  Widget _phonesBody(BuildContext context, _Tok t) {
    final l = context.l10n;
    final rows = _phones;
    if (_phonesError != null) {
      return Padding(
        padding: const EdgeInsets.symmetric(horizontal: 4),
        child: Text(_phonesError!, key: const ValueKey('me-phones-error'), style: TextStyle(color: t.danger)),
      );
    }
    if (rows == null) return const LinearProgressIndicator(minHeight: 2);
    if (rows.isEmpty) {
      return _Rows(tok: t, rows: [
        _RowData(icon: Symbols.devices, value: l.profilePhonesEmptyTitle, hint: l.profilePhonesEmptyMessage),
      ]);
    }
    return _Rows(tok: t, rows: [
      for (final phone in rows)
        _RowData(
          key: ValueKey('me-phone-${phone.id}'),
          icon: (phone.platform ?? '').toLowerCase() == 'ios' ? Symbols.phone_iphone : Symbols.smartphone,
          value: phone.name,
          tag: phone.current ? l.profilePhonesThisPhone : null,
          hint: lastUsedLine(phone),
          end: _RemoveButton(
            key: ValueKey('me-phone-out-${phone.id}'),
            tok: t,
            busy: _leaving == phone.id,
            semantics: l.profilePhonesSignOutNamed(phone.name),
            onPressed: _leaving == null ? () => _removePhone(phone) : null,
          ),
        ),
    ]);
  }
}
