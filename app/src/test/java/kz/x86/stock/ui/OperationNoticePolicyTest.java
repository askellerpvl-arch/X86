package kz.x86.stock.ui;

import org.junit.Test;
import static org.junit.Assert.*;

public class OperationNoticePolicyTest {
    @Test public void onlyKnownConfirmedReferencesFromMainStockOriginAreAccepted() {
        assertTrue(OperationNoticePolicy.accepts(true,"https://x86.kz","sale",12));
        assertTrue(OperationNoticePolicy.accepts(true,"https://x86.kz","receive",22));
        assertFalse(OperationNoticePolicy.accepts(false,"https://x86.kz","sale",12));
        assertFalse(OperationNoticePolicy.accepts(true,"https://foreign.example","sale",12));
        assertFalse(OperationNoticePolicy.accepts(true,"https://x86.kz","sale",0));
        assertFalse(OperationNoticePolicy.accepts(true,"https://x86.kz","sale",-1));
        assertFalse(OperationNoticePolicy.accepts(true,"https://x86.kz","clicked",12));
    }
}
