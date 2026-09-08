import 'tracking_config.dart';

class EnrolmentResult {
  const EnrolmentResult({
    required this.token,
    required this.deviceId,
    required this.tracking,
  });

  final String token;
  final int deviceId;
  final TrackingConfig tracking;

  factory EnrolmentResult.fromJson(Map<String, dynamic> json) =>
      EnrolmentResult(
        token: json['token'] as String,
        deviceId: (json['device_id'] as num).toInt(),
        tracking: TrackingConfig.fromJson(
          (json['tracking'] as Map?)?.cast<String, dynamic>() ??
              const <String, dynamic>{},
        ),
      );
}
