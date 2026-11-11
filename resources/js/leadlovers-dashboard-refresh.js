export function watchPendingLeadLovers({ hasPending, canReload, reload, schedule = setTimeout, interval = 30000 }) {
    if (!hasPending()) {
        return;
    }

    schedule(function checkPending() {
        if (!hasPending()) {
            return;
        }

        if (canReload()) {
            reload();
            return;
        }

        schedule(checkPending, interval);
    }, interval);
}
