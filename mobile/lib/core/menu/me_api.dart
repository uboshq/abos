import '../api_client/api_client.dart';
import '../auth/auth_user.dart';
import '../auth/session_profile.dart';
import 'menu_module.dart';

/// `GET /me` — who is signed in, which company/branch, and their menu.
///
/// <p>⚠️ Needs the `app` ability, not `sync` — a plain refresh token cannot
/// call this (403), which never matters here since [ApiClient] only ever
/// attaches the access token, and the access token carries both abilities.
class MeApi {
  const MeApi._();

  static Future<MeResponse> fetch() async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('/me');
    final body = response.data ?? const {};
    final userJson = body['user'] as Map<String, dynamic>? ?? const {};
    return MeResponse(
      user: AuthUser(
        id: AuthUser.fromJson(userJson).id,
        name: userJson['name'] as String? ?? '',
        email: userJson['email'] as String? ?? '',
        roles: ((userJson['roles'] as List?) ?? const [])
            .map((e) => e.toString())
            .toList(),
        permissions: ((body['permissions'] as List?) ?? const [])
            .map((e) => e.toString())
            .toList(),
      ),
      profile: SessionProfile.fromJson(body),
      menu: ((body['menu'] as List?) ?? const [])
          .map((e) => MenuModule.fromJson(e as Map<String, dynamic>))
          .toList(),
    );
  }
}

class MeResponse {
  const MeResponse({required this.user, required this.profile, required this.menu});

  final AuthUser user;
  final SessionProfile profile;
  final List<MenuModule> menu;
}
