import 'dart:convert';
import 'dart:io';

import 'package:abos_mobile/core/api_client/api_client.dart';
import 'package:abos_mobile/core/api_client/pinned_trust.dart';
import 'package:crypto/crypto.dart';
import 'package:dio/dio.dart';
import 'package:dio/io.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ লাইভ সার্ভার কেবল পাঁচ মূলে বাঁধা — অ্যাপের নিরাপত্তা, OWASP MASVS-NETWORK-2, ১৯ অক্টোবর ২০২৬ ([[PinnedTrust]])।
///
/// দাবি:
///  - অ্যাপে বসানো মূলগুলো ঠিক ISRG Root X1, X2, USERTrust RSA, আর (২০ অক্টোবর ২০২৬) Let's Encrypt-এর নতুন Root YR ও YE —
///    DER-এর SHA-256 ছাপ মিলিয়ে (letsencrypt.org/certificates-এর DER আর PEM দুটো থেকে, openssl দিয়ে নেওয়া);
///  - Let's Encrypt-এর শৃঙ্খলগুলো এই মূলেই মেলে: আজকের (লাইভ থেকে নেওয়া: পাতা ← YR2 ← Root YR ← X1), ক্রস-সই ছাড়া নতুনটা
///    (পাতা ← YR2 ← Root YR), আর ECDSA-রটা (YE2 ← Root YE); অচেনা মূল বা মাঝের সার্টিফিকেট ছাড়া পাতা মেলে না;
///  - WebView-এর pin (android network_security_config.xml) ঠিক এই পাঁচ মূলের SPKI ছাপ, মেয়াদসহ;
///  - pin কেবল লাইভের ঠিকানায়, পরীক্ষার LAN-এ নয়;
///  - অন্য মূলের সার্টিফিকেট দেখানো সার্ভারে pin-করা অ্যাডাপ্টার পৌঁছায় না, অথচ একই অ্যাডাপ্টার সেই মূলকে বিশ্বাস করালে
///    পৌঁছায় — ফেরানোর কারণ মূলের তালিকাই, অন্য কিছু নয়।
void main() {
  const fingerprints = {
    'ISRG Root X1': '96bcec06264976f37460779acf28c5a7cfe8a3c0aae11a8ffcee05c0bddf08c6',
    'ISRG Root X2': '69729b8e15a86efc177a57afb7171dfc64add28c2fca8cf1507e34453ccb1470',
    'USERTrust RSA': 'e793c9b02fd8aa13e21c31228accb08119643b749c898964b1746d46c3d4cbd2',
    'ISRG Root YR': 'e57b7e6f150c419102e8d5c055729ff967b9d1a829bf00cec89ca604ebf4a86f',
    'ISRG Root YE': 'e14ffcad5b0025731006caa43a121a22d8e9700f4fb9cf852f02a708aa5d5666',
  };

  String sha256OfPem(String pem) {
    final body = pem.split('\n').where((l) => l.isNotEmpty && !l.startsWith('-----')).join();
    return sha256.convert(base64Decode(body)).toString();
  }

  test('the app carries exactly the five roots, byte for byte', () {
    expect(PinnedTrust.roots.map(sha256OfPem).toList(), fingerprints.values.toList(),
        reason: '⛔ বসানো মূল বদলে গেছে — ভুল মূলে pin করলে সব ফোন সার্ভারে পৌঁছাবে না');
    expect(PinnedTrust.context, returnsNormally);
  });

  test('the pin applies to the live address only', () {
    expect(PinnedTrust.pinsFor(Uri.parse('https://erp.adi.com.bd/api/v1')), isTrue);
    expect(PinnedTrust.pinsFor(Uri.parse('http://192.168.0.10:8090/api/v1')), isFalse);
    expect(PinnedTrust.pinsFor(Uri.parse('https://example.com/api/v1')), isFalse);
  });

  test('the client pins on the live address and not on a test LAN address', () {
    final live = ApiClient.buildFor('https://erp.adi.com.bd/api/v1').httpClientAdapter;
    expect(live, isA<IOHttpClientAdapter>());
    expect((live as IOHttpClientAdapter).createHttpClient, isNotNull,
        reason: '⛔ লাইভের ক্লায়েন্টে pin বসেনি — যেকোনো স্বীকৃত সার্টিফিকেট চলত');

    final lan = ApiClient.buildFor('http://192.168.0.10:8090/api/v1').httpClientAdapter;
    expect(lan is IOHttpClientAdapter && lan.createHttpClient != null, isFalse,
        reason: '⛔ পরীক্ষার LAN-এও pin — ডেভেলপারের সার্ভারে পৌঁছানো যেত না');
  });

  group("the chains Let's Encrypt serves", () {
    late String openssl;
    late Directory dir;
    late String pinned;

    setUpAll(() async {
      openssl = await _openssl();
      dir = await Directory.systemTemp.createTemp('pins');
      pinned = '${dir.path}/pinned.pem';
      await File(pinned).writeAsString(PinnedTrust.roots.join('\n'));
    });

    tearDownAll(() => dir.delete(recursive: true));

    // ⓘ লাইভের পাতা ১৪ ডিসেম্বর ২০২৬-এ শেষ — যাচাই ২০ অক্টোবর ২০২৬-এর সময়ে (-attime), তাই টেস্ট মেয়াদে পচে না
    Future<bool> verifies(String cert, {List<String> between = const []}) async {
      final untrusted = '${dir.path}/between.pem';
      await File(untrusted).writeAsString(between.map((f) => File('test/fixtures/pins/$f').readAsStringSync()).join());
      final r = await Process.run(openssl, [
        'verify', '-attime', '1792497600', '-CAfile', pinned,
        if (between.isNotEmpty) ...['-untrusted', untrusted],
        cert,
      ]);
      return r.exitCode == 0;
    }

    test('the chain of today, the new chain without the cross-sign, and the ECDSA chain all verify', () async {
      expect(await verifies('test/fixtures/pins/live-leaf.pem', between: ['yr2.pem', 'root-yr-by-x1.pem']), isTrue,
          reason: '⛔ আজকের লাইভ শৃঙ্খল মেলে না — সব ফোন লাইভ হারাবে');
      expect(await verifies('test/fixtures/pins/live-leaf.pem', between: ['yr2.pem']), isTrue,
          reason: '⛔ ক্রস-সই ছাড়া নতুন শৃঙ্খল মেলে না — LE ক্রস-সই ছাড়লে সব ফোন লাইভ হারাবে (Root YR নেই)');
      expect(await verifies('test/fixtures/pins/ye2.pem'), isTrue,
          reason: '⛔ ECDSA শৃঙ্খল (YE2 ← Root YE) মেলে না — Root YE নেই');
      expect(await verifies('test/fixtures/pins/ye2.pem', between: ['root-ye-by-x2.pem']), isTrue);
    });

    test('an unknown root, or the page without its intermediate, does not verify', () async {
      final stranger = '${dir.path}/stranger.pem';
      await File(stranger).writeAsString(_cert);
      expect(await verifies(stranger), isFalse, reason: '⛔ অচেনা মূলের সার্টিফিকেট মিলে গেল');
      expect(await verifies('test/fixtures/pins/live-leaf.pem'), isFalse,
          reason: '⛔ মাঝের সার্টিফিকেট ছাড়াই পাতা মিলল — যাচাইটা আসলে কিছু দেখছে না');
    });

    test('the WebView pins are exactly these roots, with an expiry', () async {
      final xml = File('android/app/src/main/res/xml/network_security_config.xml').readAsStringSync();
      final pins = RegExp(r'<pin digest="SHA-256">([^<]+)</pin>').allMatches(xml).map((m) => m.group(1)).toSet();

      final spki = <String>{};
      for (final (i, root) in PinnedTrust.roots.indexed) {
        final file = '${dir.path}/root$i.pem';
        await File(file).writeAsString(root);
        final r = await Process.run(openssl, ['x509', '-in', file, '-pubkey', '-noout']);
        final body = (r.stdout as String).split(RegExp(r'\r?\n')).where((l) => l.isNotEmpty && !l.startsWith('-----')).join();
        spki.add(base64Encode(sha256.convert(base64Decode(body)).bytes));
      }
      expect(spki, hasLength(PinnedTrust.roots.length));
      expect(pins, spki, reason: '⛔ ওয়েব পাতার pin আর অ্যাপের মূল আলাদা — একটা বদলে আরেকটা ভুলে যাওয়া হয়েছে');
      expect(xml, contains('<domain includeSubdomains="true">${PinnedTrust.host}</domain>'));

      final expiry = DateTime.parse(RegExp(r'expiration="([0-9-]+)"').firstMatch(xml)!.group(1)!);
      expect(expiry.isAfter(DateTime(2026, 10, 20)) && expiry.isBefore(DateTime(2028, 10, 21)), isTrue,
          reason: '⛔ মেয়াদ নেই বা দুই বছরের বেশি — ভুল pin-এ পুরনো অ্যাপ বহু দিন আটকে থাকত');
    });
  });

  group('a server under another root', () {
    late HttpServer server;
    late SecurityContext otherRoot;

    setUpAll(() async {
      final serverContext = SecurityContext()
        ..useCertificateChainBytes(utf8.encode(_cert))
        ..usePrivateKeyBytes(utf8.encode(_key));
      server = await HttpServer.bindSecure(InternetAddress.loopbackIPv4, 0, serverContext);
      server.listen((r) => r.response
        ..write('ok')
        ..close());
      otherRoot = SecurityContext(withTrustedRoots: false)..setTrustedCertificatesBytes(utf8.encode(_cert));
    });

    tearDownAll(() => server.close(force: true));

    test('is refused by the pinned adapter, and reached by the same adapter told to trust that root', () async {
      final url = 'https://localhost:${server.port}/ping';

      final pinned = Dio()..httpClientAdapter = PinnedTrust.adapter();
      await expectLater(pinned.get<String>(url), throwsA(isA<DioException>()),
          reason: '⛔ তিন মূলের বাইরের সার্টিফিকেট মেনে নিল — মাঝপথে কেউ টোকেন পড়তে পারত');

      final trusting = Dio()..httpClientAdapter = PinnedTrust.adapter(context: () => otherRoot);
      final ok = await trusting.get<String>(url);
      expect(ok.data, 'ok', reason: 'প্রস্তুতি: পরীক্ষার সার্ভারেই পৌঁছানো গেল না — দাবির ভিত্তি নেই');
    });
  });
}

