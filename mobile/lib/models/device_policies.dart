import 'dart:convert';

/// Restricciones del terminal que se piden desde la web (`GET /api/device` → `policies`) o que
/// hay aplicadas de verdad en el móvil. Ver docs/24.
class DevicePolicies {
  const DevicePolicies({
    this.blockScreenCapture = false,
    this.blockUninstall = false,
    this.blockFactoryReset = false,
    this.blockSafeBoot = false,
    this.release = false,
    this.lostMode = false,
    this.lostMessage,
  });

  final bool blockScreenCapture;
  final bool blockUninstall;
  final bool blockFactoryReset;
  final bool blockSafeBoot;

  /// Se ha pedido "Liberar terminal": quitarlo todo y dejar de ser Device Owner.
  final bool release;

  /// Modo perdido (robo o pérdida): bloquear la pantalla y mostrar [lostMessage]. No lo quita
  /// la suspensión temporal de 30 min. docs/24.
  final bool lostMode;
  final String? lostMessage;

  static const DevicePolicies none = DevicePolicies();

  /// Las mismas, sin las restricciones (para la suspensión temporal) pero manteniendo el modo perdido.
  DevicePolicies withoutRestrictions() => DevicePolicies(
    release: release,
    lostMode: lostMode,
    lostMessage: lostMessage,
  );

  bool get any =>
      blockScreenCapture ||
      blockUninstall ||
      blockFactoryReset ||
      blockSafeBoot;

  factory DevicePolicies.fromJson(Map<String, dynamic> json) => DevicePolicies(
    blockScreenCapture: json['block_screen_capture'] == true,
    blockUninstall: json['block_uninstall'] == true,
    blockFactoryReset: json['block_factory_reset'] == true,
    blockSafeBoot: json['block_safe_boot'] == true,
    release: json['release'] == true,
    lostMode: json['lost_mode'] == true,
    lostMessage: json['lost_message'] is String
        ? json['lost_message'] as String
        : null,
  );

  Map<String, dynamic> toJson() => <String, dynamic>{
    'block_screen_capture': blockScreenCapture,
    'block_uninstall': blockUninstall,
    'block_factory_reset': blockFactoryReset,
    'block_safe_boot': blockSafeBoot,
    'release': release,
    'lost_mode': lostMode,
    'lost_message': lostMessage,
  };

  String encode() => jsonEncode(toJson());

  static DevicePolicies decode(String? raw) {
    if (raw == null || raw.isEmpty) return none;
    try {
      return DevicePolicies.fromJson(jsonDecode(raw) as Map<String, dynamic>);
    } catch (_) {
      return none;
    }
  }
}

/// Lo que el móvil tiene aplicado de verdad (lo devuelve el plugin nativo).
class AppliedPolicies {
  const AppliedPolicies({required this.deviceOwner, required this.policies});

  final bool deviceOwner;
  final DevicePolicies policies;

  static const AppliedPolicies unknown = AppliedPolicies(
    deviceOwner: false,
    policies: DevicePolicies.none,
  );

  factory AppliedPolicies.fromNative(Map<String, bool> raw) => AppliedPolicies(
    deviceOwner: raw['device_owner'] ?? false,
    policies: DevicePolicies.fromJson(raw),
  );

  /// Cuerpo de `POST /api/device/state`.
  Map<String, dynamic> toReport() => <String, dynamic>{
    'device_owner': deviceOwner,
    'applied': <String, bool>{
      'block_screen_capture': policies.blockScreenCapture,
      'block_uninstall': policies.blockUninstall,
      'block_factory_reset': policies.blockFactoryReset,
      'block_safe_boot': policies.blockSafeBoot,
      'lost_mode': policies.lostMode,
    },
  };
}

/// Clave de la APK: solo hash PBKDF2 + sal (la clave nunca viaja en claro).
class UnlockConfig {
  const UnlockConfig({
    required this.hash,
    required this.salt,
    required this.iterations,
  });

  final String hash;
  final String salt;
  final int iterations;

  static UnlockConfig? fromJson(Object? json) {
    if (json is! Map) return null;
    final Object? hash = json['hash'];
    final Object? salt = json['salt'];
    final Object? it = json['iterations'];
    if (hash is! String || salt is! String || it is! num) return null;
    if (hash.isEmpty || salt.isEmpty) return null;
    return UnlockConfig(hash: hash, salt: salt, iterations: it.toInt());
  }

  Map<String, dynamic> toJson() => <String, dynamic>{
    'hash': hash,
    'salt': salt,
    'iterations': iterations,
  };

  String encode() => jsonEncode(toJson());

  static UnlockConfig? decode(String? raw) {
    if (raw == null || raw.isEmpty) return null;
    try {
      return fromJson(jsonDecode(raw));
    } catch (_) {
      return null;
    }
  }
}
