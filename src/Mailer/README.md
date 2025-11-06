# Mailer - Email Sending

## Philosophy

Mini provides **Symfony Mailer integration** with sensible defaults. In production, use PHP's sendmail (configured via php.ini). In development, log emails without sending. Override with any SMTP/API provider via DSN.

**Key Principles:**
- **PHP's native mail by default** - Respects php.ini sendmail_path
- **Symfony Mailer power** - Full access to Symfony's Email API
- **Environment-based config** - DSN via environment variables
- **Debug mode safety** - Never accidentally send emails in development
- **Fluent API** - Chain methods for clean email building

## Setup

### Install Symfony Mailer

```bash
composer require symfony/mailer
```

### Default Configuration (PHP Sendmail)

No additional configuration needed! In production:

```php
// Uses PHP's sendmail (configured in php.ini)
mail()
    ->to('user@example.com')
    ->subject('Welcome!')
    ->text('Hello!')
    ->send();
```

In debug mode (when `DEBUG=1`), emails are logged to error_log but not sent.

### Custom Mailer Configuration

```php
<?php
// _config/mini/Mailer/MailerInterface.php

use mini\Mailer\Mailer;

// Use default Mailer with environment-based configuration
return new Mailer();
```

Or create a custom implementation:

```php
<?php
// _config/mini/Mailer/MailerInterface.php

use mini\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

return new class implements MailerInterface {
    public function send(Email $email): void {
        // Custom sending logic
        // E.g., queue for background processing
        queue('send-email', $email);
    }
};
```

## Common Usage Examples

### Basic Email

```php
use function mini\mail;

mail()
    ->to('user@example.com')
    ->subject('Welcome!')
    ->text('Welcome to our platform!')
    ->send();
```

### HTML Email

```php
mail()
    ->to('user@example.com')
    ->subject('Order Confirmation')
    ->html('<h1>Thank you for your order!</h1>')
    ->send();
```

### Email with Plain Text and HTML

```php
mail()
    ->to('user@example.com')
    ->subject('Newsletter')
    ->text('Plain text version for email clients that don't support HTML')
    ->html('<h1>HTML version</h1><p>With formatting!</p>')
    ->send();
```

### Multiple Recipients

```php
mail()
    ->to('user1@example.com')
    ->to('user2@example.com')
    ->cc('manager@example.com')
    ->bcc('archive@example.com')
    ->subject('Team Update')
    ->text('Important team update')
    ->send();
```

### Email with Attachments

```php
mail()
    ->to('user@example.com')
    ->subject('Invoice')
    ->text('Please find your invoice attached.')
    ->attachFromPath('/path/to/invoice.pdf')
    ->send();
```

### Email with Custom Headers

```php
mail()
    ->to('user@example.com')
    ->subject('Notification')
    ->text('You have a new notification')
    ->priority(Email::PRIORITY_HIGH)
    ->getHeaders()
        ->addTextHeader('X-Custom-Header', 'value');

mail()->send();
```

## Advanced Examples

### Templated Emails

```php
use function mini\render;

$htmlContent = render('emails/welcome', [
    'name' => $user->name,
    'activationLink' => $activationUrl
]);

mail()
    ->to($user->email)
    ->subject('Welcome to ' . $_ENV['APP_NAME'])
    ->html($htmlContent)
    ->send();
```

### Email with Inline Images

```php
mail()
    ->to('user@example.com')
    ->subject('Product Catalog')
    ->html('<h1>Our Products</h1><img src="cid:logo">')
    ->embed(fopen('/path/to/logo.png', 'r'), 'logo')
    ->send();
```

### Transactional Email

```php
function sendPasswordResetEmail(string $email, string $token): void {
    $resetUrl = "https://example.com/reset-password?token=$token";

    mail()
        ->to($email)
        ->subject('Password Reset Request')
        ->html("
            <h1>Password Reset</h1>
            <p>Click the link below to reset your password:</p>
            <a href=\"$resetUrl\">Reset Password</a>
            <p>This link expires in 1 hour.</p>
        ")
        ->send();
}
```

### Bulk Emailing

```php
$users = db()->query("SELECT email, name FROM users WHERE subscribed = 1");

foreach ($users as $user) {
    mail()
        ->to($user['email'])
        ->subject('Monthly Newsletter')
        ->html(render('emails/newsletter', ['name' => $user['name']]))
        ->send();
}
```

### Email Queueing (Custom Implementation)

```php
<?php
// _config/mini/Mailer/MailerInterface.php

use mini\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

return new class implements MailerInterface {
    public function send(Email $email): void {
        // Serialize email for queue
        db()->exec(
            "INSERT INTO email_queue (to_email, subject, body, created_at) VALUES (?, ?, ?, ?)",
            [
                implode(', ', array_map(fn($a) => $a->getAddress(), $email->getTo())),
                $email->getSubject(),
                $email->getHtmlBody() ?? $email->getTextBody(),
                date('Y-m-d H:i:s')
            ]
        );
    }
};
```

## Environment Configuration

### SMTP Configuration

