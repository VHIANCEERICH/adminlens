<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/helpers/data.php';
require_once __DIR__ . '/helpers/status.php';
require_once __DIR__ . '/helpers/archive.php';
require_once __DIR__ . '/helpers/charts.php';

adminlens_require_role('admin');

$errors = [];
$success_message = '';
$show_add_modal = false;
$add_values = [
    'product_name' => '',
    'product_type' => '',
    'stock_on_hand' => '0',
    'price' => '0.00',
];

function adminlens_remove_product_files(string $sku): void
{
    $safeSku = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($sku));

    foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
        $imageFile = __DIR__ . '/assets/product_images/' . $safeSku . '.' . $ext;
        if (is_file($imageFile)) {
            @unlink($imageFile);
        }
    }

    $chartFile = CHARTS_DIR . $safeSku . '_chart.png';
    if (is_file($chartFile)) {
        @unlink($chartFile);
    }
}

function adminlens_slugify_product_name(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim((string) $slug, '-');

    return $slug !== '' ? $slug : 'product';
}

function adminlens_generate_next_sku_number(PDO $pdo): int
{
    $stmt = $pdo->query('SELECT sku FROM products');
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $usedNumbers = [];

    foreach ($rows as $rowSku) {
        $rowSku = strtolower(trim((string) $rowSku));
        if (preg_match('/-(\d+)$/', $rowSku, $matches)) {
            $usedNumbers[(int) $matches[1]] = true;
        }
    }

    $nextNumber = 1;
    while (isset($usedNumbers[$nextNumber])) {
        $nextNumber++;
    }

    return $nextNumber;
}

function adminlens_generate_next_sku(PDO $pdo, string $productName): string
{
    $base = adminlens_slugify_product_name($productName);
    $nextNumber = adminlens_generate_next_sku_number($pdo);

    return sprintf('%s-%03d', $base, $nextNumber);
}

function adminlens_sku_exists(PDO $pdo, string $sku): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE LOWER(TRIM(sku)) = LOWER(TRIM(?))');
    $stmt->execute([$sku]);

    return (int) $stmt->fetchColumn() > 0;
}

function adminlens_product_exists(PDO $pdo, string $productName, string $productType): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM products
         WHERE LOWER(TRIM(product_name)) = LOWER(TRIM(?))
           AND LOWER(TRIM(product_type)) = LOWER(TRIM(?))'
    );
    $stmt->execute([$productName, $productType]);

    return (int) $stmt->fetchColumn() > 0;
}

