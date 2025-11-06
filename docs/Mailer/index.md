# Mailer - Email Sending

Send emails with `mini\mailer()` using Symfony Mailer.

## Basic Usage

```php
<?php
use mini\Mailer\Email;

mini\mailer()->send(
    Email::create()
        ->to('user@example.com')
        ->subject('Welcome!')
        ->text('Thanks for signing up.')
);
```

## HTML Emails

```php
<?php
mini\mailer()->send(
    Email::create()
        ->to('user@example.com')
        ->subject('Weekly Newsletter')
        ->html('<h1>Hello!</h1><p>Here are this week\'s updates...</p>')
        ->text('Hello! Here are this week\'s updates...')  // Fallback
);
```

## Multiple Recipients

```php
<?php
mini\mailer()->send(
    Email::create()
        ->to('user1@example.com', 'user2@example.com')
        ->cc('manager@example.com')
        ->bcc('admin@example.com')
        ->subject('Team Update')
        ->text('...')
);
```

## From Address

```php
<?php
mini\mailer()->send(
    Email::create()
        ->from('noreply@example.com')
        ->to('user@example.com')
        ->subject('Password Reset')
        ->text('Click here to reset your password...')
);
```

## Attachments

```php
<?php
mini\mailer()->send(
    Email::create()
        ->to('user@example.com')
        ->subject('Invoice')
        ->text('Please find your invoice attached.')
        ->attachFromPath('/path/to/invoice.pdf')
);
```

## Reply-To

```php
<?php
mini\mailer()->send(
    Email::create()
        ->to('user@example.com')
        ->replyTo('support@example.com')
        ->subject('Support Ticket #123')
        ->text('...')
);
```

## Configuration

Configure mailer via environment variables or `_config/Symfony/Component/Mailer/Mailer.php`:

```bash
# .env
MAILER_DSN=smtp://user:pass@smtp.example.com:587
```

Or:

```php
<?php
return Symfony\Component\Mailer\Mailer::create('smtp://...');
```

## Template Emails

```php
<?php
$html = mini\render('emails/welcome', [
    'username' => $user->username,
    'activationLink' => $activationUrl
]);

mini\mailer()->send(
    Email::create()
        ->to($user->email)
        ->subject('Welcome to Our Platform!')
        ->html($html)
);
```

## API Reference

See `Symfony\Component\Mailer\Mailer` for full Symfony Mailer documentation.
