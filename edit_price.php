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
    'price' => (string) ($product['price'] ?? '0.00'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['price'] = trim((string) ($_POST['price'] ?? $values['price']));

    try {
        $pdo = require __DIR__ . '/config/db.php';
        $stmt = $pdo->prepare('UPDATE products SET price = ? WHERE sku = ?');
        $stmt->execute([
            (float) $values['price'],
            $sku,
        ]);

        generate_all_charts();
        header('Location: inventory.php');
        exit;
    } catch (Throwable $e) {
        $errors[] = 'Unable to update the product price.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Price - AdminLens</title>
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

        <main class="chat-panel">
            <h1 class="page-title">Edit Price</h1>
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
                <input class="chat-input" type="number" step="0.01" min="0" name="price" value="<?= htmlspecialchars($values['price']) ?>" required>
                <button type="submit" class="button">Update Price</button>
            </form>
        </main>
    </div>
</body>
</html>
