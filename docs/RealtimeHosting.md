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

A page polls instead of streaming:
- after a refused or failed connect (three in a row);
- when a second tab of the same player takes the stream (`event: replaced`);
- in a browser without `EventSource`;
- when the server already holds as many streams as *Most server-sent event
  streams open at once* (`realtime_stream_max`) allows (`503`, see *Workers*).

So a stream that is refused or buffered costs speed, not function. A server
with too few PHP workers is different: streams are not refused there, they
wait, and the pages wait with them (see *Workers* below and *Measured*).

## What every host needs

- **Workers.** One PHP worker per open stream, held for up to
  `realtime_stream_seconds` (50 s by default; the browser reconnects after
  that). Size Apache's `MaxRequestWorkers` (and `ServerLimit`), or PHP-FPM's
  `pm.max_children`, for the players online at the same time **plus** the
  workers that normal page requests need (see *Measured* below). If workers run
  out, new streams and page loads wait in the same queue until a stream ends,
  and pages take tens of seconds or minutes. Set *Most server-sent event
  streams open at once* (`realtime_stream_max`, *Game Settings*, 0 = no limit)
  below the workers, leaving some for the pages: a stream above it is turned
  away at once (`503`), and that player's page polls instead. The limit counts
  streams that are open (a stream counts until it ends: when the browser left,
  at its next ping, within 30 s; if its worker died, for its lifetime and 15 s); two players starting at the same moment can pass it
  by one. `pm = ondemand` works.
- **Database connections.** A stream holds one connection to the database for
  its whole life: it closes the game's legacy link as it starts and works
  through Doctrine's alone (a page request holds both while it runs, which is
  short). Set MariaDB's/MySQL's `max_connections` to at least the streaming
  players plus room for the pages; the default of 151 covers about 120
  streaming players.
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

## Measured

A load test with 50 players, run with the code of this release, gives an idea of the sizes. Each virtual player
logged in, changed pages in place every 5 to 10 seconds (the village, the
forest, fights, shops; about one in seven actions was a chat line in the
village), asked for the notices every minute, and received realtime events by
polling every 10 s or by one stream. Five minutes per run after a 25 s ramp.

The machine: 4 vCPUs and 16 GB, PHP 8.3 with OPcache (8.4, which the Docker
image ships, see *PHP 8.4* below), the data cache on,
MariaDB 11.4 in a container on the same machine, the load generator too.
Apache 2.4 with `mpm_prefork` and mod_php; nginx 1.24 with PHP-FPM
(`pm = static`), with the configuration from this page.

| 50 players | Apache, poll | Apache, SSE | nginx + FPM, poll | nginx + FPM, SSE |
| --- | --- | --- | --- | --- |
| Page changed in place, p50 / p95 | 41 / 57 ms | 43 / 59 ms | 42 / 59 ms | 44 / 60 ms |
| Poll, p50 / p95 | 15 / 20 ms | – | 16 / 21 ms | – |
| A chat line reaches the others, p50 / p95 | 5.1 / 9.5 s | 0.53 / 0.97 s | 5.1 / 9.6 s | 0.52 / 0.97 s |
| Requests per second | about 13 | about 8 (+ 50 open streams) | about 13 | about 8 (+ 50 open streams) |
| Busy PHP workers, average / peak | 4 / 6 | 52 / 55 | 1 / 3 | 48 / 53 |
| Database connections, average / peak | 2 / 5 | 98 / 109 (49 / 56 now, see below) | 2 / 7 | 95 / 106 |
| CPU (4 cores), average | 10 % | 11 % | 10 % | 11 % |

No request failed in these four runs, and no stream was refused.

**Too few workers.** The same SSE run with 40 workers for 50 players:
nothing failed, but streams took the workers and everything else waited. On
Apache, pages took 139 s at p95 and logins 190 s; on PHP-FPM, pages took 35 s
at p95. The pages did not fall back to polling, because a stream that waits
for a worker is not refused, it is only late.

