import 'dart:convert';

/// ⭐ ওয়েবের ফাইল — ডাউনলোড, ছাপা আর টেনে নামিয়ে নতুন করে আনা, অ্যাপের ভিতরের জানালায় ([WebInsideScreen])।
///
/// <p>Android-এর WebView নিজে কোনো ফাইল নামায় না, `window.print()`-এ কিছুই করে না, আর ওয়েবের পাতা টেনে নতুন করার উপায়
/// রাখে না। তাই জানালাটা পাতার ভিতরে তিনটা ছোট কাজ বসায় — সব একই সেশনে, একই ঠিকানায়, কোনো চাবি অ্যাপের হাতে আসে না:
///
///   ⭐ ফাইলের লিংক (ডাউনলোড, এক্সপোর্ট, PDF) → পাতা নিজের সেশনেই ফাইলটা আনে, অ্যাপ সেটা ফোনের "শেয়ার/সেভ"-এ দেয়।
///   ⭐ ছাপা → অ্যাপ পাতাটা PDF বানিয়ে ফোনের ছাপার পর্দা খোলে (সেখান থেকেই PDF সেভ বা শেয়ার)।
///   ⭐ একদম উপরে থেকে নিচে টানলে → পাতা নতুন করে আসে।
class WebFiles {
  const WebFiles._();

  /// The JavaScript channel the page posts a fetched file to.
  static const String fileChannel = 'AbosFile';

  /// The channel `window.print()` and the pull-down post to.
  static const String pageChannel = 'AbosPage';

  static final RegExp _fileSegment = RegExp(r'(^|/)(download|export|exports|pdf)(/|$)', caseSensitive: false);
  static final RegExp _fileExtension =
      RegExp(r'\.(pdf|xlsx?|csv|zip|docx?|pptx?|txt|png|jpe?g)$', caseSensitive: false);

  /// Whether a same-site address is a file rather than a page — the web's download, export and PDF routes, a file
  /// extension, or a list's own `?export=csv` ([ExportListing] on the server).
  static bool isDownload(Uri uri) {
    if (uri.queryParameters.containsKey('export') || uri.queryParameters['download'] == '1') return true;
    return _fileSegment.hasMatch(uri.path) || _fileExtension.hasMatch(uri.path);
  }

  /// The name a file should carry — the server's own (`Content-Disposition`), else the address's last part.
  static String fileNameFor(Uri uri, {String? contentDisposition, String? mime}) {
    final fromHeader = _fromDisposition(contentDisposition);
    var name = fromHeader ?? (uri.pathSegments.isEmpty ? '' : uri.pathSegments.last);
    name = name.replaceAll(RegExp(r'[\\/:*?"<>|\x00-\x1F]'), '_').trim();
    if (name.isEmpty) name = 'abos-file';
    if (!name.contains('.')) {
      final ext = switch (mime ?? '') {
        'application/pdf' => 'pdf',
        'text/csv' => 'csv',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        _ => null,
      };
      if (ext != null) name = '$name.$ext';
    }
    return name.length > 120 ? name.substring(name.length - 120) : name;
  }

  static String? _fromDisposition(String? header) {
    if (header == null || header.isEmpty) return null;
    final star = RegExp(r"filename\*\s*=\s*(?:UTF-8|utf-8)''([^;]+)").firstMatch(header);
    if (star != null) {
      try {
        return Uri.decodeComponent(star.group(1)!.trim());
      } catch (_) {}
    }
    final plain = RegExp(r'filename\s*=\s*"?([^";]+)"?').firstMatch(header);
    return plain?.group(1)?.trim();
  }

  /// A fetched file the page posted — `{name, mime, disposition, data(base64)}`; null when it is not one.
  static ({String name, String mime, List<int> bytes})? decodePosted(String message, Uri from) {
    try {
      final json = jsonDecode(message);
      if (json is! Map || json['data'] is! String) return null;
      final mime = (json['mime'] as String?)?.split(';').first.trim() ?? 'application/octet-stream';
      final bytes = base64Decode(json['data'] as String);
      final url = Uri.tryParse((json['url'] as String?) ?? '') ?? from;
      return (
        name: fileNameFor(url, contentDisposition: json['disposition'] as String?, mime: mime),
        mime: mime.isEmpty ? 'application/octet-stream' : mime,
        bytes: bytes,
      );
    } catch (_) {
      return null;
    }
  }

  /// Fetch [url] inside the page (its own session cookie) and post the file to [fileChannel].
  static String fetchScript(Uri url) => '''
(function(u){
  fetch(u, {credentials: 'same-origin'}).then(function(r){
    if (!r.ok) { $fileChannel.postMessage(JSON.stringify({error: r.status})); return; }
    var d = r.headers.get('Content-Disposition') || '';
    var m = r.headers.get('Content-Type') || '';
    return r.blob().then(function(b){
      var f = new FileReader();
      f.onloadend = function(){
        var s = String(f.result || ''); var i = s.indexOf(',');
        $fileChannel.postMessage(JSON.stringify({url: u, disposition: d, mime: m, data: i < 0 ? '' : s.substring(i + 1)}));
      };
      f.readAsDataURL(b);
    });
  }).catch(function(){ $fileChannel.postMessage(JSON.stringify({error: 0})); });
})(${jsonEncode(url.toString())});
''';

  /// Set once per page: `window.print()` asks the app, a link with `download` goes through [fetchScript], and a
  /// pull-down from the very top asks for a fresh page.
  static const String pageScript = '''
(function(){
  if (window.__abosInApp) return; window.__abosInApp = true;
  window.print = function(){ $pageChannel.postMessage('print'); };
  document.addEventListener('click', function(e){
    var a = e.target && e.target.closest ? e.target.closest('a[download]') : null;
    if (!a || !a.href) return;
    e.preventDefault(); $pageChannel.postMessage('download:' + a.href);
  }, true);
  var y0 = null;
  window.addEventListener('touchstart', function(e){
    y0 = (window.scrollY <= 0 && e.touches.length === 1) ? e.touches[0].clientY : null;
  }, {passive: true});
  window.addEventListener('touchend', function(e){
    if (y0 === null || !e.changedTouches.length) return;
    if (e.changedTouches[0].clientY - y0 > 110 && window.scrollY <= 0) $pageChannel.postMessage('refresh');
    y0 = null;
  }, {passive: true});
})();
''';

  /// The page as it is now, for printing.
  static const String htmlScript = 'document.documentElement.outerHTML';

  /// `runJavaScriptReturningResult` hands a string back JSON-quoted on Android — unquoted here.
  static String unquote(Object result) {
    final text = result.toString();
    if (text.length >= 2 && text.startsWith('"') && text.endsWith('"')) {
      try {
        final decoded = jsonDecode(text);
        if (decoded is String) return decoded;
      } catch (_) {}
    }
    return text;
  }
}
