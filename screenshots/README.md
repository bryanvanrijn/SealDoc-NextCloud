# Screenshots

Empty on purpose, and `appinfo/info.xml` carries no `<screenshot>` element until it is not.

A manifest that points at a file which does not exist is worse than one with no screenshot at
all: the app store renders a broken image, and nothing in the repository says why. The reference
was added before the images existed and removed again once that was noticed.

## What is needed before an app store submission

Two images, taken from a browser that is already logged in as an administrator:

| file | what it should show |
|---|---|
| `admin-settings.png` | Administration settings → SealDoc, with a server URL filled in and "Test connection" showing `Server reachable` |
| `files-shield.png` | a Files folder holding a sealed document, with the shield visible on the row |

Then add them back to `appinfo/info.xml`:

```xml
<screenshot>https://raw.githubusercontent.com/bryanvanrijn/SealDoc-NextCloud/main/screenshots/admin-settings.png</screenshot>
<screenshot>https://raw.githubusercontent.com/bryanvanrijn/SealDoc-NextCloud/main/screenshots/files-shield.png</screenshot>
```

## Why they are not generated automatically

An attempt to take them headlessly failed for a reason worth recording, because it will catch
the next person too. Nextcloud sets its session cookies with the `Secure` flag whenever
`overwriteprotocol` is `https`, which is the normal configuration behind a reverse proxy. A
headless browser talking to the instance over plain HTTP therefore stores no cookies at all and
lands back on the login page, while `curl` against the same instance logs in without complaint
because it is more permissive about `Secure` over HTTP.

Working around that means either driving the browser over HTTPS with the proxy's hostname in
`trusted_domains`, or temporarily changing `overwriteprotocol` on a running instance. Neither is
worth automating for two images that take a minute to capture by hand.
