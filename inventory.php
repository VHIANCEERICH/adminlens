<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/helpers/data.php';
require_once __DIR__ . '/helpers/status.php';
require_once __DIR__ . '/helpers/archive.php';
require_once __DIR__ . '/helpers/charts.php';

$errors = [];
$show_add_modal = false;
$add_values = [
    'sku' => '',
    'product_name' => '',
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'add_product') {
    $show_add_modal = true;

    $add_values['sku'] = trim((string) ($_POST['sku'] ?? ''));
    $add_values['product_name'] = trim((string) ($_POST['product_name'] ?? ''));
    $add_values['stock_on_hand'] = trim((string) ($_POST['stock_on_hand'] ?? '0'));
    $add_values['price'] = trim((string) ($_POST['price'] ?? '0.00'));

    $sku = $add_values['sku'];
    $product_name = $add_values['product_name'];
    $stock_on_hand = (int) $add_values['stock_on_hand'];
    $price = (float) $add_values['price'];

    if ($sku === '' || $product_name === '') {
        $errors[] = 'SKU and Product Name are required.';
    }

    try {
        $pdo = require __DIR__ . '/config/db.php';

        if (!$errors) {
            $stmt = $pdo->prepare(
                'INSERT INTO products (sku, product_name, stock_on_hand, price)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$sku, $product_name, $stock_on_hand, $price]);

            if (!empty($_FILES['product_image']['name']) && (int) ($_FILES['product_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/assets/product_images/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $safeSku = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($sku));
                $ext = strtolower(pathinfo((string) $_FILES['product_image']['name'], PATHINFO_EXTENSION));
                $allowed = ['png', 'jpg', 'jpeg', 'webp'];

                if (in_array($ext, $allowed, true)) {
                    $imagePath = $uploadDir . $safeSku . '.' . $ext;
                    move_uploaded_file((string) $_FILES['product_image']['tmp_name'], $imagePath);
                }
            }

            generate_all_charts();
            header('Location: inventory.php');
            exit;
        }
    } catch (Throwable $e) {
        $errors[] = 'Unable to add product. Make sure the SKU is unique and all fields are valid.';
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
                <a href="index.php#charts">Charts</a>
            </nav>
        </header>

        <main>
            <h1 class="page-title">Inventory Table</h1>
            <p class="page-intro">All products sorted by units sold, with stock status and direct access to each product page.</p>

            <div class="inventory-toolbar">
                <a class="button button--secondary" href="#add-product-modal">+ Add Product</a>
            </div>

            <?php if ($errors): ?>
                <div class="alert-box" style="margin-bottom:16px;">
                    <?php foreach ($errors as $error): ?>
                        <div><?= htmlspecialchars($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <table class="data-table">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Product Name</th>
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
                        ?>
                        <tr class="<?= adminlens_row_class($status) ?>">
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
                                $status = (string) ($product['status'] ?? 'OK');
                                $imageUrl = get_product_image_url($sku);
                                ?>
                                <tr class="row-archived">
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

                <div class="form-field">
                    <label for="sku">SKU</label>
                    <input id="sku" class="chat-input" type="text" name="sku" placeholder="e.g. blackshirt-002" value="<?= htmlspecialchars($add_values['sku']) ?>" required>
                </div>

                <div class="form-field">
                    <label for="product_name">Product Name</label>
                    <input id="product_name" class="chat-input" type="text" name="product_name" placeholder="e.g. Classic Black T-Shirt" value="<?= htmlspecialchars($add_values['product_name']) ?>" required>
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
    </script>
</body>
</html>
