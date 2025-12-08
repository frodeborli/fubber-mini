# Mini Mail

RFC 5322 email composition with automatic MIME structure generation.

## Quick Start

```php
use mini\Mail\Email;
use function mini\mailer;

$email = (new Email())
    ->withFrom('sender@example.com')
    ->withTo('recipient@example.com')
    ->withSubject('Hello!')
    ->withTextBody('Plain text version')
    ->withHtmlBody('<h1>HTML version</h1>');

// Send email
mailer()->send($email);
```

## Features

- **Declarative API** - Describe what you want; MIME structure is built automatically
- **Lazy compilation** - MIME parts created only when needed
- **Streaming** - Large attachments streamed without loading into memory
- **RFC compliant** - Proper encoding for headers (RFC 2047) and bodies (base64/quoted-printable)
- **PSR-7 compatible** - Implements `MessageInterface` and `StreamInterface`

## Addresses

Address methods accept strings or `MailboxInterface`:

```php
// Simple email
$email->withFrom('sender@example.com');

// With display name
$email->withFrom('Frode Børli <frode@ennerd.com>');

// Using Mailbox object
$email->withFrom(new Mailbox('frode@ennerd.com', 'Frode Børli'));

// Multiple recipients
$email->withTo('alice@example.com', 'bob@example.com');

// Add incrementally
$email->withTo('alice@example.com')
      ->withAddedTo('bob@example.com')
      ->withAddedCc('carol@example.com');
```

Non-ASCII display names are automatically encoded per RFC 2047.

## HTML with Inline Images

Reference images in HTML using `cid:` URLs. Array keys become Content-IDs:

```php
$email = (new Email())
    ->withFrom('newsletter@example.com')
    ->withTo('subscriber@example.com')
    ->withSubject('Weekly Update')
    ->withTextBody('View in browser for images.')
    ->withHtmlBody(
        '<img src="cid:logo"> <p>Hello!</p>',
        [
            'logo' => '/path/to/logo.png',  // File path
            // or: 'logo' => Message::fromFile('logo.png'),
        ]
    );
```

## Attachments

```php
$email->withAttachments([
    '/path/to/report.pdf',                    // Filename from path
    'Monthly Report.pdf' => '/tmp/data.pdf',  // Override filename
    Message::fromFile('/tmp/generated.xlsx'), // MessageInterface
]);
```

MIME types are detected automatically from file extensions.

## Complete Example

```php
$email = (new Email())
    ->withFrom('Name <sender@example.com>')
    ->withTo('recipient@example.com')
    ->withCc('cc@example.com')
    ->withBcc('bcc@example.com')
    ->withReplyTo('replies@example.com')
    ->withSubject('Project Update')
    ->withDate(new DateTimeImmutable())
    ->withTextBody('Plain text fallback.')
    ->withHtmlBody(
        file_get_contents('template.html'),
        ['logo' => '/assets/logo.png']
    )
    ->withAttachments([
        'Report.pdf' => '/documents/report.pdf',
    ]);
```

## MIME Structure

The implementation automatically builds the correct nested structure:

```
multipart/mixed
├── multipart/alternative
│   ├── text/plain
│   └── multipart/related
│       ├── text/html
│       └── image/png (Content-ID: <logo>)
└── application/pdf (attachment)
```

## Streaming

`Email` implements `StreamInterface`. For large emails with attachments:

```php
// Stream to file
$fp = fopen('email.eml', 'w');
while (!$email->eof()) {
    fwrite($fp, $email->read(8192));
}
fclose($fp);

// Or cast to string (loads into memory)
$raw = (string) $email;
```

## Sending Emails

The `mailer()` function returns a mail transport wrapped in a `Mailer` that handles:

- **Bcc stripping** - Bcc recipients receive the email, but the header is removed from the message
- **Envelope sender** - Resolved from: explicit parameter → config → From header
- **Envelope recipients** - Collected from To + Cc + Bcc headers

```php
use function mini\mailer;

// Simple send - envelope derived from headers
mailer()->send($email);

// Explicit envelope sender (e.g., for bounce handling)
mailer()->send($email, 'bounces@example.com');

// Explicit recipients (overrides To/Cc/Bcc)
mailer()->send($email, 'bounces@example.com', ['specific@example.com']);
```

### Configuration

Override by creating `_config/mini/Mail/MailTransportInterface.php`:

```php
<?php
use mini\Mail\Mailer;
use mini\Mail\SendmailTransport;

// Use sendmail instead of mail()
return new Mailer(new SendmailTransport('/usr/sbin/sendmail'));
```

Or with a default sender for bounce handling:

```php
<?php
use mini\Mail\Mailer;
use mini\Mail\NativeMailTransport;

return new Mailer(new NativeMailTransport(), 'noreply@example.com');
```

### Available Transports

| Transport | Description |
|-----------|-------------|
| `NativeMailTransport` | Uses PHP's `mail()` function (default) |
| `SendmailTransport` | Pipes directly to sendmail binary |

### Custom Transport

Implement `MailTransportInterface`:

```php
use mini\Mail\MailTransportInterface;
use mini\Mail\EmailInterface;

class SmtpTransport implements MailTransportInterface
{
    public function send(EmailInterface $email, string $sender, array $recipients): void
    {
        // $email - Complete email (Bcc already stripped by Mailer)
        // $sender - Envelope sender address
        // $recipients - Envelope recipient addresses

        // Connect to SMTP and send...
    }
}
```

## Classes

| Class | Purpose |
|-------|---------|
| `Email` | High-level composition API |
| `EmailInterface` | Interface for Email |
| `Mailbox` | RFC 5322 mailbox (display name + addr-spec) |
| `MailboxInterface` | Interface for mailboxes |
| `Mailer` | Wraps transport, handles Bcc stripping and envelope |
| `MailTransportInterface` | Interface for mail transports |
| `NativeMailTransport` | Transport using PHP's `mail()` |
| `SendmailTransport` | Transport using sendmail binary |
| `Message` | Single MIME part |
| `MultipartMessage` | Container for multiple MIME parts |
| `MultipartType` | Enum: mixed, alternative, related, etc. |
| `Base64Stream` | Streaming base64 encoder |
| `QuotedPrintableStream` | Streaming quoted-printable encoder |

## Design Principles

1. **Headers as source of truth** - Address methods store in headers; `getFrom()` etc. parse on demand
2. **Immutable** - All `with*` methods return new instances
3. **Lazy** - MIME structure built only when needed, cached until mutation
4. **Streaming** - Attachments encoded on-the-fly, not buffered in memory
5. **Minimal dependencies** - Only PSR-7 interfaces required
