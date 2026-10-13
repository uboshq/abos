import 'dart:async';
import 'dart:io';

import 'package:file_selector/file_selector.dart';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';
import 'package:printing/printing.dart';
import 'package:share_plus/share_plus.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:webview_flutter/webview_flutter.dart';
import 'package:webview_flutter_android/webview_flutter_android.dart';

import '../../core/auth/token_storage.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/web_inside/web_files.dart';
import '../../core/web_inside/web_inside.dart';
import '../../core/widgets/empty_state.dart';

/// ⭐ ওয়েবের একটা পর্দা, অ্যাপের ভিতরেই — মালিক, ১৩ অক্টোবর ২০২৬: *"web e na dhuke jar jar kaj 100% app ei korte pare"*।
///
/// <p>যে মেনু-সারির ফোন-পর্দা নেই, সেটা এখানে খোলে: অ্যাপ সার্ভারের কাছে একবারের চাবি চায় ([WebHandoffApi]) — ৬০ সেকেন্ড,
/// একবার, এই ফোনে বাঁধা — আর জানালাটা সেই চাবি দিয়ে ওয়েবে ঢোকে। মানুষটা ওয়েবে যা পান, এখানেও ঠিক তাই।
///
/// <p>⛔ জানালা ABOS-এর ঠিকানাতেই থাকে — বাইরের লিংক ফোনের নিজের অ্যাপে খোলে ([WebPaths.staysInside])।
/// ⭐ ফাইল নামানো, ছাপা, নথি জোড়া, পিছনে ফেরা, টেনে নতুন করা — সব এখানেই ([WebFiles])। ভুল হলে বাংলায় বলে, আবার চেষ্টার বোতাম।
/// ⓘ পর্দা আড়াল (FLAG_SECURE) গোটা অ্যাপের — কোম্পানির সুইচ মেনে ([PhonePrivacy]), এই পর্দাও তার ভিতরে।
class WebInsideScreen extends StatefulWidget {
  const WebInsideScreen({
    super.key,
    required this.webPath,
    required this.title,
    this.askKey,
    this.deviceId,
    this.webBuilder,
  });

  /// The web screen's own path (`/…`); null when the address was not one of ours.
  final String? webPath;
  final String title;

  /// Seams — the real ones need a server, a secure store and the platform WebView.
  final Future<WebKey> Function(String webPath, {String? code})? askKey;
  final Future<String> Function()? deviceId;

  /// Builds the window for an issued key; a test draws a stand-in.
  final Widget Function(BuildContext context, String url, Map<String, String> headers)? webBuilder;

  static const String deviceHeader = 'X-ABOS-Device';

  @override
  State<WebInsideScreen> createState() => _WebInsideScreenState();
}

class _WebInsideScreenState extends State<WebInsideScreen> {
  WebViewController? _controller;
  String? _issuedUrl;
  Map<String, String> _headers = const {};
  String? _error;
  bool _asking = true;
  int _progress = 0;
  String? _currentPath;
  DateTime? _lastRelogin;

  @override
  void initState() {
    super.initState();
    _open(widget.webPath);
  }

  /// Ask for a key for [path], with the two-step code when the door wants it, then open it.
  Future<void> _open(String? path, {String? code}) async {
    if (path == null) {
      setState(() {
        _asking = false;
        _error = 'এই পর্দার ঠিকানাটা চেনা গেল না — মেনু থেকে আবার খুলুন।';
      });
      return;
    }
    setState(() {
      _asking = true;
      _error = null;
    });
    final key = await (widget.askKey ?? WebHandoffApi.ask)(path, code: code);
    if (!mounted) return;
    switch (key) {
      case WebKeyNeedsCode(:final wasWrong, :final message):
        final typed = await _askForCode(message, wasWrong: wasWrong);
        if (!mounted) return;
        if (typed == null) {
          setState(() {
            _asking = false;
            _error = 'দুই ধাপের কোড ছাড়া ওয়েবের পর্দা খোলে না — ওয়েবের লগইনের মতোই।';
          });
          return;
        }
        return _open(path, code: typed);
      case WebKeyRefused(:final message):
        setState(() {
          _asking = false;
          _error = message;
        });
      case WebKeyIssued(:final url):
        final device = await (widget.deviceId ?? TokenStorage.instance.deviceId)();
        if (!mounted) return;
        _currentPath = path;
        _headers = {WebInsideScreen.deviceHeader: device};
        if (widget.webBuilder == null) {
          final controller = _controller ??= _buildController();
          await controller.loadRequest(Uri.parse(url), headers: _headers);
        }
        if (!mounted) return;
        setState(() {
          _issuedUrl = url;
          _asking = false;
        });
    }
  }

