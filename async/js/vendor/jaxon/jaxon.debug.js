/*
    File: jaxon.debug.js
    
    This optional file contains the debugging module for use with jaxon.
    If you include this module after the standard <jaxon_core.js> module, you will receive debugging messages,
    including errors, that occur during the processing of your jaxon requests.
    
    Title: jaxon debugging module
    
    Please see <copyright.inc.php> for a detailed description, copyright and license information.
*/

/*
    @package jaxon
    @version $Id: jaxon.debug.js 327 2007-02-28 16:55:26Z calltoconstruct $
    @copyright Copyright (c) 2005-2007 by Jared White & J. Max Wilson
    @copyright Copyright (c) 2008-2009 by Joseph Woolley, Steffen Konerow, Jared White  & J. Max Wilson
    @license http://www.jaxonproject.org/bsd_license.txt BSD License
*/

try {
    /*
        Class: jaxon.debug

        This object contains the variables and functions used to display process state
        messages and to trap error conditions and report them to the user via
        a secondary browser window or alert messages as necessary.
    */
    if ('undefined' == typeof jaxon)
        throw { name: 'SequenceError', message: 'Error: Jaxon core was not detected, debug module disabled.' }

    if ('undefined' == typeof jaxon.debug)
        jaxon.debug = {}

    jaxon.debug.active = true;
} catch (e) {
    alert(e.name + ': ' + e.message);
}

