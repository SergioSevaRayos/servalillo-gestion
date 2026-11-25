import 'package:device_policy/device_policy.dart';

import '../core/clock.dart';
import '../data/secure_store.dart';
import '../models/api_exceptions.dart';
import '../models/device_policies.dart';
import '../models/device_status.dart';
import 'tracker_api.dart';

/// Acceso a las restricciones del móvil. Interfaz para poder testear sin Android.
abstract class DevicePolicyPort {
  Future<AppliedPolicies> state();
  Future<AppliedPolicies> apply(DevicePolicies policies);
  Future<AppliedPolicies> release();
}

/// Implementación real: el plugin local `device_policy` (Device Owner).
class NativeDevicePolicyPort implements DevicePolicyPort {
  const NativeDevicePolicyPort([this._channel = const DevicePolicyChannel()]);

  final DevicePolicyChannel _channel;

  @override
  Future<AppliedPolicies> state() async =>
      AppliedPolicies.fromNative(await _channel.state());

  @override
  Future<AppliedPolicies> apply(DevicePolicies p) async =>
      AppliedPolicies.fromNative(
        await _channel.apply(
          blockScreenCapture: p.blockScreenCapture,
          blockUninstall: p.blockUninstall,
          blockFactoryReset: p.blockFactoryReset,
          blockSafeBoot: p.blockSafeBoot,
        ),
      );

  @override
  Future<AppliedPolicies> release() async =>
      AppliedPolicies.fromNative(await _channel.release());
}

class PolicySyncResult {
  const PolicySyncResult({required this.applied, this.status});

  final AppliedPolicies applied;

  /// Respuesta de `GET /api/device`, o null si no hubo red / token.
  final DeviceStatus? status;
}

/// Protección del terminal del chofer (docs/24):
/// `GET /api/device` → aplicar (o liberar) en el móvil → `POST /api/device/state`.
/// Sin red, reaplica la última política guardada. La llaman el servicio en primer plano
/// (cada pocos minutos, también en la pausa nocturna) y la pantalla de la APK.
class PolicySyncService {
  PolicySyncService({
    required SecureStore store,
    required TrackerApi api,
    required DevicePolicyPort port,
    Clock clock = const SystemClock(),
    String? appVersion,
  }) : _store = store,
       _api = api,
       _port = port,
       _clock = clock,
       _appVersion = appVersion;

  final SecureStore _store;
  final TrackerApi _api;
  final DevicePolicyPort _port;
  final Clock _clock;
  final String? _appVersion;

  Future<PolicySyncResult> sync() async {
    final String? token = await _store.token;
    DeviceStatus? status;

    if (token != null) {
      try {
        status = await _api.fetchDevice(token: token);
        await _store.setPolicies(status.policies);
        await _store.setUnlock(status.unlock);
      } on TrackerApiException {
        // Sin red / token revocado: se sigue con lo último guardado.
      }
    }

    final AppliedPolicies applied = await applyStored();

    if (token != null) {
      try {
        await _api.reportState(
          token: token,
          applied: applied,
          appVersion: _appVersion,
        );
      } on TrackerApiException {
        // Se reintentará en la próxima sincronización.
      }
    }

    return PolicySyncResult(applied: applied, status: status);
  }

  /// Aplica la última política guardada, respetando una suspensión temporal en curso.
  Future<AppliedPolicies> applyStored() async {
    final DevicePolicies desired = await _store.policies;

    if (desired.release) {
      return _port.release();
    }

    final DateTime? until = await _store.suspendedUntil;
    if (until != null && _clock.now().isBefore(until)) {
      return _port.apply(DevicePolicies.none);
    }
    if (until != null) {
      await _store.setSuspendedUntil(null);
    }

    return _port.apply(desired);
  }

  /// El técnico (con la clave) quita las restricciones durante [duration].
  Future<AppliedPolicies> suspend(Duration duration) async {
    await _store.setSuspendedUntil(_clock.now().add(duration));
    return applyStored();
  }

  /// El técnico (con la clave) libera el terminal desde el propio móvil, sin red.
  Future<AppliedPolicies> releaseLocally() => _port.release();
}
