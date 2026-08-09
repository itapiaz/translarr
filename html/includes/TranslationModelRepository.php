<?php
// html/includes/TranslationModelRepository.php
/**
 * Caché en SQLite de los modelos disponibles por proveedor.
 * Permite que la pantalla de configuración funcione sin depender de que la
 * API del proveedor esté disponible en cada carga.
 */
class TranslationModelRepository
{
    /**
     * Persiste una lista de modelos normalizados para un proveedor.
     * Los modelos que ya no aparezcan se marcan como no seleccionables (no se borran).
     */
    public static function sync(PDO $pdo, string $provider, array $models): void
    {
        $pdo->beginTransaction();
        try {
            // Marcar todos los modelos actuales como "no seleccionables" por defecto
            $pdo->prepare("UPDATE provider_models SET is_selectable=0 WHERE provider=?")
                ->execute([$provider]);

            $upsert = $pdo->prepare(
                "INSERT INTO provider_models
                    (provider, model_id, display_name, capabilities, is_recommended, is_selectable, raw_data, fetched_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                 ON CONFLICT(provider, model_id) DO UPDATE SET
                    display_name=excluded.display_name,
                    capabilities=excluded.capabilities,
                    is_recommended=excluded.is_recommended,
                    is_selectable=excluded.is_selectable,
                    raw_data=excluded.raw_data,
                    fetched_at=CURRENT_TIMESTAMP"
            );

            foreach ($models as $m) {
                $upsert->execute([
                    $provider,
                    $m['id'],
                    $m['display_name'] ?? $m['id'],
                    $m['capabilities'] ?? '',
                    (int) ($m['is_recommended'] ?? 0),
                    (int) ($m['is_selectable'] ?? 1),
                    $m['raw_data'] ?? '',
                ]);
            }

            $pdo->prepare(
                "INSERT INTO provider_model_sync (provider, status, message, fetched_at, updated_at)
                 VALUES (?, 'ok', '', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                 ON CONFLICT(provider) DO UPDATE SET
                    status='ok', message='', fetched_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP"
            )->execute([$provider]);

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Modelos cacheados de un proveedor (ordenados: recomendados primero).
     */
    public static function get(PDO $pdo, string $provider): array
    {
        $stmt = $pdo->prepare(
            "SELECT provider, model_id, display_name, capabilities, is_recommended, is_selectable, fetched_at
             FROM provider_models
             WHERE provider=?
             ORDER BY is_selectable DESC, is_recommended DESC, display_name ASC"
        );
        $stmt->execute([$provider]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Estado de la última sincronización de un proveedor.
     */
    public static function syncStatus(PDO $pdo, string $provider): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT provider, status, message, fetched_at FROM provider_model_sync WHERE provider=?"
        );
        $stmt->execute([$provider]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Registra una sincronización fallida conservando el caché anterior.
     */
    public static function markSyncFailed(PDO $pdo, string $provider, string $message): void
    {
        $pdo->prepare(
            "INSERT INTO provider_model_sync (provider, status, message, fetched_at, updated_at)
             VALUES (?, 'error', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             ON CONFLICT(provider) DO UPDATE SET status='error', message=?, updated_at=CURRENT_TIMESTAMP"
        )->execute([$provider, $message, $message]);
    }
}