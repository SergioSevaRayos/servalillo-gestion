import 'package:flutter/services.dart';

/// Puente con el código nativo de Android (`DevicePolicyPlugin.kt`).
///
/// Es un plugin (y no un MethodChannel en `MainActivity`) para que quede registrado también en el
/// engine del servicio en primer plano, que es quien aplica las restricciones periódicamente.
///
/// Todos los métodos devuelven el estado REAL que hay aplicado en el móvil:
/// `{device_owner, block_screen_capture, block_uninstall, block_factory_reset, block_safe_boot}`.
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
  }) => _call('apply', <String, bool>{
    'block_screen_capture': blockScreenCapture,
    'block_uninstall': blockUninstall,
    'block_factory_reset': blockFactoryReset,
    'block_safe_boot': blockSafeBoot,
  });

  /// Quita todas las restricciones y deja de ser administradora del dispositivo.
  Future<Map<String, bool>> release() => _call('release');

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
