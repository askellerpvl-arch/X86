package kz.x86.stock;

import android.Manifest;
import android.app.Activity;
import android.app.DownloadManager;
import android.content.ActivityNotFoundException;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.IntentFilter;
import android.content.ContentValues;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.provider.MediaStore;
import android.util.Base64;
import android.view.View;
import android.view.Window;
import android.webkit.CookieManager;
import android.webkit.JavascriptInterface;
import android.webkit.DownloadListener;
import android.webkit.PermissionRequest;
import android.webkit.URLUtil;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Toast;
import android.window.OnBackInvokedDispatcher;

import androidx.swiperefreshlayout.widget.SwipeRefreshLayout;

import org.json.JSONObject;

import java.io.File;
import java.io.FileOutputStream;
import java.io.OutputStream;

public class MainActivity extends Activity {
    private static final String START_URL = "https://x86.kz/stock/";
    private static final int REQ_CAMERA = 1001;
    private static final int REQ_FILE = 1002;
    private static final int REQ_STORAGE = 1003;
    private static final long EXIT_CONFIRM_MS = 2000L;


    private static final String DOWNLOAD_HOOK_JS =
            "(function(){" +
            "if(window.__x86AndroidDownloadHook)return true;window.__x86AndroidDownloadHook=true;" +
            "function saveUrl(u,n){" +
            "if(!u||(!(u.indexOf('blob:')===0)&&!(u.indexOf('data:')===0)))return false;" +
            "try{fetch(u).then(function(r){return r.blob();}).then(function(b){" +
            "var f=new FileReader();f.onloadend=function(){try{X86Android.saveBase64(f.result,n||'',b.type||'application/octet-stream');}catch(e){}};" +
            "f.readAsDataURL(b);}).catch(function(){});return true;}catch(e){return false;}}" +
            "var oldClick=HTMLAnchorElement.prototype.click;" +
            "HTMLAnchorElement.prototype.click=function(){if(saveUrl(this.href,this.download||''))return;return oldClick.apply(this,arguments);};" +
            "document.addEventListener('click',function(ev){var a=ev.target&&ev.target.closest?ev.target.closest('a'):null;" +
            "if(a&&saveUrl(a.href,a.download||'')){ev.preventDefault();ev.stopImmediatePropagation();}},true);" +
            "var oldOpen=window.open;window.open=function(u){if(typeof u==='string'&&saveUrl(u,''))return null;return oldOpen.apply(window,arguments);};" +
            "return true;})()";

    private static final String APP_BOTTOM_SCAN_JS =
            "(function(){" +
            "var nav=document.querySelector('nav');if(!nav)return false;" +
            "if(document.getElementById('x86-app-scan-nav'))return true;" +
            "var receive=nav.querySelector('button[data-nav=\"receive\"]');" +
            "var products=nav.querySelector('button[data-nav=\"products\"]');" +
            "var sale=nav.querySelector('button[data-nav=\"sale\"]');" +
            "if(!receive||!products||!sale)return false;" +
            "var st=document.getElementById('x86-app-scan-style');" +
            "if(!st){st=document.createElement('style');st.id='x86-app-scan-style';" +
            "st.textContent='nav.x86-app-nav{grid-template-columns:1fr 1fr 1fr 1.15fr 1fr 1fr 1fr!important;min-height:74px!important}' +" +
            "'#x86-app-scan-nav{margin:5px 2px!important;border:0!important;border-radius:14px!important;background:linear-gradient(145deg,#8b5cf6,#6d28d9)!important;color:#fff!important;box-shadow:0 5px 14px rgba(109,40,217,.28)!important;padding:4px 1px 5px!important;font-size:9px!important;font-weight:850!important;overflow:visible!important;transition:transform .12s ease!important}' +" +
            "'#x86-app-scan-nav span{display:block!important;width:32px!important;height:30px!important;line-height:30px!important;margin:0 auto 1px!important;border-radius:10px!important;background:rgba(255,255,255,.16)!important;color:#fff!important;font-size:18px!important;font-weight:900!important}' +" +
            "'#x86-app-scan-nav:active{transform:scale(.96)!important}';document.head.appendChild(st);}" +
            "nav.classList.add('x86-app-nav');" +
            "var b=document.createElement('button');b.id='x86-app-scan-nav';b.type='button';b.setAttribute('aria-label','Сканировать товар в продажу');b.innerHTML='<span>▣</span>СКАН';" +
            "b.addEventListener('click',function(ev){ev.preventDefault();ev.stopPropagation();" +
            "try{sale.click();}catch(x){}" +
            "var n=0;var t=setInterval(function(){n++;var scan=document.getElementById('sale-scan');" +
            "if(scan&&scan.offsetParent!==null){clearInterval(t);try{scan.click();}catch(x){}}else if(n>12){clearInterval(t);}},100);" +
            "});" +
            "nav.insertBefore(b,products);return true;" +
            "})()";

