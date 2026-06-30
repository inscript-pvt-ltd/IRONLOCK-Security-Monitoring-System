package com.ironlock.guardmonitor

import android.os.Bundle
import android.view.WindowManager
import io.flutter.embedding.android.FlutterActivity

class MainActivity : FlutterActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        // Security posture (audit L6): the app shows precise location, the
        // guard's photo, and shift/PII data. FLAG_SECURE blocks screenshots and
        // screen-recording and keeps the content out of the app-switcher
        // thumbnail. iOS cannot fully block screenshots; this is Android-only.
        window.setFlags(
            WindowManager.LayoutParams.FLAG_SECURE,
            WindowManager.LayoutParams.FLAG_SECURE,
        )
    }
}
