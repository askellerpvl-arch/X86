package kz.x86.stock.ui;

import java.util.HashMap;
import org.junit.Test;
import static org.junit.Assert.*;

public class UiFeatureFlagsTest {
    private static class MemoryStore implements UiFeatureFlags.Store {
        private final HashMap<String, Boolean> values = new HashMap<>();
        public boolean get(String key) { return Boolean.TRUE.equals(values.get(key)); }
        public void put(String key, boolean value) { values.put(key, value); }
    }
    @Test public void defaultsAreOffAndSettingsSurviveNewController() {
        MemoryStore store = new MemoryStore();
        UiFeatureFlags flags = new UiFeatureFlags(store);
        assertFalse(flags.enabled(UiFeatureFlags.CONNECTION_BADGE));
        flags.set(UiFeatureFlags.CONNECTION_BADGE, true);
        assertTrue(new UiFeatureFlags(store).enabled(UiFeatureFlags.CONNECTION_BADGE));
    }
    @Test public void classicModeDisablesAllEnhancementsWithoutDiscardingPreferences() {
        MemoryStore store = new MemoryStore(); UiFeatureFlags flags = new UiFeatureFlags(store);
        flags.set(UiFeatureFlags.SAFE_REFRESH, true);
        flags.set(UiFeatureFlags.CLASSIC_MODE, true);
        assertFalse(flags.enabled(UiFeatureFlags.SAFE_REFRESH));
        flags.set(UiFeatureFlags.CLASSIC_MODE, false);
        assertTrue(flags.enabled(UiFeatureFlags.SAFE_REFRESH));
        assertFalse(UiFeatureFlags.effective(true, true));
        assertFalse(flags.enabled("unknown"));
    }
}