    /*
     * X86 Stock is mostly a single-page interface. Some screens (for example
     * the product card shown as a panel) do not add an entry to WebView's
     * navigation history. Android therefore cannot know that there is a
     * screen to close. Before touching native history we ask the page to close
     * the visible dialog/panel itself.
     */
    private static final String PAGE_BACK_JS =
            "(function(){" +
            "function vis(e){if(!e)return false;var s=getComputedStyle(e),r=e.getBoundingClientRect();" +
            "return s.display!=='none'&&s.visibility!=='hidden'&&parseFloat(s.opacity||'1')>0&&r.width>0&&r.height>0;}" +
            "function click(e){try{e.click();return true;}catch(x){return false;}}" +
            "var dialogs=[].slice.call(document.querySelectorAll('[role=\\\"dialog\\\"],dialog[open],.modal.show,.modal.active,.modal.open,.drawer.open,.drawer.active,.sheet.open,.sheet.active,.popup.open,.popup.active,.overlay.open,.overlay.active'));" +
            "for(var i=dialogs.length-1;i>=0;i--){var d=dialogs[i];if(!vis(d))continue;" +
            "var c=d.querySelector('[data-bs-dismiss=\\\"modal\\\"],[data-dismiss=\\\"modal\\\"],[data-close],button[aria-label*=\\\"close\\\" i],button[aria-label*=\\\"закры\\\" i],.modal-close,.drawer-close,.sheet-close,.popup-close,.close');" +
            "if(c&&vis(c))return click(c);" +
            "var a=[].slice.call(d.querySelectorAll('button,a,[role=button],span,div'));" +
            "for(var j=a.length-1;j>=0;j--){var t=(a[j].textContent||'').trim();if((t==='×'||t==='✕'||t==='✖'||t==='x'||t==='X')&&vis(a[j]))return click(a[j]);}" +
            "}" +
            "var all=[].slice.call(document.querySelectorAll('button,a,[role=button],span,div'));" +
            "for(var k=all.length-1;k>=0;k--){var e=all[k];if(!vis(e))continue;var t=(e.textContent||'').trim();" +
            "if(!(t==='×'||t==='✕'||t==='✖'||t==='x'||t==='X'))continue;var r=e.getBoundingClientRect();" +
            "if(r.left>window.innerWidth*0.55&&r.top<window.innerHeight*0.35)return click(e);}" +
            "var b=document.querySelector('button[aria-label*=\\\"back\\\" i],a[aria-label*=\\\"back\\\" i],button[aria-label*=\\\"назад\\\" i],a[aria-label*=\\\"назад\\\" i],[data-back],.back-button,.btn-back');" +
            "if(b&&vis(b))return click(b);" +
            "return false;" +
            "})()";


