  /* handle 5XX (or any other unwanted status code) */
  if (beresp.status >= 500 && beresp.status < 600) {
    /* deliver stale if the object is available */
    if (stale.exists) {
      return(deliver_stale);
    }

    if (req.restarts < 1 && (req.request == "GET" || req.request == "HEAD")) {
      restart;
    }

    /* else go to vcl_error to deliver a synthetic */
    error beresp.status beresp.response;
  }

  # Don't allow static files to set cookies.
  # (?i) denotes case insensitive in PCRE (perl compatible regular expressions).
  # This list of extensions appears twice, once here and again in vcl_recv so
  # make sure you edit both and keep them equal.
  if ( req.http.X-Pass == "0" && req.url.ext ~ "(?i)(7z|avi|bmp|bz2|css|csv|doc|docx|eot|flac|flv|gif|gz|ico|jpeg|jpg|js|less|mka|mkv|mov|mp3|mp4|mpeg|mpg|odt|otf|ogg|ogm|opus|pdf|png|ppt|pptx|rar|rtf|svg|svgz|swf|tar|tbz|tgz|ttf|txt|txz|wav|webm|webp|woff|woff2|xls|xlsx|xml|xz|zip)") {
    unset beresp.http.set-cookie;
  }

  # If object is the Fastly Drupal HTML we should mark the object as uncacheable before sending to the user
  if ( !req.http.Fastly-FF && beresp.http.Fastly-Drupal-HTML ) {
    set beresp.http.Cache-Control = "no-store, no-cache, must-revalidate, max-age=0";
    unset beresp.http.Fastly-Drupal-HTML;
  }

  # Drupal can respond with Vary on Cookie, which is bad for CHR. 
  # Remove cookie from the Vary header, as we handle request cookies already in vcl_recv. 
  if ( beresp.http.Vary:cookie ) {
    unset beresp.http.Vary:cookie;
  }
