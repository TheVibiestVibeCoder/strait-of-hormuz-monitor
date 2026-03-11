<?php
$binary = defined('PHP_BINARY') ? PHP_BINARY : 'PHP_BINARY not available';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>PHP Binary Check</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      background: #f6f7f9;
      color: #222;
      padding: 40px;
    }
    .box {
      max-width: 700px;
      margin: 0 auto;
      background: #fff;
      border: 1px solid #ddd;
      border-radius: 10px;
      padding: 24px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }
    code {
      display: block;
      margin-top: 12px;
      padding: 12px;
      background: #f1f1f1;
      border-radius: 6px;
      font-size: 16px;
    }
  </style>
</head>
<body>
  <div class="box">
    <h1>PHP Binary Check</h1>
    <p>Your server reports this PHP binary path:</p>
    <code><?php echo htmlspecialchars($binary, ENT_QUOTES, 'UTF-8'); ?></code>
  </div>
</body>
</html>