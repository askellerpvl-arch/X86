package kz.x86.stock.ui;

import kz.x86.stock.security.StockOriginPolicy;

public final class OperationNoticePolicy {
    private OperationNoticePolicy() { }
    public static boolean accepts(boolean mainFrame, String origin, String action, long reference) {
        return mainFrame && StockOriginPolicy.isTrustedCameraOrigin(origin) && reference > 0
                && ("sale".equals(action) || "receive".equals(action));
    }
}
