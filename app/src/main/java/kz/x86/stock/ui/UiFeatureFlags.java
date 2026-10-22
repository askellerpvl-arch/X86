package kz.x86.stock.ui;

import java.util.Arrays;
import java.util.HashSet;
import java.util.Set;

public final class UiFeatureFlags {
    public interface Store { boolean get(String key); void put(String key, boolean value); }
    public static final String CLASSIC_MODE = "x86.classic_mode";
    public static final String SAFE_REFRESH = "x86.safe_refresh";
    public static final String CONNECTION_BADGE = "x86.connection_badge";
    public static final String ENHANCED_BACK = "x86.enhanced_back";
    public static final String NEW_DOWNLOAD_BRIDGE = "x86.new_download_bridge";
    public static final String SCANNER_TORCH = "x86.scanner_torch";
    public static final String PAGE_SEARCH = "x86.page_search";
    public static final String MODULE_NAVIGATION = "x86.module_navigation";
    public static final String MODULE_SEARCH = "x86.module_search";
    public static final String ITEM_ACTIONS = "x86.item_actions";
    public static final String MOBILE_GESTURES = "x86.mobile_gestures";
    public static final String CASHIER_LAYOUT = "x86.cashier_layout";
    public static final String OPERATION_NOTICES = "x86.operation_notices";
    public static final String SCAN_CONFIRM = "x86.scan_confirm";
    public static final String SCANNER_FEEDBACK = "x86.scanner_feedback";
    private static final Set<String> KEYS = new HashSet<>(Arrays.asList(CLASSIC_MODE, SAFE_REFRESH,
            CONNECTION_BADGE, ENHANCED_BACK, NEW_DOWNLOAD_BRIDGE, SCANNER_TORCH, PAGE_SEARCH,
            MODULE_NAVIGATION, MODULE_SEARCH, ITEM_ACTIONS, MOBILE_GESTURES, CASHIER_LAYOUT, OPERATION_NOTICES, SCAN_CONFIRM, SCANNER_FEEDBACK));
    private final Store store;
    public UiFeatureFlags(Store store) { this.store = store; }
    public static boolean effective(boolean classicMode, boolean requestedFlag) { return !classicMode && requestedFlag; }
    public boolean enabled(String key) { return KEYS.contains(key) && effective(store.get(CLASSIC_MODE), store.get(key)); }
    public boolean requested(String key) { return KEYS.contains(key) && store.get(key); }
    public void set(String key, boolean value) {
        if (!KEYS.contains(key)) throw new IllegalArgumentException("Unknown feature flag");
        store.put(key, value);
    }
}
