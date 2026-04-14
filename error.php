<?php
$message = (string) ($_GET['message'] ?? 'Something went wrong while loading AdminLens.');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AdminLens Error</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="page-shell chat-page">
        <header class="site-header">
            <div class="brand">AdminLens</div>
            <nav class="site-nav">
                <a href="index.php">Dashboard</a>
                <a href="inventory.php">Inventory</a>
            </nav>
        </header>

        <main class="chat-panel">
            <h1 class="page-title">Unable to Load Page</h1>
            <p class="page-intro"><?= htmlspecialchars($message) ?></p>
            <a class="back-link" href="index.php">Back to Dashboard</a>
        </main>
    </div>
</body>
</html>
