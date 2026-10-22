package kz.x86.stock.ui;

public enum ConnectivityUiState {
    ONLINE, OFFLINE, UNCONFIRMED;
    public static ConnectivityUiState fromPageSignal(boolean mainFrameAffected, boolean networkAvailable, boolean knownWriteRequestLost) {
        if (knownWriteRequestLost) return UNCONFIRMED;
        if (!mainFrameAffected) return ONLINE;
        return networkAvailable ? ONLINE : OFFLINE;
    }
}
