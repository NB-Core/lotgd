// ==UserScript==
// @name         LotGD Jaxon Integration
// @namespace    http://tampermonkey.net/
// @version      1.0
// @description  Integrate Jaxon AJAX library with LotGD
// @author       Your Name
// @match        *://yourlotgdwebsite.com/*
// @grant        none
// ==/UserScript==

(function() {
    'use strict';

    // Ensure jaxon config exists
    if (typeof jaxon !== 'undefined') {
        try {
            if(typeof jaxon.config == undefined)
                jaxon.config = {};
        }
        catch(e) {
            jaxon = jaxon || {};
            jaxon.config = {};
        }

        jaxon.config.requestURI = "/async/process.php";
        jaxon.config.statusMessages = false;
        jaxon.config.waitCursor = true;
        jaxon.config.version = "Jaxon 4.x";
        jaxon.config.defaultMode = "asynchronous";
        jaxon.config.defaultMethod = "POST";
        jaxon.config.responseType = "JSON";

        // Initialize jaxon dialogs if not already present
        jaxon.dialogs = jaxon.dialogs || {};

        // Register command handlers when DOM is ready
        if (jaxon.dom && jaxon.dom.ready) {
            jaxon.dom.ready(function() {
                if (jaxon.command && jaxon.command.handler) {
                    jaxon.command.handler.register("jquery", (args) => jaxon.cmd.script.execute(args));

                    jaxon.command.handler.register("bags.set", (args) => {
                        for (const bag in args.data) {
                            jaxon.ajax.parameters.bags[bag] = args.data[bag];
                        }
                    });
                }
            });
        }

        // Register redirect command handler
        if (jaxon.command && jaxon.command.handler) {
            jaxon.command.handler.register("rd", (command) => {
                const { data: sUrl, delay: nDelay } = command;
                if (nDelay <= 0) {
                    window.location = sUrl;
                    return true;
                }
                window.setTimeout(() => window.location = sUrl, nDelay * 1000);
                return true;
            });
        }
    }

    // Create the JaxonLotgd namespace structure that the PHP-generated code expects
    // Note: The PHP getScript() seems to generate code expecting JaxonLotgd, not Jaxon.Lotgd
    window.JaxonLotgd = window.JaxonLotgd || {};
    window.JaxonLotgd.Async = window.JaxonLotgd.Async || {};
    window.JaxonLotgd.Async.Handler = window.JaxonLotgd.Async.Handler || {};
    
    // Create empty handler objects - the PHP-generated script will add the methods
    window.JaxonLotgd.Async.Handler.Mail = window.JaxonLotgd.Async.Handler.Mail || {};
    window.JaxonLotgd.Async.Handler.Timeout = window.JaxonLotgd.Async.Handler.Timeout || {};
    window.JaxonLotgd.Async.Handler.Commentary = window.JaxonLotgd.Async.Handler.Commentary || {};
    
    // Mark as ready
    window.JaxonLotgdReady = true;
    
    // Dispatch ready event
    if (typeof window.CustomEvent === 'function') {
        window.dispatchEvent(new CustomEvent('JaxonLotgdReady'));
    }
    
    console.log('JaxonLotgd namespace created for PHP-generated handlers');
    
})();
