These VCL snippets will be uploaded by the module. You can
also upload them by hand. You should name them

drupalmodule_<function_name>

e.g. recv.vcl will become

drupalmodule_recv

In addition to these snippets you need to add a request setting

  - Action: Pass
  - Condition: req.http.X-Pass == "1"