    private static final String HOME_BACK_JS =
            "(function(){" +
            "function vis(e){if(!e)return false;var s=getComputedStyle(e),r=e.getBoundingClientRect();return s.display!=='none'&&s.visibility!=='hidden'&&r.width>0&&r.height>0;}" +
            "function active(e){var n=e;for(var z=0;z<4&&n;z++,n=n.parentElement){" +
            "var c=(' '+String(n.className||'')+' ').toLowerCase();" +
            "if(c.indexOf(' active ')>=0||c.indexOf(' current ')>=0||c.indexOf(' selected ')>=0)return true;" +
            "var ac=n.getAttribute&&n.getAttribute('aria-current');if(ac&&ac!=='false')return true;" +
            "if(n.getAttribute&&n.getAttribute('aria-selected')==='true')return true;" +
            "if(n.getAttribute&&n.getAttribute('data-active')==='true')return true;}return false;}" +
            "var a=[].slice.call(document.querySelectorAll('a,button,[role=button],[data-page],[data-tab],[data-section]'));" +
            "var home=null;for(var i=0;i<a.length;i++){var e=a[i];if(!vis(e))continue;" +
            "var t=((e.textContent||'')+' '+(e.getAttribute('aria-label')||'')).trim().toLowerCase();" +
            "var h=(e.getAttribute('href')||'').toLowerCase();" +
            "var d=((e.getAttribute('data-page')||'')+' '+(e.getAttribute('data-tab')||'')+' '+(e.getAttribute('data-section')||'')).toLowerCase();" +
            "if(t.indexOf('главная')!==-1||h.indexOf('home')!==-1||d.indexOf('home')!==-1){home=e;break;}}" +
            "if(!home)return 'unknown';if(active(home))return 'home';" +
            "try{home.click();return 'navigated';}catch(x){return 'unknown';}" +
            "})()";

    private WebView webView;
    private SwipeRefreshLayout swipeRefreshLayout;
    private PermissionRequest pendingPermissionRequest;
    private ValueCallback<Uri[]> fileCallback;
    private String currentUrl = START_URL;
    private long lastBackAt = 0L;
    private byte[] pendingDownloadBytes;
    private String pendingDownloadName;
    private String pendingDownloadMime;
    private long pendingPdfDownloadId = -1L;