/// openssl — PATH-এ, নইলে Git-এর সাথে আসাটা; ⛔ না পেলে টেস্ট ফেল করে, চুপচাপ বাদ দেয় না
Future<String> _openssl() async {
  for (final c in ['openssl', r'C:\Program Files\Git\usr\bin\openssl.exe', r'C:\Program Files\Git\mingw64\bin\openssl.exe']) {
    try {
      if ((await Process.run(c, ['version'])).exitCode == 0) return c;
    } on ProcessException {
      continue;
    }
  }
  fail('openssl পাওয়া গেল না — শৃঙ্খলের দাবি চালানো যায় না');
}

/// পরীক্ষার নিজের সই করা সার্টিফিকেট (localhost) — তিন মূলের বাইরে
const _cert = '''
-----BEGIN CERTIFICATE-----
MIIDJzCCAg+gAwIBAgIUSr4QdBqXch7JtFUQk2Vc1F3crFYwDQYJKoZIhvcNAQEL
BQAwFDESMBAGA1UEAwwJbG9jYWxob3N0MCAXDTI2MTAxOTE5MTIzN1oYDzIxMjYw
OTI1MTkxMjM3WjAUMRIwEAYDVQQDDAlsb2NhbGhvc3QwggEiMA0GCSqGSIb3DQEB
AQUAA4IBDwAwggEKAoIBAQCVOGUKD9KLj2TsjD6m5zrDxh0vW+6G95fzylIRcqid
5o2FqBmh7YoNJKr+w2W59+eVffJByilJB3vYCcWKGLQmZQFIkkyjz0SoXl2Z0VDY
2u04q0e469n0cGj3Ulmm2CEBsluJagYN2fsrc+MHHSYlILTwcssZPdu9BPehzq3M
lB5tnpufUmb0WKZN8+XRh2Tq786l/ovtwY0C/mHr0iOXJOR7Nc7zGtLlMQUYDbmD
mVnSzT1uBrhP2og3Wi7w95Q8oM4BaEsQpZKwtp3KVncnhoy8KheCA5QQpgqaYOtI
7krpM7sMT2okoaubtheLJaifLFWtIougf73os4vPrZ/RAgMBAAGjbzBtMB0GA1Ud
DgQWBBSa5A/vToKCSTH8zYp03hh3rysMajAfBgNVHSMEGDAWgBSa5A/vToKCSTH8
zYp03hh3rysMajAPBgNVHRMBAf8EBTADAQH/MBoGA1UdEQQTMBGCCWxvY2FsaG9z
dIcEfwAAATANBgkqhkiG9w0BAQsFAAOCAQEAMihiS4eAlzqHhGGD0jouQuULqDdl
JLIkGcwHPjvG+oJzaZKx6lu0t1S/Ej66n9077fRb6xPMUJgzNXex41dNjLyN+ZNM
BIJIgB31XvMiLWHZPQFiiyKVfa3tEViEUu0ont/Wsq0sZmUtNeA2nBw/oV3yRjS8
AKdCyJH1d1adpKym+BH+RTL0hd5d6FHV3h2hqkwsu/g7B+CqtBW3jK430yPuu4Mf
rnbtRUTr0jAYsZPwgtGT9iDf3ws7y8f+Cw4mpw6pbKo1LecPjcSMfJmoos0VJqiI
rD1L1z9Dn66ibqYX6yS64uqI3t4lbLd1ODsAY406WiFjPYY/MS+gKXWmaA==
-----END CERTIFICATE-----
''';

