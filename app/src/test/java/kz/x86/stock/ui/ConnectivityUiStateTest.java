package kz.x86.stock.ui;

import org.junit.Test;
import static org.junit.Assert.*;

public class ConnectivityUiStateTest {
    @Test public void distinguishesOfflineAndUnknownWriteWithoutRetry() {
        assertEquals(ConnectivityUiState.OFFLINE, ConnectivityUiState.fromPageSignal(true, false, false));
        assertEquals(ConnectivityUiState.ONLINE, ConnectivityUiState.fromPageSignal(true, true, false));
        assertEquals(ConnectivityUiState.UNCONFIRMED, ConnectivityUiState.fromPageSignal(true, false, true));
        assertEquals(ConnectivityUiState.UNCONFIRMED, ConnectivityUiState.fromPageSignal(true, true, true));
    }
    @Test public void subresourceErrorsDoNotProduceAnOfflinePageSignal() {
        assertEquals(ConnectivityUiState.ONLINE, ConnectivityUiState.fromPageSignal(false, false, false));
    }
}