    private final BroadcastReceiver downloadReceiver = new BroadcastReceiver() {
        @Override
        public void onReceive(Context context, Intent intent) {
            if (!DownloadManager.ACTION_DOWNLOAD_COMPLETE.equals(intent.getAction())) return;
            long id = intent.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1L);
            if (id == -1L || id != pendingPdfDownloadId) return;
            pendingPdfDownloadId = -1L;
            DownloadManager dm = (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);
            if (dm == null) return;
            Uri uri = dm.getUriForDownloadedFile(id);
            if (uri != null) openSavedFile(uri, "application/pdf");
        }
    };

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        Window window = getWindow();
        window.setStatusBarColor(Color.rgb(248, 250, 252));
        window.setNavigationBarColor(Color.WHITE);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            window.getDecorView().setSystemUiVisibility(
                    View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR | View.SYSTEM_UI_FLAG_LIGHT_NAVIGATION_BAR
            );
        }

        swipeRefreshLayout = new SwipeRefreshLayout(this);
        webView = new WebView(this);
        swipeRefreshLayout.addView(webView, new SwipeRefreshLayout.LayoutParams(
                SwipeRefreshLayout.LayoutParams.MATCH_PARENT,
                SwipeRefreshLayout.LayoutParams.MATCH_PARENT
        ));
        swipeRefreshLayout.setOnRefreshListener(() -> {
            if (webView != null) {
                webView.reload();
            } else {
                swipeRefreshLayout.setRefreshing(false);
            }
        });
        swipeRefreshLayout.setOnChildScrollUpCallback((parent, child) ->
                webView != null && webView.canScrollVertically(-1)
        );
        setContentView(swipeRefreshLayout);
        webView.addJavascriptInterface(new DownloadBridge(), "X86Android");

        IntentFilter downloadFilter = new IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            registerReceiver(downloadReceiver, downloadFilter, Context.RECEIVER_EXPORTED);
        } else {
            registerReceiver(downloadReceiver, downloadFilter);
        }

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            getOnBackInvokedDispatcher().registerOnBackInvokedCallback(
                    OnBackInvokedDispatcher.PRIORITY_DEFAULT,
                    this::handleBack
            );
        }

        WebSettings s = webView.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setDatabaseEnabled(true);
        s.setAllowFileAccess(false);
        s.setAllowContentAccess(true);
        s.setMediaPlaybackRequiresUserGesture(false);
        s.setSupportZoom(false);
        s.setBuiltInZoomControls(false);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            s.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        }

        CookieManager cookies = CookieManager.getInstance();
        cookies.setAcceptCookie(true);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            cookies.setAcceptThirdPartyCookies(webView, true);
        }

        webView.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                Uri uri = request.getUrl();
                String scheme = uri.getScheme();
                if ("blob".equalsIgnoreCase(scheme) || "data".equalsIgnoreCase(scheme)) {
                    requestBlobDownload(uri.toString(), "", "");
                    return true;
                }
                if ("http".equalsIgnoreCase(scheme) || "https".equalsIgnoreCase(scheme)) {
                    String host = uri.getHost();
                    if (host != null && (host.equalsIgnoreCase("x86.kz") || host.endsWith(".x86.kz"))) return false;
                    return openExternal(uri);
                }
                return openExternal(uri);
            }

            @Override
            @SuppressWarnings("deprecation")
            public boolean shouldOverrideUrlLoading(WebView view, String url) {
                Uri uri = Uri.parse(url);
                String scheme = uri.getScheme();
                if ("blob".equalsIgnoreCase(scheme) || "data".equalsIgnoreCase(scheme)) {
                    requestBlobDownload(url, "", "");
                    return true;
                }
                if ("http".equalsIgnoreCase(scheme) || "https".equalsIgnoreCase(scheme)) {
                    String host = uri.getHost();
                    if (host != null && (host.equalsIgnoreCase("x86.kz") || host.endsWith(".x86.kz"))) return false;
                    return openExternal(uri);
                }
                return openExternal(uri);
            }

            @Override
            public void onPageFinished(WebView view, String url) {
                super.onPageFinished(view, url);
                currentUrl = url;
                lastBackAt = 0L;
                if (swipeRefreshLayout != null) swipeRefreshLayout.setRefreshing(false);
                installBottomScanButton();
                installDownloadHook();
            }

            @Override
            public void doUpdateVisitedHistory(WebView view, String url, boolean isReload) {
                super.doUpdateVisitedHistory(view, url, isReload);
                if (url != null && !url.isEmpty()) currentUrl = url;
                lastBackAt = 0L;
            }
        });

        webView.setWebChromeClient(new WebChromeClient() {
            @Override
            public void onPermissionRequest(PermissionRequest request) {
                runOnUiThread(() -> {
                    boolean wantsCamera = false;
                    for (String r : request.getResources()) {
                        if (PermissionRequest.RESOURCE_VIDEO_CAPTURE.equals(r)) {
                            wantsCamera = true;
                            break;
                        }
                    }
                    if (!wantsCamera) {
                        request.deny();
                        return;
                    }
                    if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M ||
                            checkSelfPermission(Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED) {
                        request.grant(request.getResources());
                    } else {
                        pendingPermissionRequest = request;
                        requestPermissions(new String[]{Manifest.permission.CAMERA}, REQ_CAMERA);
                    }
                });
            }

            @Override
            public boolean onShowFileChooser(WebView webView, ValueCallback<Uri[]> filePathCallback,
                                             FileChooserParams fileChooserParams) {
                if (fileCallback != null) fileCallback.onReceiveValue(null);
                fileCallback = filePathCallback;
                Intent intent = fileChooserParams.createIntent();
                try {
                    startActivityForResult(intent, REQ_FILE);
                    return true;
                } catch (ActivityNotFoundException e) {
                    fileCallback = null;
                    Toast.makeText(MainActivity.this, "Не удалось открыть выбор файла", Toast.LENGTH_SHORT).show();
                    return false;
                }
            }
        });

        webView.setDownloadListener(new DownloadListener() {
            @Override
            public void onDownloadStart(String url, String userAgent, String contentDisposition,
                                        String mimetype, long contentLength) {
                String name = URLUtil.guessFileName(url, contentDisposition, mimetype);
                if (url != null && (url.startsWith("blob:") || url.startsWith("data:"))) {
                    requestBlobDownload(url, name, mimetype);
                    return;
                }
                try {
                    DownloadManager.Request request = new DownloadManager.Request(Uri.parse(url));
                    request.setMimeType(mimetype);
                    request.addRequestHeader("User-Agent", userAgent);
                    String cookie = CookieManager.getInstance().getCookie(url);
                    if (cookie != null) request.addRequestHeader("Cookie", cookie);
                    request.setTitle(name);
                    request.setDescription("X86 Склад");
                    request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
                    request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, name);
                    DownloadManager dm = (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);
                    long downloadId = dm.enqueue(request);
                    boolean pdf = (mimetype != null && mimetype.toLowerCase().contains("pdf")) ||
                            (name != null && name.toLowerCase().endsWith(".pdf"));
                    if (pdf) pendingPdfDownloadId = downloadId;
                    Toast.makeText(MainActivity.this, pdf ? "PDF скачивается и откроется автоматически" : "Скачивание началось", Toast.LENGTH_SHORT).show();
                } catch (Exception e) {
                    Toast.makeText(MainActivity.this, "Не удалось скачать файл", Toast.LENGTH_LONG).show();
                }
            }
        });

        if (savedInstanceState == null) {
            webView.loadUrl(START_URL);
        } else {
            webView.restoreState(savedInstanceState);
            String restoredUrl = webView.getUrl();
            if (restoredUrl != null && !restoredUrl.isEmpty()) currentUrl = restoredUrl;
        }
    }

    private void installBottomScanButton() {
        if (webView == null) return;
        webView.evaluateJavascript(APP_BOTTOM_SCAN_JS, null);
    }


    private void installDownloadHook() {
        if (webView == null) return;
        webView.evaluateJavascript(DOWNLOAD_HOOK_JS, null);
    }

    private void requestBlobDownload(String url, String fileName, String mimeType) {
        if (webView == null || url == null || url.isEmpty()) return;
        String js = "(function(){try{fetch(" + JSONObject.quote(url) + ").then(function(r){return r.blob();}).then(function(b){" +
                "var f=new FileReader();f.onloadend=function(){X86Android.saveBase64(f.result," +
                JSONObject.quote(fileName == null ? "" : fileName) + ",b.type||" +
                JSONObject.quote(mimeType == null ? "" : mimeType) + ");};f.readAsDataURL(b);" +
                "}).catch(function(){});}catch(e){}})()";
        webView.evaluateJavascript(js, null);
    }

    private String safeFileName(String requested, String mimeType) {
        String name = requested == null ? "" : requested.trim();
        name = name.replaceAll("[\\\\/:*?\\\"<>|\\r\\n]+", "_");
        if (name.isEmpty() || name.equalsIgnoreCase("download") || !name.contains(".")) {
            if (mimeType != null && mimeType.toLowerCase().contains("pdf")) name = "X86-etiketki.pdf";
            else name = name.isEmpty() ? "X86-file" : name;
        }
        return name;
    }

    private void saveDecodedFile(byte[] bytes, String requestedName, String mimeType) {
        if (bytes == null || bytes.length == 0) {
            Toast.makeText(this, "Файл пустой", Toast.LENGTH_LONG).show();
            return;
        }
        final String name = safeFileName(requestedName, mimeType);
        final String type = (mimeType == null || mimeType.isEmpty()) ? "application/octet-stream" : mimeType;

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            try {
                ContentValues values = new ContentValues();
                values.put(MediaStore.Downloads.DISPLAY_NAME, name);
                values.put(MediaStore.Downloads.MIME_TYPE, type);
                values.put(MediaStore.Downloads.RELATIVE_PATH, Environment.DIRECTORY_DOWNLOADS + "/X86");
                values.put(MediaStore.Downloads.IS_PENDING, 1);
                Uri uri = getContentResolver().insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, values);
                if (uri == null) throw new IllegalStateException("MediaStore insert failed");
                try (OutputStream out = getContentResolver().openOutputStream(uri)) {
                    if (out == null) throw new IllegalStateException("Output stream unavailable");
                    out.write(bytes);
                    out.flush();
                }
                ContentValues ready = new ContentValues();
                ready.put(MediaStore.Downloads.IS_PENDING, 0);
                getContentResolver().update(uri, ready, null, null);
                Toast.makeText(this, "Сохранено: Загрузки/X86/" + name, Toast.LENGTH_SHORT).show();
                if (type.toLowerCase().contains("pdf") || name.toLowerCase().endsWith(".pdf")) {
                    openSavedFile(uri, "application/pdf");
                }
            } catch (Exception e) {
                Toast.makeText(this, "Не удалось сохранить файл", Toast.LENGTH_LONG).show();
            }
            return;
        }

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M &&
                checkSelfPermission(Manifest.permission.WRITE_EXTERNAL_STORAGE) != PackageManager.PERMISSION_GRANTED) {
            pendingDownloadBytes = bytes;
            pendingDownloadName = name;
            pendingDownloadMime = type;
            requestPermissions(new String[]{Manifest.permission.WRITE_EXTERNAL_STORAGE}, REQ_STORAGE);
            return;
        }
        saveLegacyDownload(bytes, name);
    }

    @SuppressWarnings("deprecation")
    private void saveLegacyDownload(byte[] bytes, String name) {
        try {
            File dir = Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_DOWNLOADS);
            if (!dir.exists() && !dir.mkdirs()) throw new IllegalStateException("Downloads unavailable");
            File outFile = new File(dir, name);
            try (FileOutputStream out = new FileOutputStream(outFile)) {
                out.write(bytes);
                out.flush();
            }
            Toast.makeText(this, "Сохранено: Загрузки/" + name, Toast.LENGTH_LONG).show();
        } catch (Exception e) {
            Toast.makeText(this, "Не удалось сохранить файл", Toast.LENGTH_LONG).show();
        }
    }

    private class DownloadBridge {
        @JavascriptInterface
        public void saveBase64(String dataUrl, String fileName, String mimeType) {
            if (dataUrl == null || dataUrl.isEmpty()) return;
            try {
                int comma = dataUrl.indexOf(',');
                String payload = comma >= 0 ? dataUrl.substring(comma + 1) : dataUrl;
                byte[] bytes = Base64.decode(payload, Base64.DEFAULT);
                runOnUiThread(() -> saveDecodedFile(bytes, fileName, mimeType));
            } catch (Exception e) {
                runOnUiThread(() -> Toast.makeText(MainActivity.this, "Ошибка сохранения файла", Toast.LENGTH_LONG).show());
            }
        }
    }

    private void openSavedFile(Uri uri, String mimeType) {
        try {
            Intent intent = new Intent(Intent.ACTION_VIEW);
            intent.setDataAndType(uri, (mimeType == null || mimeType.isEmpty()) ? "application/pdf" : mimeType);
            intent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
            startActivity(intent);
        } catch (ActivityNotFoundException e) {
            Toast.makeText(this, "PDF сохранён. Установите приложение для просмотра PDF", Toast.LENGTH_LONG).show();
        } catch (Exception e) {
            Toast.makeText(this, "PDF сохранён, но открыть его автоматически не удалось", Toast.LENGTH_LONG).show();
        }
    }

    private boolean openExternal(Uri uri) {
        try {
            startActivity(new Intent(Intent.ACTION_VIEW, uri));
            return true;
        } catch (Exception e) {
            return false;
        }
    }

    private String normalizeUrl(String url) {
        if (url == null) return "";
        return url.endsWith("/") ? url.substring(0, url.length() - 1) : url;
    }

    @Override
    protected void onSaveInstanceState(Bundle outState) {
        webView.saveState(outState);
        super.onSaveInstanceState(outState);
    }

    private void handleBack() {
        if (webView == null) {
            confirmExit();
            return;
        }

        // First close an in-page card/dialog. Product cards in X86 Stock do not
        // create browser history, so native Back must close them via the page.
        webView.evaluateJavascript(PAGE_BACK_JS, result -> runOnUiThread(() -> {
            if ("true".equals(result)) {
                lastBackAt = 0L;
                return;
            }

            if (webView.canGoBack()) {
                lastBackAt = 0L;
                webView.goBack();
                return;
            }

            backFallback();
        }));
    }

    private void backFallback() {
        String url = webView != null ? webView.getUrl() : currentUrl;
        String current = normalizeUrl(url);
        String start = normalizeUrl(START_URL);

        // A real URL outside the stock root goes back to the stock home first.
        if (!current.isEmpty() && !current.equals(start)) {
            lastBackAt = 0L;
            webView.loadUrl(START_URL);
            return;
        }

        // The bottom tabs are SPA state and usually keep the same /stock/ URL.
        // If a non-home tab is active, Back switches to "Главная". If
        // "Главная" is already active, enable double-Back exit protection.
        webView.evaluateJavascript(HOME_BACK_JS, result -> runOnUiThread(() -> {
            if ("\"home\"".equals(result)) {
                confirmExit();
                return;
            }

            if ("\"navigated\"".equals(result)) {
                lastBackAt = 0L;
                return;
            }

            // If markup ever changes, loading the stock root is the safe fallback.
            // The next Back on the actual home screen will show the exit prompt.
            lastBackAt = 0L;
            webView.loadUrl(START_URL);
        }));
    }

    private void confirmExit() {
        long now = System.currentTimeMillis();
        if (now - lastBackAt <= EXIT_CONFIRM_MS) {
            lastBackAt = 0L;
            finishAndRemoveTask();
            return;
        }

        lastBackAt = now;
        Toast.makeText(this, "Нажмите ещё раз «Назад», чтобы выйти", Toast.LENGTH_SHORT).show();
    }

    @Override
    public void onBackPressed() {
        handleBack();
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode == REQ_CAMERA && pendingPermissionRequest != null) {
            if (grantResults.length > 0 && grantResults[0] == PackageManager.PERMISSION_GRANTED) {
                pendingPermissionRequest.grant(pendingPermissionRequest.getResources());
            } else {
                pendingPermissionRequest.deny();
                Toast.makeText(this, "Камера нужна для сканирования этикеток", Toast.LENGTH_LONG).show();
            }
            pendingPermissionRequest = null;
        }
        if (requestCode == REQ_STORAGE) {
            if (grantResults.length > 0 && grantResults[0] == PackageManager.PERMISSION_GRANTED && pendingDownloadBytes != null) {
                saveLegacyDownload(pendingDownloadBytes, pendingDownloadName == null ? "X86-file" : pendingDownloadName);
            } else {
                Toast.makeText(this, "Нет доступа к папке Загрузки", Toast.LENGTH_LONG).show();
            }
            pendingDownloadBytes = null;
            pendingDownloadName = null;
            pendingDownloadMime = null;
        }
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode == REQ_FILE && fileCallback != null) {
            Uri[] result = WebChromeClient.FileChooserParams.parseResult(resultCode, data);
            fileCallback.onReceiveValue(result);
            fileCallback = null;
        }
    }
    @Override
    protected void onDestroy() {
        try {
            unregisterReceiver(downloadReceiver);
        } catch (Exception ignored) {
        }
        if (webView != null) {
            webView.removeJavascriptInterface("X86Android");
            webView.destroy();
        }
        swipeRefreshLayout = null;
        super.onDestroy();
    }

}