(function(self, parameters, request, response, command, call, query, utils) {
    /*
        String: jaxon.debug.windowSource
        
        The default URL that is given to the debugging window upon creation.
    */
    self.windowSource = 'about:blank';

    /*
        String: jaxon.debug.windowID
        
        A 'unique' name used to identify the debugging window that is attached to this jaxon session.
    */
    self.windowID = 'jaxon_debug_window'; // + new Date().getTime();

    /*
        String: windowStyle
        
        The parameters that will be used to create the debugging window.
    */
    self.windowStyle = 'width=800,height=600,scrollbars=yes,resizable=yes,status=yes';

    /*
        String: windowTemplate
        
        The HTML template and CSS style information used to populate the
        debugging window upon creation.
    */
    self.windowTemplate =
        '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">' +
        '<html><head>' +
        '<title>jaxon debug output</title>' +
        '<style type="text/css">' +
        '/* <![CDATA[ */' +
        '.debugEntry { margin: 3px; padding: 3px; border-top: 1px solid #999999; } ' +
        '.debugDate { font-weight: bold; margin: 2px; } ' +
        '.debugText { margin: 2px; } ' +
        '.warningText { margin: 2px; font-weight: bold; } ' +
        '.errorText { margin: 2px; font-weight: bold; color: #ff7777; }' +
        '/* ]]> */' +
        '</style>' +
        '</head><body>' +
        '<h2>jaxon debug output</h2>' +
        '<div id="debugTag"></div>' +
        '</body></html>';

    /*
        Boolean: jaxon.debug.isLoaded
        
        true - indicates that the debugging module is loaded
    */
    self.isLoaded = true;

    /*
        Boolean: isLoaded
        
        true - indicates that the verbose debugging module is loaded.
    */
    self.verbose.isLoaded = false;

    /*
        Boolean: active
        
        true - indicates that the verbose debugging module is active.
    */
    self.verbose.active = false;

    /*
        Function: jaxon.debug.getExceptionText
        
        Parameters:
        e - (object): Exception
    */
    const getExceptionText = function(e) {
        if ('undefined' != typeof e.code) {
            if ('undefined' != typeof self.exceptions[e.code]) {
                const msg = self.exceptions[e.code];
                if ('undefined' != typeof e.data) {
                    msg.replace('{data}', e.data);
                }
                return msg;
            }
        } else if ('undefined' != typeof e.name) {
            const msg = 'undefined' != typeof e.message ? e.name + ': ' + e.message : e.name;
            return msg;
        }
        return 'An unknown error has occurred.';
    }

    /*
        Function: jaxon.debug.prepareDebugText
        
        Convert special characters to their HTML equivellents so they will show up in the <jaxon.debug.window>.
        
        Parameters:
            text - (string): Debug text
    */
    self.prepareDebugText = function(text) {
        try {
            text = text.replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\n/g, '<br />');
            return text;
        } catch (e) {
            const stringReplace = function(haystack, needle, newNeedle) {
                const segments = haystack.split(needle);
                haystack = '';
                for (let i = 0; i < segments.length; ++i) {
                    if (0 != i)
                        haystack += newNeedle;
                    haystack += segments[i];
                }
                return haystack;
            }
            self.prepareDebugText = function(text) {
                text = stringReplace(text, '&', '&amp;');
                text = stringReplace(text, '<', '&lt;');
                text = stringReplace(text, '>', '&gt;');
                text = stringReplace(text, '\n', '<br />');
                return text;
            }
            self.prepareDebugText(text);
        }
    }

    /*
        Function: jaxon.debug.writeDebugMessage
        
        Output a debug message to the debug window if available or send to an
        alert box.  If the debug window has not been created, attempt to 
        create it.
        
        Parameters:
        
        text - (string):  The text to output.
        
        prefix - (string):  The prefix to use; this is prepended onto the 
            message; it should indicate the type of message (warning, error)
            
        cls - (string):  The className that will be applied to the message;
            invoking a style from the CSS provided in  <self.windowTemplate>.
            Should be one of the following:
            - warningText
            - errorText
    */
    self.writeDebugMessage = function(text, prefix, cls) {
        try {
            if (!self.window || self.window.closed) {
                self.window = window.open(self.windowSource, self.windowID, self.windowStyle);
                if ("about:blank" === self.windowSource)
                    self.window.document.write(self.windowTemplate);
            }
            if (prefix === undefined)
                prefix = '';
            if (cls === undefined)
                cls = 'debugText';

            text = self.prepareDebugText(text);

            const xdwd = self.window.document;
            const debugTag = xdwd.getElementById('debugTag');
            const debugEntry = xdwd.createElement('div');
            const debugDate = xdwd.createElement('span');
            const debugText = xdwd.createElement('pre');

            debugDate.innerHTML = new Date().toString();
            debugText.innerHTML = prefix + text;

            debugEntry.appendChild(debugDate);
            debugEntry.appendChild(debugText);
            debugTag.insertBefore(debugEntry, debugTag.firstChild);
            // don't allow 'style' issues to hinder the debug output
            try {
                debugEntry.className = 'debugEntry';
                debugDate.className = 'debugDate';
                debugText.className = cls;
            } catch (e) {}
        } catch (e) {
            if (text.length > 1500) {
                text = text.substr(0, 1500) + ' ...\n(Truncated)';
            }
            alert(self.messages.heading + text);
        }
    }

    /**
     * See https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Errors/Cyclic_object_value
     * @returns {string}
     */
    const getCircularReplacer = () => {
        const ancestors = [];
        return function (key, value) {
            if (typeof value !== "object" || value === null) {
                return value;
            }
            // Added feature: do not include the window and document object.
            if (value === window) {
                return "[Window]";
            }
            if (value === document) {
                return "[Document]";
            }
            // `this` is the object that value is contained in,
            // i.e., its direct parent.
            while (ancestors.length > 0 && ancestors.at(-1) !== this) {
                ancestors.pop();
            }
            if (ancestors.includes(value)) {
                return "[Circular]";
            }
            ancestors.push(value);
            return value;
        };
    };

    /**
     * Serialize object with cyclic structures.
     *
     * @param {mixed} obj 
     *
     * @returns {string}
     */
    self.stringify = (obj) => JSON.stringify(obj, getCircularReplacer(), 2);

    /*
        Function: jaxon.ajax.command.unregister
        
        Catch any exception thrown during the unregistration of command handler and display an appropriate debug message.
        
        This is a wrapper around the standard <jaxon.ajax.command.unregister> function.
        
        Parameters:
            child - (object): Childnode
            obj - (object): Object
            
    */
    const commandHandler = command.unregister('script.debug');
    command.register('script.debug', ({ message }) => {
        self.writeDebugMessage(message, self.messages.warning, 'warningText');
        return commandHandler({ message });
    });

    /*
        Function: jaxon.debug.executeCommand
        
        Catch any exceptions that are thrown by a response command handler
        and display a message in the debugger.
        
        This is a wrapper function which surrounds the standard 
        <jaxon.ajax.command.execute> function.
    */
    const executeCommand = command.execute;
    command.execute = function(context) {
        const { sequence, command: { name, fullName, args } } = context;
        try {
            if ('undefined' == typeof name)
                throw { code: 10006 };
            if (false == command.isRegistered({ name }))
                throw { code: 10007, data: name };
            return executeCommand(context);
        } catch (e) {
            let msg = 'jaxon.ajax.command.execute (';
            if ('undefined' != typeof sequence) {
                msg += '#' + sequence + ', ';
            }
            if ('undefined' != typeof fullName) {
                msg += '"' + fullName + '"';
            }
            if ('undefined' != typeof args) {
                msg += self.stringify(args);
            }
            msg += '):\n' + getExceptionText(e) + '\n';
            self.writeDebugMessage(msg, self.messages.error, 'errorText');
        }
        return true;
    }

    /*
        Function: jaxon.ajax.command.call
        
        Validates that a function name was provided, generates a message 
        indicating that a jaxon call is starting and sets a flag in the
        request object indicating that debugging is enabled for this call.
        
        This is a wrapper around the standard <jaxon.ajax.command.call> function.
    */
        const callHandler = command.callHandler;
        command.callHandler = function(name, args, context) {
            const { command: { fullName }, component } = context;
            try {
                const rv = callHandler(name, args, context);
    
                self.writeDebugMessage(self.messages.processing.calling.supplant({
                    cmd: fullName || name,
                    options: self.stringify({
                        ...(component ? { component } : {}),
                        args,
                    }),
                }));
    
                return rv;
            } catch (e) {
                const msg = 'jaxon.ajax.command.callHandler: ' + getExceptionText(e) + '\n';
                self.writeDebugMessage(msg, self.messages.error, 'errorText');
                throw e;
            }
        }
    
    /*
        Function: jaxon.utils.dom.$
        
        Catch any exceptions thrown while attempting to locate an HTML element by it's unique name.
        
        This is a wrapper around the standard <jaxon.utils.dom.$> function.
        
        Parameters:
        sId - (string): Element ID or name
        
    */
    const dom = utils.dom.$;
    utils.dom.$ = function(sId) {
        try {
            let returnValue = dom(sId);
            if ('object' != typeof returnValue)
                throw { code: 10008 };
            return returnValue;
        } catch (e) {
            const msg = '$:' + getExceptionText(e) + '\n';
            self.writeDebugMessage(msg, self.messages.warning, 'warningText');
        }
    }

    /*
        Function: jaxon.ajax.request._send
        
        Generate a message indicating that the jaxon request is
        about the be sent to the server.
        
        This is a wrapper around the standard <jaxon.ajax.request._send> 
        function.
    */
    const sendRequest = request._send;
    request._send = function(oRequest) {
        try {
            const length = oRequest.requestData.length || 0;
            self.writeDebugMessage(self.messages.request.sending);
            oRequest.beginDate = new Date();
            sendRequest(oRequest);
            self.writeDebugMessage(self.messages.request.sent.supplant({ length }));
        } catch (e) {
            const msg = 'jaxon.ajax.request._send: ' + getExceptionText(e) + '\n';
            self.writeDebugMessage(msg, self.messages.error, 'errorText');
            throw e;
        }
    }

    /*
        Function: jaxon.ajax.request.submit
        
        Generate a message indicating that a request is ready to be 
        submitted; providing the URL and the function being invoked.
        
        Catch any exceptions thrown and display a message.
        
        This is a wrapper around the standard <jaxon.ajax.request.submit>
        function.
    */
    const submitRequest = request.submit;
    request.submit = function(oRequest) {
        let msg = oRequest.method + ': ' + oRequest.requestURI + '\n';
        let text = decodeURIComponent(oRequest.requestData);
        text = text.replace(new RegExp('&jxn', 'g'), '\n&jxn');
        msg += text;
        self.writeDebugMessage(msg);

        msg = self.messages.request.calling;
        const separator = '\n';
        for (let mbr in oRequest.functionName) {
            msg += separator + mbr + ': ' + oRequest.functionName[mbr];
        }
        self.writeDebugMessage(msg);

        try {
            return submitRequest(oRequest);
        } catch (e) {
            self.writeDebugMessage(e.message);
            if (0 < oRequest.requestRetry)
                throw e;
        }
    }

    /*
        Function: jaxon.ajax.request.initialize
        
        Generate a message indicating that the request object is
        being initialized.
        
        This is a wrapper around the standard <jaxon.ajax.request.initialize>
        function.
    */
    const initializeRequest = request.initialize;
    request.initialize = function(oRequest) {
        try {
            const msg = self.messages.request.init;
            self.writeDebugMessage(msg);
            return initializeRequest(oRequest);
        } catch (e) {
            const msg = 'jaxon.ajax.request.initialize: ' + getExceptionText(e) + '\n';
            self.writeDebugMessage(msg, self.messages.error, 'errorText');
            throw e;
        }
    }

    /*
        Function: jaxon.ajax.parameters.process
        
        Generate a message indicating that the request object is
        being populated with the parameters provided.
        
        This is a wrapper around the standard <jaxon.ajax.parameters.process>
        function.
    */
    const processParameters = parameters.process;
    parameters.process = function(oRequest) {
        try {
            if ('undefined' != typeof oRequest.parameters) {
                const msg = self.messages.processing.parameters.supplant({
                    count: oRequest.parameters.length
                });
                self.writeDebugMessage(msg);
            } else {
                const msg = self.messages.processing.no_parameters;
                self.writeDebugMessage(msg);
            }
            return processParameters(oRequest);
        } catch (e) {
            const msg = 'jaxon.ajax.parameters.process: ' + getExceptionText(e) + '\n';
            self.writeDebugMessage(msg, self.messages.error, 'errorText');
            throw e;
        }
    }

    /*
        Function: jaxon.ajax.request.prepare
        
        Generate a message indicating that the request is being
        prepared.  This may occur more than once for a request
        if it errors and a retry is attempted.
        
        This is a wrapper around the standard <jaxon.ajax.request.prepare>
    */
    const prepareRequest = request.prepare;
    request.prepare = function(oRequest) {
        try {
            const msg = self.messages.request.preparing;
            self.writeDebugMessage(msg);
            return prepareRequest(oRequest);
        } catch (e) {
            const msg = 'jaxon.ajax.request.prepare: '; + getExceptionText(e) + '\n';
            self.writeDebugMessage(msg, self.messages.error, 'errorText');
            throw e;
        }
    }

    /*
        Function: jaxon.ajax.request.execute
        
        Validates that a function name was provided, generates a message 
        indicating that a jaxon request is starting and sets a flag in the
        request object indicating that debugging is enabled for this request.
        
        This is a wrapper around the standard <jaxon.ajax.request.execute> function.
    */
    const executeRequest = request.execute;
    request.execute = function() {
        try {
            self.writeDebugMessage(self.messages.request.starting);

            const numArgs = arguments.length;

            if (0 == numArgs)
                throw { code: 10010 };

            const oFunction = arguments[0];
            const oOptions = 1 < numArgs ? arguments[1] : {};
            oOptions.debugging = true;

            return executeRequest(oFunction, oOptions);
        } catch (e) {
            const msg = 'jaxon.ajax.request.execute: ' + getExceptionText(e) + '\n';
            self.writeDebugMessage(msg, self.messages.error, 'errorText');
            throw e;
        }
    }

    /*
        Function: jaxon.ajax.response.received
        
        Generate a message indicating that a response has been received
        from the server; provide some statistical data regarding the
        response and the response time.
        
        Catch any exceptions that are thrown during the processing of
        the response and generate a message.
        
        This is a wrapper around the standard <jaxon.ajax.response.received>
        function.
    */
    const responseReceived = response.received;
    response.received = function(oRequest) {
        try {
            const status = oRequest.response.status;
            if (response.isSuccessCode(status)) {
                oRequest.midDate = new Date();
                const msg = self.messages.response.success.supplant({
                    status: status,
                    length: self.stringify(oRequest.responseContent).length,
                    duration: oRequest.midDate - oRequest.beginDate
                }) + '\n' + self.stringify(oRequest.responseContent);
                self.writeDebugMessage(msg);
            } else if (response.isErrorCode(status)) {
                const msg = self.messages.response.content.supplant({
                    status: status,
                    text: self.stringify(oRequest.responseContent)
                });
                self.writeDebugMessage(msg, self.messages.error, 'errorText');
            } else if (response.isRedirectCode(status)) {
                const msg = self.messages.response.redirect.supplant({
                    location: oRequest.response.headers.get('location')
                });
                self.writeDebugMessage(msg);
            }
            return responseReceived(oRequest);
        } catch (e) {
            const msg = 'jaxon.ajax.response.received: ' + getExceptionText(e) + '\n';
            self.writeDebugMessage(msg, self.messages.error, 'errorText');
        }

        return null;
    }

    /*
        Function: jaxon.ajax.response.complete
        
        Generate a message indicating that the request has completed
        and provide some statistics regarding the request and response.
        
        This is a wrapper around the standard <jaxon.ajax.request.complete>
        function.
    */
    const responseCompleted = response.complete;
    response.complete = function(oRequest) {
        try {
            let returnValue = responseCompleted(oRequest);
            oRequest.endDate = new Date();
            const duration = (oRequest.endDate - oRequest.beginDate);
            const msg = self.messages.processing.done.supplant({ duration });
            self.writeDebugMessage(msg);
            return returnValue;
        } catch (e) {
            const msg = 'jaxon.ajax.response.complete: ' + getExceptionText(e) + '\n';
            self.writeDebugMessage(msg, self.messages.error, 'errorText');
            throw e;
        }
    }

    /*
        Function: jaxon.cmd.node.assign
        
        Catch any exceptions thrown during the assignment and display an error message.
        
        This is a wrapper around the standard <jaxon.cmd.node.assign> function.
    */
    const nodeAssign = jaxon.cmd.node.assign;
    jaxon.cmd.node.assign = function({ target: element, prop: property, data }) {
        try {
            return nodeAssign(element, property, data);
        } catch (e) {
            const msg = 'jaxon.cmd.node.assign: ' + getExceptionText(e) + '\n' +
                'Eval: element.' + property + ' = data;\n' +
                self.stringify({ target: element, prop: property, data }) + '\n';
            self.writeDebugMessage(msg, self.messages.error, 'errorText');
        }
        return true;
    }

    const execCallCommand = call.execCommand;
    call.execCommand = (xCall, xOptions) => {
        xOptions = execCallCommand(xCall, xOptions);
        const callNames = {
            select: 'selector',
            event: 'event handler',
            attr: 'attribute',
            func: 'function',
        };
        const callName = callNames[xCall._type] ?? `[unknown(${xCall._type})]`
        console.log(`Call parser has called ${callName} ${xOptions.call}.`);
        return xOptions;
    };

    const jQuerySelector = query.select;
    query.select = (xSelector, xContext = null) => {
        const sel = self.stringify(xSelector);
        try {
            const elements = jQuerySelector(xSelector, xContext);
            const jqLibrary = query.jq === window.jQuery ? 'jQuery' :
                (query.jq === window.chibi ? 'Chibi' : '[Custom]')
            const msg = 'jaxon.parser.query.select(' + sel + ') using ' + jqLibrary + '\n';
            self.writeDebugMessage(msg, self.messages.info);
            return elements;
        } catch (e) {
            const msg = 'jaxon.parser.query.select(' + sel + '): ' + getExceptionText(e) + '\n';
            self.writeDebugMessage(msg, self.messages.error, 'errorText');
        }
    };
})(jaxon.debug, jaxon.ajax.parameters, jaxon.ajax.request, jaxon.ajax.response,
    jaxon.ajax.command, jaxon.parser.call, jaxon.parser.query, jaxon.utils);

