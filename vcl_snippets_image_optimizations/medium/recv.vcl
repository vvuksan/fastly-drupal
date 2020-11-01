if ( req.url.ext ~ "(?i)^(gif|png|jpg|jpeg|webp)$" ) {
  set req.http.X-Fastly-Imageopto-Api = "fastly";
  if ( !subfield(req.url.qs, "optimize", "&") ) {
    set req.url = querystring.set(req.url, "optimize", "medium");
  }
}
