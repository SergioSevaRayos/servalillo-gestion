/// Última APK publicada (`GET /api/device` → `app_update`). Si su [versionCode] es mayor que el
/// de la app instalada, la app se actualiza sola (docs/24).
class AppUpdateInfo {
  const AppUpdateInfo({
    required this.versionCode,
    required this.sha256,
    required this.size,
    this.version,
  });

  final String? version;
  final int versionCode;
  final String sha256; // hex
  final int size;

  static AppUpdateInfo? fromJson(Object? json) {
    if (json is! Map) return null;
    final Object? code = json['version_code'];
    final Object? sha = json['sha256'];
    if (code is! num || sha is! String || sha.isEmpty) return null;
    final Object? size = json['size'];
    final Object? version = json['version'];
    return AppUpdateInfo(
      version: version is String ? version : null,
      versionCode: code.toInt(),
      sha256: sha.toLowerCase(),
      size: size is num ? size.toInt() : 0,
    );
  }
}
