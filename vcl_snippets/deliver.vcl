    if (resp.status >= 500 && resp.status < 600) {
        /* restart if the stale object is available */
        if (stale.exists) {
            restart;
        }
    }

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

    # Add an easy way to see whether custom Fastly VCL has been uploaded
    if ( req.http.Fastly-Debug ) {
        set resp.http.Fastly-Drupal-VCL-Uploaded = "8-1.0.0";
    } else {
        remove resp.http.Fastly-Drupal-VCL-Uploaded;
    }