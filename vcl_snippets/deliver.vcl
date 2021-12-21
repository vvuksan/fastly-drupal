    # If object is the Fastly Drupal HTML mark the object as uncacheable before sending to the user
    if ( fastly.ff.visits_this_service == 0 && resp.http.Fastly-Drupal-HTML ) {
        set resp.http.Cache-Control = "no-store, no-cache, must-revalidate, max-age=0";
    }

    if ( fastly.ff.visits_this_service == 0 && !req.http.Fastly-Debug ) {
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
        unset resp.http.Fastly-Drupal-HTML;

    }

    # Add an easy way to see whether custom Fastly VCL has been uploaded
    if ( req.http.Fastly-Debug ) {
        set resp.http.Fastly-Drupal-VCL-Uploaded = "8-1.0.4";
    } else {
        remove resp.http.Fastly-Drupal-VCL-Uploaded;
    }