```bash
# .env file

# SMTP server
MAILER_DSN=smtp://username:password@smtp.example.com:587

# Or use TLS
MAILER_DSN=smtp://username:password@smtp.example.com:465?encryption=ssl

# Default from address
MAILER_FROM_EMAIL=noreply@example.com
MAILER_FROM_NAME="My Application"
```

### Popular Email Services

**Gmail:**
```bash
MAILER_DSN=gmail+smtp://username:password@default
```

**SendGrid:**
```bash
MAILER_DSN=sendgrid+api://API_KEY@default
```

**Mailgun:**
```bash
MAILER_DSN=mailgun+https://API_KEY:DOMAIN@default
```

**Postmark:**
```bash
MAILER_DSN=postmark+api://API_TOKEN@default
```

**Amazon SES:**
```bash
MAILER_DSN=ses+smtp://USERNAME:PASSWORD@default
```

### Development Configuration

```bash
# .env.local

# Log emails but don't send
MAILER_DSN=null://null

# Or use Mailtrap for testing
MAILER_DSN=smtp://username:password@smtp.mailtrap.io:2525
```

## Using with Templates

### Email Template Example

```php
<?php
// _views/emails/welcome.php

/**
 * @var string $name
 * @var string $activationLink
 */
?>
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; }
        .button { background: #007bff; color: white; padding: 10px 20px; }
    </style>
</head>
<body>
    <h1>Welcome, <?= h($name) ?>!</h1>
    <p>Thank you for joining us. Click below to activate your account:</p>
    <a href="<?= h($activationLink) ?>" class="button">Activate Account</a>
</body>
</html>
```

```php
// Usage
$html = render('emails/welcome', [
    'name' => $user->name,
    'activationLink' => $activationUrl
]);

mail()
    ->to($user->email)
    ->subject('Welcome!')
    ->html($html)
    ->send();
```

## Symfony Email API Reference

### Setting Recipients

```php
->to('user@example.com')
->to('user@example.com', 'User Name')
->cc('manager@example.com')
->bcc('archive@example.com')
->replyTo('support@example.com')
```

### Setting Sender

```php
->from('noreply@example.com')
->from('noreply@example.com', 'My App')
```

### Setting Content

```php
->subject('Email Subject')
->text('Plain text body')
->html('<h1>HTML body</h1>')
```

### Attachments

```php
->attachFromPath('/path/to/file.pdf')
->attachFromPath('/path/to/file.pdf', 'custom-name.pdf')
->attach($fileContents, 'filename.txt', 'text/plain')
->embed(fopen('/path/to/image.png', 'r'), 'image-id')
```

### Priority and Headers

```php
->priority(Email::PRIORITY_HIGH)
->getHeaders()->addTextHeader('X-Custom', 'value')
```

## Configuration

**Config File:** `_config/mini/Mailer/MailerInterface.php` (optional)

**Environment Variables:**
- `MAILER_DSN` - Transport DSN (e.g., `smtp://user:pass@smtp.example.com:587`)
- `MAILER_FROM_EMAIL` - Default from email address
- `MAILER_FROM_NAME` - Default from name

**Mini-prefixed alternatives** (use when avoiding conflicts with Symfony):
- `MINI_MAILER_DSN` ’ Takes precedence over `MAILER_DSN`
- `MINI_MAILER_FROM_EMAIL` ’ Takes precedence over `MAILER_FROM_EMAIL`
- `MINI_MAILER_FROM_NAME` ’ Takes precedence over `MAILER_FROM_NAME`

## Overriding the Service

```php
// _config/mini/Mailer/MailerInterface.php

use mini\Mailer\MailerInterface;

// Queue emails instead of sending immediately
return new App\Mailer\QueuedMailer();
```

## Error Handling

Mailer throws exceptions on failure. Catch and log them:

```php
try {
    mail()
        ->to('invalid-email')
        ->subject('Test')
        ->text('Test')
        ->send();
} catch (\Exception $e) {
    logger()->error('Failed to send email', ['exception' => $e]);
}
```

## Mailer Scope

Mailer is **Singleton** - one instance shared across the application lifecycle. Transport configuration is loaded once from environment variables during instantiation.

## Best Practices

### 1. Always Provide Plain Text Alternative

```php
// Good: Both text and HTML
mail()
    ->to($email)
    ->subject('Welcome')
    ->text('Welcome to our platform!')
    ->html('<h1>Welcome to our platform!</h1>')
    ->send();

// Avoid: HTML only
mail()->to($email)->html('<h1>Welcome</h1>')->send();
```

### 2. Use Templates for Complex Emails

```php
// Good: Reusable template
$html = render('emails/order-confirmation', ['order' => $order]);
mail()->to($user->email)->html($html)->send();

// Avoid: Inline HTML strings
mail()->to($user->email)->html('<html><body>...</body></html>')->send();
```

### 3. Set From Address Globally

```bash
# .env
MAILER_FROM_EMAIL=noreply@example.com
MAILER_FROM_NAME="My Application"
```

### 4. Never Send in Debug Mode Without Explicit Configuration

Mini automatically uses `null://null` transport in debug mode to prevent accidental email sends during development.

### 5. Validate Email Addresses

```php
if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
    mail()->to($email)->subject('Test')->text('Test')->send();
} else {
    logger()->warning('Invalid email address', ['email' => $email]);
}
```
