if ( resp.status >= 500 && resp.status < 600 && !req.http.ResponseObject ) {
    set req.http.ResponseObject = "970";
    restart;
}