/*
    The jaxon verbose debugging module.
    This is an optional module, include in your project with care. :)
*/
jaxon.dom.ready(function() {
    // Generate wrapper functions for verbose debug.
    (function(self, debug) {
        if (!self.active) {
            return;
        }

        /*
            Function: jaxon.debug.verbose.makeFunction
            
            Generate a wrapper function around the specified function.
            
            Parameters:
            
            obj - (object):  The object that contains the function to be wrapped.
            name - (string):  The name of the function to be wrapped.
            
            Returns:
            
            function - The wrapper function.
        */
        const makeFunction = function(obj, name) {
            return function() {
                let fun = name + '(';

                let separator = '';
                const pLen = arguments.length;
                for (let p = 0; p < pLen; ++p) {
                    fun += separator;
                    fun += debug.stringify(arguments[p]);
                    separator = ',';
                }

                fun += ');';

                let msg = '--> ' + fun;

                debug.writeDebugMessage(msg);

                let returnValue = true;
                let code = 'returnValue = obj(';
                separator = '';
                for (let p = 0; p < pLen; ++p) {
                    code += separator + 'arguments[' + p + ']';
                    separator = ',';
                }
                code += ');';

                eval(code);

                msg = '<-- ' + fun + ' returns ' + debug.stringify(returnValue);
                debug.writeDebugMessage(msg);

                return returnValue;
            }
        }

        /*
            Function: jaxon.debug.verbose.hook
            
            Generate a wrapper function around each of the functions contained within the specified object.
            
            Parameters: 
            
            x - (object):  The object to be scanned.
            base - (string):  The base reference to be prepended to the generated wrapper functions.
        */
        self.hook = function(x, base) {
            for (let m in x) {
                if ('function' === typeof(x[m])) {
                    x[m] = makeFunction(x[m], base + m);
                }
            }
        }

        self.hook(jaxon, 'jaxon.');
        self.hook(jaxon.cmd.node, 'jaxon.cmd.node.');
        self.hook(jaxon.cmd.event, 'jaxon.cmd.event.');
        self.hook(jaxon.cmd.script, 'jaxon.cmd.script.');
        self.hook(jaxon.utils.dom, 'jaxon.utils.dom.');
        self.hook(jaxon.utils.string, 'jaxon.utils.string.');
        self.hook(jaxon.utils.queue, 'jaxon.utils.queue.');
        self.hook(jaxon.utils.upload, 'jaxon.utils.upload.');
        self.hook(jaxon.ajax.callback, 'jaxon.ajax.callback.');
        self.hook(jaxon.ajax.command, 'jaxon.ajax.command.');

        self.isLoaded = true;
    })(jaxon.debug.verbose, jaxon.debug);
});
