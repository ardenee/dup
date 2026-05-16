$(document).ready(function () {
    $(".enabler").each(function () {
        if (!$(this).prop('checked')) {
            $('#content_' + $(this).attr('id')).hide();
        }
    });

    $(".enabler").click(function () {
        if ($(this).prop('checked')) {
            $('#content_' + $(this).attr('id')).fadeIn("fast", "linear");
        } else {
            $('#content_' + $(this).attr('id')).fadeOut("fast", "linear");
        }
    });

    // bind 'myForm' and provide a simple callback function 
    $('#configForm').ajaxForm({
        beforeSubmit: function () {
            $('.config_submitter').each(function () {
                $(this).attr("disabled", "disabled");
                $(this).after('<span><img src="' + sbRoot + 'dup/images/loading16.gif"> Saving...</span>');
                $(this).hide();
            });
        },
        success: function () {
            setTimeout('config_success()', 2000);
			check_notifications();
        }
    });
});

function config_success() {
    $('.config_submitter').each(function () {
        $(this).removeAttr("disabled");
        $(this).next().remove();
        $(this).show();
    });
}