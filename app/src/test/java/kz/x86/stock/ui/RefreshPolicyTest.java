package kz.x86.stock.ui;

import org.junit.Test;
import static org.junit.Assert.*;

public class RefreshPolicyTest {
    @Test public void onlyPositiveSafeStateAllowsRefresh() {
        assertTrue(RefreshPolicy.mayRefresh(true, true, "\"safe\""));
        assertFalse(RefreshPolicy.mayRefresh(false, true, "\"safe\""));
        assertFalse(RefreshPolicy.mayRefresh(true, false, "\"safe\""));
        for (String state : new String[]{null, "", "null", "true", "safe", "\"busy\"", "\"unknown\"", "\"safe\" garbage"})
            assertFalse(RefreshPolicy.mayRefresh(true, true, state));
    }
}
