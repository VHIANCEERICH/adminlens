<?php

function adminlens_storage_dir(): string
{
    $dir = __DIR__ . '/../storage';

    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    return $dir;
}

function adminlens_archive_file(): string
{
    return adminlens_storage_dir() . '/archived_products.json';
}

function adminlens_normalize_sku(string $sku): string
{
    return strtolower(trim($sku));
}

function adminlens_read_json_file(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return [];
    }

    $decoded = json_decode($contents, true);

    return is_array($decoded) ? $decoded : [];
}

function adminlens_get_archived_skus(): array
{
    $raw = adminlens_read_json_file(adminlens_archive_file());
    $skus = [];

    foreach ($raw as $sku) {
        if (is_string($sku) && $sku !== '') {
            $skus[] = adminlens_normalize_sku($sku);
        }
    }

    return array_values(array_unique($skus));
}

function adminlens_write_archived_skus(array $skus): void
{
    $skus = array_values(array_unique(array_filter(array_map(
        static fn ($sku) => is_string($sku) ? adminlens_normalize_sku($sku) : '',
        $skus
    ))));

    file_put_contents(
        adminlens_archive_file(),
        json_encode($skus, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function adminlens_is_archived(string $sku): bool
{
    return in_array(adminlens_normalize_sku($sku), adminlens_get_archived_skus(), true);
}

function adminlens_archive_product(string $sku): void
{
    $normalized = adminlens_normalize_sku($sku);
    $skus = adminlens_get_archived_skus();

    if (!in_array($normalized, $skus, true)) {
        $skus[] = $normalized;
        adminlens_write_archived_skus($skus);
    }
}

function adminlens_unarchive_product(string $sku): void
{
    $normalized = adminlens_normalize_sku($sku);
    $skus = array_values(array_filter(
        adminlens_get_archived_skus(),
        static fn ($item) => $item !== $normalized
    ));

    adminlens_write_archived_skus($skus);
}

