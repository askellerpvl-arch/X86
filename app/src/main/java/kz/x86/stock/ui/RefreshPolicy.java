package kz.x86.stock.ui;

public final class RefreshPolicy {
    private RefreshPolicy() { }
    /** probeResult is the JSON encoded evaluateJavascript result, never an inferred DOM state. */
    public static boolean mayRefresh(boolean trustedStockUrl, boolean atTop, String probeResult) {
        return trustedStockUrl && atTop && "\"safe\"".equals(probeResult);
    }
}
