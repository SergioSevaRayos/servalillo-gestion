import 'package:uuid/uuid.dart';

import '../config/app_config.dart';
import '../core/backoff.dart';
import '../data/secure_store.dart';
import '../models/api_exceptions.dart';
import '../models/enrolment_result.dart';
import 'tracker_api.dart';

class EnrolmentOutcome {
  const EnrolmentOutcome({required this.enrolled, this.error});
  final bool enrolled;
  final String? error;
}

/// Garantiza que el dispositivo esté enrolado antes de trackear.
class EnrolmentService {
  EnrolmentService({
    required SecureStore store,
    required TrackerApi api,
    ExponentialBackoff? backoff,
    Uuid uuid = const Uuid(),
    bool? configured,
    String? secret,
    String? appVersion,
  }) : _store = store,
       _api = api,
       _backoff = backoff ?? ExponentialBackoff(),
       _uuid = uuid,
       _configured = configured ?? AppConfig.isConfigured,
       _secret = secret ?? AppConfig.enrolmentSecret,
       _appVersion = appVersion ?? AppConfig.appVersion;

  final SecureStore _store;
  final TrackerApi _api;
  final ExponentialBackoff _backoff;
  final Uuid _uuid;
  final bool _configured;
  final String _secret;
  final String _appVersion;

  Future<String> _ensureInstallId() async {
    final String? existing = await _store.installIdentifier;
    if (existing != null && existing.isNotEmpty) return existing;
    final String fresh = _uuid.v4();
    await _store.setInstallIdentifier(fresh);
    return fresh;
  }

  /// Enrola si hace falta. Reintenta ante fallo de red (backoff); corta ante secreto inválido.
  Future<EnrolmentOutcome> ensureEnrolled({int maxAttempts = 6}) async {
    if (!_configured) {
      return const EnrolmentOutcome(
        enrolled: false,
        error: 'Falta configurar el servidor / secreto en el build.',
      );
    }

    final EnrolState state = await _store.state;
    final String? token = await _store.token;
    if (state == EnrolState.enrolled && token != null && token.isNotEmpty) {
      return const EnrolmentOutcome(enrolled: true);
    }

    final String installId = await _ensureInstallId();
    _backoff.reset();

    for (int attempt = 0; attempt < maxAttempts; attempt++) {
      try {
        final EnrolmentResult r = await _api.register(
          installIdentifier: installId,
          secret: _secret,
          appVersion: _appVersion,
        );
        await _store.setToken(r.token);
        await _store.setDeviceId(r.deviceId);
        await _store.setConfig(r.tracking);
        await _store.setState(EnrolState.enrolled);
        return const EnrolmentOutcome(enrolled: true);
      } on EnrolmentRejected catch (e) {
        return EnrolmentOutcome(enrolled: false, error: e.message);
      } on TransientNetworkError {
        await Future<void>.delayed(_backoff.next());
      }
    }

    return const EnrolmentOutcome(
      enrolled: false,
      error: 'No se pudo contactar con el servidor.',
    );
  }

  /// El técnico revocó el token: se re-enrola con el MISMO install_identifier.
  Future<EnrolmentOutcome> reEnrol() async {
    await _store.clearToken();
    await _store.setState(EnrolState.none);
    return ensureEnrolled();
  }
}
