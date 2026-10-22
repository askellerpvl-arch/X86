package kz.x86.stock;

import android.test.ActivityInstrumentationTestCase2;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import java.io.InputStream;
import java.io.ByteArrayOutputStream;
import java.nio.charset.StandardCharsets;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicReference;

/** No production URL is loaded, no backend or financial operation is invoked. */
@SuppressWarnings("deprecation")
public class WebViewSafetyTest extends ActivityInstrumentationTestCase2<WebViewFixtureActivity> {
    public WebViewSafetyTest() { super(WebViewFixtureActivity.class); }
    private WebView view;
    private void load(String html) throws Exception {
        view = getActivity().webView;
        CountDownLatch ready = new CountDownLatch(1);
        getInstrumentation().runOnMainSync(() -> {
            view.setWebViewClient(new WebViewClient() {
                @Override public void onPageFinished(WebView v, String url) { ready.countDown(); }
            });
            view.loadDataWithBaseURL("https://x86.kz/stock/", html, "text/html", "UTF-8", null);
        });
        assertTrue("fixture load timed out", ready.await(15, TimeUnit.SECONDS));
    }
    private String evaluate(String script) throws Exception {
        CountDownLatch ready = new CountDownLatch(1);
        AtomicReference<String> result = new AtomicReference<>();
        getInstrumentation().runOnMainSync(() -> view.evaluateJavascript(script, value -> {
            result.set(value); ready.countDown();
        }));
        assertTrue("JS callback timed out", ready.await(10, TimeUnit.SECONDS));
        return result.get();
    }
    private String asset(String name) throws Exception {
        try (InputStream in = getInstrumentation().getTargetContext().getAssets().open(name);
             ByteArrayOutputStream out = new ByteArrayOutputStream()) {
            byte[] buffer = new byte[4096]; int n;
            while ((n = in.read(buffer)) != -1) out.write(buffer, 0, n);
            return new String(out.toByteArray(), StandardCharsets.UTF_8);
        }
    }
    public void testUnknownMarkupNeverAuthorizesRefresh() throws Exception {
        load("<input id='query' value='server value'>");
        assertEquals("\"unknown\"", evaluate(asset("refresh-probe.js")));
        evaluate("document.getElementById('query').value='unsaved'; window.__x86NativeSafeRefreshV1=true;");
        assertEquals("\"busy\"", evaluate(asset("refresh-probe.js")));
        assertEquals("\"unsaved\"", evaluate("document.getElementById('query').value"));
    }
    public void testOpenCheckoutCannotBeClosedByEnhancedBack() throws Exception {
        load("<div role='dialog'><input value='checkout'><button data-close onclick='window.closed=true'>Close</button></div>");
        assertEquals("\"blocked\"", evaluate(asset("enhanced-back.js")));
        assertEquals("false", evaluate("window.closed===true"));
    }
    public void testOnlyExplicitDialogCloseIsClicked() throws Exception {
        load("<div role='dialog'><button data-close onclick='window.didClose=true'>Close</button></div>");
        assertEquals("\"closed\"", evaluate(asset("enhanced-back.js")));
        assertEquals("true", evaluate("window.didClose===true"));
    }
    public void testUnknownXButtonIsNotClicked() throws Exception {
        load("<button onclick='window.didClose=true'>X</button>");
        assertEquals("\"unknown\"", evaluate(asset("enhanced-back.js")));
        assertEquals("false", evaluate("window.didClose===true"));
    }
}
