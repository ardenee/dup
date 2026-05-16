
(function (a) {
  var fileBrowserDialog, currentBrowserPath, currentRequest = null;
//-------------------------------------------------------------	  
  function browse(path, endpoint) {
    currentBrowserPath !== path && (currentBrowserPath = path, currentRequest && currentRequest.abort(), fileBrowserDialog.dialog("option", "dialogClass", "browserDialog busy"), currentRequest = a.getJSON(endpoint, {
      name: path
    }, function (data) {
      fileBrowserDialog.empty();
      var first_val = data.paths[0],
        g = 0,
        list, link = null,
        data = a.grep(data.paths, function () {
          return g++ !== 0
        });
      a("<h2>").text(first_val.current_path).appendTo(fileBrowserDialog);
      list = a("<ul>").appendTo(fileBrowserDialog);
      a.each(data, function (g, entry) {
        link = a("<a href='javascript:void(0)' />").click(function () {
          browse(entry.path, endpoint)
        }).text(entry.name);
        a('<span class="ui-icon ui-icon-folder-collapsed"></span>').prependTo(link);
        link.hover(function () {
          a("span", this).addClass("ui-icon-folder-open")
        }, function () {
          a("span", this).removeClass("ui-icon-folder-open")
        });
        link.appendTo(list)
      });
      a("a", list).wrap('<li class="ui-state-default ui-corner-all">');
      fileBrowserDialog.dialog("option", "dialogClass", "browserDialog")
    }))
  } 
//-------------------------------------------------------------
  a.fn.nFileBrowser = function (callback, options) {
    options = a.extend({}, a.Browser.defaults, options);
    fileBrowserDialog || (fileBrowserDialog = a('<div id="fileBrowserDialog" style="display:hidden"></div>').appendTo("body").dialog({
      dialogClass: "browserDialog",
      title: options.title,
      position: ["center", 40],
      minWidth: Math.min(a(document).width() - 80, 650),
      minHeight: 320,
      height: a(document).height() - 80,
      modal: true,
      autoOpen: false
    }));
    fileBrowserDialog.dialog("option", "buttons", {
      Ok: function () {
        callback(currentBrowserPath, options);
        fileBrowserDialog.dialog("close")
      },
      Cancel: function () {
        fileBrowserDialog.dialog("close")
      }
    });	
	
    var initialTmpDir = options.field.val() || options.initialDir || "";
    browse(initialTmpDir, options.url);
    fileBrowserDialog.dialog("option", "title", options.title);
    fileBrowserDialog.dialog("open");
    return false
  };
//-------------------------------------------------------------
  a.fn.fileBrowser = function (options) {
    options = a.extend({}, a.Browser.defaults, options);
    options.field = a(this);
    if (options.field.autocomplete && options.autocompleteURL) {
      var query = "";
      options.field.autocomplete({
        appendTo: "#content",
        source: function (g, e) {
          query = a.ui.autocomplete.escapeRegex(g.term);
          a.ajax({
            url: options.autocompleteURL,
            data: g,
            dataType: "json",
            success: function (options3) {
              var g = RegExp("^" + query, "i"),
                options3 = a.grep(options3, function (a) {
                  return g.test(a)
                });
              e(options3)
            }
          })
        },
        open: function () {
          a(".ui-autocomplete li.ui-menu-item a").removeClass("ui-corner-all");
          a(".ui-autocomplete li.ui-menu-item:odd a").addClass("ui-menu-item-alternate")
        }
      }).data("autocomplete")._renderItem = function (ul, item) {
        var f = item.label,
          f = f.replace(RegExp("(?![^&;]+;)(?!<[^<>]*)(" + query + ")(?![^<>]*>)(?![^&;]+;)", "gi"), function (FullMatch) {
            return "<b>" + FullMatch + "</b>"
          });
        return a("<li></li>").data("item.autocomplete", item).append("<a class='nowrap'>" + f + "</a>").appendTo(ul)
      }
    }
	
    var initialDir, callback = null,
      callback = function (a, options2) {
        options2.field.val(a)
      };
    initialDir = options.field.val() || "";
    options = a.extend(options, {
      initialDir: initialDir
    });
    return options.field.addClass("fileBrowserField").after(a('<input type="button" value="Browse&hellip;" class="fileBrowser" />').click(function () {
      a(this).nFileBrowser(callback, options);
      return !1
    }))
  }
})(jQuery);