  Future<String?> _askForCode(String message, {required bool wasWrong}) => showDialog<String>(
        context: context,
        barrierDismissible: false,
        builder: (context) => _TextDialog(
          title: 'দুই ধাপের কোড',
          message: message,
          messageIsError: wasWrong,
          fieldKey: const ValueKey('web-inside-code'),
          hint: 'অথেনটিকেটর অ্যাপের ৬ অঙ্ক',
          numeric: true,
          okLabel: 'খুলুন',
          cancelValue: null,
        ),
      );

  WebViewController _buildController() {
    final controller = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setBackgroundColor(Colors.white)
      ..addJavaScriptChannel(WebFiles.fileChannel, onMessageReceived: (m) => _onFile(m.message))
      ..addJavaScriptChannel(WebFiles.pageChannel, onMessageReceived: (m) => _onPage(m.message))
      ..setNavigationDelegate(NavigationDelegate(
        onProgress: (p) {
          if (mounted) setState(() => _progress = p);
        },
        onPageFinished: (url) {
          final uri = Uri.tryParse(url);
          if (uri != null && WebPaths.staysInside(uri)) {
            _currentPath = uri.hasQuery ? '${uri.path}?${uri.query}' : uri.path;
          }
          unawaited(_controller?.runJavaScript(WebFiles.pageScript).catchError((_) {}));
          if (mounted) setState(() => _progress = 100);
        },
        onWebResourceError: (error) {
          if (error.isForMainFrame != true || !mounted) return;
          setState(() => _error = 'পাতাটা আনা গেল না — সংযোগ দেখে আবার চেষ্টা করুন।');
        },
        onNavigationRequest: _onNavigation,
      ))
      ..setOnJavaScriptAlertDialog((request) => _alert(request.message))
      ..setOnJavaScriptConfirmDialog((request) => _confirm(request.message))
      ..setOnJavaScriptTextInputDialog((request) => _prompt(request.message, request.defaultText));

    final platform = controller.platform;
    if (platform is AndroidWebViewController) {
      unawaited(platform.setOnShowFileSelector(_pickFiles));
      unawaited(platform.setMediaPlaybackRequiresUserGesture(true));
    }
    return controller;
  }

  Future<NavigationDecision> _onNavigation(NavigationRequest request) async {
    final uri = Uri.tryParse(request.url);
    if (uri == null) return NavigationDecision.prevent;
    if (!WebPaths.staysInside(uri)) {
      // ⛔ বাইরের ঠিকানা জানালায় নয় — ফোনের নিজের অ্যাপে (ব্রাউজার, ম্যাপ, ফোন, ইমেইল)
      unawaited(launchUrl(uri, mode: LaunchMode.externalApplication).catchError((_) => false));
      return NavigationDecision.prevent;
    }
    // ⓘ ওয়েবের সেশন ফুরোলে লগইনের পাতা আসে — জানালা নিজে নতুন চাবি চায়, মানুষকে পাসওয়ার্ড টাইপ করাতে হয় না
    if (request.isMainFrame && (uri.path == '/login' || uri.path == '/signin')) {
      final now = DateTime.now();
      if (_lastRelogin == null || now.difference(_lastRelogin!) > const Duration(seconds: 30)) {
        _lastRelogin = now;
        unawaited(_open(_currentPath ?? widget.webPath));
        return NavigationDecision.prevent;
      }
    }
    if (WebFiles.isDownload(uri)) {
      _download(uri);
      return NavigationDecision.prevent;
    }
    return NavigationDecision.navigate;
  }

