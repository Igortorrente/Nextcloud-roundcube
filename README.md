# nextcloud-roundcube
NextCloud app to integrate RoundCube Webmail. The app embeds the [RoundCube webmail](https://roundcube.net/ "RoundCube's homepage") interface in NextCloud.

## History
This app uses idea and code from [this app](https://github.com/hypery2k/owncloud/tree/master/roundcube).
The app needed an update to work with newer versions of NextCloud. This app doesn't have all features but at least you can auto-login.

## Features
- Auto login
- Enable/disable SSL verification
- Show/hide RC topline bar
- Default path to RC
- Per email domain path to RC

## Requirements
- NextCloud >= 21
- Roundcube Webmail >= 1.4.1
- curl

## Tested with
- NextCloud 21.0.2
- Roundcube Webmail 1.4.1
- Roundcube in a different machine/subdomain than NextCloud

## Installation
- Install app by cloning this repository.
- The RC installation must be accessible from the same NextCloud server (same domain).

## Configuration
- You may need to configure a virtual host with a proxypass alias to somewhere else.
  - Apache would need mods proxy, proxy_http
- NextCloud settings (as admin), Additional:
  - Set at least the default RC path: e.g. roundcube1/
  - Save settings

### Apache example:

```apache
ServerName nextcloud.domain.com

SSLProxyEngine on
ProxyPass /roundcube1/ https://proxymail1.domain.com/
ProxyPass /roundcube2/ https://proxymail2.domain.com/
ProxyPassReverse /roundcube1/ https://proxymail1.domain.com/
ProxyPassReverse /roundcube2/ https://proxymail2.domain.com/
```

### nginx example:

```nginx
location /roundcube1/ {
	proxy_pass https://proxymail1.domain.com/;
}
location /roundcube2/ {
	proxy_pass https://proxymail2.domain.com/;
}
```