if ((string) ($_GET['status'] ?? '') === 'added') {
    $successSku = trim((string) ($_GET['sku'] ?? ''));
    $successName = trim((string) ($_GET['name'] ?? ''));

    if ($successName !== '') {
        $success_message = 'Product "' . $successName . '" was successfully added'
            . ($successSku !== '' ? ' with SKU ' . $successSku : '')
            . '.';
    } else {
        $success_message = 'Product was successfully added.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'add_product') {
    $show_add_modal = true;

    $add_values['product_name'] = trim((string) ($_POST['product_name'] ?? ''));
    $selected_product_type = trim((string) ($_POST['product_type'] ?? ''));
    $new_product_type = trim((string) ($_POST['product_type_new'] ?? ''));
    $add_values['product_type'] = $new_product_type !== '' ? $new_product_type : $selected_product_type;
    $add_values['stock_on_hand'] = trim((string) ($_POST['stock_on_hand'] ?? '0'));
    $add_values['price'] = trim((string) ($_POST['price'] ?? '0.00'));

    $product_name = $add_values['product_name'];
    $product_type = $add_values['product_type'];
    $stock_on_hand_raw = $add_values['stock_on_hand'];
    $price_raw = $add_values['price'];

    if ($product_name === '') {
        $errors[] = 'Product Name is required.';
    }

    if ($product_type === '') {
        $errors[] = 'Product Type is required.';
    }

    if ($stock_on_hand_raw === '' || filter_var($stock_on_hand_raw, FILTER_VALIDATE_INT) === false) {
        $errors[] = 'Stock on Hand must be a whole number.';
    }

    if ($price_raw === '' || !is_numeric($price_raw)) {
        $errors[] = 'Unit Price must be a valid number.';
    }

    $stock_on_hand = (int) $stock_on_hand_raw;
    $price = (float) $price_raw;

    if ($stock_on_hand < 0) {
        $errors[] = 'Stock on Hand cannot be negative.';
    }

    if ($price < 0) {
        $errors[] = 'Unit Price cannot be negative.';
    }

    try {
        $pdo = require __DIR__ . '/config/db.php';

        if (!$errors) {
            if (adminlens_product_exists($pdo, $product_name, $product_type)) {
                $errors[] = 'A product with that product name and type already exists.';
            }
        }

        $sku = '';
        $imageFile = $_FILES['product_image'] ?? null;
        $hasImage = is_array($imageFile) && (int) ($imageFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $allowed = ['png', 'jpg', 'jpeg', 'webp'];
        $imageExt = '';

        if (!$errors && $hasImage) {
            $uploadError = (int) ($imageFile['error'] ?? UPLOAD_ERR_OK);

            if ($uploadError !== UPLOAD_ERR_OK) {
                $errors[] = 'Product image upload failed. Please try again.';
            } else {
                $imageExt = strtolower(pathinfo((string) ($imageFile['name'] ?? ''), PATHINFO_EXTENSION));
                if (!in_array($imageExt, $allowed, true)) {
                    $errors[] = 'Product image must be a PNG, JPG, JPEG, or WEBP file.';
                }
            }
        }

        if (!$errors) {
            $pdo->beginTransaction();
            $sku = adminlens_generate_next_sku($pdo, $product_name);

            if (adminlens_sku_exists($pdo, $sku)) {
                $errors[] = 'Unable to generate a unique SKU right now. Please try again.';
            }
        }

        if (!$errors) {
            $stmt = $pdo->prepare(
                'INSERT INTO products (sku, product_name, product_type, stock_on_hand, price)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$sku, $product_name, $product_type, $stock_on_hand, $price]);

            if ($hasImage && $imageExt !== '') {
                $uploadDir = __DIR__ . '/assets/product_images/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $safeSku = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($sku));
                $imagePath = $uploadDir . $safeSku . '.' . $imageExt;
                if (!move_uploaded_file((string) $imageFile['tmp_name'], $imagePath)) {
                    throw new RuntimeException('Unable to save uploaded product image.');
                }
            }

            $pdo->commit();
            generate_all_charts();
            header('Location: inventory.php?status=added&sku=' . rawurlencode($sku) . '&name=' . rawurlencode($product_name));
            exit;
        }

        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if (!$errors) {
            $errors[] = 'Unable to add product right now. Please check the details and try again.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string) ($_POST['action'] ?? ''), ['archive', 'unarchive', 'delete'], true)) {
    $action = (string) ($_POST['action'] ?? '');
    $sku = trim((string) ($_POST['sku'] ?? ''));

    if ($sku !== '') {
        try {
            $pdo = require __DIR__ . '/config/db.php';

            if ($action === 'archive') {
                adminlens_archive_product($sku);
            } elseif ($action === 'unarchive') {
                adminlens_unarchive_product($sku);
            } elseif ($action === 'delete') {
                $stmt = $pdo->prepare('DELETE FROM products WHERE sku = ?');
                $stmt->execute([$sku]);
                adminlens_unarchive_product($sku);
                adminlens_remove_product_files($sku);
            }

            generate_all_charts();
            header('Location: inventory.php');
            exit;
        } catch (Throwable $e) {
            $errors[] = 'Unable to update that product right now.';
        }
    }
}

try {
    generate_all_charts();
    $products = get_all_products();
    $archived_products = get_archived_products();
    $best_sku = (string) (get_best_seller()['sku'] ?? '');
    $least_sku = (string) (get_least_sold()['sku'] ?? '');
    $product_types = [];
    foreach (array_merge($products, $archived_products) as $product) {
        $type = trim((string) ($product['product_type'] ?? ''));
        if ($type !== '') {
            $product_types[$type] = true;
        }
    }
    $product_types = array_keys($product_types);
    natcasesort($product_types);
    $product_types = array_values($product_types);
} catch (Throwable $e) {
    header('Location: error.php?message=' . rawurlencode('Unable to load inventory data.'));
    exit;
}

function adminlens_status_badge_class(string $status): string
{
    return 'badge ' . get_badge_class($status);
}

function adminlens_row_class(string $status): string
{
    return match ($status) {
        'OUT OF STOCK' => 'row-out',
        'LOW STOCK' => 'row-low',
        default => '',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AdminLens Inventory</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="page-shell">
        <header class="site-header">
            <div class="brand">AdminLens</div>
            <nav class="site-nav">
                <a href="index.php">Dashboard</a>
                <a href="inventory.php" class="is-active">Inventory</a>
                <a href="orders.php">Orders</a>
                <a href="order_details.php">Order Details</a>
                <a href="index.php#charts">Charts</a>
                <a href="auth/logout.php?redirect=admin">Logout</a>
            </nav>
        </header>

        <main>
            <h1 class="page-title">Inventory Table</h1>
            <p class="page-intro">All products sorted by units sold, with stock status and direct access to each product page.</p>

            <div class="inventory-toolbar">
                <div class="inventory-filters">
                    <label class="inventory-filter">
                        <span>Search</span>
                        <input id="inventory-search" class="chat-input" type="search" placeholder="Search SKU, product name, or type">
                    </label>
                    <label class="inventory-filter">
                        <span>Product Type</span>
                        <select id="inventory-type-filter" class="chat-input">
                            <option value="">All types</option>
                            <?php foreach ($product_types as $type): ?>
                                <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="inventory-filter">
                        <span>Status</span>
                        <select id="inventory-status-filter" class="chat-input">
                            <option value="">All statuses</option>
                            <option value="AVAILABLE">Available</option>
                            <option value="LOW STOCK">Low Stock</option>
                            <option value="OUT OF STOCK">Out of Stock</option>
                        </select>
                    </label>
                </div>
                <a class="button button--secondary" href="#add-product-modal">+ Add Product</a>
            </div>

            <div class="inventory-results" id="inventory-results">Showing all active products.</div>
            <div class="inventory-empty" id="inventory-empty" hidden>No products match the current search and filters.</div>

            <?php if ($errors): ?>
                <div class="alert-box" style="margin-bottom:16px;">
                    <?php foreach ($errors as $error): ?>
                        <div><?= htmlspecialchars($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <table class="data-table" id="inventory-table">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Product Name</th>
                        <th>Product Type</th>
                        <th>Stock on Hand</th>
                        <th>Reorder Point</th>
                        <th>Units Sold</th>
                        <th>Unit Price</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <?php
                        $sku = (string) ($product['sku'] ?? '');
                        $status = (string) ($product['status'] ?? 'OK');
                        $is_best = $sku === $best_sku;
                        $is_least = $sku === $least_sku;
                        $imageUrl = get_product_image_url($sku);
                        $productType = trim((string) ($product['product_type'] ?? ''));
                        ?>
                        <tr
                            class="<?= adminlens_row_class($status) ?> inventory-row"
                            data-name="<?= htmlspecialchars(strtolower((string) ($product['product_name'] ?? ''))) ?>"
                            data-sku="<?= htmlspecialchars(strtolower($sku)) ?>"
                            data-type="<?= htmlspecialchars(strtolower($productType)) ?>"
                            data-status="<?= htmlspecialchars($status) ?>"
                        >
                            <td class="sku"><?= htmlspecialchars($sku) ?></td>
                            <td class="row-name">
                                <?php if ($is_best): ?>
                                    <span class="marker-best">&#9733;</span>
                                <?php elseif ($is_least): ?>
                                    <span class="marker-least">&#9679;</span>
                                <?php endif; ?>
                                <?php if ($imageUrl): ?>
                                    <span class="table-thumb-wrap">
                                        <img class="table-thumb" src="<?= htmlspecialchars($imageUrl) ?>" alt="<?= htmlspecialchars((string) ($product['product_name'] ?? 'Product')) ?>">
                                    </span>
                                <?php endif; ?>
                                <a href="product.php?sku=<?= rawurlencode($sku) ?>">
                                    <?= htmlspecialchars((string) ($product['product_name'] ?? 'Unnamed Product')) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($productType !== '' ? $productType : 'Uncategorized') ?></td>
                            <td><?= number_format((int) ($product['stock_on_hand'] ?? 0)) ?></td>
                            <td><?= number_format((int) ($product['reorder_point'] ?? 0)) ?></td>
                            <td><?= number_format((int) ($product['units_sold'] ?? 0)) ?></td>
                            <td>&#8369;<?= number_format((float) ($product['price'] ?? 0), 2) ?></td>
                            <td>
                                <span class="<?= adminlens_status_badge_class($status) ?>">
                                    <?= htmlspecialchars($status) ?>
                                </span>
                            </td>
                            <td>
                                <div class="table-actions">
                                    <a class="action-btn action-btn--primary" href="edit_product.php?sku=<?= rawurlencode($sku) ?>">Edit</a>
                                    <form method="POST" action="inventory.php" class="inline-form">
                                        <input type="hidden" name="action" value="archive">
                                        <input type="hidden" name="sku" value="<?= htmlspecialchars($sku) ?>">
                                        <button type="submit" class="action-btn action-btn--warning">Archive</button>
                                    </form>
                                    <form method="POST" action="inventory.php" class="inline-form">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="sku" value="<?= htmlspecialchars($sku) ?>">
                                        <button type="submit" class="action-btn action-btn--danger">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($archived_products): ?>
                <section class="section-block" style="margin-top:32px;">
                    <h2 class="section-title">Archived Products</h2>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Product Name</th>
                                <th>Product Type</th>
                                <th>Stock on Hand</th>
                                <th>Reorder Point</th>
                                <th>Units Sold</th>
                                <th>Unit Price</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($archived_products as $product): ?>
                                <?php
                                $sku = (string) ($product['sku'] ?? '');
                                $status = (string) ($product['status'] ?? 'Ok');
                                $imageUrl = get_product_image_url($sku);
                                $productType = trim((string) ($product['product_type'] ?? ''));
                                ?>
                                <tr
                                    class="row-archived inventory-row"
                                    data-name="<?= htmlspecialchars(strtolower((string) ($product['product_name'] ?? ''))) ?>"
                                    data-sku="<?= htmlspecialchars(strtolower($sku)) ?>"
                                    data-type="<?= htmlspecialchars(strtolower($productType)) ?>"
                                    data-status="<?= htmlspecialchars(strtoupper($status)) ?>"
                                >
                                    <td class="sku"><?= htmlspecialchars($sku) ?></td>
                                    <td class="row-name">
                                        <?php if ($imageUrl): ?>
                                            <span class="table-thumb-wrap">
                                                <img class="table-thumb" src="<?= htmlspecialchars($imageUrl) ?>" alt="<?= htmlspecialchars((string) ($product['product_name'] ?? 'Product')) ?>">
                                            </span>
                                        <?php endif; ?>
                                        <a href="product.php?sku=<?= rawurlencode($sku) ?>">
                                            <?= htmlspecialchars((string) ($product['product_name'] ?? 'Unnamed Product')) ?>
                                        </a>
                                    </td>
                                    <td><?= htmlspecialchars($productType !== '' ? $productType : 'Uncategorized') ?></td>
                                    <td><?= number_format((int) ($product['stock_on_hand'] ?? 0)) ?></td>
                                    <td><?= number_format((int) ($product['reorder_point'] ?? 0)) ?></td>
                                    <td><?= number_format((int) ($product['units_sold'] ?? 0)) ?></td>
                                    <td>&#8369;<?= number_format((float) ($product['price'] ?? 0), 2) ?></td>
                                    <td>
                                        <span class="<?= adminlens_status_badge_class($status) ?>">
                                            <?= htmlspecialchars($status) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <form method="POST" action="inventory.php" class="inline-form">
                                                <input type="hidden" name="action" value="unarchive">
                                                <input type="hidden" name="sku" value="<?= htmlspecialchars($sku) ?>">
                                                <button type="submit" class="action-btn action-btn--primary">Unarchive</button>
                                            </form>
                                            <form method="POST" action="inventory.php" class="inline-form">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="sku" value="<?= htmlspecialchars($sku) ?>">
                                                <button type="submit" class="action-btn action-btn--danger">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>
            <?php endif; ?>
        </main>
    </div>

    <section id="add-product-modal" class="modal-overlay <?= ($show_add_modal || !empty($errors)) ? 'is-open' : '' ?>">
        <div class="modal-dialog">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title">Add Product</h2>
                    <p class="modal-subtitle">Create a new product record and optionally upload a product image.</p>
                </div>
                <a class="modal-close" href="inventory.php" aria-label="Close modal">&times;</a>
            </div>

            <form method="POST" enctype="multipart/form-data" class="form-grid">
                <input type="hidden" name="action" value="add_product">
                <?php
                $selectedProductType = in_array($add_values['product_type'], $product_types, true) ? $add_values['product_type'] : '';
                $customProductType = $selectedProductType === '' ? $add_values['product_type'] : '';
                ?>

                <?php if ($errors): ?>
                    <div class="alert-box">
                        <?php foreach ($errors as $error): ?>
                            <div><?= htmlspecialchars($error) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="form-field">
                    <label>SKU</label>
                    <input class="chat-input" type="text" value="Auto-generated after save" readonly>
                    <div class="field-help">The SKU is generated automatically from the product name with the next available number.</div>
                </div>

                <div class="form-field">
                    <label for="product_name">Product Name</label>
                    <input id="product_name" class="chat-input" type="text" name="product_name" placeholder="e.g. Classic Black T-Shirt" value="<?= htmlspecialchars($add_values['product_name']) ?>" required>
                </div>

                <div class="form-field">
                    <label for="product_type">Product Type</label>
                    <div class="product-type-fields">
                        <select id="product_type" class="chat-input" name="product_type">
                            <option value="">Select an existing type</option>
                            <?php foreach ($product_types as $type): ?>
                                <option value="<?= htmlspecialchars($type) ?>" <?= $selectedProductType === $type ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($type) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input
                            id="product_type_new"
                            class="chat-input"
                            type="text"
                            name="product_type_new"
                            placeholder="Or type a new product type"
                            value="<?= htmlspecialchars($customProductType) ?>"
                        >
                        <button type="button" class="action-btn action-btn--danger product-type-clear" id="product_type_clear">Delete</button>
                    </div>
                    <div class="field-help">Choose an existing type to load its name into the editable field, or type a brand-new type.</div>
                </div>

                <div class="form-field">
                    <label for="stock_on_hand">Stock on Hand</label>
                    <input id="stock_on_hand" class="chat-input" type="number" name="stock_on_hand" min="0" value="<?= htmlspecialchars($add_values['stock_on_hand']) ?>" required>
                </div>

                <div class="form-field">
                    <label for="price">Unit Price</label>
                    <input id="price" class="chat-input" type="number" step="0.01" name="price" min="0" value="<?= htmlspecialchars($add_values['price']) ?>" required>
                </div>

                <div class="form-field">
                    <label for="product_image">Product Image</label>
                    <input id="product_image" class="chat-input" type="file" name="product_image" accept="image/*">
                    <div class="field-help">Upload a product photo and it will appear on the product detail page.</div>
                    <div class="image-preview">
                        <div class="image-preview__box" id="product-image-preview">
                            <span class="image-preview__empty">No image selected yet.</span>
                        </div>
                    </div>
                </div>

                <p class="page-intro" style="margin: 0;">Optional image upload. The file will be saved using the SKU as its filename.</p>

                <div class="form-actions">
                    <a class="action-btn" href="inventory.php">Cancel</a>
                    <button type="submit" class="button">Save Product</button>
                </div>
            </form>
        </div>
    </section>

    <?php if ($success_message !== ''): ?>
        <div class="toast toast--success is-visible" id="inventory-toast" role="status" aria-live="polite">
            <div class="toast__content">
                <strong>Success</strong>
                <span><?= htmlspecialchars($success_message) ?></span>
            </div>
            <button type="button" class="toast__close" id="inventory-toast-close" aria-label="Dismiss notification">&times;</button>
        </div>
    <?php endif; ?>

    <script>
        (function () {
            var input = document.getElementById('product_image');
            var preview = document.getElementById('product-image-preview');
            if (!input || !preview) return;

            input.addEventListener('change', function () {
                var file = this.files && this.files[0] ? this.files[0] : null;

                if (!file) {
                    preview.innerHTML = '<span class="image-preview__empty">No image selected yet.</span>';
                    return;
                }

                var reader = new FileReader();
                reader.onload = function (event) {
                    preview.innerHTML = '<img src="' + event.target.result + '" alt="Selected product image preview">';
                };
                reader.readAsDataURL(file);
            });
        })();

        (function () {
            var typeSelect = document.getElementById('product_type');
            var typeInput = document.getElementById('product_type_new');
            var clearButton = document.getElementById('product_type_clear');
            if (!typeSelect || !typeInput || !clearButton) return;

            typeSelect.addEventListener('change', function () {
                if (typeSelect.value !== '') {
                    typeInput.value = typeSelect.value;
                } else {
                    typeInput.placeholder = 'Or type a new product type';
                }
            });

            clearButton.addEventListener('click', function () {
                typeSelect.value = '';
                typeInput.value = '';
                typeInput.placeholder = 'Or type a new product type';
                typeInput.focus();
            });
        })();

        (function () {
            var searchInput = document.getElementById('inventory-search');
            var typeFilter = document.getElementById('inventory-type-filter');
            var statusFilter = document.getElementById('inventory-status-filter');
            var rows = Array.prototype.slice.call(document.querySelectorAll('.inventory-row'));
            var emptyState = document.getElementById('inventory-empty');
            var results = document.getElementById('inventory-results');
            var archivedSection = document.querySelector('.section-block');
            var timerId = null;

            if (!searchInput || !typeFilter || !statusFilter || rows.length === 0 || !emptyState || !results) return;

            var updateFilters = function () {
                var searchTerm = searchInput.value.trim().toLowerCase();
                var selectedType = typeFilter.value.trim().toLowerCase();
                var selectedStatus = statusFilter.value.trim().toUpperCase();
                var activeVisible = 0;
                var archivedVisible = 0;

                rows.forEach(function (row) {
                    var haystack = [
                        row.getAttribute('data-sku') || '',
                        row.getAttribute('data-name') || '',
                        row.getAttribute('data-type') || ''
                    ].join(' ');
                    var rowType = row.getAttribute('data-type') || '';
                    var rowStatus = row.getAttribute('data-status') || '';
                    var matchesSearch = searchTerm === '' || haystack.indexOf(searchTerm) !== -1;
                    var matchesType = selectedType === '' || rowType === selectedType;
                    var matchesStatus = selectedStatus === '' || rowStatus === selectedStatus;
                    var isVisible = matchesSearch && matchesType && matchesStatus;
                    row.hidden = !isVisible;

                    if (isVisible) {
                        if (row.classList.contains('row-archived')) {
                            archivedVisible++;
                        } else {
                            activeVisible++;
                        }
                    }
                });

                if (archivedSection) {
                    archivedSection.hidden = archivedVisible === 0;
                }

                emptyState.hidden = activeVisible !== 0;

                if (searchTerm === '' && selectedType === '' && selectedStatus === '') {
                    results.textContent = 'Showing all active products.';
                    return;
                }

                results.textContent = 'Showing ' + activeVisible + ' active product' + (activeVisible === 1 ? '' : 's') + '.';
            };

            var debouncedUpdate = function () {
                window.clearTimeout(timerId);
                timerId = window.setTimeout(updateFilters, 220);
            };

            searchInput.addEventListener('input', debouncedUpdate);
            typeFilter.addEventListener('change', updateFilters);
            statusFilter.addEventListener('change', updateFilters);
        })();

        (function () {
            var toast = document.getElementById('inventory-toast');
            if (!toast) return;

            var closeButton = document.getElementById('inventory-toast-close');
            var hideToast = function () {
                toast.classList.remove('is-visible');
            };

            if (closeButton) {
                closeButton.addEventListener('click', hideToast);
            }

            window.setTimeout(hideToast, 3600);

            if (window.history && window.history.replaceState) {
                var cleanUrl = window.location.pathname + window.location.hash;
                window.history.replaceState({}, document.title, cleanUrl);
            }
        })();
    </script>
</body>
</html>
