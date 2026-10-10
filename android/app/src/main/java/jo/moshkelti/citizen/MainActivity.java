package jo.moshkelti.citizen;

import android.Manifest;
import android.app.Activity;
import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.content.res.Configuration;
import android.graphics.Color;
import android.net.Uri;
import android.net.http.SslError;
import android.os.Build;
import android.os.Bundle;
import android.os.SystemClock;
import android.view.Gravity;
import android.view.KeyEvent;
import android.view.MotionEvent;
import android.view.View;
import android.view.WindowInsets;
import android.view.WindowManager;
import android.webkit.CookieManager;
import android.webkit.GeolocationPermissions;
import android.webkit.PermissionRequest;
import android.webkit.SslErrorHandler;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;
import java.io.ByteArrayInputStream;
import java.io.InputStream;
import java.util.ArrayList;
import java.util.Collections;
import java.util.Locale;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/** Thin citizen interface: all authentication, authorization and report logic stay in Laravel. */
public final class MainActivity extends Activity {
    private static final int PickImages = 101;
    private static final int LocationPermission = 102;
    private final OriginPolicy policy = new OriginPolicy(BuildConfig.CITIZEN_ORIGIN);
    private final ExecutorService fileExecutor = Executors.newSingleThreadExecutor();
    private WebView web;
    private LinearLayout errorPanel;
    private TextView errorText;
    private Button retry;
    private ProgressBar progress;
    private ValueCallback<Uri[]> fileCallback;
    private GeolocationPermissions.Callback locationCallback;
    private String locationOrigin;
    private String language;
    private boolean multipleImages;
    private long lastUserInteraction;

