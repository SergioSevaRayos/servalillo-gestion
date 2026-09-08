import 'package:flutter/material.dart';
import 'package:flutter_foreground_task/flutter_foreground_task.dart';

import 'app.dart';
import 'foreground/foreground_service.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();

  // Canal UI <-> isolate del servicio. Debe ir antes de runApp.
  FlutterForegroundTask.initCommunicationPort();
  TrackerForegroundService.init();

  runApp(const TrackerApp());
}