const _key = '''
-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQCVOGUKD9KLj2Ts
jD6m5zrDxh0vW+6G95fzylIRcqid5o2FqBmh7YoNJKr+w2W59+eVffJByilJB3vY
CcWKGLQmZQFIkkyjz0SoXl2Z0VDY2u04q0e469n0cGj3Ulmm2CEBsluJagYN2fsr
c+MHHSYlILTwcssZPdu9BPehzq3MlB5tnpufUmb0WKZN8+XRh2Tq786l/ovtwY0C
/mHr0iOXJOR7Nc7zGtLlMQUYDbmDmVnSzT1uBrhP2og3Wi7w95Q8oM4BaEsQpZKw
tp3KVncnhoy8KheCA5QQpgqaYOtI7krpM7sMT2okoaubtheLJaifLFWtIougf73o
s4vPrZ/RAgMBAAECggEABEubyPbcoizOBGsjEI3eaK1GwMq+Kn9tDdT4mMz6opT0
5PCKUFl0nF0+WNgKERjcPzbhZthpaNNA0t6xI4EDiolkyJCBiUOZn9kbi6Z1U9C1
fGidx2pumzUzKE9kCkP1a0WVkfaN1w87xivvUJCbvPzciIFdbEwWC7JdaHh5E0by
8p6+1j+g9op6mwa3lvA/hFHuvmlJORe2wiZFaFn1v+brmoM95lCe3JqMpnBme59j
UWi29MXisZ8vbwF+s+LaZ4BSHhOiswJwGk+Nf7A8d5BCYN2FEUjuJ2gk2Cx3Ejzw
ye+0sxuEyR00PBDmFZ42XbOTZl7Kh0mKR4OgsJ5MawKBgQDSpFx9/hqnZfPEZ7IG
c/3U/Qxf2lM3gCzAtmlU4T+gYypRe6+SC2Rc97wrU1YIfyL6yAJs41I86p2bHtBs
gbx4ws0LBfpx4jIhtkv8OM2m4YH7rDzjiOkoWyD0h8iDBOx69eJYytzaDM2qx9zx
2EL9nd6vc73iS2c5tDbJqCz4dwKBgQC1WiknD9GawhqiUqN+YsirVORxH6oFvOg0
4BXSzxXbfst8NZ9JYcXoFuzYAYE31VLkvDeHiU030LrIBgZOBObMLasaYzS/pmRc
/xO9jts7GO8znXoAgEMA7zhhjBLYH6M5nEQzzao2/MHW/rYvD1NNULJCBCwn3OxW
MevwDkOD9wKBgARgqhNsPn2QHtrMFOURcAsHH0S2stgKb+OpZtL+w2W/6dc7rNBq
MDHGbECyQK14hu+TTImax9DAR3o7z7URlSslUUGTZDsNqmEM+tNJEu0O7raeQ72u
0Lmn+y1ZzQ78ATYk+glCXgiiD3wnHy5fgjTnHQOkciPL0V9foWvbdOPNAoGBAK8G
DwWF/NMRVkcm3meRyv2f9sE+wAa9pJWzVxyXhA7MtnJwBgT6edhel8J44U8TcOcU
x7rlRkMCDeIR/m+5x01zHdlfuX7lhaNDArhFcUv96o20BBULtJ7oQU/TsjQnhI6L
RD+C1C4xjpqCRS3x6oGJyiBwinUGgIlHsN+DxKFNAoGAbBMhRKJfs1TlWK64Q6UZ
d9RWeoq3MNOOQumhHR76LwbzrQGzeJ4Gxp7Ta+DBWocbQQdeNYgxdc9AxUteJbO7
+0lR2FrYw7a6cjNcMIHxqFE4Vau9aeQGkuFRXOs47ptcoih4zdqYuwOyHTwRN8Nt
w4yeHg6BrsgInf9AoxrAtRs=
-----END PRIVATE KEY-----
''';
