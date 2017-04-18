sub vcl_recv {
#FASTLY recv

    if (req.request != "HEAD" && req.request != "GET" && req.request != "FASTLYPURGE") {
      return(pass);
    }

  # Use anonymous, cached pages if all backends are down.
  if (!req.backend.healthy) {
    unset req.http.Cookie;
  }

  # Allow the backend to serve up stale content if it is responding slowly.
  set req.grace = 6h;

   # Do not cache these paths.
  if (req.url ~ "^/(status|update)\.php$" ||
      req.url.path == "/admin" ||
      req.url.path == "/ooyala/ping" ||
      req.url ~ "^/(admin|flag|info)/.*$" ||
      req.url ~ "^.*/(ajax|ahah|system/files)/.*$" ) {
       return (pass);
  }
  
  # Always cache the following file types for all users. This list of extensions
  # appears twice, once here and again in vcl_fetch so make sure you edit both
  # and keep them equal.
  if (req.url.ext ~ "(?i)(7z|avi|bmp|bz2|css|csv|doc|docx|eot|flac|flv|gif|gz|ico|jpeg|jpg|js|less|mka|mkv|mov|mp3|mp4|mpeg|mpg|odt|otf|ogg|ogm|opus|pdf|png|ppt|pptx|rar|rtf|svg|svgz|swf|tar|tbz|tgz|ttf|txt|txz|wav|webm|webp|woff|woff2|xls|xlsx|xml|xz|zip)") {
    unset req.http.Cookie;
  }

  # Remove all cookies that Drupal doesn't need to know about. We explicitly
  # list the ones that Drupal does need, the SESS and NO_CACHE. If, after
  # running this code we find that either of these two cookies remains, we
  # will pass as the page cannot be cached.
  if (req.http.Cookie) {
    # 1. Append a semi-colon to the front of the cookie string.
    # 2. Remove all spaces that appear after semi-colons.
    # 3. Match the cookies we want to keep, adding the space we removed
    #    previously back. (\1) is first matching group in the regsuball.
    # 4. Remove all other cookies, identifying them by the fact that they have
    #    no space after the preceding semi-colon.
    # 5. Remove all spaces and semi-colons from the beginning and end of the
    #    cookie string.
    set req.http.Cookie = ";" req.http.Cookie;
    set req.http.Cookie = regsuball(req.http.Cookie, "; +", ";");
    set req.http.Cookie = regsuball(req.http.Cookie, ";(SESS[a-z0-9]+|SSESS[a-z0-9]+|NO_CACHE)=", "; \1=");
    set req.http.Cookie = regsuball(req.http.Cookie, ";[^ ][^;]*", "");
    set req.http.Cookie = regsuball(req.http.Cookie, "^[; ]+|[; ]+$", "");

    if (req.http.Cookie == "") {
      # If there are no remaining cookies, remove the cookie header. If there
      # aren't any cookie headers, Varnish's default behavior will be to cache
      # the page.
      unset req.http.Cookie;
    }
    else {
      # If there is any cookies left (a session or NO_CACHE cookie), do not
      # cache the page. Pass it on to Apache directly.
      return (pass);
    }
  }

  # If all that fails or drops out lets send a cached version
  return(lookup);
}

sub vcl_fetch {
#FASTLY fetch

  if ((beresp.status == 500 || beresp.status == 503) && req.restarts < 1 && (req.request == "GET" || req.request == "HEAD")) {
    restart;
  }

  if(req.restarts > 0 ) {
    set beresp.http.Fastly-Restarts = req.restarts;
  }

  if (beresp.http.Set-Cookie) {
    set req.http.Fastly-Cachetype = "SETCOOKIE";
    return (pass);
  }

  if (beresp.http.Cache-Control ~ "private") {
    set req.http.Fastly-Cachetype = "PRIVATE";
    return (pass);
  }

  if (beresp.status == 500 || beresp.status == 503) {
    set req.http.Fastly-Cachetype = "ERROR";
    set beresp.ttl = 1s;
    set beresp.grace = 5s;
    return (deliver);
    #error 903 "backend read error";
  }

  if (beresp.http.Expires || beresp.http.Surrogate-Control ~ "max-age" || beresp.http.Cache-Control ~"(s-maxage|max-age)") {
    # keep the ttl here
  } else {
    # apply the default ttl
    set beresp.ttl = 3600s;
  }

  # Caching of 40x and 500 responses is disabled.
  # To enable it enable the setting below.
  if (beresp.status == 404 || beresp.status == 301 || beresp.status == 500) {
    #set beresp.ttl = 10m;
  }

  # Don't allow static files to set cookies.
  # (?i) denotes case insensitive in PCRE (perl compatible regular expressions).
  # This list of extensions appears twice, once here and again in vcl_recv so
  # make sure you edit both and keep them equal.
  if (req.url.ext ~ "(?i)(7z|avi|bmp|bz2|css|csv|doc|docx|eot|flac|flv|gif|gz|ico|jpeg|jpg|js|less|mka|mkv|mov|mp3|mp4|mpeg|mpg|odt|otf|ogg|ogm|opus|pdf|png|ppt|pptx|rar|rtf|svg|svgz|swf|tar|tbz|tgz|ttf|txt|txz|wav|webm|webp|woff|woff2|xls|xlsx|xml|xz|zip)") {
    unset beresp.http.set-cookie;
  }

  # Allow items to be stale if needed.
  set beresp.grace = 6h;

  return(deliver);
}

sub vcl_hit {
#FASTLY hit

  if (!obj.cacheable) {
    return(pass);
  }
  return(deliver);
}

sub vcl_miss {
#FASTLY miss
  return(fetch);
}

sub vcl_deliver {
#FASTLY deliver

  if ( !req.http.Fastly-Debug ) {
    # Remove server fingerprints.
    unset resp.http.Server;
    unset resp.http.Via;
    unset resp.http.X-Generator;
    unset resp.http.X-Powered-By;

    # Remove non-Fastly debug headers.
    unset resp.http.X-Drupal-Cache;
    unset resp.http.X-Varnish;
    unset resp.http.X-Varnish-Cache;

    # Remove Fastly debug headers.
    unset resp.http.X-Cache-Debug;
    unset resp.http.X-Backend-Key;
  }

  return(deliver);
}

sub vcl_error {
#FASTLY error

# Redirect to some other URL in the case of a homepage failure.
#if (req.url ~ "^/?$") {
#  set obj.status = 302;
#  set obj.http.Location = "http://backup.example.com/";
#}

# Otherwise redirect to the homepage, which will likely be in the cache.
set obj.http.Content-Type = "text/html; charset=utf-8";
synthetic {"
  <html>
  <head>
    <title>Page Unavailable</title>
    <style>
      body { background: #303030; text-align: center; color: white; }
      #page { border: 1px solid #CCC; width: 500px; margin: 100px auto 0; padding: 30px; background: #323232; }
      a, a:link, a:visited { color: #CCC; }
      .error { color: #222; }
    </style>
  </head>
  <body onload="setTimeout(function() { window.location = '/' }, 5000)">
    <div id="page">
      <h1 class="title">Page Unavailable</h1>
      <p>The page you requested is temporarily unavailable.</p>
      <p>We're redirecting you to the <a href="/">homepage</a> in 5 seconds.</p>
      <div class="error">(Error "} obj.status " " obj.response {")</div>
    </div>
  </body>
  </html>
  "};
  return(deliver);
}

sub vcl_pass {
#FASTLY pass
}
