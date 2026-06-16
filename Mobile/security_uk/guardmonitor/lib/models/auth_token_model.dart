import 'guard_profile_model.dart';

class AuthTokenModel {
  const AuthTokenModel({
    required this.tokenType,
    required this.accessToken,
    required this.expiresAt,
    this.refreshToken,
    this.guard,
  });

  final String tokenType;
  final String accessToken;
  final DateTime expiresAt;
  final String? refreshToken;
  final GuardProfileModel? guard;

  /// `POST /auth/login` response — always includes `refresh_token` and `guard`.
  factory AuthTokenModel.fromJson(Map<String, dynamic> json) {
    return AuthTokenModel(
      tokenType: json['token_type'] as String? ?? 'Bearer',
      accessToken: json['access_token'] as String,
      refreshToken: json['refresh_token'] as String?,
      expiresAt: DateTime.parse(json['expires_at'] as String),
      guard: json['guard'] != null
          ? GuardProfileModel.fromJson(json['guard'] as Map<String, dynamic>)
          : null,
    );
  }
}
