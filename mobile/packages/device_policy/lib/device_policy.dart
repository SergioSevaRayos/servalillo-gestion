import 'package:flutter/services.dart';

/// Puente con el código nativo de Android (`DevicePolicyPlugin.kt`).
///
/// Es un plugin (y no un MethodChannel en `MainActivity`) para que quede registrado también en el
/// engine del servicio en primer plano, que es quien aplica las restricciones periódicamente.
///
/// Todos los métodos devuelven el estado REAL que hay aplicado en el móvil:
/// `{device_owner, block_screen_capture, block_uninstall, block_factory_reset, block_safe_boot, lost_mode}`.
/// Si la APK no es Device Owner, no hacen nada y devuelven todo `false`.
class DevicePolicyChannel {
  const DevicePolicyChannel();

  static const MethodChannel _channel = MethodChannel(
    'es.servalillo/device_policy',
  );

  Future<Map<String, bool>> state() => _call('state');

  Future<Map<String, bool>> apply({
    required bool blockScreenCapture,
    required bool blockUninstall,
    required bool blockFactoryReset,
    required bool blockSafeBoot,
    String? lostMessage,
  }) => _call('apply', <String, Object?>{
    'block_screen_capture': blockScreenCapture,
    'block_uninstall': blockUninstall,
    'block_factory_reset': blockFactoryReset,
    'block_safe_boot': blockSafeBoot,
    // Modo perdido: con mensaje bloquea la pantalla y lo muestra; null lo quita.
    'lost_message': lostMessage,
  });

  /// Quita todas las restricciones y deja de ser administradora del dispositivo.
  Future<Map<String, bool>> release() => _call('release');

  /// versionCode de la app instalada (el "+N" de pubspec.yaml).
  Future<int> versionCode() async =>
      (await _channel.invokeMethod<int>('versionCode')) ?? 0;

  /// Instala una APK descargada sin preguntar (solo Device Owner). Si va bien, Android reinicia
  /// la app. Devuelve false si no se pudo lanzar (no es Device Owner, fichero inexistente…).
  Future<bool> installApk(String path) async =>
      (await _channel.invokeMethod<bool>('installApk', <String, String>{
        'path': path,
      })) ??
      false;

  Future<Map<String, bool>> _call(String method, [Object? args]) async {
    final Map<Object?, Object?>? raw = await _channel
        .invokeMethod<Map<Object?, Object?>>(method, args);
    return <String, bool>{
      for (final MapEntry<Object?, Object?> e
          in (raw ?? const <Object?, Object?>{}).entries)
        e.key.toString(): e.value == true,
    };
  }
}
