package cz.thaimost.tablet;

import android.app.Activity;
import android.app.ActivityManager;
import android.app.AlertDialog;
import android.app.admin.DevicePolicyManager;
import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.graphics.Color;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.provider.Settings;
import android.text.InputType;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.view.Window;
import android.view.WindowInsets;
import android.view.WindowInsetsController;
import android.view.WindowManager;
import android.webkit.RenderProcessGoneDetail;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Button;
import android.widget.EditText;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;
import android.widget.Toast;

/**
 * Kiosková obálka pro tablet v masérně: otevře odkaz tabletu z kartotéky na celou obrazovku,
 * drží obrazovku zapnutou, pustí zvuk bez klepnutí a zamkne se v popředí (připnutí aplikace / lock task).
 * Skrytý vstup pro správce: 7× klepnout do levého horního rohu do 5 s a zadat PIN.
 */
public class MainActivity extends Activity {
    private static final String PREFS = "tablet";
    private static final String K_URL = "url";
    private static final String K_PIN = "pin";
    private static final int BG = 0xFF10201F;

    private final Handler handler = new Handler(Looper.getMainLooper());
    private SharedPreferences prefs;
    private FrameLayout root;
    private WebView web;
    private TextView offlineView;
    private boolean pageFailed = false;
    private boolean offline = false;
    private boolean adminUnlocked = false;
    private int taps = 0;
    private long firstTap = 0;

