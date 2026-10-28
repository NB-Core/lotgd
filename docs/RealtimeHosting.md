# Hosting realtime: polling or server-sent events

Realtime (*Game Settings → Realtime*) brings mail, stats, chat lines, grants
and duels to an open page without a reload. A page gets them in one of two
ways:

- **Polling** (`realtime_transport = poll`, the default). The page asks every
  `realtime_poll_seconds` (10 s by default). This works on any host, shared
  webspace included, and needs no server configuration.
- **Server-sent events** (`realtime_transport = sse`). The page holds one
  open request to `async/stream.php`, and events arrive within about a
  second. Each open stream keeps one PHP worker busy, and every proxy
  between the browser and PHP must hand the stream on unbuffered.

How it works inside is in [ReactiveCore.md](ReactiveCore.md) (Phase 7). This
page covers turning it on and configuring the web server.

## Turning it on

1. Turn on *realtime* and *partial rendering*. The *Live features* table at
   the top of *Game Settings* shows what is still missing.
2. Set *How pages receive realtime events* to **Server-sent events** and save.
   The button *Turn on the recommended live features* leaves this setting
   alone on purpose. The game cannot tell whether your host keeps streams
   open, so the table only reports the setting; it does not test the server.
3. Make sure the player has *Change pages in place* on and plays on a skin
   that changes pages in place (all bundled Twig skins do).

A page that streams carries
`<meta name="lotgd-realtime-stream" content="/async/stream.php">` in its
source. Without that meta the setting is not active for that page, and it
polls.

Polling is always the fallback. A page polls instead of streaming:
- after a refused or failed connect (three in a row);
- when a second tab of the same player takes the stream (`event: replaced`);
- in a browser without `EventSource`.

So a misconfigured server costs speed, not function.

## What every host needs

- **Workers.** One PHP worker per open stream, held for up to
  `realtime_stream_seconds` (50 s by default; the browser reconnects after
  that). Size Apache's `MaxRequestWorkers`, or PHP-FPM's `pm.max_children`,
  for the players online at the same time **plus** normal page requests. If
  workers run out, new streams are refused and those pages poll, but page
  loads also start to queue. `pm = ondemand` works.
- **No buffering, no compression** for `async/stream.php`, in every layer:
  - PHP: the script turns off output buffering and `zlib.output_compression`
    for itself.
  - Web server and proxies: see below.
- **Timeouts** above one stream's lifetime (use 90–120 s): the web server's
  timeout, the FastCGI or proxy read timeout, and PHP's `max_execution_time`
  (and PHP-FPM's `request_terminate_timeout`, if set).

## Apache

### mod_php (also the Docker image)

Works as it is. Apache's `mod_deflate` does not compress `text/event-stream`
unless you added that type yourself. The bundled Docker image (Apache
prefork with mod_php) needs nothing beyond enough `MaxRequestWorkers`.

### php-fpm (mod_proxy_fcgi), mpm_event

Turning off output buffering in Apache is not enough. `mod_proxy_fcgi` keeps
the response until the script ends unless `flushpackets` is on. Use the same
`fcgi://` name as your handler line:

```apache
# Your handler, for example:
<FilesMatch "\.php$">
    SetHandler "proxy:unix:/run/php/php8.5-fpm.sock|fcgi://localhost"
</FilesMatch>

# Pass each event on at once.
<Proxy "fcgi://localhost/" enablereuse=on flushpackets=on>
</Proxy>

# Never compress the stream (compression buffers it).
SetEnvIfNoCase Request_URI "^/async/stream\.php" no-gzip=1 dont-vary=1

# Longer than one stream.
Timeout 120
ProxyTimeout 120
```

With `ProxyPassMatch` instead of `SetHandler`, add `flushpackets=on` to that
line.

## nginx

### nginx with php-fpm

Add an exact-match location for the stream. An exact match takes precedence
over your `\.php$` regex location, so the rest stays as it is:

```nginx
location = /async/stream.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/async/stream.php;
    # If your PHP block sets PHP_VALUE, repeat it here character for
    # character: PHP-FPM keeps a PHP_VALUE on the worker for later requests,
    # so a different one leaks into normal pages.
    # fastcgi_param PHP_VALUE "…";
    fastcgi_pass php-handler;           # your upstream or socket

    fastcgi_buffering off;
    fastcgi_read_timeout 120s;
    gzip off;
    fastcgi_cache off;
}
```

The script also sends `X-Accel-Buffering: no`, which turns buffering off in
the nginx that receives it from PHP. The explicit `fastcgi_buffering off`
keeps it from depending on that.

### nginx as a reverse proxy in front (TLS termination)

When a TLS server proxies to another web server (nginx, Apache, a container),
that front proxy needs its own block. **This is the usual culprit:** the
inner nginx consumes `X-Accel-Buffering` and does not pass it on, so the
front proxy buffers the stream by default. The browser then sees nothing
until the stream closes.

```nginx
location = /async/stream.php {
    proxy_pass http://127.0.0.1:83;      # your backend
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-Proto $scheme;

    proxy_buffering off;
    proxy_cache off;
    proxy_read_timeout 120s;
    gzip off;
}
```

Copy the `proxy_set_header` lines your main location uses. The game reads
the client address and the scheme from them.

### Rate limits

A `limit_req` zone that matches the stream (for example one keyed on
user agents like `curl`) answers 429 after a few connects. Players are not
affected unless the zone matches them. A test with `curl` may be, so use the
browser, or another `-A`.

## Caddy

`reverse_proxy` and `php_fastcgi` flush `text/event-stream` responses on
their own. Keep `encode` (compression) away from the stream:

```caddy
@notstream not path /async/stream.php
encode @notstream zstd gzip
```

## Shared webspace

Stay on polling. Hosts that limit PHP processes or execution time, or that
buffer in front of PHP, cannot hold streams open.

## Checking it

In the browser's developer tools, open *Network* and filter on `stream.php`:

| What you see | What it means |
| --- | --- |
| One request of type `eventsource`, pending; `: ping` every 15 s; an event within a second of new mail | It works |
| Pending, but nothing arrives until it closes (after ~50 s), then everything at once | A layer buffers: `flushpackets`, `fastcgi_buffering`/`proxy_buffering`, or compression |
| 404 | The setting is `poll`, realtime is off, or the player has no partial rendering |
| 401 or 403 | Not logged in, or the page's token is from an older session: reload |
| 429 | A rate limit matches the request |
| `event: replaced`, then polling | A second tab of the same player took the stream (one stream per player, by design) |
| The stream ends early with 502/504 | A timeout below the stream's lifetime |

From a shell, with the session cookie and the `csrf` value from the page's
`<meta name="lotgd-async">`:

```sh
curl -N -A 'stream-check' -H 'Cookie: PHPSESSID=…' \
  'https://your.host/async/stream.php?csrf=…&cursor=0'
```

Lines should appear one by one (`: ping` every 15 s), not all together when
the stream ends.
