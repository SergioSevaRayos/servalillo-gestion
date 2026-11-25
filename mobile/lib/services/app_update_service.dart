import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:device_policy/device_policy.dart';

import '../core/clock.dart';
import '../data/secure_store.dart';
import '../models/app_update_info.dart';
import '../models/device_policies.dart';
import 'tracker_api.dart';

/// Lo nativo que necesita la actualización. Interfaz para poder testear sin Android.
abstract class AppInstallerPort {
  Future<int> installedVersionCode();

  /// Lanza la instalación sin preguntar. False si no se pudo (p. ej. no es Device Owner).
  Future<bool> install(String path);
}

class NativeAppInstallerPort implements AppInstallerPort {
  const NativeAppInstallerPort([this._channel = const DevicePolicyChannel()]);

  final DevicePolicyChannel _channel;

  @override
  Future<int> installedVersionCode() => _channel.versionCode();

  @override
  Future<bool> install(String path) => _channel.installApk(path);
}

enum UpdateOutcome {
  /// No hay nada que hacer (sin APK publicada, ya está al día, sin token…).
  upToDate,

  /// No es Device Owner: no puede instalarse sola. Hay que actualizar a mano.
  notManaged,

  /// Ya se intentó esta versión hace poco y falló: se espera antes de reintentar.
  backingOff,

  /// La descarga falló o la huella no coincide.
  failed,

  /// Instalación lanzada: Android reiniciará la app si sale bien.
  installing,
}

/// Actualización automática de la APK (docs/24): si la web publica una APK con un versionCode mayor
/// que el instalado, la descarga (`GET /api/device/apk`), comprueba su sha256 y se instala sola.
/// Solo funciona como Device Owner. Si un intento falla, no se repite la misma versión hasta
/// pasadas [retryAfter].
class AppUpdateService {
  AppUpdateService({
    required SecureStore store,
    required TrackerApi api,
    required AppInstallerPort installer,
    required Future<Directory> Function() cacheDir,
    Clock clock = const SystemClock(),
    this.retryAfter = const Duration(hours: 6),
  }) : _store = store,
       _api = api,
       _installer = installer,
       _cacheDir = cacheDir,
       _clock = clock;

  final SecureStore _store;
  final TrackerApi _api;
  final AppInstallerPort _installer;
  final Future<Directory> Function() _cacheDir;
  final Clock _clock;
  final Duration retryAfter;

  static const String fileName = 'tracker-update.apk';

  Future<UpdateOutcome> maybeUpdate(
    AppUpdateInfo? info,
    AppliedPolicies applied,
  ) async {
    final File file = File('${(await _cacheDir()).path}/$fileName');

    final int installed = await _installer.installedVersionCode();
    if (info == null || info.versionCode <= installed) {
      // Al día: se borra una descarga vieja que hubiera quedado.
      if (await file.exists()) await file.delete();
      return UpdateOutcome.upToDate;
    }

    if (!applied.deviceOwner) return UpdateOutcome.notManaged;

    final String? token = await _store.token;
    if (token == null) return UpdateOutcome.upToDate;

    final ({int code, DateTime at})? last = await _store.lastUpdateAttempt;
    if (last != null &&
        last.code == info.versionCode &&
        _clock.now().difference(last.at) < retryAfter) {
      return UpdateOutcome.backingOff;
    }
    await _store.setLastUpdateAttempt(info.versionCode, _clock.now());

    try {
      await _api.downloadApk(token: token, dest: file);
    } catch (_) {
      return UpdateOutcome.failed;
    }

    final String digest = (await sha256.bind(file.openRead()).first).toString();
    if (digest != info.sha256) {
      await file.delete();
      return UpdateOutcome.failed;
    }

    return await _installer.install(file.path)
        ? UpdateOutcome.installing
        : UpdateOutcome.failed;
  }
}
