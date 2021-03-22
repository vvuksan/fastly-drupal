  # Flag that tells us to pass
  # Don't allow clients to force a pass
  if ( req.restarts == 0 ) {
    set req.http.X-Pass = "0";
  }

  # Allow the backend to serve up stale content if it is responding slowly.
  set req.grace = 6h;

   # Do not cache these paths.
   # Picked from https://github.com/NITEMAN/varnish-bites/blob/master/varnish3/drupal-base.vcl
  if (req.url.path ~ "^/(status|update)\.php$" ||
    req.url.path == "/admin" ||
    req.url.path ~ "^/(admin|flag|info)/.*$" ||
    req.url.path ~ "^.*/(ajax|ahah|system/files)/.*$" ) {
      set req.http.X-Pass = "1";
  }

  # Always cache the following file types for all users. This list of extensions
  # appears twice, once here and again in vcl_fetch so make sure you edit both
  # and keep them equal.
  if (req.http.X-Pass == "0" && req.url.ext ~ "(?i)(7z|avi|bmp|bz2|css|csv|doc|docx|eot|flac|flv|gif|gz|ico|jpeg|jpg|js|less|mka|mkv|mov|mp3|mp4|mpeg|mpg|odt|otf|ogg|ogm|opus|pdf|png|ppt|pptx|rar|rtf|svg|svgz|swf|tar|tbz|tgz|ttf|txt|txz|wav|webm|webp|woff|woff2|xls|xlsx|xml|xz|zip)$") {
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
    set req.http.Cookie = regsuball(req.http.Cookie, ";(SimpleSAMLSessionID|SimpleSAMLAuthToken|S?SESS[a-z0-9]+|NO_CACHE=", "; \1=");
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
      set req.http.X-Pass = "1";
    }
  }
