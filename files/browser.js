/* DUP folder picker, restored from the original jQuery UI browser.
 * Lists directories on the server only; never exposes file contents. */
;(function ($) {
    "use strict";

    $.Browser = $.Browser || {};
    $.Browser.defaults = {
        title: "Choose Directory",
        url: "browser-files.php"
    };

    var dialog = null;
    var pending = null;
    var selectedPath = "";
    var canSelect = false;
    var activeInput = null;
    var activeOptions = null;

    function ensureDialog() {
        if (dialog) return;
        dialog = $('<div id="fileBrowserDialog" style="display:none"></div>')
            .appendTo("body").dialog({
                dialogClass: "browserDialog",
                title: "Choose Directory",
                position: ["center", 40],
                minWidth: Math.min($(window).width() - 40, 650),
                width: Math.min($(window).width() - 40, 650),
                height: Math.min($(window).height() - 40, 550),
                modal: true,
                autoOpen: false,
                close: function () {
                    if (pending) {
                        pending.abort();
                        pending = null;
                    }
                },
                buttons: [{
                    text: "Select Folder",
                    "class": "btn btn-large",
                    click: function () {
                        if (!canSelect || !activeInput) return;
                        activeInput.val(selectedPath).trigger("change");
                        dialog.dialog("close");
                    }
                }, {
                    text: "Cancel",
                    "class": "btn btn-large",
                    click: function () { dialog.dialog("close"); }
                }]
            });
    }

    function setSelectionAllowed(allowed) {
        canSelect = allowed;
        if (dialog) {
            dialog.dialog("widget").find(".ui-dialog-buttonpane button:first")
                .button(allowed ? "enable" : "disable");
        }
    }

    function showMessage(message) {
        setSelectionAllowed(false);
        dialog.empty().append($("<p/>").text(message));
        dialog.dialog("option", "dialogClass", "browserDialog");
    }

    function browse(path) {
        if (pending) {
            pending.abort();
            pending = null;
        }
        setSelectionAllowed(false);
        selectedPath = "";
        dialog.empty().append($("<p/>").text("Loading folders..."));
        dialog.dialog("option", "dialogClass", "browserDialog busy");

        pending = $.ajax({
            url: activeOptions.url,
            type: "GET",
            data: { name: path || "/" },
            dataType: "json",
            cache: false
        }).done(function (data) {
            var entries = data && data.paths;
            if (!$.isArray(entries) || entries.length === 0) {
                showMessage((data && data.error) || "No accessible folders found.");
                return;
            }

            var current = data.current_path || entries[0].current_path || entries[0].path || "/";
            selectedPath = current;
            setSelectionAllowed(/^\/volume[0-9]+(?:\/|$)/.test(current) && !data.error);

            dialog.empty().append($("<h2/>").text(current === "/" ? "Available Volumes" : current));
            if (data.error) {
                dialog.append($("<p/>").text(data.error));
            }

            var list = $("<ul/>").appendTo(dialog);
            $.each(entries.slice(1), function (_, entry) {
                if (!entry || !entry.path || !entry.name) return;

                var link = $('<a href="#"/>')
                    .append($('<span class="ui-icon ui-icon-folder-collapsed"/>'))
                    .append(document.createTextNode(entry.name))
                    .click(function (event) {
                        event.preventDefault();
                        browse(entry.path);
                    });
                $("<li/>").addClass("ui-state-default ui-corner-all")
                    .append(link).appendTo(list);
            });
            if (entries.length === 1 && !data.error) {
                dialog.append($("<p/>").text("No subfolders found. You can select this folder."));
            }
            dialog.dialog("option", "dialogClass", "browserDialog");
        }).fail(function (xhr, status) {
            if (status !== "abort") {
                showMessage(xhr.status === 401 || xhr.status === 403 ?
                    "Your session has expired or this action requires administrator access." :
                    "Unable to list folders. Check server folder permissions.");
            }
        }).always(function () {
            pending = null;
        });
    }

    $.fn.fileBrowser = function (options) {
        return this.each(function () {
            var input = $(this);
            if (input.data("dup-folder-browser")) return;
            var settings = $.extend({}, $.Browser.defaults, options);
            input.attr("readonly", "readonly").addClass("fileBrowserField")
                .data("dup-folder-browser", true);
            $("<input/>", {
                type: "button",
                value: "Browse...",
                "class": "btn fileBrowser"
            }).insertAfter(input).click(function () {
                ensureDialog();
                activeInput = input;
                activeOptions = settings;
                dialog.dialog("option", "title", settings.title);
                dialog.dialog("open");
                browse(input.val() || "/");
                return false;
            });
        });
    };
})(jQuery);
