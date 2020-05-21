(function ($) {
    $("#edit-vcl-snippets").click(function (e) {
        e.preventDefault();
        if (confirm('Are you sure you want to update Fastly VCL with latest?')) {
            $("#edit-vcl-snippets").trigger("click-custom");
        }

    });

    $("#edit-purge-all").click(function (e) {
        e.preventDefault();
        if (confirm('Are you sure you want to purge the entire service?')) {
            $("#edit-purge-all").trigger("click-custom-purge-all");
        }

    });

    $("#edit-purge-all-keys").click(function (e) {
      e.preventDefault();
      if (confirm('Are you sure you want to purge all content for this site?')) {
        $("#edit-purge-all-keys").trigger("click-custom-purge-all-keys");
      }
    });

    $("#edit-upload-error-maintenance").click(function (e) {
        e.preventDefault();
        if (confirm('Are you sure you want to upload new maintenance page?')) {
            $("#edit-upload-error-maintenance").trigger("click-custom-upload-error-maintenance");
        }

    });
})(jQuery);
