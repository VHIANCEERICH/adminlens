<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/helpers/data.php';
require_once __DIR__ . '/helpers/status.php';
require_once __DIR__ . '/helpers/charts.php';

$errors = [];
$values = [
    'sku' => '',
    'product_name' => '',
    'stock_on_hand' => '0',
    'price' => '0.00',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $key => $default) {
        $values[$key] = trim((string) ($_POST[$key] ?? $default));
    }

    try {
        $pdo = require __DIR__ . '/config/db.php';
        $stmt = $pdo->prepare(
            'INSERT INTO products (sku, product_name, stock_on_hand, price)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $values['sku'],
            $values['product_name'],
            (int) $values['stock_on_hand'],
            (float) $values['price'],
        ]);

        generate_all_charts();
        header('Location: inventory.php');
        exit;
    } catch (Throwable $e) {
        $errors[] = 'Unable to add product. Make sure the SKU is unique and all fields are valid.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Product - AdminLens</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="page-shell chat-page">
        <header class="site-header">
            <div class="brand">AdminLens</div>
            <nav class="site-nav">
                <a href="index.php">Dashboard</a>
                <a href="inventory.php" class="is-active">Inventory</a>
                <a href="index.php#charts">Charts</a>
            </nav>
        </header>

        <main class="chat-panel form-card">
            <h1 class="page-title">Add Product</h1>
            <p class="page-intro">Create a new product record for the boutique inventory.</p>

            <?php if ($errors): ?>
                <div class="alert-box">
                    <?php foreach ($errors as $error): ?>
                        <div><?= htmlspecialchars($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="form-grid">
                <div class="form-field">
                    <label for="sku">SKU</label>
                    <input id="sku" class="chat-input" type="text" name="sku" value="<?= htmlspecialchars($values['sku']) ?>" placeholder="e.g. blackshirt-002" required>
                </div>

                <div class="form-field">
                    <label for="product_name">Product Name</label>
                    <input id="product_name" class="chat-input" type="text" name="product_name" value="<?= htmlspecialchars($values['product_name']) ?>" placeholder="e.g. Classic Black T-Shirt" required>
                </div>

                <div class="form-field">
                    <label for="stock_on_hand">Stock on Hand</label>
                    <input id="stock_on_hand" class="chat-input" type="number" name="stock_on_hand" min="0" value="<?= htmlspecialchars($values['stock_on_hand']) ?>" required>
                </div>

                <div class="form-field">
                    <label for="price">Unit Price</label>
                    <input id="price" class="chat-input" type="number" step="0.01" name="price" min="0" value="<?= htmlspecialchars($values['price']) ?>" required>
                </div>

                <div class="form-actions">
                    <a class="action-btn" href="inventory.php">Cancel</a>
                    <button type="submit" class="button">Save Product</button>
                </div>
            </form>
        </main>
    </div>
</body>
</html>
