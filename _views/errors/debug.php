<?php
$root = \mini\Mini::$mini->root;
$sensitiveValues = $sensitiveValues ?? [];
$sanitize = function($text) use ($root, $sensitiveValues) {
    // Replace root path with ./
    $text = str_replace($root, '.', $text);
    // Redact sensitive values
    foreach ($sensitiveValues as $value) {
        $text = str_replace($value, '[REDACTED]', $text);
    }
    return $text;
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($errorType ?? 'Error', ENT_QUOTES, 'UTF-8') ?> (Debug)</title>
    <style>
        .debug-container { position: fixed; top: 20px; left: 20px; right: 20px; bottom: 20px; z-index: 999999; overflow-y: auto; font-family: 'Consolas', 'Monaco', monospace; background: #1e1e1e; color: #d4d4d4; font-size: 14px; line-height: 1.5; padding: 2rem; box-sizing: border-box; border-radius: 8px; box-shadow: 0 0 0 9999px rgba(0,0,0,0.7); transition: all 0.3s ease; }
        .debug-container h1 { color: #f44747; margin: 0 0 1rem 0; font-size: 2rem; }
        .debug-container h2 { color: #569cd6; margin: 2rem 0 1rem 0; font-size: 1.2rem; }
        .debug-container .error-type { color: #ce9178; font-weight: bold; }
        .debug-container .error-message { color: #d7ba7d; background: #3c3c3c; padding: 1rem; border-radius: 4px; margin: 1rem 0; }
        .debug-container .error-location { color: #9cdcfe; }
        .debug-container .stack-trace { background: #252526; border: 1px solid #404040; border-radius: 4px; padding: 1rem; overflow-x: auto; white-space: pre; color: #cccccc; }
        .debug-container .debug-info { background: #0e639c20; border: 1px solid #0e639c; border-radius: 4px; padding: 1rem; margin: 1rem 0; }
        .debug-container .debug-info h3 { color: #569cd6; margin: 0 0 0.5rem 0; }
        .debug-container .back-link { margin-top: 2rem; }
        .debug-container .back-link a { color: #569cd6; text-decoration: none; }
        .debug-container .back-link a:hover { text-decoration: underline; }
        .debug-container .toggle-btn { position: absolute; top: 1rem; right: 1rem; background: #3c3c3c; border: 1px solid #404040; color: #d4d4d4; padding: 0.5rem 1rem; border-radius: 4px; cursor: pointer; font-family: inherit; font-size: 12px; display: none; }
        .debug-container .toggle-btn:hover { background: #4c4c4c; }
        .debug-container.collapsed { top: auto; bottom: 20px; height: auto; max-height: 60px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.5); }
        .debug-container.collapsed h1 { font-size: 1rem; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; padding-right: 100px; }
        .debug-container.collapsed > *:not(h1):not(.toggle-btn) { display: none; }
    </style>
</head>
<body>
    <div class="debug-container">
        <button class="toggle-btn" onclick="this.parentElement.classList.toggle('collapsed'); this.textContent = this.parentElement.classList.contains('collapsed') ? 'Expand' : 'Collapse';">Collapse</button>
        <h1>🐛 <?= htmlspecialchars($errorType ?? 'Error', ENT_QUOTES, 'UTF-8') ?></h1>

        <h2>Exception Details</h2>
        <div class="error-type"><?= htmlspecialchars($errorType ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></div>
        <div class="error-message"><?= htmlspecialchars($sanitize($message ?? 'No message'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="error-location"><strong>File:</strong> <?= htmlspecialchars($sanitize($file ?? 'unknown'), ENT_QUOTES, 'UTF-8') ?>:<?= htmlspecialchars($line ?? '0', ENT_QUOTES, 'UTF-8') ?></div>

        <h2>Stack Trace</h2>
        <div class="stack-trace"><?= htmlspecialchars($sanitize($trace ?? 'No trace available'), ENT_QUOTES, 'UTF-8') ?></div>

        <div class="debug-info">
            <h3>⚠️ Debug Mode Active</h3>
            <p>This detailed error information is only shown because debug mode is enabled. In production, set <code>DEBUG=false</code> in your environment.</p>
        </div>

        <div class="back-link">
            <a href="javascript:history.back()">← Go Back</a> |
            <a href="/">Home</a>
        </div>
    </div>
    <script>
    (function() {
        var container = document.querySelector('.debug-container');
        var btn = container.querySelector('.toggle-btn');
        // Check if there are other elements in the body besides the debug container
        var siblings = Array.from(document.body.children).filter(function(el) {
            return el !== container && el.tagName !== 'SCRIPT';
        });
        if (siblings.length > 0) {
            btn.style.display = 'block';
        }
    })();
    </script>
</body>
</html>
