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
    private static final Set<String> KEYS = new HashSet<>(Arrays.asList(CLASSIC_MODE, SAFE_REFRESH,
            CONNECTION_BADGE, ENHANCED_BACK, NEW_DOWNLOAD_BRIDGE));
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