  void _download(Uri uri) {
    _snack('ফাইল আনা হচ্ছে…');
    unawaited(_controller?.runJavaScript(WebFiles.fetchScript(uri)).catchError((_) {}));
  }

  Future<void> _onPage(String message) async {
    if (message == 'refresh') {
      await _controller?.reload();
    } else if (message == 'print') {
      await _print();
    } else if (message.startsWith('download:')) {
      final uri = Uri.tryParse(message.substring('download:'.length));
      if (uri != null && WebPaths.staysInside(uri)) _download(uri);
    }
  }

  Future<void> _onFile(String message) async {
    final file = WebFiles.decodePosted(message, Uri.parse(_issuedUrl ?? 'https://${WebPaths.allowedHost}/'));
    if (file == null) {
      _snack('ফাইলটা আনা গেল না — আবার চেষ্টা করুন।');
      return;
    }
    try {
      final dir = await getTemporaryDirectory();
      final saved = File('${dir.path}/${file.name}');
      await saved.writeAsBytes(file.bytes, flush: true);
      if (!mounted) return;
      await _offerFile(saved, file.mime);
    } catch (_) {
      _snack('ফাইলটা রাখা গেল না — ফোনে জায়গা আছে কি না দেখুন।');
    }
  }

  Future<void> _offerFile(File file, String mime) => showModalBottomSheet<void>(
        context: context,
        builder: (context) => SafeArea(
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            ListTile(
              leading: const Icon(Icons.insert_drive_file_outlined),
              title: Text(file.uri.pathSegments.last, maxLines: 2, overflow: TextOverflow.ellipsis),
            ),
            ListTile(
              key: const ValueKey('web-file-open'),
              leading: const Icon(Icons.open_in_new),
              title: const Text('খুলুন'),
              onTap: () {
                Navigator.of(context).pop();
                unawaited(OpenFilex.open(file.path, type: mime));
              },
            ),
            ListTile(
              key: const ValueKey('web-file-share'),
              leading: const Icon(Icons.share_outlined),
              title: const Text('পাঠান বা সেভ করুন'),
              onTap: () {
                Navigator.of(context).pop();
                unawaited(Share.shareXFiles([XFile(file.path, mimeType: mime)]));
              },
            ),
          ]),
        ),
      );

  /// ছাপা — পাতাটা যেমন আছে, PDF বানিয়ে ফোনের ছাপার পর্দায় (সেখান থেকে PDF সেভ বা শেয়ারও হয়)।
  Future<void> _print() async {
    final controller = _controller;
    if (controller == null) return;
    try {
      final html = WebFiles.unquote(await controller.runJavaScriptReturningResult(WebFiles.htmlScript));
      final base = 'https://${WebPaths.allowedHost}${_currentPath ?? '/'}';
      await Printing.layoutPdf(
        name: widget.title,
        // ⓘ ছাপার পাতা HTML — Android-এর WebView নিজে ছাপে না, আর HTML থেকে PDF বানানোর এটাই প্যাকেজের একমাত্র পথ
        // (Android-এ ফোনের নিজের WebView দিয়েই আঁকে); বাতিলের চিহ্ন বিকল্প ছাড়াই, তাই চুপ রাখা।
        // ignore: deprecated_member_use
        onLayout: (format) => Printing.convertHtml(format: format, html: html, baseUrl: base),
      );
    } catch (_) {
      _snack('ছাপা গেল না — পাতাটা পুরো আসার পরে আবার চেষ্টা করুন।');
    }
  }

  /// ⭐ ওয়েবের ফর্মে নথি জোড়া — ছবি তুলুন, গ্যালারি, বা যেকোনো ফাইল।
  Future<List<String>> _pickFiles(FileSelectorParams params) async {
    final imagesOnly = params.acceptTypes.isNotEmpty &&
        params.acceptTypes.every((t) => t.trim().isEmpty || t.startsWith('image/'));
    final choice = await showModalBottomSheet<String>(
      context: context,
      builder: (context) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          ListTile(
              leading: const Icon(Icons.photo_camera_outlined),
              title: const Text('ছবি তুলুন'),
              onTap: () => Navigator.of(context).pop('camera')),
          ListTile(
              leading: const Icon(Icons.photo_library_outlined),
              title: const Text('গ্যালারি থেকে'),
              onTap: () => Navigator.of(context).pop('gallery')),
          if (!imagesOnly)
            ListTile(
                leading: const Icon(Icons.attach_file),
                title: const Text('ফাইল বাছুন (PDF, এক্সেল…)'),
                onTap: () => Navigator.of(context).pop('file')),
        ]),
      ),
    );
    try {
      final multiple = params.mode == FileSelectorMode.openMultiple;
      switch (choice) {
        case 'camera':
          final shot = await ImagePicker().pickImage(source: ImageSource.camera, imageQuality: 85);
          return shot == null ? const [] : [Uri.file(shot.path).toString()];
        case 'gallery':
          if (multiple) {
            final shots = await ImagePicker().pickMultiImage(imageQuality: 85);
            return [for (final s in shots) Uri.file(s.path).toString()];
          }
          final shot = await ImagePicker().pickImage(source: ImageSource.gallery, imageQuality: 85);
          return shot == null ? const [] : [Uri.file(shot.path).toString()];
        case 'file':
          final groups = [
            XTypeGroup(
              label: 'ফাইল',
              mimeTypes: [
                for (final t in params.acceptTypes)
                  if (t.contains('/')) t.trim(),
              ],
            ),
          ];
          if (multiple) {
            final files = await openFiles(acceptedTypeGroups: groups);
            return [for (final f in files) Uri.file(f.path).toString()];
          }
          final file = await openFile(acceptedTypeGroups: groups);
          return file == null ? const [] : [Uri.file(file.path).toString()];
      }
    } catch (_) {
      _snack('ফাইলটা নেওয়া গেল না — আবার চেষ্টা করুন।');
    }
    return const [];
  }

  Future<void> _alert(String message) => showDialog<void>(
        context: context,
        builder: (context) => AlertDialog(
          content: Text(message),
          actions: [FilledButton(onPressed: () => Navigator.of(context).pop(), child: const Text('ঠিক আছে'))],
        ),
      );

  Future<bool> _confirm(String message) async =>
      await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          content: Text(message),
          actions: [
            TextButton(onPressed: () => Navigator.of(context).pop(false), child: const Text('না')),
            FilledButton(onPressed: () => Navigator.of(context).pop(true), child: const Text('হ্যাঁ')),
          ],
        ),
      ) ??
      false;

  Future<String> _prompt(String message, String? initial) async =>
      await showDialog<String>(
        context: context,
        builder: (context) => _TextDialog(message: message, initial: initial, okLabel: 'ঠিক আছে', cancelValue: ''),
      ) ??
      '';

  void _snack(String text) {
    if (!mounted) return;
    ScaffoldMessenger.maybeOf(context)
      ?..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(content: Text(text)));
  }

  /// ⭐ পিছনে — আগে ওয়েবের নিজের পিছনের পাতা, শেষে জানালা বন্ধ।
  Future<void> _back() async {
    final controller = _controller;
    if (controller != null && _error == null && await controller.canGoBack()) {
      await controller.goBack();
      return;
    }
    if (mounted) Navigator.of(context).maybePop();
  }

  @override
  Widget build(BuildContext context) {
    final url = _issuedUrl;
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) unawaited(_back());
      },
      child: Scaffold(
        appBar: AppBar(
          leading: IconButton(
            key: const ValueKey('web-inside-close'),
            tooltip: 'বন্ধ করুন',
            icon: const Icon(Icons.close),
            onPressed: () => Navigator.of(context).maybePop(),
          ),
          title: Text(widget.title, maxLines: 1, overflow: TextOverflow.ellipsis),
          actions: [
            if (url != null && _error == null) ...[
              IconButton(
                key: const ValueKey('web-inside-print'),
                tooltip: 'ছাপুন বা PDF',
                icon: const Icon(Icons.print_outlined),
                onPressed: _print,
              ),
              IconButton(
                key: const ValueKey('web-inside-reload'),
                tooltip: 'নতুন করে আনুন',
                icon: const Icon(Icons.refresh),
                onPressed: () => _controller?.reload(),
              ),
            ],
          ],
          bottom: url != null && _error == null && _progress < 100
              ? PreferredSize(
                  preferredSize: const Size.fromHeight(3),
                  child: LinearProgressIndicator(
                      value: _progress <= 0 ? null : _progress / 100, minHeight: 3),
                )
              : null,
        ),
        body: _body(url),
      ),
    );
  }

  Widget _body(String? url) {
    if (_error != null) {
      return Padding(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
          EmptyState(icon: Icons.public_off_outlined, title: 'পাতাটা খোলা গেল না', message: _error),
          const SizedBox(height: AppSpacing.md),
          FilledButton.icon(
            key: const ValueKey('web-inside-retry'),
            onPressed: () => _open(_currentPath ?? widget.webPath),
            icon: const Icon(Icons.refresh),
            label: const Text('আবার চেষ্টা করুন'),
          ),
        ]),
      );
    }
    if (_asking || url == null) {
      return const Center(child: CircularProgressIndicator());
    }
    final builder = widget.webBuilder;
    if (builder != null) return builder(context, url, _headers);
    final controller = _controller;
    if (controller == null) return const SizedBox.shrink();
    return WebViewWidget(controller: controller);
  }
}

