<?php
// Migration 16 — Deduplicate categories + UNIQUE constraint; fix reports product search (no other DB changes)
try {
    // Remap products from duplicate categories to the original (lowest id per name)
    $pdo->exec("
        UPDATE products p
        JOIN categories dup ON dup.id = p.category_id
        JOIN (SELECT name, MIN(id) AS orig_id FROM categories GROUP BY name) orig ON orig.name = dup.name
        SET p.category_id = orig.orig_id
        WHERE dup.id != orig.orig_id
    ");

    // Delete duplicate categories (keep only lowest id per name)
    $pdo->exec("
        DELETE FROM categories WHERE id NOT IN (
            SELECT orig_id FROM (SELECT name, MIN(id) AS orig_id FROM categories GROUP BY name) t
        )
    ");

    // Add UNIQUE constraint if not already present
    $has = $pdo->query("SHOW INDEX FROM categories WHERE Key_name='uq_cat_name'")->fetch();
    if (!$has) {
        $pdo->exec("ALTER TABLE categories ADD UNIQUE KEY uq_cat_name (name)");
    }

    return true;
} catch (Throwable $e) {
    echo 'Migration 16 error: ' . $e->getMessage() . PHP_EOL;
    return false;
}
