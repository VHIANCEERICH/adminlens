<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/helpers/data.php';
require_once __DIR__ . '/helpers/status.php';
require_once __DIR__ . '/helpers/archive.php';
require_once __DIR__ . '/helpers/charts.php';

$sku = (string) ($_GET['sku'] ?? $_POST['sku'] ?? '');
$product = $sku !== '' ? get_product_by_sku($sku, true) : null;
$errors = [];

if (!$product) {
    header('Location: inventory.php');
    exit;
}

$values = [
    'product_type' => (string) ($product['product_type'] ?? ''),
    'stock_on_hand' => (string) ($product['stock_on_hand'] ?? 0),
    'price' => (string) ($product['price'] ?? '0.00'),
];

$product_types = [];

try {
    foreach (get_all_products(true) as $item) {
        $type = trim((string) ($item['product_type'] ?? ''));
        if ($type !== '') {
            $product_types[$type] = true;
        }
    }

    $product_types = array_keys($product_types);
    natcasesort($product_types);
    $product_types = array_values($product_types);
} catch (Throwable $e) {
    $product_types = [];
}

function adminlens_replace_product_image(string $sku, array $file): void
{
    $uploadDir = __DIR__ . '/assets/product_images/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $safeSku = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($sku));
    foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
        $existing = $uploadDir . $safeSku . '.' . $ext;
        if (is_file($existing)) {
            @unlink($existing);
        }
    }

    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowed = ['png', 'jpg', 'jpeg', 'webp'];

    if (in_array($ext, $allowed, true)) {
        $target = $uploadDir . $safeSku . '.' . $ext;
        move_uploaded_file((string) $file['tmp_name'], $target);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected_product_type = trim((string) ($_POST['product_type'] ?? $values['product_type']));
    $new_product_type = trim((string) ($_POST['product_type_new'] ?? ''));
    $values['product_type'] = $new_product_type !== '' ? $new_product_type : $selected_product_type;
    $values['stock_on_hand'] = trim((string) ($_POST['stock_on_hand'] ?? $values['stock_on_hand']));
    $values['price'] = trim((string) ($_POST['price'] ?? $values['price']));

    if ($values['product_type'] === '') {
        $errors[] = 'Product Type is required.';
    }

    try {
        if (!$errors) {
            $pdo = require __DIR__ . '/config/db.php';
            $stmt = $pdo->prepare('UPDATE products SET product_type = ?, stock_on_hand = ?, price = ? WHERE sku = ?');
            $stmt->execute([
                $values['product_type'],
                (int) $values['stock_on_hand'],
                (float) $values['price'],
                $sku,
            ]);
        }

        if (!$errors && !empty($_FILES['product_image']['name']) && (int) ($_FILES['product_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            adminlens_replace_product_image($sku, $_FILES['product_image']);
        }

        if (!$errors) {
            generate_all_charts();
            header('Location: inventory.php');
            exit;
        }
    } catch (Throwable $e) {
        $errors[] = 'Unable to update this product right now.';
    }
}

$currentImage = get_product_image_url($sku);
$status = (string) ($product['status'] ?? 'OK');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Product - AdminLens</title>
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
            <h1 class="page-title">Edit Product</h1>
            <p class="page-intro"><?= htmlspecialchars((string) ($product['product_name'] ?? $sku)) ?></p>

            <?php if ($errors): ?>
                <div class="alert-box" style="margin-bottom:16px;">
                    <?php foreach ($errors as $error): ?>
                        <div><?= htmlspecialchars($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="form-card">
                <div class="detail-grid" style="grid-template-columns: 1.2fr 0.8fr;">
                    <section class="detail-panel">
                        <div style="display:flex; justify-content:space-between; gap:16px; align-items:flex-start; margin-bottom:12px;">
                            <div>
                                <h2 class="section-title" style="margin-bottom:6px;">Product Details</h2>
                                <p class="sku"><?= htmlspecialchars($sku) ?></p>
                            </div>
                            <span class="badge <?= get_badge_class($status) ?>"><?= htmlspecialchars($status) ?></span>
                        </div>

                        <?php if ($currentImage): ?>
                            <div class="product-image-frame" style="max-width:240px; margin-bottom:18px;">
                                <img src="<?= htmlspecialchars($currentImage) ?>" alt="<?= htmlspecialchars((string) ($product['product_name'] ?? 'Product')) ?> image">
                            </div>
                        <?php endif; ?>

                        <form method="POST" enctype="multipart/form-data" class="form-grid">
                            <input type="hidden" name="sku" value="<?= htmlspecialchars($sku) ?>">
                            <?php
                            $selectedProductType = in_array($values['product_type'], $product_types, true) ? $values['product_type'] : '';
                            $customProductType = $selectedProductType === '' ? $values['product_type'] : '';
                            ?>

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
                                <input id="stock_on_hand" class="chat-input" type="number" min="0" name="stock_on_hand" value="<?= htmlspecialchars($values['stock_on_hand']) ?>" required>
                            </div>

                            <div class="form-field">
                                <label for="price">Unit Price</label>
                                <input id="price" class="chat-input" type="number" step="0.01" min="0" name="price" value="<?= htmlspecialchars($values['price']) ?>" required>
                            </div>

                            <div class="form-field">
                                <label for="product_image">Replace Product Image</label>
                                <input id="product_image" class="chat-input" type="file" name="product_image" accept="image/*">
                                <div class="field-help">Leave blank to keep the current product image.</div>
                            </div>

                            <div class="form-actions">
                                <a class="action-btn" href="inventory.php">Cancel</a>
                                <button type="submit" class="button">Save Changes</button>
                            </div>
                        </form>
                    </section>

                    <section class="detail-panel">
                        <h2 class="section-title">Quick Reference</h2>
                        <table class="detail-table">
                            <tbody>
                                <tr>
                                    <td>Product Name</td>
                                    <td><?= htmlspecialchars((string) ($product['product_name'] ?? '')) ?></td>
                                </tr>
                                <tr>
                                    <td>Product Type</td>
                                    <td><?= htmlspecialchars((string) ($values['product_type'] !== '' ? $values['product_type'] : 'Uncategorized')) ?></td>
                                </tr>
                                <tr>
                                    <td>Reorder Point</td>
                                    <td><?= number_format((int) ($product['reorder_point'] ?? 0)) ?></td>
                                </tr>
                                <tr>
                                    <td>Units Sold</td>
                                    <td><?= number_format((int) ($product['units_sold'] ?? 0)) ?></td>
                                </tr>
                                <tr>
                                    <td>Inventory Value</td>
                                    <td>&#8369;<?= number_format(((float) ($values['stock_on_hand'] ?? 0)) * ((float) ($values['price'] ?? 0)), 2) ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </section>
                </div>
            </div>
        </main>
    </div>

    <script>
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
    </script>
</body>
</html>