    private final Runnable retry = new Runnable() {
        @Override
        public void run() {
            if (web != null && offline) {
                pageFailed = false;
                web.loadUrl(savedUrl());
                handler.postDelayed(this, 5000);
            }
        }
    };

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        prefs = getSharedPreferences(PREFS, MODE_PRIVATE);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON
                | WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED
                | WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON
                | WindowManager.LayoutParams.FLAG_DISMISS_KEYGUARD);
        if (Build.VERSION.SDK_INT >= 28) {
            WindowManager.LayoutParams lp = getWindow().getAttributes();
            lp.layoutInDisplayCutoutMode = Build.VERSION.SDK_INT >= 30
                    ? WindowManager.LayoutParams.LAYOUT_IN_DISPLAY_CUTOUT_MODE_ALWAYS
                    : WindowManager.LayoutParams.LAYOUT_IN_DISPLAY_CUTOUT_MODE_SHORT_EDGES;
            getWindow().setAttributes(lp);
        }
        root = new FrameLayout(this);
        root.setBackgroundColor(BG);
        setContentView(root);
        setupDeviceOwner();
        immersive();
        acceptLink(getIntent());
        if (savedUrl() == null) showSetup(); else showWeb();
    }

    /** Odkaz z naskenovaného QR kódu (otevřený přes fotoaparát/Lens) se uloží jako odkaz masérny. */
    private boolean acceptLink(Intent intent) {
        if (intent == null || !Intent.ACTION_VIEW.equals(intent.getAction()) || intent.getData() == null) return false;
        Uri d = intent.getData();
        String u = d.toString();
        if (!"https".equals(d.getScheme()) || !u.contains("r=tablet") || d.getQueryParameter("t") == null) return false;
        prefs.edit().putString(K_URL, u).apply();
        return true;
    }

    /* ---------- celá obrazovka a zámek ---------- */

    private void immersive() {
        Window w = getWindow();
        if (Build.VERSION.SDK_INT >= 30) {
            w.setDecorFitsSystemWindows(false);
            WindowInsetsController c = w.getInsetsController();
            if (c != null) {
                c.hide(WindowInsets.Type.systemBars());
                c.setSystemBarsBehavior(WindowInsetsController.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE);
            }
        } else {
            w.getDecorView().setSystemUiVisibility(View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY
                    | View.SYSTEM_UI_FLAG_FULLSCREEN
                    | View.SYSTEM_UI_FLAG_HIDE_NAVIGATION
                    | View.SYSTEM_UI_FLAG_LAYOUT_STABLE
                    | View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION
                    | View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN);
        }
    }

    @Override
    public void onWindowFocusChanged(boolean hasFocus) {
        super.onWindowFocusChanged(hasFocus);
        if (hasFocus) {
            immersive();
            handler.postDelayed(this::immersive, 500);
        }
    }

    private void lock() {
        try {
            ActivityManager am = (ActivityManager) getSystemService(Context.ACTIVITY_SERVICE);
            if (am != null && am.getLockTaskModeState() == ActivityManager.LOCK_TASK_MODE_NONE) {
                startLockTask();
            }
        } catch (Exception ignored) {
        }
    }

    /** Je-li aplikace „device owner“ (nastaveno přes adb), zamkne se bez jakéhokoli potvrzování a skryje stavový řádek. */
    private void setupDeviceOwner() {
        try {
            DevicePolicyManager dpm = (DevicePolicyManager) getSystemService(Context.DEVICE_POLICY_SERVICE);
            if (dpm != null && dpm.isDeviceOwnerApp(getPackageName())) {
                ComponentName admin = new ComponentName(this, AdminReceiver.class);
                dpm.setLockTaskPackages(admin, new String[]{getPackageName()});
                if (Build.VERSION.SDK_INT >= 28) dpm.setLockTaskFeatures(admin, 0);
                try { dpm.setStatusBarDisabled(admin, true); } catch (Exception ignored) { }
                try { dpm.setKeyguardDisabled(admin, true); } catch (Exception ignored) { }
            }
        } catch (Exception ignored) {
        }
    }

    @Override
    protected void onResume() {
        super.onResume();
        immersive();
        if (!adminUnlocked) lock();
        if (web != null) web.onResume();
    }

    @Override
    protected void onPause() {
        super.onPause();
        if (web != null) web.onPause();
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        setIntent(intent);
        immersive();
        if (acceptLink(intent)) showWeb();
    }

    @Override
    public void onBackPressed() {
        // záměrně nic – tlačítko Zpět tablet neopustí
    }

    /* ---------- uložený odkaz ---------- */

    private String savedUrl() {
        String u = prefs.getString(K_URL, null);
        return (u == null || u.trim().isEmpty()) ? null : u.trim();
    }

    /* ---------- první spuštění ---------- */

    private void showSetup() {
        root.removeAllViews();
        web = null;
        handler.removeCallbacks(retry);
        ScrollView sv = new ScrollView(this);
        LinearLayout col = new LinearLayout(this);
        col.setOrientation(LinearLayout.VERTICAL);
        int pad = dp(28);
        col.setPadding(pad, pad, pad, pad);
        sv.addView(col, new ViewGroup.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));

        TextView t = text("Thai Most – nastavení tabletu", 26, Color.WHITE);
        col.addView(t);
        col.addView(text("Vložte odkaz masérny z kartotéky (Tým → Masérny a tablety) a zvolte PIN správce.", 16, 0xFFA9BDB7));

        final EditText url = field("https://masage.dvere.app/?r=tablet&t=…", InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_URI);
        String old = savedUrl();
        if (old != null) url.setText(old);
        col.addView(url);

        final EditText pin = field("PIN správce (aspoň 4 číslice)", InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_VARIATION_PASSWORD);
        col.addView(pin);

        Button ok = new Button(this);
        ok.setText("Uložit a spustit");
        ok.setOnClickListener(v -> {
            String u = url.getText().toString().trim();
            String p = pin.getText().toString().trim();
            if (!u.startsWith("https://") || !u.contains("r=tablet")) {
                Toast.makeText(this, "Odkaz musí začínat https:// a obsahovat r=tablet", Toast.LENGTH_LONG).show();
                return;
            }
            if (p.length() < 4 && prefs.getString(K_PIN, "").length() < 4) {
                Toast.makeText(this, "PIN musí mít aspoň 4 číslice", Toast.LENGTH_LONG).show();
                return;
            }
            SharedPreferences.Editor e = prefs.edit().putString(K_URL, u);
            if (p.length() >= 4) e.putString(K_PIN, p);
            e.apply();
            showWeb();
        });
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        lp.topMargin = dp(16);
        col.addView(ok, lp);
        root.addView(sv, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
    }

    private TextView text(String s, int sp, int color) {
        TextView t = new TextView(this);
        t.setText(s);
        t.setTextSize(sp);
        t.setTextColor(color);
        t.setPadding(0, dp(8), 0, dp(8));
        return t;
    }

    private EditText field(String hint, int type) {
        EditText e = new EditText(this);
        e.setHint(hint);
        e.setInputType(type);
        e.setSingleLine(true);
        e.setTextColor(Color.WHITE);
        e.setHintTextColor(0xFF7F948E);
        return e;
    }

    private int dp(int v) {
        return (int) (v * getResources().getDisplayMetrics().density + 0.5f);
    }

    /* ---------- tablet (webová stránka) ---------- */

    private void showWeb() {
        root.removeAllViews();
        final String url = savedUrl();
        if (url == null) { showSetup(); return; }
        final String host = Uri.parse(url).getHost();

        web = new WebView(this);
        web.setBackgroundColor(BG);
        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setMediaPlaybackRequiresUserGesture(false); // znělka na konci masáže bez klepnutí
        s.setSupportZoom(false);
        s.setBuiltInZoomControls(false);
        s.setCacheMode(WebSettings.LOAD_DEFAULT);
        web.setLongClickable(false);
        web.setOnLongClickListener(v -> true);
        web.setHapticFeedbackEnabled(false);
        web.setOverScrollMode(View.OVER_SCROLL_NEVER);
        web.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                Uri u = request.getUrl();
                return host == null || !host.equalsIgnoreCase(u.getHost()); // jinam než na náš server se neodchází
            }

            @Override
            public void onPageStarted(WebView view, String u, android.graphics.Bitmap favicon) {
                pageFailed = false;
            }

            @Override
            public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
                if (request.isForMainFrame()) {
                    pageFailed = true;
                    setOffline(true);
                }
            }

            @Override
            public void onPageFinished(WebView view, String u) {
                if (!pageFailed) setOffline(false);
            }

            @Override
            public boolean onRenderProcessGone(WebView view, RenderProcessGoneDetail detail) {
                handler.post(MainActivity.this::showWeb);
                return true;
            }
        });
        root.addView(web, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));

        offlineView = text("Bez spojení se serverem – zkouším znovu…", 18, Color.WHITE);
        offlineView.setBackgroundColor(0xCC4B2420);
        offlineView.setGravity(Gravity.CENTER);
        offlineView.setVisibility(View.GONE);
        root.addView(offlineView, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.BOTTOM));

        // skrytý roh pro správce
        View corner = new View(this);
        corner.setOnClickListener(v -> cornerTap());
        root.addView(corner, new FrameLayout.LayoutParams(dp(90), dp(90), Gravity.TOP | Gravity.START));

        web.loadUrl(url);
    }

    private void setOffline(boolean on) {
        if (on == offline) return;
        offline = on;
        if (offlineView != null) offlineView.setVisibility(on ? View.VISIBLE : View.GONE);
        handler.removeCallbacks(retry);
        if (on) handler.postDelayed(retry, 5000);
    }

    /* ---------- správce ---------- */

    private void cornerTap() {
        long now = System.currentTimeMillis();
        if (taps == 0 || now - firstTap > 5000) { taps = 0; firstTap = now; }
        taps++;
        if (taps >= 7) {
            taps = 0;
            askPin();
        }
    }

    private void askPin() {
        final EditText e = field("PIN správce", InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_VARIATION_PASSWORD);
        e.setTextColor(Color.BLACK);
        e.setHintTextColor(Color.GRAY);
        new AlertDialog.Builder(this)
                .setTitle("Správa tabletu")
                .setView(e)
                .setPositiveButton("OK", (d, w) -> {
                    String pin = prefs.getString(K_PIN, "");
                    if (!pin.isEmpty() && pin.equals(e.getText().toString().trim())) adminMenu();
                    else Toast.makeText(this, "Špatný PIN", Toast.LENGTH_SHORT).show();
                    immersive();
                })
                .setNegativeButton("Zrušit", (d, w) -> immersive())
                .show();
    }

    private void adminMenu() {
        new AlertDialog.Builder(this)
                .setTitle("Správa tabletu")
                .setItems(new String[]{
                        "Obnovit stránku",
                        "Změnit odkaz / PIN",
                        "Odemknout (připnutí) a otevřít nastavení Androidu",
                        "Zpět"}, (d, which) -> {
                    if (which == 0 && web != null) {
                        pageFailed = false;
                        web.loadUrl(savedUrl());
                    } else if (which == 1) {
                        showSetup();
                    } else if (which == 2) {
                        adminUnlocked = true;
                        try { stopLockTask(); } catch (Exception ignored) { }
                        startActivity(new Intent(Settings.ACTION_SETTINGS).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK));
                    }
                    immersive();
                })
                .show();
    }
}
