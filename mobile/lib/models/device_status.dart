import 'device_policies.dart';
import 'tracking_config.dart';

/// Respuesta de `GET /api/device` (Bloque 10/11): para mostrar el chofer asignado y detectar
/// una desactivación sin esperar al siguiente lote.
class DeviceStatus {
  const DeviceStatus({
    required this.deviceId,
    required this.label,
    required this.isActive,
    required this.driverName,
    required this.tracking,
    required this.serverTime,
    this.policies = DevicePolicies.none,
    this.unlock,
  });

  final int deviceId;
  final String label;
  final bool isActive;
  final String? driverName;
  final TrackingConfig tracking;
  final DateTime? serverTime;

  /// Protección del terminal pedida desde la web (docs/24).
  final DevicePolicies policies;

  /// Clave de la APK (hash + sal), o null si no tiene.
  final UnlockConfig? unlock;

  bool get isAssigned => driverName != null;

  factory DeviceStatus.fromJson(Map<String, dynamic> json) => DeviceStatus(
    deviceId: (json['device_id'] as num).toInt(),
    label: (json['label'] as String?) ?? 'Tracker',
    isActive: (json['is_active'] as bool?) ?? true,
    driverName: (json['driver'] as Map?)?['name'] as String?,
    tracking: TrackingConfig.fromJson(
      (json['tracking'] as Map?)?.cast<String, dynamic>() ??
          const <String, dynamic>{},
    ),
    serverTime: json['server_time'] is String
        ? DateTime.tryParse(json['server_time'] as String)
        : null,
    policies: json['policies'] is Map
        ? DevicePolicies.fromJson(
            (json['policies'] as Map).cast<String, dynamic>(),
          )
        : DevicePolicies.none,
    unlock: UnlockConfig.fromJson(json['unlock']),
  );
}