/// A dialog with one text box — it owns its own controller, so the box outlives no dispose while the dialog animates
/// away.
class _TextDialog extends StatefulWidget {
  const _TextDialog({
    required this.message,
    required this.okLabel,
    required this.cancelValue,
    this.title,
    this.messageIsError = false,
    this.fieldKey,
    this.hint,
    this.numeric = false,
    this.initial,
  });

  final String? title;
  final String message;
  final bool messageIsError;
  final Key? fieldKey;
  final String? hint;
  final bool numeric;
  final String? initial;
  final String okLabel;

  /// What "থাক" returns — null for the code (nothing opens), '' for a page's prompt().
  final String? cancelValue;

  @override
  State<_TextDialog> createState() => _TextDialogState();
}

class _TextDialogState extends State<_TextDialog> {
  late final TextEditingController _field = TextEditingController(text: widget.initial ?? '');

  @override
  void dispose() {
    _field.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
        title: widget.title == null ? null : Text(widget.title!),
        content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(widget.message, style: TextStyle(color: widget.messageIsError ? AppColors.danger : null)),
          const SizedBox(height: AppSpacing.sm),
          TextField(
            key: widget.fieldKey,
            controller: _field,
            autofocus: true,
            keyboardType: widget.numeric ? TextInputType.number : TextInputType.text,
            maxLength: widget.numeric ? 16 : null,
            decoration: InputDecoration(hintText: widget.hint),
          ),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(widget.cancelValue), child: const Text('থাক')),
          FilledButton(
            onPressed: () {
              final text = _field.text.trim();
              Navigator.of(context).pop(widget.numeric && text.isEmpty ? widget.cancelValue : _field.text);
            },
            child: Text(widget.okLabel),
          ),
        ],
      );
}