    @Override public void onCreate(Bundle state) {
        super.onCreate(state);
        language = getPreferences(MODE_PRIVATE).getString("language", Locale.getDefault().getLanguage().equals("ar") ? "ar" : "en");
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_SECURE);
        WebView.setWebContentsDebuggingEnabled(false);
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setBackgroundColor(Color.WHITE);
        root.setFitsSystemWindows(true);
        if (Build.VERSION.SDK_INT >= 30) {
            root.setOnApplyWindowInsetsListener((view, insets) -> {
                android.graphics.Insets bars = insets.getInsets(WindowInsets.Type.systemBars() | WindowInsets.Type.ime());
                view.setPadding(bars.left, bars.top, bars.right, bars.bottom);
                return WindowInsets.CONSUMED;
            });
        }
        progress = new ProgressBar(this, null, android.R.attr.progressBarStyleHorizontal);
        root.addView(progress, new LinearLayout.LayoutParams(-1, 6));
        web = new WebView(this) {
            @Override public boolean onTouchEvent(MotionEvent event) {
                if (event.getAction() == MotionEvent.ACTION_UP) { lastUserInteraction = SystemClock.elapsedRealtime(); }
                return super.onTouchEvent(event);
            }
            @Override public boolean onKeyDown(int keyCode, KeyEvent event) {
                lastUserInteraction = SystemClock.elapsedRealtime();
                return super.onKeyDown(keyCode, event);
            }
        };
        root.addView(web, new LinearLayout.LayoutParams(-1, 0, 1));
        errorPanel = new LinearLayout(this);
        errorPanel.setOrientation(LinearLayout.VERTICAL);
        errorPanel.setGravity(Gravity.CENTER);
        errorPanel.setPadding(32, 32, 32, 32);
        errorText = new TextView(this);
        errorText.setTextSize(18);
        errorText.setGravity(Gravity.CENTER);
        errorPanel.addView(errorText);
        retry = new Button(this);
        retry.setOnClickListener(view -> web.loadUrl(BuildConfig.CITIZEN_ORIGIN + "/?lang=" + language));
        errorPanel.addView(retry);
        root.addView(errorPanel, new LinearLayout.LayoutParams(-1, 0, 1));
        errorPanel.setVisibility(View.GONE);
        setContentView(root);
        configureWebView();
        if (Build.VERSION.SDK_INT >= 33) {
            getOnBackInvokedDispatcher().registerOnBackInvokedCallback(android.window.OnBackInvokedDispatcher.PRIORITY_DEFAULT, this::navigateBack);
        }
        // Never restore a saved WebView/history bundle containing authenticated pages.
        web.clearCache(true);
        web.loadUrl(BuildConfig.CITIZEN_ORIGIN + "/?lang=" + language);
    }

    @SuppressWarnings("deprecation")
    private void configureWebView() {
        WebSettings settings = web.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setAllowFileAccess(false);
        settings.setAllowContentAccess(false);
        settings.setAllowFileAccessFromFileURLs(false);
        settings.setAllowUniversalAccessFromFileURLs(false);
        settings.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        settings.setCacheMode(WebSettings.LOAD_NO_CACHE);
        settings.setSaveFormData(false);
        settings.setGeolocationEnabled(true);
        settings.setSafeBrowsingEnabled(true);
        settings.setSupportMultipleWindows(false);
        settings.setJavaScriptCanOpenWindowsAutomatically(false);
        web.setImportantForAutofill(View.IMPORTANT_FOR_AUTOFILL_NO_EXCLUDE_DESCENDANTS);
        CookieManager.getInstance().setAcceptCookie(true);
        CookieManager.getInstance().setAcceptThirdPartyCookies(web, false);
        // No JavaScript-to-native bridge, intent deep links, download manager or arbitrary HTTP client.
        web.setDownloadListener((url, agent, disposition, mime, size) -> notifyUser(R.string.blocked_link));
        web.setWebViewClient(new WebViewClient() {
            @Override public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                String url = request.getUrl().toString();
                if (policy.internal(url)) { return false; }
                if (request.isForMainFrame() && request.hasGesture() && request.getMethod().equals("GET") && policy.external(url)) {
                    try { startActivity(new Intent(Intent.ACTION_VIEW, request.getUrl()).addCategory(Intent.CATEGORY_BROWSABLE)); }
                    catch (ActivityNotFoundException ignored) { notifyUser(R.string.blocked_link); }
                } else if (request.isForMainFrame()) { notifyUser(R.string.blocked_link); }
                return true;
            }

            @Override public WebResourceResponse shouldInterceptRequest(WebView view, WebResourceRequest request) {
                String url = request.getUrl().toString();
                if (policy.internal(url) || (!request.isForMainFrame() && request.getMethod().equals("GET") && policy.tile(url))) { return null; }
                return new WebResourceResponse("text/plain", "UTF-8", 403, "Forbidden", Collections.singletonMap("Cache-Control", "no-store"), new ByteArrayInputStream(new byte[0]));
            }

            @Override public void onPageStarted(WebView view, String url, android.graphics.Bitmap icon) {
                cancelLocation();
                cancelFiles();
                if (!policy.internal(url)) { view.stopLoading(); showError(); return; }
                if ("/login".equals(Uri.parse(url).getPath())) { view.clearHistory(); view.clearCache(true); }
                errorPanel.setVisibility(View.GONE);
                web.setVisibility(View.VISIBLE);
                progress.setVisibility(View.VISIBLE);
            }

            @Override public void onPageFinished(WebView view, String url) {
                progress.setVisibility(View.GONE);
                if (!policy.internal(url)) { return; }
                view.evaluateJavascript("document.documentElement.lang", value -> {
                    if ("\"ar\"".equals(value) || "\"en\"".equals(value)) {
                        language = value.substring(1, 3);
                        getPreferences(MODE_PRIVATE).edit().putString("language", language).apply();
                    }
                });
                if ("/login".equals(Uri.parse(url).getPath())) {
                    view.clearHistory();
                    view.clearCache(true);
                    view.clearFormData();
                    android.webkit.WebStorage.getInstance().deleteAllData();
                    GeolocationPermissions.getInstance().clearAll();
                }
            }

            @Override public void onReceivedSslError(WebView view, SslErrorHandler handler, SslError error) {
                handler.cancel();
                showError();
            }

            @Override public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
                if (request.isForMainFrame()) { showError(); }
            }

            @Override public void onReceivedHttpError(WebView view, WebResourceRequest request, WebResourceResponse response) {
                if (request.isForMainFrame() && response.getStatusCode() >= 500) { showError(); }
            }

            @Override public boolean onRenderProcessGone(WebView view, android.webkit.RenderProcessGoneDetail detail) {
                view.destroy();
                finish();
                return true;
            }
        });
        web.setWebChromeClient(new WebChromeClient() {
            @Override public void onProgressChanged(WebView view, int value) { progress.setProgress(value); }
            @Override public void onPermissionRequest(PermissionRequest request) { request.deny(); }

            @Override public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> callback, FileChooserParams params) {
                cancelFiles();
                if (!policy.internal(view.getUrl()) || params.getMode() == FileChooserParams.MODE_SAVE) {
                    callback.onReceiveValue(null);
                    return true;
                }
                fileCallback = callback;
                multipleImages = params.getMode() == FileChooserParams.MODE_OPEN_MULTIPLE;
                Intent picker = new Intent(Intent.ACTION_OPEN_DOCUMENT).addCategory(Intent.CATEGORY_OPENABLE)
                    .setType("image/*").putExtra(Intent.EXTRA_MIME_TYPES, new String[]{"image/jpeg", "image/png", "image/webp"})
                    .putExtra(Intent.EXTRA_ALLOW_MULTIPLE, multipleImages);
                try { startActivityForResult(picker, PickImages); }
                catch (ActivityNotFoundException ignored) { cancelFiles(); notifyUser(R.string.invalid_upload); }
                return true;
            }

            @Override public void onGeolocationPermissionsShowPrompt(String origin, GeolocationPermissions.Callback callback) {
                cancelLocation();
                if (!policy.sameOrigin(origin) || !policy.internal(web.getUrl())
                        || !"/reports/create".equals(Uri.parse(web.getUrl()).getPath())
                        || lastUserInteraction == 0 || SystemClock.elapsedRealtime() - lastUserInteraction > 5000) {
                    callback.invoke(origin, false, false);
                    return;
                }
                locationOrigin = origin;
                locationCallback = callback;
                if (hasLocationPermission()) { completeLocation(true); }
                else { requestPermissions(new String[]{Manifest.permission.ACCESS_COARSE_LOCATION, Manifest.permission.ACCESS_FINE_LOCATION}, LocationPermission); }
            }

            @Override public void onGeolocationPermissionsHidePrompt() { cancelLocation(); }
        });
    }

    private String localized(int resource) {
        Configuration configuration = new Configuration(getResources().getConfiguration());
        configuration.setLocale(new Locale(language));
        return createConfigurationContext(configuration).getString(resource);
    }

    private void notifyUser(int resource) { Toast.makeText(this, localized(resource), Toast.LENGTH_LONG).show(); }

    private void showError() {
        cancelLocation();
        cancelFiles();
        web.setVisibility(View.GONE);
        progress.setVisibility(View.GONE);
        errorText.setText(localized(R.string.connection_error));
        retry.setText(localized(R.string.retry));
        errorPanel.setVisibility(View.VISIBLE);
    }

    private boolean hasLocationPermission() {
        return checkSelfPermission(Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED
                || checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED;
    }

    private void completeLocation(boolean permitted) {
        if (locationCallback == null) { return; }
        boolean allowed = permitted && policy.internal(web.getUrl()) && "/reports/create".equals(Uri.parse(web.getUrl()).getPath());
        locationCallback.invoke(locationOrigin, allowed, false);
        locationCallback = null;
        locationOrigin = null;
        if (!allowed) { notifyUser(R.string.location_denied); }
    }

    private void cancelLocation() {
        if (locationCallback != null) { locationCallback.invoke(locationOrigin, false, false); }
        locationCallback = null;
        locationOrigin = null;
    }

    private void cancelFiles() {
        if (fileCallback != null) { fileCallback.onReceiveValue(null); }
        fileCallback = null;
    }

    @Override public void onRequestPermissionsResult(int request, String[] permissions, int[] results) {
        super.onRequestPermissionsResult(request, permissions, results);
        if (request == LocationPermission) { completeLocation(hasLocationPermission()); }
    }

    @Override protected void onActivityResult(int request, int result, Intent data) {
        super.onActivityResult(request, result, data);
        if (request != PickImages || fileCallback == null) { return; }
        if (result != RESULT_OK || data == null) { cancelFiles(); return; }
        ArrayList<Uri> selected = new ArrayList<>();
        if (data.getClipData() != null) {
            int count = data.getClipData().getItemCount();
            if (count > 3 || (!multipleImages && count > 1)) { cancelFiles(); notifyUser(R.string.invalid_upload); return; }
            for (int index = 0; index < count; index++) { selected.add(data.getClipData().getItemAt(index).getUri()); }
        } else if (data.getData() != null) { selected.add(data.getData()); }
        ValueCallback<Uri[]> pending = fileCallback;
        fileExecutor.execute(() -> {
            boolean valid = !selected.isEmpty();
            for (Uri uri : selected) { if (!validImage(uri)) { valid = false; break; } }
            final boolean accepted = valid;
            runOnUiThread(() -> {
                if (isDestroyed() || fileCallback != pending) { return; }
                pending.onReceiveValue(accepted ? selected.toArray(new Uri[0]) : null);
                fileCallback = null;
                if (!accepted) { notifyUser(R.string.invalid_upload); }
            });
        });
    }

    private boolean validImage(Uri uri) {
        if (uri == null || !"content".equals(uri.getScheme()) || uri.getAuthority() == null
                || uri.getAuthority().startsWith(getPackageName())) { return false; }
        try {
            String mime = getContentResolver().getType(uri);
            if (!"image/jpeg".equals(mime) && !"image/png".equals(mime) && !"image/webp".equals(mime)) { return false; }
            try (InputStream stream = getContentResolver().openInputStream(uri)) {
                if (stream == null) { return false; }
                byte[] header = new byte[12];
                int headerSize = 0;
                while (headerSize < header.length) {
                    int read = stream.read(header, headerSize, header.length - headerSize);
                    if (read == -1) { return false; }
                    headerSize += read;
                }
                boolean jpeg = header.length >= 3 && (header[0] & 255) == 255 && (header[1] & 255) == 216 && (header[2] & 255) == 255;
                boolean png = header.length >= 8 && (header[0] & 255) == 137 && header[1] == 80 && header[2] == 78 && header[3] == 71 && header[4] == 13 && header[5] == 10 && header[6] == 26 && header[7] == 10;
                boolean webp = header.length == 12 && header[0] == 82 && header[1] == 73 && header[2] == 70 && header[3] == 70 && header[8] == 87 && header[9] == 69 && header[10] == 66 && header[11] == 80;
                if (!(jpeg && "image/jpeg".equals(mime)) && !(png && "image/png".equals(mime)) && !(webp && "image/webp".equals(mime))) { return false; }
                byte[] buffer = new byte[8192];
                long size = header.length;
                int count;
                while ((count = stream.read(buffer)) != -1) {
                    size += count;
                    if (size > 5 * 1024 * 1024) { return false; }
                }
                return true;
            }
        } catch (Exception ignored) { return false; }
    }

    private void navigateBack() {
        if (errorPanel.getVisibility() == View.VISIBLE || !web.canGoBack()) { finish(); }
        else { web.goBack(); }
    }

    @Override public boolean onKeyUp(int keyCode, KeyEvent event) {
        // Android 13+ uses the registered platform predictive-back callback.
        if (Build.VERSION.SDK_INT < 33 && keyCode == KeyEvent.KEYCODE_BACK) { navigateBack(); return true; }
        return super.onKeyUp(keyCode, event);
    }

    @Override protected void onDestroy() {
        cancelLocation();
        cancelFiles();
        fileExecutor.shutdownNow();
        web.stopLoading();
        web.clearCache(true);
        web.destroy();
        super.onDestroy();
    }
}
