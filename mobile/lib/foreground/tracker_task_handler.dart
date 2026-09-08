import 'package:flutter_foreground_task/flutter_foreground_task.dart';
import 'package:http/http.dart' as http;

import '../config/app_config.dart';
import '../core/clock.dart';
import '../data/app_database.dart';
import '../data/position_queue.dart';
import '../data/secure_store.dart';
import '../services/enrolment_service.dart';
import '../services/location_sampler.dart';
import '../services/sync_service.dart';
import '../services/tracker_api.dart';
import '../services/tracker_controller.dart';
import 'foreground_service.dart';

/// Punto de entrada del isolate del servicio. DEBE ser top-level + vm:entry-point.
@pragma('vm:entry-point')
void startCallback() {
  FlutterForegroundTask.setTaskHandler(TrackerTaskHandler());
}

class TrackerTaskHandler extends TaskHandler {
  TrackerController? _controller;
  http.Client? _client;
  bool _busy = false;
  int _skip = 0;

  @override
  Future<void> onStart(DateTime timestamp, TaskStarter starter) async {
    // Sin esto, geolocator / battery_plus / secure_storage lanzan MissingPluginException.
    FlutterForegroundTask.initCommunicationPort();

    final db = await openAppDatabase();
    final queue = PositionQueue(db, cap: AppConfig.queueCap);
    final store = SecureStore(SecureKeyValueStore());
    _client = http.Client();
    final api = TrackerApi(_client!, AppConfig.serverUrl);

    // Por si el servicio arrancó en boot antes de que la UI enrolara.
    await EnrolmentService(store: store, api: api).ensureEnrolled();

    _controller = TrackerController(
      queue: queue,
      store: store,
      sampler: GeolocatorSampler(),
      sync: SyncService(queue: queue, store: store, api: api),
      clock: const SystemClock(),
    );

    await _runTick();
  }

  @override
  void onRepeatEvent(DateTime timestamp) {
    if (_busy) return;
    if (_skip > 0) {
      _skip--;
      return;
    }
    _busy = true;
    _runTick().whenComplete(() => _busy = false);
  }

  Future<void> _runTick() async {
    final controller = _controller;
    if (controller == null) return;

    final TickResult result = await controller.tick();
    _skip = result.skipTicks;

    FlutterForegroundTask.sendDataToMain(<String, dynamic>{
      'action': result.action.name,
      'message': result.message,
      'pending': result.pending,
    });

    await TrackerForegroundService.updateNotification(result.message);

    if (result.action == TickAction.stopRevoked ||
        result.action == TickAction.stopDeactivated) {
      await FlutterForegroundTask.stopService();
    }
  }

  @override
  Future<void> onDestroy(DateTime timestamp, bool isTimeout) async {
    _client?.close();
  }

  @override
  void onReceiveData(Object data) {
    if (data == 'tick') {
      onRepeatEvent(DateTime.now());
    }
  }
}
