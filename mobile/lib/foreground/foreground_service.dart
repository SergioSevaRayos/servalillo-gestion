import 'package:flutter_foreground_task/flutter_foreground_task.dart';

import '../models/tracking_config.dart';
import 'tracker_task_handler.dart';

/// Configura y controla el servicio en primer plano del tracker.
class TrackerForegroundService {
  const TrackerForegroundService._();

  static const int _serviceId = 111;
  static const String _channelId = 'servalillo_tracker';

  /// Se llama en `main()` (antes de `runApp`) y de nuevo cuando cambia el intervalo real.
  static void init({Duration interval = const Duration(seconds: 45)}) {
    FlutterForegroundTask.init(
      androidNotificationOptions: AndroidNotificationOptions(
        channelId: _channelId,
        channelName: 'Seguimiento GPS',
        channelDescription: 'Comparte la ubicación del camión con la oficina.',
        onlyAlertOnce: true,
        priority: NotificationPriority.LOW,
      ),
      iosNotificationOptions: const IOSNotificationOptions(
        showNotification: false,
        playSound: false,
      ),
      foregroundTaskOptions: ForegroundTaskOptions(
        eventAction: ForegroundTaskEventAction.repeat(interval.inMilliseconds),
        autoRunOnBoot: true,
        autoRunOnMyPackageReplaced: true,
        allowWakeLock: true,
        allowWifiLock: true,
      ),
    );
  }

  static Future<bool> isRunning() => FlutterForegroundTask.isRunningService;

  static Future<ServiceRequestResult> start() async {
    if (await FlutterForegroundTask.isRunningService) {
      return FlutterForegroundTask.restartService();
    }
    return FlutterForegroundTask.startService(
      serviceId: _serviceId,
      notificationTitle: 'Servalillo · seguimiento activo',
      notificationText: 'Compartiendo la ubicación del camión.',
      notificationIcon: null,
      callback: startCallback,
    );
  }

  static Future<ServiceRequestResult> stop() =>
      FlutterForegroundTask.stopService();

  /// Reajusta el intervalo del `onRepeatEvent` al que diga el servidor.
  static Future<ServiceRequestResult> applyConfig(TrackingConfig config) {
    init(interval: config.pingInterval);
    return FlutterForegroundTask.updateService(
      foregroundTaskOptions: ForegroundTaskOptions(
        eventAction: ForegroundTaskEventAction.repeat(
          config.pingInterval.inMilliseconds,
        ),
        autoRunOnBoot: true,
        autoRunOnMyPackageReplaced: true,
        allowWakeLock: true,
        allowWifiLock: true,
      ),
    );
  }

  static Future<void> updateNotification(String text) =>
      FlutterForegroundTask.updateService(notificationText: text);
}
