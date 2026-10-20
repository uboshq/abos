import 'dart:ui' as ui;

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/painting.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api_client/api_client.dart';

/// ⭐ নিজের মুখের ঠিকানা — হোম `/me` থেকে লেখে, ছবির পর্দা বসানো বা সরানোর পরে (a6, ২০ অক্টোবর ২০২৬)। ⓘ null = ছবি নেই
/// (নামের প্রথম অক্ষর)। হোম এটা শোনে, তাই নতুন মুখ সাথে সাথে ওপরের বার, ছবির মেনু আর "আমি" পাতায় — `/me` আবার না ডেকেই।
final myFaceProvider = StateProvider<String?>((ref) => null);

/// ⭐ মানুষের ছবি, টোকেনসহ — সার্ভারের ছবির দরজা (`GET /users/{public_id}/photo`) `Authorization: Bearer` চায়
/// (a6/me-photo-devices: ছবি আর public ফাইল নয়, কেবল সহকর্মী দেখেন)।
///
/// <p>⛔ <b>কেন হেডারসহ সাধারণ `NetworkImage` নয়</b>: ওটা Dart-এর সাধারণ HttpClient দিয়ে আনে — অ্যাপের [ApiClient]-এর
/// বাইরে। তাতে (১) লাইভের তিন-মূল pinning থাকে না ([PinnedTrust], MASVS-NETWORK-2) অথচ টোকেন যায়, আর (২) ৩০ মিনিটের access
/// টোকেন ফুরোলে ৪০১ — নবায়ন কেউ করে না, মুখ চলে যায়। এখানে আনা [ApiClient.dio] দিয়ে: একই টোকেন-ভাণ্ডার, একই pinning, ৪০১-এ
/// একবার নবায়ন।
///
/// <p>⛔ টোকেন কেবল অ্যাপের নিজের সার্ভারে ([sameOrigin]) — অন্য ঠিকানার ছবিতে (পুরনো কোনো বাইরের লিংক) টোকেন যায় না।
///
/// <p>⭐ ক্যাশ: চাবি কেবল ঠিকানা (টোকেন নয়), আর ঠিকানায় `v=` ছাপ — ছবি না বদলালে একই চাবি, Flutter-এর ImageCache থেকে আসে,
/// নতুন টোকেনে আবার নামে না; বদলালে নতুন ঠিকানা, পুরনোটা আর দেখায় না। ⓘ ফোনের ডিস্কে মুখ জমা রাখা হয় না (গোপনীয়তা)।
@immutable
class FaceImage extends ImageProvider<FaceImage> {
  const FaceImage(this.url, {this.fetch});

  final String url;

  /// পরীক্ষার পথ — বাইট আনা; না দিলে [fetchBytes]
  final Future<Uint8List> Function(String url)? fetch;

  @override
  Future<FaceImage> obtainKey(ImageConfiguration configuration) => SynchronousFuture<FaceImage>(this);

  @override
  ImageStreamCompleter loadImage(FaceImage key, ImageDecoderCallback decode) => OneFrameImageStreamCompleter(
        _load(key, decode),
        informationCollector: () => [DiagnosticsProperty<String>('Face', key.url)],
      );

  Future<ImageInfo> _load(FaceImage key, ImageDecoderCallback decode) async {
    final bytes = await (key.fetch ?? fetchBytes)(key.url);
    if (bytes.isEmpty) throw StateError('empty face');
    final buffer = await ui.ImmutableBuffer.fromUint8List(bytes);
    final codec = await decode(buffer);
    final frame = await codec.getNextFrame();
    return ImageInfo(image: frame.image, debugLabel: 'face');
  }

  /// ছবির বাইট — নিজের সার্ভারে টোকেনসহ ([ApiClient] নিজে বসায়), অন্য ঠিকানায় টোকেন ছাড়া
  static Future<Uint8List> fetchBytes(String url, {Dio? dio}) async {
    final client = dio ?? ApiClient.dio;
    final ours = sameOrigin(Uri.parse(url), Uri.parse(client.options.baseUrl));
    final response = await client.get<List<int>>(
      url,
      options: Options(
        responseType: ResponseType.bytes,
        headers: const {'Accept': 'image/jpeg, image/png, image/*'},
        extra: ours ? null : ApiClient.skipAuthRefresh,
      ),
    );
    final data = response.data;
    return data == null ? Uint8List(0) : Uint8List.fromList(data);
  }

  /// একই scheme, host আর port — তবেই টোকেন
  static bool sameOrigin(Uri a, Uri b) =>
      a.scheme.toLowerCase() == b.scheme.toLowerCase() &&
      a.host.toLowerCase() == b.host.toLowerCase() &&
      a.port == b.port;

  @override
  bool operator ==(Object other) => other is FaceImage && other.url == url;

  @override
  int get hashCode => url.hashCode;

  @override
  String toString() => 'FaceImage("$url")';
}
