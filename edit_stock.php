<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/helpers/data.php';
require_once __DIR__ . '/helpers/status.php';
require_once __DIR__ . '/helpers/charts.php';

$sku = (string) ($_GET['sku'] ?? $_POST['sku'] ?? '');
$product = $sku !== '' ? get_product_by_sku($sku) : null;
$errors = [];

if (!$product) {
    header('Location: inventory.php');
    exit;
}

$values = [
    'stock_on_hand' => (string) ($product['stock_on_hand'] ?? 0),
    'reorder_point' => (string) ($product['reorder_point'] ?? 0),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['stock_on_hand'] = trim((string) ($_POST['stock_on_hand'] ?? $values['stock_on_hand']));
    $values['reorder_point'] = trim((string) ($_POST['reorder_point'] ?? $values['reorder_point']));

    try {
        $pdo = require __DIR__ . '/config/db.php';
        $stmt = $pdo->prepare('UPDATE products SET stock_on_hand = ?, reorder_point = ? WHERE sku = ?');
        $stmt->execute([
            (int) $values['stock_on_hand'],
            (int) $values['reorder_point'],
            $sku,
        ]);

        generate_all_charts();
        header('Location: inventory.php');
        exit;
    } catch (Throwable $e) {
        $errors[] = 'Unable to update stock values.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Stock - AdminLens</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="page-shell chat-page">
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

        <main class="chat-panel">
            <h1 class="page-title">Edit Stock</h1>
            <p class="page-intro"><?= htmlspecialchars((string) ($product['product_name'] ?? $sku)) ?></p>

            <?php if ($errors): ?>
                <div class="alert-box">
                    <?php foreach ($errors as $error): ?>
                        <div><?= htmlspecialchars($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="chat-form">
                <input type="hidden" name="sku" value="<?= htmlspecialchars($sku) ?>">
                <input class="chat-input" type="number" name="stock_on_hand" min="0" value="<?= htmlspecialchars($values['stock_on_hand']) ?>" required>
                <input class="chat-input" type="number" name="reorder_point" min="0" value="<?= htmlspecialchars($values['reorder_point']) ?>" required>
                <button type="submit" class="button">Update Stock</button>
            </form>
        </main>
    </div>
</body>
</html>
