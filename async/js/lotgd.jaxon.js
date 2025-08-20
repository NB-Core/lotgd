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

    // Initialize JaxonLotgd alias and configuration
    function initializeJaxonLotgd() {
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

        // Create the Jaxon namespace structure if it doesn't exist
        // This simulates what the PHP getScript() method should generate
        function createJaxonNamespace() {
            // Create the base Jaxon namespace
            window.Jaxon = window.Jaxon || {};
            window.Jaxon.Lotgd = window.Jaxon.Lotgd || {};
            window.Jaxon.Lotgd.Async = window.Jaxon.Lotgd.Async || {};
            window.Jaxon.Lotgd.Async.Handler = window.Jaxon.Lotgd.Async.Handler || {};
            
            // Create the handler objects
            window.Jaxon.Lotgd.Async.Handler.Mail = window.Jaxon.Lotgd.Async.Handler.Mail || {};
            window.Jaxon.Lotgd.Async.Handler.Mail.mailStatus = function() {
                return jaxon.request({ jxncls: 'Lotgd.Async.Handler.Mail', jxnmthd: 'mailStatus' }, { parameters: arguments });
            };

            window.Jaxon.Lotgd.Async.Handler.Timeout = window.Jaxon.Lotgd.Async.Handler.Timeout || {};
            window.Jaxon.Lotgd.Async.Handler.Timeout.timeoutStatus = function() {
                return jaxon.request({ jxncls: 'Lotgd.Async.Handler.Timeout', jxnmthd: 'timeoutStatus' }, { parameters: arguments });
            };

            window.Jaxon.Lotgd.Async.Handler.Commentary = window.Jaxon.Lotgd.Async.Handler.Commentary || {};
            window.Jaxon.Lotgd.Async.Handler.Commentary.commentaryText = function() {
                return jaxon.request({ jxncls: 'Lotgd.Async.Handler.Commentary', jxnmthd: 'commentaryText' }, { parameters: arguments });
            };
            window.Jaxon.Lotgd.Async.Handler.Commentary.commentaryRefresh = function() {
                return jaxon.request({ jxncls: 'Lotgd.Async.Handler.Commentary', jxnmthd: 'commentaryRefresh' }, { parameters: arguments });
            };
            window.Jaxon.Lotgd.Async.Handler.Commentary.pollUpdates = function() {
                return jaxon.request({ jxncls: 'Lotgd.Async.Handler.Commentary', jxnmthd: 'pollUpdates' }, { parameters: arguments });
            };
            
            console.log('Jaxon namespace structure created');
            return true;
        }

        // Wait for the Jaxon-generated namespace to be available OR create it ourselves
        function waitForJaxonNamespace() {
            // Check if it exists (from PHP-generated script)
            if (typeof window.Jaxon !== 'undefined' 
                && window.Jaxon.Lotgd && window.Jaxon.Lotgd.Async && window.Jaxon.Lotgd.Async.Handler) {
                console.log('Found existing Jaxon.Lotgd structure');
            } else {
                // Create it ourselves
                console.log('Creating Jaxon.Lotgd structure manually');
                createJaxonNamespace();
            }
            
            // Verify it exists now
            if (typeof window.Jaxon !== 'undefined' 
                && window.Jaxon.Lotgd && window.Jaxon.Lotgd.Async && window.Jaxon.Lotgd.Async.Handler) {
                
                // Create the JaxonLotgd alias pointing to the generated structure
                window.JaxonLotgd = {
                    Async: {
                        Handler: window.Jaxon.Lotgd.Async.Handler
                    }
                };
                
                // Mark JaxonLotgd as successfully initialized
                window.JaxonLotgdReady = true;
                
                // Dispatch a custom event to notify other scripts
                if (typeof window.CustomEvent === 'function') {
                    window.dispatchEvent(new CustomEvent('JaxonLotgdReady'));
                }
                
                console.log('JaxonLotgd alias created successfully');
                return true;
            }
            
            console.error('Failed to create or find Jaxon.Lotgd structure');
            return false;
        }

        // Try to initialize immediately (for inline execution)
        if (waitForJaxonNamespace()) {
            return;
        }

        // If not ready, poll for the namespace (for async execution)
        var checkNamespace = setInterval(function() {
            if (waitForJaxonNamespace()) {
                clearInterval(checkNamespace);
            }
        }, 50);
        
        // Timeout after 5 seconds
        setTimeout(function() {
            clearInterval(checkNamespace);
            console.error('Jaxon.Lotgd.Async.Handler namespace not available after timeout');
            console.log('Available objects:', { 
                jaxon: typeof jaxon,
                Jaxon: typeof window.Jaxon,
                window_keys: Object.getOwnPropertyNames(window).filter(name => 
                    name.toLowerCase().includes('jax')
                )
            });
        }, 5000);
    }

    // Check if jaxon is available, if not wait for it
    if (typeof jaxon !== 'undefined' && jaxon.request) {
        initializeJaxonLotgd();
    } else {
        // Poll for jaxon availability
        var checkJaxon = setInterval(function() {
            if (typeof jaxon !== 'undefined' && jaxon.request) {
                clearInterval(checkJaxon);
                initializeJaxonLotgd();
            }
        }, 50);
        
        // Fallback timeout to prevent infinite waiting
        setTimeout(function() {
            if (typeof jaxon === 'undefined') {
                console.error('Jaxon library failed to load within timeout period');
                clearInterval(checkJaxon);
            }
        }, 5000);
    }
})();
