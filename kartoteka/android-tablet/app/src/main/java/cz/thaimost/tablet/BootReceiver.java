package cz.thaimost.tablet;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/** Po zapnutí tabletu zkusí aplikaci spustit (nejspolehlivější je ji nastavit jako výchozí domovskou aplikaci). */
public class BootReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context context, Intent intent) {
        try {
            Intent i = new Intent(context, MainActivity.class);
            i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            context.startActivity(i);
        } catch (Exception ignored) {
        }
    }
}
