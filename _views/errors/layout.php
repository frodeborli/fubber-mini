<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php $this->show('title', 'Error'); ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 2rem; background: #f5f5f5; }
        .error-container { max-width: 600px; margin: 2rem auto; background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #dc3545; margin: 0 0 1rem 0; }
        p { color: #666; line-height: 1.6; }
        .error-code { font-size: 4rem; font-weight: bold; color: #dc3545; margin: 0; }
        .back-link { margin-top: 2rem; }
        .back-link a { color: #007bff; text-decoration: none; }
        .back-link a:hover { text-decoration: underline; }
        <?php $this->show('styles'); ?>
    </style>
</head>
<body>
    <div class="error-container">
        <?php $this->show('content'); ?>
        <div class="back-link">
            <a href="javascript:history.back()">← Go Back</a> |
            <a href="/">Home</a>
        </div>
    </div>
</body>
</html>
