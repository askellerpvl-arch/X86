package kz.x86.stock;

import org.junit.Test;
import static org.junit.Assert.*;

public class ReleaseBaselineTest {
    @Test public void unreleasedWorkKeepsBaselineIdentity() {
        assertEquals("kz.x86.stock", BuildConfig.APPLICATION_ID);
        assertEquals(5, BuildConfig.VERSION_CODE);
        assertEquals("1.5.0", BuildConfig.VERSION_NAME);
    }
}
