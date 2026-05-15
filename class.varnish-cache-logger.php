<?php

final class ClpVarnishCacheLogger {

    private const TRANSIENT_KEY = 'clp_varnish_purge_log';
    private const MAX_ENTRIES   = 20;

    public static function log(PurgeType $type, string $target, bool $success, string $message = ''): void {
        $log = self::get_log();
        array_unshift($log, [
            'time'    => current_time('mysql'),
            'type'    => $type->value,
            'target'  => $target,
            'success' => $success,
            'message' => $message,
        ]);
        set_transient(self::TRANSIENT_KEY, array_slice($log, 0, self::MAX_ENTRIES), DAY_IN_SECONDS);
    }

    public static function get_log(): array {
        return (array) (get_transient(self::TRANSIENT_KEY) ?: []);
    }

    public static function clear(): void {
        delete_transient(self::TRANSIENT_KEY);
    }
}
