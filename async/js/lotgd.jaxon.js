(function() {
    function initializeJaxonLotgd() {
        try {
            if(typeof jaxon.config == undefined)
                jaxon.config = {};
        }
        catch(e) {
            jaxon = {};
            jaxon.config = {};
        };

        jaxon.config.requestURI = "/async/process.php";
        jaxon.config.statusMessages = false;
        jaxon.config.waitCursor = true;
        jaxon.config.version = "Jaxon 4.x";
        jaxon.config.defaultMode = "asynchronous";
        jaxon.config.defaultMethod = "POST";
        jaxon.config.responseType = "JSON";

        // Create a convenient alias for the Jaxon-generated namespace
        // The actual generated namespace will be Jaxon.Lotgd.Async.Handler.*
        window.JaxonLotgd = window.Jaxon = window.Jaxon || {};
        
        // Initialize jaxon dialogs if not already present
        jaxon.dialogs = jaxon.dialogs || {};

        // Register command handlers when DOM is ready
        jaxon.dom.ready(function() {
            jaxon.command.handler.register("jquery", (args) => jaxon.cmd.script.execute(args));

            jaxon.command.handler.register("bags.set", (args) => {
                for (const bag in args.data) {
                    jaxon.ajax.parameters.bags[bag] = args.data[bag];
                }
            });
        });

        // Register redirect command handler
        jaxon.command.handler.register("rd", (command) => {
            const { data: sUrl, delay: nDelay } = command;
            if (nDelay <= 0) {
                window.location = sUrl;
                return true;
            }
            window.setTimeout(() => window.location = sUrl, nDelay * 1000);
            return true;
        });

        // Mark JaxonLotgd as successfully initialized
        window.JaxonLotgdReady = true;
        
        // Dispatch a custom event to notify other scripts that JaxonLotgd is ready
        if (typeof window.CustomEvent === 'function') {
            window.dispatchEvent(new CustomEvent('JaxonLotgdReady'));
        }
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
