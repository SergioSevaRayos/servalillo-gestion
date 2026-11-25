import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../models/device_policies.dart';
import '../models/tracking_config.dart';

/// Almacén clave-valor simple (para poder testear sin el keystore de Android).
abstract class KeyValueStore {
  Future<String?> read(String key);
  Future<void> write(String key, String value);
  Future<void> delete(String key);
}

class SecureKeyValueStore implements KeyValueStore {
  SecureKeyValueStore()
    : _storage = const FlutterSecureStorage(
        aOptions: AndroidOptions(encryptedSharedPreferences: true),
      );

  final FlutterSecureStorage _storage;

  @override
  Future<String?> read(String key) => _storage.read(key: key);

  @override
  Future<void> write(String key, String value) =>
      _storage.write(key: key, value: value);

  @override
  Future<void> delete(String key) => _storage.delete(key: key);
}

class InMemoryKeyValueStore implements KeyValueStore {
  final Map<String, String> _map = <String, String>{};

  @override
  Future<String?> read(String key) async => _map[key];

  @override
  Future<void> write(String key, String value) async => _map[key] = value;

  @override
  Future<void> delete(String key) async => _map.remove(key);
}

enum EnrolState { none, enrolled, revoked, deactivated }

/// Estado persistente del tracker: identificador, token, config, última señal.
class SecureStore {
  SecureStore(this._kv);

  final KeyValueStore _kv;

  static const String _kInstallId = 'install_identifier';
  static const String _kToken = 'token';
  static const String _kDeviceId = 'device_id';
  static const String _kConfig = 'tracking_config';
  static const String _kState = 'enrol_state';
  static const String _kLastSent = 'last_sent_at';
  static const String _kDropped = 'dropped_count';
  static const String _kPolicies = 'device_policies';
  static const String _kUnlock = 'unlock_config';
  static const String _kSuspendedUntil = 'policies_suspended_until';
  static const String _kPinFailures = 'pin_failures';
  static const String _kPinLockedUntil = 'pin_locked_until';
  static const String _kUpdateAttempt = 'last_update_attempt';

  Future<String?> get installIdentifier => _kv.read(_kInstallId);
  Future<void> setInstallIdentifier(String v) => _kv.write(_kInstallId, v);

  Future<String?> get token => _kv.read(_kToken);
  Future<void> setToken(String v) => _kv.write(_kToken, v);
  Future<void> clearToken() => _kv.delete(_kToken);

  Future<int?> get deviceId async =>
      int.tryParse(await _kv.read(_kDeviceId) ?? '');
  Future<void> setDeviceId(int v) => _kv.write(_kDeviceId, v.toString());

  Future<TrackingConfig> get config async =>
      TrackingConfig.decode(await _kv.read(_kConfig));
  Future<void> setConfig(TrackingConfig c) => _kv.write(_kConfig, c.encode());

  Future<EnrolState> get state async {
    final String? raw = await _kv.read(_kState);
    return EnrolState.values.firstWhere(
      (EnrolState s) => s.name == raw,
      orElse: () => EnrolState.none,
    );
  }

  Future<void> setState(EnrolState s) => _kv.write(_kState, s.name);

  Future<DateTime?> get lastSentAt async {
    final String? raw = await _kv.read(_kLastSent);
    return raw == null ? null : DateTime.tryParse(raw);
  }

  Future<void> setLastSentAt(DateTime t) =>
      _kv.write(_kLastSent, t.toUtc().toIso8601String());

  Future<int> get droppedCount async =>
      int.tryParse(await _kv.read(_kDropped) ?? '0') ?? 0;
  Future<void> addDropped(int n) async =>
      _kv.write(_kDropped, ((await droppedCount) + n).toString());

  // --- Protección del terminal (docs/24) ---

  /// Última política recibida del servidor: se reaplica sin red y al arrancar.
  Future<DevicePolicies> get policies async =>
      DevicePolicies.decode(await _kv.read(_kPolicies));
  Future<void> setPolicies(DevicePolicies p) =>
      _kv.write(_kPolicies, p.encode());

  Future<UnlockConfig?> get unlock async =>
      UnlockConfig.decode(await _kv.read(_kUnlock));
  Future<void> setUnlock(UnlockConfig? c) =>
      c == null ? _kv.delete(_kUnlock) : _kv.write(_kUnlock, c.encode());

  /// Restricciones quitadas temporalmente por el técnico (con la clave) hasta esta hora.
  Future<DateTime?> get suspendedUntil => _readTime(_kSuspendedUntil);
  Future<void> setSuspendedUntil(DateTime? t) =>
      _writeTime(_kSuspendedUntil, t);

  Future<int> get pinFailures async =>
      int.tryParse(await _kv.read(_kPinFailures) ?? '0') ?? 0;
  Future<void> setPinFailures(int n) => _kv.write(_kPinFailures, n.toString());

  Future<DateTime?> get pinLockedUntil => _readTime(_kPinLockedUntil);
  Future<void> setPinLockedUntil(DateTime? t) =>
      _writeTime(_kPinLockedUntil, t);

  /// Último intento de actualización automática: versionCode y hora (para no reintentar en bucle).
  Future<({int code, DateTime at})?> get lastUpdateAttempt async {
    final String? raw = await _kv.read(_kUpdateAttempt);
    final List<String> parts = (raw ?? '').split('|');
    if (parts.length != 2) return null;
    final int? code = int.tryParse(parts[0]);
    final DateTime? at = DateTime.tryParse(parts[1]);
    if (code == null || at == null) return null;
    return (code: code, at: at);
  }

  Future<void> setLastUpdateAttempt(int code, DateTime at) =>
      _kv.write(_kUpdateAttempt, '$code|${at.toUtc().toIso8601String()}');

  Future<DateTime?> _readTime(String key) async {
    final String? raw = await _kv.read(key);
    return raw == null ? null : DateTime.tryParse(raw);
  }

  Future<void> _writeTime(String key, DateTime? t) =>
      t == null ? _kv.delete(key) : _kv.write(key, t.toUtc().toIso8601String());
}
