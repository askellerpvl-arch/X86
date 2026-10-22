package kz.x86.stock;

import androidx.test.ext.junit.runners.AndroidJUnit4;
import androidx.test.ext.junit.rules.ActivityScenarioRule;
import org.junit.Rule;
import org.junit.Test;
import org.junit.runner.RunWith;
import static org.junit.Assert.*;
import static androidx.test.platform.app.InstrumentationRegistry.getInstrumentation;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import java.io.InputStream;
import java.io.ByteArrayOutputStream;
import java.nio.charset.StandardCharsets;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicReference;

/** No production URL is loaded, no backend or financial operation is invoked. */
@RunWith(AndroidJUnit4.class)
public class WebViewSafetyTest {
    @Rule public ActivityScenarioRule<WebViewFixtureActivity> activity = new ActivityScenarioRule<>(WebViewFixtureActivity.class);
    private WebView view;
    private void load(String html) throws Exception {
        activity.getScenario().onActivity(host -> view = host.webView);
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
    @Test public void testUnknownMarkupNeverAuthorizesRefresh() throws Exception {
        load("<input id='query' value='server value'>");
        assertEquals("\"unknown\"", evaluate(asset("refresh-probe.js")));
        evaluate("document.getElementById('query').value='unsaved'; window.__x86NativeSafeRefreshV1=true;");
        assertEquals("\"busy\"", evaluate(asset("refresh-probe.js")));
        assertEquals("\"unsaved\"", evaluate("document.getElementById('query').value"));
    }
    @Test public void testOpenCheckoutCannotBeClosedByEnhancedBack() throws Exception {
        load("<div role='dialog'><input value='checkout'><button data-close onclick='window.closed=true'>Close</button></div>");
        assertEquals("\"blocked\"", evaluate(asset("enhanced-back.js")));
        assertEquals("false", evaluate("window.closed===true"));
    }
    @Test public void testOnlyExplicitDialogCloseIsClicked() throws Exception {
        load("<div role='dialog'><button data-close onclick='window.didClose=true'>Close</button></div>");
        assertEquals("\"closed\"", evaluate(asset("enhanced-back.js")));
        assertEquals("true", evaluate("window.didClose===true"));
    }
    @Test public void testUnknownXButtonIsNotClicked() throws Exception {
        load("<button onclick='window.didClose=true'>X</button>");
        assertEquals("\"unknown\"", evaluate(asset("enhanced-back.js")));
        assertEquals("false", evaluate("window.didClose===true"));
    }

    @Test public void testTorchDoesNotStartAnotherCamera() throws Exception {
        load("<p>No scanner</p>");
        evaluate(asset("scanner-torch.js"));
        assertEquals("\"unavailable\"", evaluate("window.__x86TorchV1.status"));
    }

    @Test public void testTorchWaitsForCameraTrackConfirmation() throws Exception {
        load("<video style='width:200px;height:100px'></video>");
        evaluate("window.testTorch=false; var track={readyState:'live',getCapabilities:function(){return {torch:true}},getSettings:function(){return {torch:false}},applyConstraints:function(c){window.testTorch=c.advanced[0].torch; return Promise.resolve()}};Object.defineProperty(document.querySelector('video'),'srcObject',{value:{getVideoTracks:function(){return [track]}}});");
        evaluate(asset("scanner-torch.js"));
        assertEquals("\"on\"", evaluate("window.__x86TorchV1.status"));
        assertEquals("true", evaluate("window.testTorch"));
    }

    @Test public void testRejectedTorchRequestNeverReportsSuccess() throws Exception {
        load("<video style='width:200px;height:100px'></video>");
        evaluate("var track={readyState:'live',getCapabilities:function(){return {torch:true}},getSettings:function(){return {torch:false}},applyConstraints:function(){return Promise.reject(new Error('denied'))}};Object.defineProperty(document.querySelector('video'),'srcObject',{value:{getVideoTracks:function(){return [track]}}});");
        evaluate(asset("scanner-torch.js"));
        assertEquals("\"failed\"", evaluate("window.__x86TorchV1.status"));
    }

    @Test public void testFindOnPagePreservesEnteredText() throws Exception {
        load("<p>Widget P/N 111</p><input id='quantity' value='7'>");
        CountDownLatch found = new CountDownLatch(1);
        java.util.concurrent.atomic.AtomicInteger matches = new java.util.concurrent.atomic.AtomicInteger();
        getInstrumentation().runOnMainSync(() -> {
            view.setFindListener((active, total, done) -> {
                if (done) { matches.set(total); found.countDown(); }
            });
            view.findAllAsync("Widget");
        });
        assertTrue(found.await(10, TimeUnit.SECONDS));
        assertEquals(1, matches.get());
        assertEquals("\"7\"", evaluate("document.getElementById('quantity').value"));
    }
}
