package cz.thaimost.tablet;

import android.app.admin.DeviceAdminReceiver;

/** Slouží jen k tomu, aby se aplikace mohla stát „device owner“ (plný kiosk): adb shell dpm set-device-owner cz.thaimost.tablet/.AdminReceiver */
public class AdminReceiver extends DeviceAdminReceiver {
}