**With the limit and one connection per stream.** Streams now close the
legacy database link as they start, and *Most server-sent event streams open
at once* (`realtime_stream_max`) turns streams above it away. The same runs
again:

| 50 players, SSE | Apache, 150 workers, no limit | Apache, 40 workers, limit 30 | nginx + FPM, 40 workers, limit 30 |
| --- | --- | --- | --- |
| Page changed in place, p95 | 63 ms | 59 ms | 60 ms |
| Streams open / players polling | 50 / 0 | 30 / 20 | 30 / 20 |
| Streams turned away (`503`) | 0 | 60 (three tries each, then polling) | 60 |
| A chat line reaches the others, p50 / p95 | 0.52 / 0.97 s | 0.76 / 9.1 s | 0.73 / 9.1 s |
| Busy PHP workers, average / peak | 51 / 57 | 33 / 35 | 29 / 34 |
| Database connections, average / peak | 49 / 56 | 31 / 36 | 30 / 35 |

No request failed. With the limit, the players above it get their chat lines
by polling (the p95 above), and every page stays as fast as with enough
workers.

**PHP 8.4.** The same SSE run (nginx + PHP-FPM, 80 workers, 50 players, one
connection per stream), once on PHP 8.3 and once on PHP 8.4.27, which the
Docker image ships:

| 50 players, SSE | PHP 8.3 | PHP 8.4 |
| --- | --- | --- |
| Page changed in place, p50 / p95 | 40 / 56 ms | 41 / 57 ms |
| A chat line reaches the others, p50 / p95 | 0.54 / 0.97 s | 0.52 / 0.97 s |
| PHP memory, 80 workers | 2.8 GB | 2.7 GB |
| Database connections, average | 49 | 49 |
| CPU (4 cores), average | 11 % | 11 % |

The two behave the same; everything on this page holds for both.

What follows from it:

- **Polling** costs little: about one request per player every ten seconds,
  answered in 15–20 ms, and a handful of workers. Chat lines and mail arrive
  within the poll interval (5 s on average at 10 s).
- **Server-sent events** bring them within a second, at the price of one PHP
  worker and one database connection per player online, for as long as they
  are online. CPU is not the limit; workers, memory and connections are.
- **Workers:** players online × 1.1 plus 10 for the pages is enough at this
  rate of play. A PHP worker that has served the game uses about 35–40 MB of
  memory (RSS), so 100 streaming players need about 4–5 GB for PHP alone.
- **Database:** `max_connections` ≥ players online + 20.
- **Limit:** set `realtime_stream_max` to the workers minus those the pages
  need (10 at this rate of play for 50 players): with 40 workers, 30.
- **Choose by what the host can hold.** On a small server, or when you cannot
  raise the limits, keep `poll`; the game plays the same, chat and mail are a
  few seconds slower.

### Configuration used

Apache, `mpm_prefork` with mod_php (as in the Docker image):

```apache
<IfModule mpm_prefork_module>
    StartServers            5
    MinSpareServers         5
    MaxSpareServers         10
    ServerLimit             150
    MaxRequestWorkers       150
    MaxConnectionsPerChild  0
</IfModule>
Timeout 120
```

PHP-FPM pool:

```ini
pm = static
pm.max_children = 80
; above one stream's life (realtime_stream_seconds, 50 by default)
request_terminate_timeout = 120s
listen.backlog = 511
```

`pm = dynamic` (with `pm.max_children` as above and enough
`pm.max_spare_servers`) or `ondemand` work too; `static` avoids starting
workers while players connect.

nginx: the `location = /async/stream.php` block from *nginx with php-fpm*
above, and `worker_connections` well above the players online (every stream
is one connection; 4096 was used).

MariaDB: `max_connections = 150` (the default 151) covers 100 streaming
players and their pages.

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
