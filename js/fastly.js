(function ($) {
    $("#edit-vcl-snippets").click(function (e) {
        e.preventDefault();
        if (confirm('Are you sure you want to delete that?')) {
            $("#edit-vcl-snippets").trigger("click-custom");
        }

    });
})(jQuery);
