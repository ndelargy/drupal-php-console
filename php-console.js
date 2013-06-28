/**
 * PHP Console
 *
 * A web-based php debug console
 *
 * Copyright (C) 2010, Jordi Boggiano
 * http://seld.be/ - j.boggiano@seld.be
 *
 * Licensed under the new BSD License
 * See the LICENSE file for details
 *
 * Source on Github http://github.com/Seldaek/php-console
 */
(function(require, $, ace) {
    "use strict";

    var updateStatusBar, prepareClippyButton, refreshKrumoState, handleSubmit, initializeAce,
        options, editor;
    options = {
        tabsize: 4,
        editor: 'editor'
    };

    /**
     * updates the text of the status bar
     */
    var updateStatusBar = function(e) {
        var cursor_position = editor.getCursorPosition();
        $('.statusbar .position').text('Line: ' + (1+cursor_position.row) + ', Column: ' + cursor_position.column);
    },

    /**
     * prepares a clippy button for clipboard access
     */
    prepareClippyButton = function(e) {
        var selection = editor.getSession().doc.getTextRange(editor.getSelectionRange());
        if (!selection) {
            $('.statusbar .copy').hide();
            return;
        }
        $('#clippy embed').attr('FlashVars', 'text=' + selection);
        $('#clippy param[name="FlashVars"]').attr('value', 'text=' + selection);
        $('.statusbar .copy').html($('.statusbar .copy').html()).show();
    },

    /**
     * adds a toggle button to expand/collapse all krumo sub-trees at once
     */
    refreshKrumoState = function() {
        if ($('.krumo-expand').length > 0) {
            $('<a class="expand" href="#">Toggle all</a>')
                .click(function(e) {
                    $('div.krumo-element.krumo-expand').each(function(idx, el) {
                        window.krumo.toggle(el);
                    });
                    e.preventDefault();
                })
                .prependTo('.output');
        }
    },

    /**
     * does an async request to eval the php code and displays the result
     */
    handleSubmit = function(e) {
       function consoleResponseHandler(res) {
            if (res.match(/#end-php-console-output#$/)) {
                $('div.output').html(res.substring(0, res.length-24));
            } else {
                $('div.output').html(res + "<br /><br /><em>Script ended unexpectedly.</em>");
            }
            refreshKrumoState();
        }

        function consoleErrorHandler(jqXHR, textStatus, errorThrown){
          $('div.output').html(errorThrown + "<br /><br /><em>Script ended unexpectedly.</em>");
        }

        e.preventDefault();
        $('div.output').html('<img src="loader.gif" class="loader" alt="" /> Loading ...');

        $.ajax({
          type: "POST",
          url: '?js=1',
          data: { code: editor.getSession().getValue() },
          success: consoleResponseHandler,
          error: consoleErrorHandler
        });
    },

    initializeAce = function() {
        var PhpMode, code;

        code = $('#' + options.editor).text();
        $('#' + options.editor).replaceWith('<div id="'+options.editor+'" class="'+options.editor+'"></div>');
        $('#' + options.editor).text(code);

        editor = ace.edit(options.editor);

        editor.focus();
        editor.gotoLine(3,0);

        // set mode
        PhpMode = require("ace/mode/php").Mode;
        editor.getSession().setMode(new PhpMode());

        // tab size
        editor.getSession().setTabSize(options.tabsize);
        editor.getSession().setUseSoftTabs(true);

        // events
        editor.getSession().selection.on('changeCursor', updateStatusBar);
        if (window.navigator.userAgent.indexOf('Opera/') === 0) {
            editor.getSession().selection.on('changeSelection', prepareClippyButton);
        }

        // commands
        editor.commands.addCommand({
            name: 'submitForm',
            bindKey: {
                win: 'Ctrl-Return|Alt-Return',
                mac: 'Command-Return|Alt-Return'
            },
            exec: function(editor) {
                $('form').submit();
            }
        });
    },

    initializeSiteChooser = function(){
      var $chooser = $('#site-choice'),
          total_sites = $chooser.find('option').length;
      // If there's no site config or only one choice hide it.
      if ( total_sites < 2 ) {
        $chooser.hide();
      }
      if ( total_sites == 1 ) {
        $chooser.after('Debugging <strong>' + $chooser.find('option').html() + '</strong>');
      }
      // If there's only one site in the config
      $chooser.on('change', function(){
        $.post('?js=1', {"site":this.value}, function(){
          document.title = 'DBG - ' + $chooser.find('option:selected').html()
        });
      });
    },

    initializeHistory = function(){
      var $history = $('#history');
      $history.on('click', 'a', function(e){
        e.preventDefault();
        $.get(this.href, function(data){
          $('#' + options.editor).text(data);
          initializeAce();
        });
        
      });
    };

    $.console = function(settings) {
        $.extend(options, settings);

        $(function() {
            $(document).ready(initializeAce);
            $(document).ready(initializeSiteChooser);
            $(document).ready(initializeHistory);
            $('form').submit(handleSubmit);
        });
    };
}(ace.require, jQuery, ace));
