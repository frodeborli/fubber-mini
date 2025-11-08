<?php
    function uuidv7(): string
    {
// 1. The "hacker" 64-bit op (handles floats/negatives correctly)
        $timestamp_bytes = substr(pack('J', (int)(microtime(true) * 1000)), 2, 6);

        // 2. Random bytes
        $random_bytes = random_bytes(10);

        // 3. Your "fast bitwise" ops on raw bytes
        // Set Version '7' AT THE RIGHT INDEX (Byte 6)
        $random_bytes[0] = chr((ord($random_bytes[0]) & 0x0F) | 0x70);
        // Set Variant '10xx' AT THE RIGHT INDEX (Byte 8)
        $random_bytes[2] = chr((ord($random_bytes[2]) & 0x3F) | 0x80);

        // 4. One 'bin2hex' call. No corruption. No floats. No padding.
        $hex = bin2hex($timestamp_bytes . $random_bytes);
        // The fastest way to *insert* hyphens
        return substr($hex, 0, 8) . '-' .
               substr($hex, 8, 4) . '-' .
               substr($hex, 12, 4) . '-' .
               substr($hex, 16, 4) . '-' .
               substr($hex, 20, 12);
    }

    function make64(): string {
        $timestamp = ((int)(microtime(true) * 1000));       // 64 bits timestamp (we'll keep 48 most significant bits)

        $uuid = dechex(
            0xF0000000000000 |
            (($timestamp << 8) & 0x0FFFFFFF000000) |
            (($timestamp << 4) & 0x000000000FFFF0)
        ) . bin2hex(random_bytes(11));
        $uuid[0] = '0';
        $uuid[8] = '-';
        $uuid[13] = '-';
        $uuid[14] = '7';
        $uuid[19] = '89ab'[ord($uuid[19]) >> 6];
        $uuid[18] = '-';
        $uuid[23] = '-';

        return $uuid;
    }

function bench($name, $cb) {
    $t = microtime(true);
    for ($i = 0; $i < 1000000; $i++) {
        $cb();
    }
    echo "$name took " . (microtime(true) - $t) . "\n";
}

for ($i = 0; $i < 10; $i++) {
    echo microtime(true). ": " . make64() . "\n";
}

bench('mine', make64(...));
bench('their', uuidv7(...));
