/*
    @package jaxon
    @version $Id: jaxon.core.js 327 2007-02-28 16:55:26Z
    @copyright Copyright (c) 2005-2007 by Jared White & J. Max Wilson
    @copyright Copyright (c) 2008-2010 by Joseph Woolley, Steffen Konerow, Jared White  & J. Max Wilson
    @copyright Copyright (c) 2017 by Thierry Feuzeu, Joseph Woolley, Steffen Konerow, Jared White  & J. Max Wilson
    @license https://opensource.org/license/bsd-3-clause/ BSD License
*/

/**
 * Class: jaxon
 */
var jaxon = {
    /**
     * Version number
     */
    version: {
        number: '5.2.5',
    },

    debug: {
        /**
         * Class: jaxon.debug.verbose
         *
         * Provide a high level of detail which can be used to debug hard to find problems.
         */
        verbose: {},
    },

    ajax: {
        callback: {},
        command: {},
        parameters: {},
        request: {},
        response: {},
        upload: {},
    },

    cmd: {
        node: {},
        script: {},
        event: {},
        dialog: {},
    },

    parser: {
        attr: {},
        call: {},
        query: {},
    },

    utils: {
        dom: {},
        form: {},
        queue: {},
        types: {},
        string: {},
        log: {},
    },

    bag: {},

    dom: {},

    dialog: {},

    config: {},
};

/**
 * This object contains all the default configuration settings.
 * These are application level settings; however, they can be overridden by
 * specifying the appropriate configuration options on a per call basis.
 */
(function(self, log) {
    /**
     * An array of header entries where the array key is the header option name and
     * the associated value is the value that will set when the request object is initialized.
     *
     * These headers will be set for both POST and GET requests.
     */
    self.commonHeaders = {
        'If-Modified-Since': 'Sat, 1 Jan 2000 00:00:00 GMT'
    };

    /**
     * An array of header entries where the array key is the header option name and the
     * associated value is the value that will set when the request object is initialized.
     */
    self.postHeaders = {};

    /**
     * An array of header entries where the array key is the header option name and the
     * associated value is the value that will set when the request object is initialized.
     */
    self.getHeaders = {};

    /**
     * true if jaxon should display a wait cursor when making a request, false otherwise.
     */
    self.waitCursor = false;

    /**
     * true if jaxon should log the status to the console during a request, false otherwise.
     */
    self.statusMessages = false;

    /**
     * The base document that will be used throughout the code for locating elements by ID.
     */
    self.baseDocument = document;

    /**
     * The URI that requests will be sent to.
     *
     * @var {string}
     */
    self.requestURI = document.URL;

    /**
     * The request mode.
     * - 'asynchronous' - The request will immediately return.
     *   The response will be processed when (and if) it is received.
     * - 'synchronous' - The request will block, waiting for the response.
     *   This option allows the server to return a value directly to the caller.
     */
    self.defaultMode = 'asynchronous';

    /**
     * The Hyper Text Transport Protocol version designated in the header of the request.
     */
    self.defaultHttpVersion = 'HTTP/1.1';

    /**
     * The content type designated in the header of the request.
     */
    self.defaultContentType = 'application/x-www-form-urlencoded';

    /**
     * The delay time, in milliseconds, associated with the <jaxon.callback.onRequestDelay> event.
     */
    self.defaultResponseDelayTime = 1000;

    /**
     * Always convert the reponse content to json.
     */
    self.convertResponseToJson = true;

    /**
     * The amount of time to wait, in milliseconds, before a request is considered expired.
     * This is used to trigger the <jaxon.callback.onExpiration> event.
     */
    self.defaultExpirationTime = 10000;

    /**
     * The method used to send requests to the server.
     * - 'POST': Generate a form POST request
     * - 'GET': Generate a GET request; parameters are appended to <jaxon.config.requestURI> to form a URL.
     */
    self.defaultMethod = 'POST'; // W3C = Method is case sensitive

    /**
     * The number of times a request should be retried if it expires.
     */
    self.defaultRetry = 5;

    /**
     * The maximum depth of recursion allowed when serializing objects to be sent to the server in a request.
     */
    self.maxObjectDepth = 20;

    /**
     * The maximum number of members allowed when serializing objects to be sent to the server in a request.
     */
    self.maxObjectSize = 2000;

    /**
     * The maximum number of commands allowed in a single response.
     */
    self.commandQueueSize = 1000;

    /**
     * The maximum number of requests that can be processed simultaneously.
     */
    self.requestQueueSize = 1000;

    /**
     * Common options for all HTTP requests to the server.
     */
    self.httpRequestOptions = {
        mode: "no-cors", // *no-cors, cors, same-origin
        cache: "no-cache", // *default, no-cache, reload, force-cache, only-if-cached
        credentials: "same-origin", // include, *same-origin, omit
        redirect: "manual", // manual, *follow, error
    };

    /**
     * Class: jaxon.config.status
     *
     * Provides support for updating the browser's status bar during the request process.
     * By splitting the status bar functionality into an object, the jaxon developer has the opportunity
     * to customize the status bar messages prior to sending jaxon requests.
     */
    self.status = {
        /**
         * A set of event handlers that will be called by the
         * jaxon framework to set the status bar messages.
         *
         * @type {object}
         */
        update: {
            onPrepare: () => log.consoleMode().debug('Sending Request...'),
            onRequest: () => log.consoleMode().debug('Waiting for Response...'),
            onProcessing: () => log.consoleMode().debug('Processing...'),
            onComplete: () => log.consoleMode().debug('Done.'),
        },

        /**
         * A set of event handlers that will be called by the
         * jaxon framework where status bar updates would normally occur.
         *
         * @type {object}
         */
        dontUpdate: {
            onPrepare: () => {},
            onRequest: () => {},
            onProcessing: () => {},
            onComplete: () => {}
        },
    };

    /**
     * Class: jaxon.config.cursor
     *
     * Provides the base functionality for updating the browser's cursor during requests.
     * By splitting this functionality into an object of it's own, jaxon developers can now
     * customize the functionality prior to submitting requests.
     */
    self.cursor = {
        /**
         * Constructs and returns a set of event handlers that will be called by the
         * jaxon framework to effect the status of the cursor during requests.
         *
         * @type {object}
         */
        update: {
            onRequest: () => {
                if (jaxon.config.baseDocument.body) {
                    jaxon.config.baseDocument.body.style.cursor = 'wait';
                }
            },
            onComplete: () => {
                if (jaxon.config.baseDocument.body) {
                    jaxon.config.baseDocument.body.style.cursor = 'auto';
                }
            },
            onFailure: function() {
                if (jaxon.config.baseDocument.body) {
                    jaxon.config.baseDocument.body.style.cursor = 'auto';
                }
            },
        },

        /**
         * Constructs and returns a set of event handlers that will be called by the jaxon framework
         * where cursor status changes would typically be made during the handling of requests.
         *
         * @type {object}
         */
        dontUpdate: {
            onRequest: () => {},
            onComplete: () => {},
            onFailure: () => {},
        },
    };
})(jaxon.config, jaxon.utils.log);

// Make jaxon accessible with the dom.findFunction function.
window.jaxon = jaxon;


/**
 * Class: jaxon.utils.dom
 *
 * global: jaxon
 */

(function(self, types, baseDocument) {
    /**
     * Shorthand for finding a uniquely named element within the document.
     *
     * @param {string} sId - The unique name of the element (specified by the ID attribute)
     *
     * @returns {object} The element found or null.
     *
     * @see <self.$>
     */
    self.$ = (sId) => !sId ? null : (types.isString(sId) ? baseDocument.getElementById(sId) : sId);

    /**
     * Create a div as workspace for the getBrowserHTML() function.
     *
     * @returns {object} The workspace DOM element.
     */
    const _getWorkspace = () => {
        const elWorkspace = self.$('jaxon_temp_workspace');
        if (elWorkspace) {
            return elWorkspace;
        }
        // Workspace not found. Must be created.
        const elNewWorkspace = baseDocument.createElement('div');
        elNewWorkspace.setAttribute('id', 'jaxon_temp_workspace');
        elNewWorkspace.style.display = 'none';
        elNewWorkspace.style.visibility = 'hidden';
        baseDocument.body.appendChild(elNewWorkspace);
        return elNewWorkspace;
    };

    /**
     * Insert the specified string of HTML into the document, then extract it.
     * This gives the browser the ability to validate the code and to apply any transformations it deems appropriate.
     *
     * @param {string} sValue A block of html code or text to be inserted into the browser's document.
     *
     * @returns {string} The (potentially modified) html code or text.
     */
    self.getBrowserHTML = (sValue) => {
        const elWorkspace = _getWorkspace();
        elWorkspace.innerHTML = sValue;
        const browserHTML = elWorkspace.innerHTML;
        elWorkspace.innerHTML = '';
        return browserHTML;
    };

    /**
     * Tests to see if the specified data is the same as the current value of the element's attribute.
     *
     * @param {string|object} element The element or it's unique name (specified by the ID attribute)
     * @param {string} attribute The name of the attribute.
     * @param {string} newData The value to be compared with the current value of the specified element.
     *
     * @returns {true} The specified value differs from the current attribute value.
     * @returns {false} The specified value is the same as the current value.
     */
    self.willChange = (element, attribute, newData) => {
        element = self.$(element);
        return !element ? false : (newData != element[attribute]);
    };

    /**
     * Get the value of an attribute of an object.
     * Can also get the value of a var in an array.
     *
     * @param {object} xElement The object with the attribute.
     * @param {string} sAttrName The attribute name.
     *
     * @returns {mixed}
     */
    self.getAttrValue = (xElement, sAttrName) => {
        if((aMatches = sAttrName.match(/^(.+)\[(\d+)\]$/)) === null)
        {
            return xElement[sAttrName];
        }

        // The attribute is an array in the form "var[indice]".
        sAttrName = aMatches[1];
        const nAttrIndice = parseInt(aMatches[2]);
        return xElement[sAttrName][nAttrIndice];
    }

    /**
     * Find a function using its name as a string.
     *
     * @param {string} sFuncName The name of the function to find.
     * @param {object} context
     *
     * @returns {object|null}
     */
    self.findFunction = (sFuncName, context = window) => {
        const aNames = sFuncName.split(".");
        const nLength = aNames.length;
        for (let i = 0; i < nLength && (context); i++) {
            context = self.getAttrValue(context, aNames[i]);
        }
        return context ?? null;
    };

    /**
     * Find an object using its name as a string.
     *
     * @param {string} sFuncName The name of the object to find.
     * @param {object} context
     *
     * @returns {object|null}
     */
    self.findObject = self.findFunction

    /**
     * Given an element and an attribute with 0 or more dots,
     * get the inner object and the corresponding attribute name.
     *
     * @param {string} sAttrName The attribute name.
     * @param {object=} xElement The outer element.
     *
     * @returns {object|null} The inner object and the attribute name in an object.
     */
    self.getInnerObject = (sAttrName, xElement = window) => {
        const aNames = sAttrName.split('.');
        // Get the last element in the array.
        sAttrName = aNames.pop();
        // Move to the inner object.
        const nLength = aNames.length;
        for (let i = 0; i < nLength && (xElement); i++) {
            // The real name for the "css" object is "style".
            const sRealAttrName = aNames[i] === 'css' ? 'style' : aNames[i];
            xElement = self.getAttrValue(xElement, sRealAttrName);
        }
        return !xElement ? null : { node: xElement, attr: sAttrName };
    };
})(jaxon.utils.dom, jaxon.utils.types, jaxon.config.baseDocument);


/**
 * Class: jaxon.utils.form
 *
 * global: jaxon
 */

(function(self, dom, log) {
    /**
     * @param {string} type
     * @param {string} name
     * @param {string} tagName
     * @param {string} prefix
     * @param {mixed} value
     * @param {boolean} checked
     * @param {boolean} withDisabled
     * @param {boolean} disabled
     *
     * @returns {void}
     */
    const fieldIsInvalid = (type, name, tagName, prefix, value, checked, withDisabled, disabled) =>
        // Do not read value of fields without name, or param fields.
        !name || 'PARAM' === tagName ||
        // Do not read value of disabled fields
        (!withDisabled && disabled) ||
        // Only read values with the given prefix, if provided.
        (prefix.length > 0 && prefix !== name.substring(0, prefix.length)) ||
        // Values of radio and checkbox, when they are not checked, are omitted.
        ((type === 'radio' || type === 'checkbox') && !checked) ||
        // Values of dropdown select, when they are undefined, are omitted.
        ((type === 'select-one' || type === 'select-multiple') && value === undefined) ||
        // Do not read value from file fields.
        type === 'file';

    /**
     * Get the value of a multiple select field.
     *
     * @param {array} options
     *
     * @returns {array}
     */
    const getSelectedValues = (options) => Array.from(options)
        .filter(({ selected }) => selected).map(({ value }) => value);

    /**
     * Get the value of a form field.
     *
     * @param {string} type
     * @param {object} values
     * @param {array} options
     *
     * @returns {mixed}
     */
    const getSimpleFieldValue = (type, value, options) =>
        // For select multiple fields, take the last selected value, as PHP does.
        // Unlike PHP, Javascript will set "value" as the first selected value.
        type !== 'select-multiple' ? value : getSelectedValues(options).pop();

    /**
     * Set the value of a form field.
     *
     * @param {object} values
     * @param {string} varName
     * @param {string} varKeys
     * @param {string} type
     * @param {mixed} falue
     * @param {array} options
     *
     * @returns {void}
     */
    const setFieldValue = (values, varName, varKeys, type, value, options) => {
        const result = { obj: values, key: varName };

        // Parse names into brackets
        const keyRegex = /\[(.*?)\]/;
        while ((matches = varKeys.match(keyRegex)) !== null) {
            // When found, matches is an array with values like "[email]" and "email".
            const key = matches[1].trim();
            if (key === '') {
                // The field is defined as an array in the form (eg users[]).
                const currentValue = result.obj[result.key] ?? [];
                result.obj[result.key] = type === 'select-multiple' ?
                    getSelectedValues(options) : [...currentValue, value];
                // Nested arrays are not supported. So the function returns here.
                return;
            }

            if (result.obj[result.key] === undefined) {
                result.obj[result.key] = {};
            }
            // The field is an object.
            result.obj = result.obj[result.key];
            result.key = key;
            varKeys = varKeys.substring(matches[0].length);
        }

        // The field is an object.
        result.obj[result.key] = getSimpleFieldValue(type, value, options);
    };

    /**
     * @param {object} xOptions
     * @param {object} child
     * @param {string} child.type
     * @param {string} child.name
     * @param {string} child.tagName
     * @param {boolean} child.checked
     * @param {boolean} child.disabled
     * @param {mixed} child.value
     * @param {array} child.options
     *
     * @returns {void}
     */
    const getValue = (xOptions, { type, name, tagName, checked, disabled, value, options }) => {
        const { prefix, formId, withDisabled } = xOptions;

        if (fieldIsInvalid(type, name, tagName, prefix, value, checked, withDisabled, disabled)) {
            return;
        }

        // Check the name validity
        const nameRegex = /^([a-zA-Z_][a-zA-Z0-9_-]*)((\[[a-zA-Z0-9_-]*\])*)$/;
        let matches = name.match(nameRegex);
        if (!matches) {
            // Invalid name
            log.warning(`Invalid field name ${name} in form ${formId}.`);
            log.warning(`The value of the field ${name} in form ${formId} is ignored.`);
            return;
        }

        if (!matches[3]) {
            // No keys into brackets. Simply set the values.
            xOptions.values[name] = getSimpleFieldValue(type, value, options);
            return;
        }

        // Matches is an array with values like user[name][first], "user", "[name][first]" and "[first]".
        const varName = matches[1];
        const varKeys = matches[2];
        // The xOptions.values parameter must be passed by reference.
        setFieldValue(xOptions.values, varName, varKeys, type, value, options);
    };

    /**
     * @param {object} xOptions
     * @param {array} children
     *
     * @returns {void}
     */
    const getValues = (xOptions, children) => {
        children.forEach(child => {
            const { childNodes = null, type } = child;
            if (childNodes !== null && type !== 'select-one' && type !== 'select-multiple') {
                getValues(xOptions, childNodes);
            }
            getValue(xOptions, child);
        });
    };

    /**
     * Build an associative array of form elements and their values from the specified form.
     *
     * @param {string} formId The unique name (id) of the form to be processed.
     * @param {boolean=false} withDisabled (optional): Include form elements which are currently disabled.
     * @param {string=''} prefix (optional): A prefix used for selecting form elements.
     *
     * @returns {object} An associative array of form element id and value.
     */
    self.getValues = (formId, withDisabled = false, prefix = '') => {
        const xOptions = {
            formId,
            // Submit disabled fields
            withDisabled: (withDisabled === true),
            // Only submit fields with a prefix
            prefix: prefix ?? '',
            // Form values
            values: {},
        };

        const { childNodes } = dom.$(formId) ?? {};
        if (childNodes) {
            getValues(xOptions, childNodes);
        }
        return xOptions.values;
    };
})(jaxon.utils.form, jaxon.utils.dom, jaxon.utils.log);


/**
 * Class: jaxon.utils.log
 *
 * global: jaxon
 */

(function(self, dom, types, debug) {
    /**
     * @var object
     */
    const xMode = {
        console: false, // Log in console only.
    };

    /**
     * @returns {void}
     */
    const resetMode = () => {
        xMode.console = false;
    };

    /**
     * @returns {this}
     */
    self.consoleMode = () => {
        xMode.console = true;
        return self;
    };

    /**
     * @param {string} sMessage
     * @param {object=} xContext
     *
     * @returns {string}
     */
    const consoleMessage = (sMessage, xContext) => sMessage +
        (!types.isObject(xContext) ? '' : ': ' + JSON.stringify(xContext));

    /**
     * @returns {object|null}
     */
    self.logger = () => (xMode.console || debug.active || !debug.logger) ?
        null : dom.findObject(debug.logger);

    /**
     * @param {string} sMessage
     * @param {object=} xContext
     *
     * @returns {void}
     */
    self.error = (sMessage, xContext) => {
        console.error(consoleMessage(sMessage, xContext));

        self.logger()?.error(sMessage, { ...xContext });
        resetMode();
    };

    /**
     * @param {string} sMessage
     * @param {object=} xContext
     *
     * @returns {void}
     */
    self.warning = (sMessage, xContext) => {
        console.warn(consoleMessage(sMessage, xContext));

        self.logger()?.warning(sMessage, { ...xContext });
        resetMode();
    };

    /**
     * @param {string} sMessage
     * @param {object=} xContext
     *
     * @returns {void}
     */
    self.notice = (sMessage, xContext) => {
        console.log(consoleMessage(sMessage, xContext));

        self.logger()?.notice(sMessage, { ...xContext });
        resetMode();
    };

    /**
     * @param {string} sMessage
     * @param {object=} xContext
     *
     * @returns {void}
     */
    self.info = (sMessage, xContext) => {
        console.info(consoleMessage(sMessage, xContext));

        self.logger()?.info(sMessage, { ...xContext });
        resetMode();
    };

    /**
     * @param {string} sMessage
     * @param {object=} xContext
     *
     * @returns {void}
     */
    self.debug = (sMessage, xContext) => {
        console.debug(consoleMessage(sMessage, xContext));

        self.logger()?.debug(sMessage, { ...xContext });
        resetMode();
    };
})(jaxon.utils.log, jaxon.utils.dom, jaxon.utils.types, jaxon.debug);


/**
 * Class: jaxon.utils.queue
 *
 * global: jaxon
 */

(function(self) {
    /**
     * Construct and return a new queue object.
     *
     * @param {integer} size The number of entries the queue will be able to hold.
     *
     * @returns {object}
     */
    self.create = size => ({
        start: 0,
        count: 0,
        size: size,
        end: 0,
        elements: [],
        paused: false,
    });

    /**
     * Check id a queue is empty.
     *
     * @param {object} oQueue The queue to check.
     *
     * @returns {boolean}
     */
    self.empty = oQueue => oQueue.count <= 0;

    /**
     * Check id a queue is empty.
     *
     * @param {object} oQueue The queue to check.
     *
     * @returns {boolean}
     */
    self.full = oQueue => oQueue.count >= oQueue.size;

    /**
     * Push a new object into the tail of the buffer maintained by the specified queue object.
     *
     * @param {object} oQueue The queue in which you would like the object stored.
     * @param {object} obj    The object you would like stored in the queue.
     *
     * @returns {integer} The number of entries in the queue.
     */
    self.push = (oQueue, obj) => {
        // No push if the queue is full.
        if(self.full(oQueue)) {
            throw { code: 10003 };
        }

        oQueue.elements[oQueue.end] = obj;
        if(++oQueue.end >= oQueue.size) {
            oQueue.end = 0;
        }
        return ++oQueue.count;
    };

    /**
     * Push a new object into the head of the buffer maintained by the specified queue object.
     *
     * This effectively pushes an object to the front of the queue... it will be processed first.
     *
     * @param {object} oQueue The queue in which you would like the object stored.
     * @param {object} obj    The object you would like stored in the queue.
     *
     * @returns {integer} The number of entries in the queue.
     */
    self.pushFront = (oQueue, obj) => {
        // No push if the queue is full.
        if(self.full(oQueue)) {
            throw { code: 10003 };
        }

        // Simply push if the queue is empty
        if(self.empty(oQueue)) {
            return self.push(oQueue, obj);
        }

        // Put the object one position back.
        if(--oQueue.start < 0) {
            oQueue.start = oQueue.size - 1;
        }
        oQueue.elements[oQueue.start] = obj;
        return ++oQueue.count;
    };

    /**
     * Attempt to pop an object off the head of the queue.
     *
     * @param {object} oQueue The queue object you would like to modify.
     *
     * @returns {object|null}
     */
    self.pop = (oQueue) => {
        if(self.empty(oQueue)) {
            return null;
        }

        let obj = oQueue.elements[oQueue.start];
        delete oQueue.elements[oQueue.start];
        if(++oQueue.start >= oQueue.size) {
            oQueue.start = 0;
        }
        oQueue.count--;
        return obj;
    };

    /**
     * Attempt to pop an object off the head of the queue.
     *
     * @param {object} oQueue The queue object you would like to modify.
     *
     * @returns {object|null}
     */
    self.peek = (oQueue) => {
        if(self.empty(oQueue)) {
            return null;
        }
        return oQueue.elements[oQueue.start];
    };
})(jaxon.utils.queue);


/**
 * Class: jaxon.utils.string
 *
 * global: jaxon
 */

(function(self) {
    /**
     * Replace all occurances of the single quote character with a double quote character.
     *
     * @param {string=} haystack The source string to be scanned
     *
     * @returns {string|false} A new string with the modifications applied. False on error.
     */
    self.doubleQuotes = haystack => haystack === undefined ?
        false : haystack.replace(new RegExp("'", 'g'), '"');

    /**
     * Replace all occurances of the double quote character with a single quote character.
     *
     * @param {string=} haystack The source string to be scanned
     *
     * @returns {string|false} A new string with the modification applied
     */
    self.singleQuotes = haystack => haystack === undefined ?
        false : haystack.replace(new RegExp('"', 'g'), "'");

    /**
     * Detect, and if found, remove the prefix 'on' from the specified string.
     * This is used while working with event handlers.
     *
     * @param {string} sEventName The string to be modified
     *
     * @returns {string} The modified string
     */
    self.stripOnPrefix = (sEventName) => {
        sEventName = sEventName.toLowerCase();
        return sEventName.indexOf('on') === 0 ? sEventName.replace(/on/, '') : sEventName;
    };

    /**
     * Detect, and add if not found, the prefix 'on' from the specified string.
     * This is used while working with event handlers.
     *
     * @param {string} sEventName The string to be modified
     *
     * @returns {string} The modified string
     */
    self.addOnPrefix = (sEventName) => {
        sEventName = sEventName.toLowerCase();
        return sEventName.indexOf('on') !== 0 ? 'on' + sEventName : sEventName;
    };

    /**
     * String functions for Jaxon
     * See http://javascript.crockford.com/remedial.html for more explanation
     */
    /**
     * Substitute variables in the string
     *
     * @param {string} str The string to substitute
     * @param {object} values The substitution values
     *
     * @returns {string}
     */
    self.supplant = (str, values) => str.replace(
        /\{([^{}]*)\}/g,
        (a, b) => {
            const t = typeof values[b];
            return t === 'string' || t === 'number' ? values[b] : a;
        }
    );
})(jaxon.utils.string);


/**
 * Class: jaxon.utils.types
 *
 * global: jaxon
 */

(function(self) {
    /**
     * Get the type of an object.
     * Unlike typeof, this function distinguishes objects from arrays.
     *
     * @param {mixed} xVar The var to check
     *
     * @returns {string}
     */
    self.of = (xVar) => Object.prototype.toString.call(xVar).slice(8, -1).toLowerCase();

    /**
     * Check if a var is an object.
     *
     * @param {mixed} xVar The var to check
     *
     * @returns {bool}
     */
    self.isObject = (xVar) => self.of(xVar) === 'object';

    /**
     * Check if a var is an array.
     *
     * @param {mixed} xVar The var to check
     *
     * @returns {bool}
     */
    self.isArray = (xVar) => self.of(xVar) === 'array';

    /**
     * Check if a var is a string.
     *
     * @param {mixed} xVar The var to check
     *
     * @returns {bool}
     */
    self.isString = (xVar) => self.of(xVar) === 'string';

    /**
     * Check if a var is a function.
     *
     * @param {mixed} xVar The var to check
     *
     * @returns {bool}
     */
    self.isFunction = (xVar) => self.of(xVar) === 'function';

    /**
     * Convert to int.
     *
     * @param {string} sValue
     *
     * @returns {integer|null}
     */
    self.toInt = (sValue) => {
        const nValue = parseInt(sValue);
        return isNaN(nValue) ? null : nValue;
    };

    if (!Array.prototype.top) {
        /**
         * Get the last element in an array
         *
         * @returns {mixed}
         */
        Array.prototype.top = function() {
            return this.length > 0 ? this[this.length - 1] : undefined;
        };
    };
})(jaxon.utils.types);


/**
 * Class: jaxon.dialog
 *
 * global: jaxon
 */

(function(self, dom, attr, call, query, types, log) {
    /**
     * Config data.
     *
     * @var {object}
     */
    const config = {
        labels: {
            confirm: {
                yes: 'Yes',
                no: 'No',
            },
        },
        options: {},
        defaults: {},
    };

    /**
     * Dialog libraries.
     *
     * @var {object}
     */
    const libs = {};

    /**
     * Set the dialog config
     *
     * @param {object} config
     * @param {object} config.labels The translated labels
     * @param {object} config.options The libraries options
     * @param {object} config.defaults The libraries options
     */
    self.config = ({ labels, options = {}, defaults = {} }) => {
        // Set the libraries options.
        if (types.isObject(options)) {
            config.options = options;
        }

        // Set the confirm labels
        config.labels.confirm = {
            ...config.labels.confirm,
            ...labels.confirm,
        };

        // Set the default libraries
        config.defaults = {
            ...config.defaults,
            ...defaults,
        };
    };

    /**
     * Find a library to execute a given function.
     *
     * @param {string} sLibName The dialog library name
     * @param {string} sFunc The dialog library function
     *
     * @returns {object}
     */
    const getLib = (sLibName, sFunc) => {
        if (libs[sLibName] && libs[sLibName][sFunc]) {
            return libs[sLibName];
        }

        !libs[sLibName] ?
            log.warning(`Unable to find a dialog library with name "${sLibName}".`) :
            log.warning(`The chosen dialog library doesn't implement the "${sFunc}" function.`);

        // Check if there is a default library in the config for the required feature.
        const sLibType = sFunc === 'show' || sFunc === 'hide' ? 'modal' : sFunc;
        if (config.defaults[sLibType]) {
            return libs[config.defaults[sLibType]];
        }
        if (!libs.default[sFunc]) {
            log.error(`Unable to find a dialog library with the "${sFunc}" function.`);
        }
        return libs.default;
    };

    /**
     * Show an alert message using a dialog library.
     *
     * @param {string} sLibName The dialog library to use
     * @param {object} message The message in the command
     * @param {string} message.type The message type
     * @param {string} message.text The message text
     * @param {string=} message.title The message title
     *
     * @returns {void}
     */
    self.alert = (sLibName, { type, title = '', text: message }) =>
        getLib(sLibName, 'alert').alert({ type, message, title });

    /**
     * Call a function after user confirmation.
     *
     * @param {string} sLibName The dialog library to use
     * @param {object} question The question in the command
     * @param {string} question.text The question text
     * @param {string=} question.title The question title
     * @param {object} callback The callbacks to call after the question is answered
     *
     * @returns {void}
     */
    self.confirm = (sLibName, { title = '', text: question }, callback) =>
        getLib(sLibName, 'confirm').confirm({ question, title }, callback);

    /**
     * Show a dialog window.
     *
     * @param {string} sLibName The dialog library to use
     * @param {object} dialog The dialog content
     *
     * @returns {true} The operation completed successfully.
     */
    self.show = (sLibName, dialog) => {
        const xLib = getLib(sLibName, 'show');
        xLib.show && xLib.show(dialog, (xDialogDom) => xDialogDom && attr.process(xDialogDom));
        return true;
    };

    /**
     * Hide a dialog window.
     *
     * @param {string} sLibName The dialog library to use
     *
     * @returns {true} The operation completed successfully.
     */
    self.hide = (sLibName) => {
        const xLib = getLib(sLibName, 'hide');
        xLib.hide && xLib.hide();
        return true;
    };

    /**
     * Create a unique id
     *
     * @param {int} nLength The unique id length
     *
     *  @returns {string}
     */
    const createUniqueId = (nLength) => {
        const chars = "abcdefghijklmnopqrstuvwxyz";
        let result = "";
        for (let i = 0; i < nLength; i++) {
            result += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return result;
    };

    /**
     * Register a dialog library.
     *
     * @param {string} sLibName The library name
     * @param {callback} xCallback The library definition callback
     *
     * @returns {void}
     */
    self.register = (sLibName, xCallback) => {
        // Create an object for the library
        libs[sLibName] = {};
        // Define the library functions
        const { labels: { confirm: labels }, options: oOptions } = config;
        // Check that the provided library option is an object,
        // and add the labels to the provided options.
        const options = {
            labels,
            ...(types.isObject(oOptions[sLibName]) ? oOptions[sLibName] : {}),
        };
        // Provide some utility functions to the dialog library.
        const utils = {
            ...types,
            ready: dom.ready,
            js: call.execExpr,
            jq: query.select,
            createUniqueId,
        };
        xCallback(libs[sLibName], options, utils);
    };

    /**
     * Default dialog plugin, based on js alert and confirm functions
     */
    self.register('default', (lib) => {
        /**
         * Get the content of a dialog.
         *
         * @param {string} sTitle The message title
         * @param {string} sContent The message content
         *
         * @returns {void}
         */
        const dialogContent = (sTitle, sContent) =>
            !sTitle ? sContent : sTitle.toUpperCase() + "\n" + sContent;

        /**
         * Show an alert message
         *
         * @param {object} alert The alert parameters
         * @param {string} alert.message The alert message
         * @param {string} alert.title The alert title
         *
         * @returns {void}
         */
        lib.alert = ({ message, title }) => alert(dialogContent(title, message));

        /**
         * Ask a confirm question to the user.
         *
         * @param {object} confirm The confirm parameters
         * @param {string} confirm.question The question to ask
         * @param {string} confirm.title The question title
         * @param {object} callback The confirm callbacks
         * @param {callback} callback.yes The function to call if the answer is yes
         * @param {callback=} callback.no The function to call if the answer is no
         *
         * @returns {void}
         */
        lib.confirm = ({ question, title}, { yes: yesCb, no: noCb }) =>
            confirm(dialogContent(title, question)) ? yesCb() : (noCb && noCb());
    });
})(jaxon.dialog, jaxon.dom, jaxon.parser.attr, jaxon.parser.call,
    jaxon.parser.query, jaxon.utils.types, jaxon.utils.log);


/**
 * Class: jaxon.dom
 *
 * global: jaxon
 */

/**
 * Plain javascript replacement for jQuery's .ready() function.
 * See https://github.com/jfriend00/docReady for a detailed description, copyright and license information.
 */
(function(self) {
    "use strict";

    let readyList = [];
    let readyFired = false;
    let readyEventHandlersInstalled = false;

    /**
     * Call this when the document is ready.
     * This function protects itself against being called more than once
     */
    const ready = () => {
        if (readyFired) {
            return;
        }
        // this must be set to true before we start calling callbacks
        readyFired = true;
        // if a callback here happens to add new ready handlers,
        // the jaxon.dom.ready() function will see that it already fired
        // and will schedule the callback to run right after
        // this event loop finishes so all handlers will still execute
        // in order and no new ones will be added to the readyList
        // while we are processing the list
        readyList.forEach(cb => cb.fn.call(window, cb.ctx));
        // allow any closures held by these functions to free
        readyList = [];
    }

    // Was used with the document.attachEvent() function.
    // const readyStateChange = () => document.readyState === "complete" && ready();

    /**
     * This is the one public interface
     * jaxon.dom.ready(fn, context);
     * The context argument is optional - if present, it will be passed as an argument to the callback
     */
    self.ready = function(callback, context) {
        // if ready has already fired, then just schedule the callback
        // to fire asynchronously, but right away
        if (readyFired) {
            setTimeout(function() { callback(context); }, 1);
            return;
        }
        // add the function and context to the list
        readyList.push({ fn: callback, ctx: context });
        // if document already ready to go, schedule the ready function to run
        if (document.readyState === "complete" ||
            (!document.attachEvent && document.readyState === "interactive")) {
            setTimeout(ready, 1);
            return;
        }
        if (!readyEventHandlersInstalled) {
            // first choice is DOMContentLoaded event
            document.addEventListener("DOMContentLoaded", ready, false);
            // backup is window load event
            window.addEventListener("load", ready, false);

            readyEventHandlersInstalled = true;
        }
    }
})(jaxon.dom);


/**
 * Class: jaxon.parser.attr
 *
 * Process Jaxon custom HTML attributes
 *
 * global: jaxon
 */

(function(self, event) {
    /**
     * The DOM nodes associated to Jaxon components
     *
     * @var {object}
     */
    const xBindings = {
        nodes: {},
    };

    /**
     * The default component item name
     *
     * @var {string}
     */
    const sDefaultComponentItem = 'main';

    /**
     * Reset the DOM nodes bindings.
     *
     * @returns {void}
     */
    self.reset = () => xBindings.nodes = {};

    /**
     * Find the DOM nodes with a given attribute
     *
     * @param {Element} xContainer A DOM node
     * @param {string} sAttr The attribute to check for
     * @param {bool} bScopeIsOuter Also check the outer element
     *
     * @returns {array}
     */
    const findNodesWithAttr = (xContainer, sAttr, bScopeIsOuter) => {
        // Some js functions return nodes without the querySelectorAll() function.
        if (!xContainer.querySelectorAll) {
            return [];
        }

        // When using the outerHTML attribute, the container node may also be returned.
        const aNodes = Array.from(xContainer.querySelectorAll(`:scope [${sAttr}]`));
        return bScopeIsOuter && xContainer.hasAttribute(sAttr) ? [xContainer, ...aNodes] : aNodes;
    };

    /**
     * @param {Element} xContainer A DOM node.
     * @param {bool} bScopeIsOuter Process the outer HTML content
     *
     * @returns {void}
     */
    const setClickHandlers = (xContainer, bScopeIsOuter) =>
        findNodesWithAttr(xContainer, 'jxn-click', bScopeIsOuter)
            .forEach(xNode => {
                const oHandler = JSON.parse(xNode.getAttribute('jxn-click'));
                event.setEventHandler({ event: 'click', func: oHandler }, { target: xNode });
            });

    /**
     * @param {Element} xTarget The event handler target.
     * @param {Element} xNode The DOM node with the attributes.
     * @param {string} sAttr The event attribute name
     *
     * @returns {void}
     */
    const setEventHandler = (xTarget, xNode, sAttr) => {
        if(!xNode.hasAttribute('jxn-call'))
        {
            return;
        }

        const sEvent = xNode.getAttribute(sAttr).trim();
        const oHandler = JSON.parse(xNode.getAttribute('jxn-call'));
        // Set the event handler on the node.
        event.setEventHandler({ event: sEvent, func: oHandler }, { target: xTarget });
    };

    /**
     * @param {Element} xContainer A DOM node.
     * @param {bool} bScopeIsOuter Process the outer HTML content
     *
     * @returns {void}
     */
    const setEventHandlers = (xContainer, bScopeIsOuter) =>
        findNodesWithAttr(xContainer, 'jxn-on', bScopeIsOuter)
            .forEach(xNode => setEventHandler(xNode, xNode, 'jxn-on'));

    /**
     * @param {Element} xParent The parent node
     * @param {string} sSelector The child selector
     * @param {string} sEvent The event name
     * @param {object} oHandler The event handler
     *
     * @returns {void}
     */
    const setChildEventHandler = (xParent, sSelector, sEvent, oHandler) => {
        xParent.querySelectorAll(`:scope ${sSelector}`).forEach(xChild => {
            // Set the event handler on the child node.
            event.setEventHandler({ event: sEvent, func: oHandler }, { target: xChild });
        });
    };

    /**
     * @param {Element} xContainer A DOM node.
     * @param {bool} bScopeIsOuter Process the outer HTML content
     *
     * @returns {void}
     */
    const setChildEventHandlers = (xContainer, bScopeIsOuter) =>
        findNodesWithAttr(xContainer, 'jxn-event', bScopeIsOuter)
            .forEach(xTarget => {
                const aEvents = JSON.parse(xTarget.getAttribute('jxn-event'));
                aEvents?.forEach(({ select, event, handler }) => {
                    setChildEventHandler(xTarget, select, event, handler);
                });
            });

    /**
     * @param {Element} xNode A DOM node.
     * @param {string} sComponentName The component name
     * @param {string=} sComponentItem The component item
     *
     * @returns {void}
     */
    self.bind = (xNode, sComponentName, sComponentItem) => {
        if (!sComponentItem) {
            sComponentItem = sDefaultComponentItem;
        }
        xBindings.nodes[`${sComponentName}_${sComponentItem}`] = xNode;
    };

    /**
     * @param {Element} xContainer A DOM node.
     * @param {bool} bScopeIsOuter Process the outer HTML content
     *
     * @returns {void}
     */
    const bindNodesToComponents = (xContainer, bScopeIsOuter) =>
        findNodesWithAttr(xContainer, 'jxn-bind', bScopeIsOuter).forEach(xNode =>
            self.bind(xNode, xNode.getAttribute('jxn-bind'), xNode.getAttribute('jxn-item')));

    /**
     * Process the custom attributes in a given DOM node.
     *
     * @param {Element} xContainer A DOM node.
     * @param {bool} bScopeIsOuter Process the outer HTML content
     *
     * @returns {void}
     */
    self.process = (xContainer = document, bScopeIsOuter = false) => {
        // Set event handlers on nodes
        setChildEventHandlers(xContainer, bScopeIsOuter);
        // Set event handlers on nodes
        setEventHandlers(xContainer, bScopeIsOuter);
        // Set event handlers on nodes
        setClickHandlers(xContainer, bScopeIsOuter);
        // Attach DOM nodes to Jaxon components
        bindNodesToComponents(xContainer, bScopeIsOuter);
    };

    /**
     * Find the DOM node a given component is bound to.
     *
     * @param {string} sComponentName The component name.
     * @param {string} sComponentItem The component item.
     *
     * @returns {Element|null}
     */
    const findComponentNode = (sComponentName, sComponentItem) => {
        const sSelector = `[jxn-bind="${sComponentName}"][jxn-item="${sComponentItem}"]`;
        const xNode = document.querySelector(sSelector);
        if (xNode !== null) {
            xBindings.nodes[`${sComponentName}_${sComponentItem}`] = xNode;
        }
        return xNode;
    };

    /**
     * Get the DOM node of a given component.
     *
     * @param {string} sComponentName The component name.
     * @param {string=} sComponentItem The component item.
     *
     * @returns {Element|null}
     */
    self.node = (sComponentName, sComponentItem = sDefaultComponentItem) => {
        const xComponent = xBindings.nodes[`${sComponentName}_${sComponentItem}`] ?? null;
        if (xComponent !== null && xComponent.isConnected) {
            return xComponent;
        }

        if (xComponent !== null) {
            // The component is no more bound to the DOM
            delete xBindings.nodes[`${sComponentName}_${sComponentItem}`];
        }
        // Try to find the component
        return findComponentNode(sComponentName, sComponentItem);
    };
})(jaxon.parser.attr, jaxon.cmd.event);


/**
 * Class: jaxon.parser.call
 *
 * Execute calls from json expressions.
 *
 * global: jaxon
 */

(function(self, query, dialog, dom, string, form, types, log) {
    /**
     * The comparison operators.
     *
     * @var {object}
     */
    const xComparators = {
        eq: (xLeftArg, xRightArg) => xLeftArg == xRightArg,
        teq: (xLeftArg, xRightArg) => xLeftArg === xRightArg,
        ne: (xLeftArg, xRightArg) => xLeftArg != xRightArg,
        nte: (xLeftArg, xRightArg) => xLeftArg !== xRightArg,
        gt: (xLeftArg, xRightArg) => xLeftArg > xRightArg,
        ge: (xLeftArg, xRightArg) => xLeftArg >= xRightArg,
        lt: (xLeftArg, xRightArg) => xLeftArg < xRightArg,
        le: (xLeftArg, xRightArg) => xLeftArg <= xRightArg,
        ty: (xLeftArg) => !!xLeftArg,
        fy: (xLeftArg) => !xLeftArg,
        error: () => false, // The default comparison operator.
    };

    /**
     * Get or set an attribute on a parent object.
     *
     * @param {object|null} xParent The parent object
     * @param {string} sName The attribute name
     * @param {mixed} xValue If defined, the value to set
     * @param {object} xOptions The call options.
     *
     * @var {object}
     */
    const processAttr = (xParent, sName, xValue, xOptions) => {
        if (!xParent) {
            return undefined;
        }
        const xElt = dom.getInnerObject(sName, xParent);
        if (!xElt) {
            return undefined;
        }
        if (xValue !== undefined) {
            // Assign an attribute.
            xElt.node[xElt.attr] = getValue(xValue, xOptions);
        }
        return xElt.node[xElt.attr];
    };

    /**
     * The call commands
     *
     * @var {object}
     */
    const xCommands = {
        select: ({ _name: sName, mode, context: xSelectContext = null }, xOptions) => {
            if (sName === 'this') {
                const { context: { target: xTarget } = {} } = xOptions;
                return mode === 'jq' ?
                    { call: '$(this)', value: query.select(xTarget) } :
                    { call: 'this', value: mode === 'js' ? xTarget : null };
            }

            const { context: { global: xGlobal } = {} } = xOptions;
            return mode === 'jq' ? {
                call: `$('${sName}')`,
                value: query.select(sName, query.context(xSelectContext, xGlobal)),
            } : {
                call: `document.getElementById('${sName}')`,
                value: document.getElementById(sName),
            };
        },
        event: ({ _name: sName, mode, func: xExpression }, xOptions) => {
            // Set an event handler.
            // Takes the expression with a different context as argument.
            const fHandler = (event) => execExpression(xExpression, {
                ...xOptions,
                context: {
                    ...xOptions.context,
                    event,
                    target: event.currentTarget,
                },
                value: null,
            });
            const { value: xCurrValue, call: sCall } = xOptions;
            mode === 'jq' ? xCurrValue.on(sName, fHandler) :
                xCurrValue.addEventListener(sName, fHandler);
            return {
                call: mode === 'jq' ? `${sCall}.on(${sName}, [handler])` :
                    `${sCall}.addEventListener(${sName}, [handler])`,
                value: xCurrValue,
            };
        },
        func: ({ _name: sName, args: aArgs = [] }, xOptions) => {
            const { value: xCurrValue, call: sCall } = xOptions;
            const sFuncName = (!sCall ? sName : `${sCall}.${sName}`) + '()';
            const func = dom.findFunction(sName, xCurrValue || window);
            if (!func) {
                if (sName === 'trim') {
                    return { call: `trim(${sName})`, value: xCurrValue.trim() };
                }
                if (sName === 'toInt') {
                    return { call: `parseInt(${sName})`, value: types.toInt(xCurrValue) };
                }

                // Tried to call an undefined function.
                log.error(`Call to undefined function ${sFuncName}.`);
                return { call: sFuncName, value: undefined };
            }

            return {
                call: sFuncName,
                value: func.apply(xCurrValue, getArgs(aArgs, xOptions)),
            };
        },
        attr: ({ _name: sName, value: xValue }, xOptions) => {
            const { value: xCurrValue, depth, call: sCall } = xOptions;
            // depth === 0 ensures that we are at top level.
            if (depth === 0 && (sName === 'window' || sName === '')) {
                if (!xValue) {
                    return { call: 'window', value: window };
                }
                log.error('Cannot assign the "window" var.');
                return { call: 'null', value: null };
            }

            const sAttrName = !sCall ? sName : `${sCall}.${sName}`;
            const xAttrValue = processAttr(xCurrValue || window, sName, xValue, xOptions);
            if (xAttrValue === undefined) {
                log.error(`Call to undefined variable ${sAttrName}.`);
            }
            return { call: sAttrName, value: xAttrValue };
        },
        invalid: (xCall) => {
            log.error('Invalid command: ' + JSON.stringify({ call: xCall }));
            return { call: undefined, value: undefined };
        },
        unknown: (xCall) => {
            log.error('Unknown command: ' + JSON.stringify({ call: xCall }));
            return { call: undefined, value: undefined };
        },
    };

    /**
     * Check if an argument is an expression.
     *
     * @param {mixed} xArg
     *
     * @returns {boolean}
     */
    const isValidCall = xArg => types.isObject(xArg) && !!xArg._type;

    /**
     * Get the value of a single argument.
     *
     * @param {object} xArg
     * @param {string} sValue
     *
     * @returns {mixed}
     */
    const getFinalValue = (xArg, sValue) => {
        const { trim, toInt } = xArg;
        if (trim) {
            sValue = sValue.trim();
        }
        if (toInt) {
            sValue = types.toInt(sValue);
        }
        return sValue;
    };

    /**
     * Get the value of a single argument.
     *
     * @param {mixed} xArg
     * @param {object} xOptions The call options.
     *
     * @returns {mixed}
     */
    const getValue = (xArg, xOptions) => {
        if (!isValidCall(xArg)) {
            return xArg;
        }
        const { _type: sType, _name: sName } = xArg;
        switch(sType) {
            case 'form': return form.getValues(sName);
            case 'html': return getFinalValue(xArg, dom.$(sName).innerHTML);
            case 'input': return getFinalValue(xArg, dom.$(sName).value);
            case 'checked': return dom.$(sName).checked;
            case 'expr': return execExpression(xArg, makeOptions(xOptions));
            default: return undefined;
        }
    };

    /**
     * Get the values of an array of arguments.
     *
     * @param {array} aArgs
     * @param {object} xOptions The call options.
     *
     * @returns {array}
     */
    const getArgs = (aArgs, xOptions) => aArgs.map(xArg => getValue(xArg, xOptions));

    /**
     * Execute a command, then fill and return the options object.
     * This function is extended in debug mode to add debug messages.
     *
     * @param {object} xCall
     * @param {object} xOptions The call options.
     *
     * @returns {void}
     */
    self.execCommand = (xCall, xOptions) => {
        const xCommand = !isValidCall(xCall) ? xCommands.invalid :
            (xCommands[xCall._type] ?? xCommands.unknown);
        xOptions.depth++; // Increment the call depth.
        const { call, value } = xCommand(xCall, xOptions);
        xOptions.call = call;
        xOptions.value = value;
        return xOptions;
    };

    /**
     * Execute a single call.
     *
     * @param {object} xCall
     * @param {object} xOptions The call options.
     *
     * @returns {void}
     */
    const execCall = (xCall, xOptions) => self.execCommand(xCall, xOptions).value;

    /**
     * Get the options for a json call.
     *
     * @param {object} xContext The context to execute calls in.
     * @param {boolean} xContext.component Take the target component as call target
     * @param {object=} xContext.target The target component
     * @param {object=} xContext.event The trigger event
     *
     * @returns {object}
     */
    const getOptions = ({ component, target, event }) => {
        const global = component ? (target ?? null) : null;
        return { context: { global, target, event }, value: null };
    };

    /**
     * Make the options for a new expression call.
     *
     * @param {object} xOptions The current options.
     *
     * @returns {object}
     */
    const makeOptions = (xOptions) => ({ ...xOptions, value: null });

    /**
     * Execute a single javascript function call.
     *
     * @param {object} xCall An object representing the function call
     * @param {object=} xContext The context to execute calls in.
     *
     * @returns {mixed}
     */
    self.execCall = (xCall, xContext = {}) => {
        const xOptions = getOptions(xContext);
        xOptions.depth = -1; // The first call must start with depth at 0.
        return types.isObject(xCall) && execCall(xCall, xOptions);
    };

    /**
     * Execute the javascript code represented by an expression object.
     * If a call returns "undefined", it will be the final return value.
     *
     * @param {array} aCalls The calls to execute
     * @param {object} xOptions The call options.
     *
     * @returns {mixed}
     */
    const execCalls = (aCalls, xOptions) => {
        xOptions.depth = -1; // The first call must start with depth at 0.
        return aCalls.reduce((xValue, xCall) =>
            xValue === undefined ? undefined : execCall(xCall, xOptions), null);
    };

    /**
     * Replace placeholders in a given string with values
     * 
     * @param {object} phrase
     * @param {string} phrase.str The string to be processed
     * @param {array} phrase.args The values for placeholders
     * @param {object=} xOptions The call options.
     *
     * @returns {string}
     */
    const makePhrase = ({ str, args }, xOptions) =>
        string.supplant(str, args.reduce((oArgs, xArg, nIndex) =>
            ({ ...oArgs, [nIndex + 1]: getValue(xArg, xOptions) }), {}));

    /**
     * Replace placeholders in a given string with values
     * 
     * @param {object} phrase
     *
     * @returns {string}
     */
    self.makePhrase = (phrase) => makePhrase(phrase, { context: { } });

    /**
     * Show an alert message
     *
     * @param {object} alert The alert content
     * @param {string} alert.lib The dialog library to use
     * @param {array} alert.message The message to show
     * @param {object} xOptions The call options.
     *
     * @returns {void}
     */
    const showAlert = ({ lib, message } = {}, xOptions) => !!message &&
        dialog.alert(lib, {
            ...message,
            text: makePhrase(message.phrase, xOptions),
        });

    /**
     * @param {object} confirm The confirmation question
     * @param {string} confirm.lib The dialog library to use
     * @param {array} confirm.question The question to ask
     * @param {object=} xAlert The alert to show if the user anwsers no to the question
     * @param {array} aCalls The calls to execute
     * @param {object} xOptions The call options.
     *
     * @returns {boolean}
     */
    const execWithConfirmation = ({ lib, question }, xAlert, aCalls, xOptions) =>
        dialog.confirm(lib, {
            ...question,
            text: makePhrase(question.phrase, makeOptions(xOptions)),
        }, {
            yes: () => execCalls(aCalls, makeOptions(xOptions)),
            no: () => showAlert(xAlert, makeOptions(xOptions)),
        });

    /**
     * @param {array} aCondition The condition to chek
     * @param {object=} xAlert The alert to show if the condition is not met
     * @param {array} aCalls The calls to execute
     * @param {object} xOptions The call options.
     *
     * @returns {boolean}
     */
    const execWithCondition = (aCondition, xAlert, aCalls, xOptions) => {
        const [sOperator, _xLeftArg, _xRightArg] = aCondition;
        const xComparator = xComparators[sOperator] ?? xComparators.error;
        const xLeftArg = getValue(_xLeftArg, makeOptions(xOptions));
        const xRightArg = getValue(_xRightArg, makeOptions(xOptions));
        xComparator(xLeftArg, xRightArg) ?
            execCalls(aCalls, makeOptions(xOptions)) :
            showAlert(xAlert, makeOptions(xOptions));
    };

    /**
     * @var {object} The debounce timers
     */
    const xDebounceTimers = {};

    /**
     * Execute the javascript code represented by an expression object.
     *
     * @param {object} xExpression
     * @param {object} xOptions The call options.
     *
     * @returns {mixed}
     */
    const execExpression = (xExpression, xOptions) => {
        const { debounce: { label = null, interval = null } = {} } = xExpression;
        if(label !== null && interval !== null) {
            if(xDebounceTimers[label] !== undefined) {
                clearTimeout(xDebounceTimers[label]);
            }
            xExpression.debounce = undefined;
            const handler = () => execExpression(xExpression, xOptions);
            xDebounceTimers[label] = setTimeout(handler, interval);
            return;
        }

        const { calls, confirm, condition, alert } = xExpression;
        if((confirm)) {
            execWithConfirmation(confirm, alert, calls, xOptions);
            return;
        }
        if((condition)) {
            execWithCondition(condition, alert, calls, xOptions);
            return;
        }
        return execCalls(calls, xOptions);
    };

    /**
     * Execute the javascript code represented by an expression object.
     *
     * @param {object} xExpression An object representing a command
     * @param {object=} xContext The context to execute calls in.
     *
     * @returns {void}
     */
    self.execExpr = (xExpression, xContext = {}) => types.isObject(xExpression) &&
        execExpression(xExpression, getOptions(xContext));
})(jaxon.parser.call, jaxon.parser.query, jaxon.dialog, jaxon.utils.dom,
    jaxon.utils.string, jaxon.utils.form, jaxon.utils.types, jaxon.utils.log);


/**
 * Class: jaxon.parser.query
 *
 * global: jaxon
 */

(function(self) {
    /**
     * The selector function.
     *
     * @var {object}
     */
    self.jq = null;

    /**
     * Make the context for a DOM selector
     *
     * @param {mixed} xSelectContext
     * @param {object} xTarget
     *
     * @returns {object}
     */
    self.context = (xSelectContext, xTarget) => {
        if (!xSelectContext) {
            return xTarget;
        }
        if (!xTarget) {
            return self.select(xSelectContext).first();
        }
        return self.select(xSelectContext, xTarget).first();
    };

    /**
     * Call the DOM selector
     *
     * @param {string|object} xSelector
     * @param {object} xContext
     *
     * @returns {object}
     */
    self.select = (xSelector, xContext = null) => {
        // window.chibi is the ChibiJs (https://github.com/kylebarrow/chibi) selector function.
        if (!self.jq) {
            // Chibi is used only when jQuery is not loaded in the page.
            self.jq = window.jQuery ?? window.chibi;
        }
        return !xContext ? self.jq(xSelector) : self.jq(xSelector, xContext);
    };
})(jaxon.parser.query);


/**
 * Class: jaxon.ajax.callback
 *
 * global: jaxon
 */

(function(self, types, log, config) {
    /**
     * The names of the available callbacks.
     *
     * @var {array}
     */
    const aCallbackNames = ['onInitialize', 'onProcessParams', 'onPrepare',
        'onRequest', 'onResponseDelay', 'onExpiration', 'onResponseReceived',
        'onProcessing', 'onFailure', 'onRedirect', 'onSuccess', 'onComplete'];

    /**
     * @param {integer} iDelay The amount of time in milliseconds to delay.
     *
     * @returns {object}
     */
    const setupTimer = (iDelay) => ({ timer: null, delay: iDelay });

    /**
     * Create timers that will be used fire the onRequestDelay and onExpiration events..
     *
     * @param {integer=} responseDelayTime
     * @param {integer=} expirationTime
     *
     * @returns {object}
     */
    self.timers = (responseDelayTime, expirationTime) => ({
        onResponseDelay: setupTimer(responseDelayTime ?? config.defaultResponseDelayTime),
        onExpiration: setupTimer(expirationTime ?? config.defaultExpirationTime),
    });

    /**
     * Create a blank callback object.
     * Two optional arguments let you set the delay time for the onResponseDelay and onExpiration events.
     *
     * @param {integer=} responseDelayTime
     * @param {integer=} expirationTime
     *
     * @returns {object} The callback object.
     */
    self.create = (responseDelayTime, expirationTime) => ({
        timers: self.timers(responseDelayTime, expirationTime),
    });

    /**
     * Get the callbacks from the oRequest.callback property.
     *
     * @param {object} oRequest
     * @param {mixed} oRequest.callback
     * @param {object} oRequest.func
     *
     * @returns {array}
     */
    const getRequestCallbacks = ({ callback: xCallbacks, func }) => {
        if (!xCallbacks) {
            return [];
        }
        if (types.isArray(xCallbacks)) {
            return xCallbacks;
        }
        if (types.isObject(xCallbacks)) {
            return [xCallbacks];
        }
        log.warning(`Invalid callback value ignored on request to ${func.name}.`);
        return [];
    };

    /**
     * Make a callback object with the callback functions defined in the request object by their own name.
     *
     * @param {object} oRequest
     *
     * @returns {array}
     */
    const getRequestCallbackByNames = (oRequest) => {
        // Check if any callback is defined in the request object by its own name.
        const oCallback = self.create();
        aCallbackNames.forEach(sName => {
            if (types.isFunction(oRequest[sName])) {
                oCallback[sName] = oRequest[sName];
                delete oRequest[sName];
            }
        });
        return Object.keys(oCallback).length > 1 ? [oCallback] : [];
    };

    /**
     * Move all the callbacks defined directly in the oRequest object to the
     * oRequest.callback property, which may then be converted to an array.
     *
     * @param {object} oRequest
     *
     * @return {void}
     */
    self.initCallbacks = (oRequest) => {
        const aCallbacks = getRequestCallbacks(oRequest);
        const aValidCallbacks = aCallbacks.filter(xCallback => types.isObject(xCallback))
            // Add the timers attribute, if it is not defined.
            .map(oCallback => ({ ...oCallback, timers: { ...oCallback.timers }}));
        if (aValidCallbacks.length !== aCallbacks.length) {
            log.warning(`Invalid callback object ignored on request to ${oRequest.func.name}.`);
        }

        const aRequestCallback = getRequestCallbackByNames(oRequest);
        const oStatusCallback = !oRequest.statusMessages ? config.status.dontUpdate : config.status.update;
        const oCursorCallback = !oRequest.waitCursor ? config.cursor.dontUpdate : config.cursor.update;

        oRequest.callbacks = [
            oStatusCallback,
            oCursorCallback,
            ...aValidCallbacks,
            ...aRequestCallback,
        ];
    };

    /**
     * Get the timer in a callback object.
     *
     * @param {object} oRequest The request context object.
     * @param {mixed} oRequest.timers
     * @param {string} sEvent
     *
     * @returns {mixed}
     */
    const getTimer = ({ timers }, sEvent) => types.isObject(timers) ? timers[sEvent] : null;

    /**
     * Execute a callback event.
     *
     * @param {object} oCallback The callback object (or objects) which contain the event handlers to be executed.
     * @param {string} sEvent The name of the event to be triggered.
     * @param {object} oRequest The callback argument.
     *
     * @returns {void}
     */
    const execute = (oCallback, sEvent, oRequest) => {
        const func = oCallback[sEvent];
        if (!func || !types.isFunction(func)) {
            return;
        }
        const timer = getTimer(oCallback, sEvent);
        if (!timer) {
            func(oRequest); // Call the function directly.
            return;
        }
        // Call the function after the timeout.
        timer.timer = setTimeout(() => func(oRequest), timer.delay);
    };

    /**
     * Execute a callback event.
     *
     * @param {object} oRequest The request context object.
     * @param {string} sEvent The name of the event to be triggered.
     *
     * @returns {void}
     */
    self.execute = (oRequest, sEvent) => oRequest.callbacks
        .forEach(oCallback => execute(oCallback, sEvent, oRequest));

    /**
     * Clear a callback timer for the specified function.
     *
     * @param {object} oCallback The callback object (or objects) that contain the specified function timer to be cleared.
     * @param {string} sEvent The name of the function associated with the timer to be cleared.
     *
     * @returns {void}
     */
    const clearTimer = (oCallback, sEvent) => {
        const timer = getTimer(oCallback, sEvent);
        types.isObject(timer) && clearTimeout(timer.timer);
    };

    /**
     * Clear a callback timer for the specified function.
     *
     * @param {object} oRequest The request context object.
     * @param {string} sEvent The name of the function associated with the timer to be cleared.
     *
     * @returns {void}
     */
    self.clearTimer = (oRequest, sEvent) => oRequest.callbacks
        .forEach(oCallback => clearTimer(oCallback, sEvent));
})(jaxon.ajax.callback, jaxon.utils.types, jaxon.utils.log, jaxon.config);


/**
 * Class: jaxon.ajax.command
 *
 * global: jaxon
 */

(function(self, config, attr, cbk, queue, dom, types, log) {
    /**
     * An array that is used internally in the jaxon.fn.handler object to keep track
     * of command handlers that have been registered.
     *
     * @var {object}
     */
    const handlers = {};

    /**
     * Registers a new command handler.
     *
     * @param {string} name The short name of the command handler.
     * @param {string} func The command handler function.
     * @param {string=''} desc The description of the command handler.
     *
     * @returns {void}
     */
    self.register = (name, func, desc = '') => handlers[name] = { desc, func };

    /**
     * Unregisters and returns a command handler.
     *
     * @param {string} name The name of the command handler.
     *
     * @returns {callable|null} The unregistered function.
     */
    self.unregister = (name) => {
        const handler = handlers[name];
        if (!handler) {
            return null;
        }
        delete handlers[name];
        return handler.func;
    };

    /**
     * @param {object} command The response command to be executed.
     * @param {string} command.name The name of the function.
     *
     * @returns {boolean}
     */
    self.isRegistered = ({ name }) => name !== undefined && handlers[name] !== undefined;

    /**
     * Calls the registered command handler for the specified command
     * (you should always check isRegistered before calling this function)
     *
     * @param {object} name The command name.
     * @param {object} args The command arguments.
     * @param {object} context The command context.
     *
     * @returns {boolean}
     */
    self.call = (name, args, context) => {
        const { func, desc } = handlers[name];
        context.command.desc = desc;
        return func(args, context);
    }

    /**
     * Perform a lookup on the command specified by the response command object passed
     * in the first parameter.  If the command exists, the function checks to see if
     * the command references a DOM object by ID; if so, the object is located within
     * the DOM and added to the command data.  The command handler is then called.
     * 
     * @param {object} context The response command to be executed.
     *
     * @returns {true} The command completed successfully.
     */
    self.execute = (context) => {
        const { command: { name, args = {}, component = {} } } = context;
        if (!self.isRegistered({ name })) {
            log.error('Trying to execute unknown command: ' + JSON.stringify({ name, args }));
            return true;
        }

        // If the command has an "id" attr, find the corresponding dom node.
        if ((component.name)) {
            context.target = attr.node(component.name, component.item);
            if (!context.target) {
                log.error('Unable to find component node: ' + JSON.stringify(component));
            }
        }
        if (!context.target && (args.id)) {
            context.target = dom.$(args.id);
            if (!context.target) {
                log.error('Unable to find node with id : ' + args.id);
            }
        }

        // Process the command
        self.call(name, args, context);
        return true;
    };

    /**
     * Process a single command
     * 
     * @param {object} context The response command to process
     *
     * @returns {boolean}
     */
    const processCommand = (context) => {
        try {
            self.execute(context);
            return true;
        } catch (e) {
            log.error(e);
        }
        return false;
    };

    /**
     * While entries exist in the queue, pull and entry out and process it's command.
     * When oQueue.paused is set to true, the processing is halted.
     *
     * Note:
     * - Set oQueue.paused to false and call this function to cause the queue processing to continue.
     * - When an exception is caught, do nothing; if the debug module is installed, it will catch the exception and handle it.
     *
     * @param {object} oQueue A queue containing the commands to execute.
     * @param {integer=0} skipCount The number of commands to skip before starting.
     *
     * @returns {void}
     */
    self.processQueue = (oQueue, skipCount = 0) => {
        // Skip commands.
        // The last entry in the queue is not a user command, thus it cannot be skipped.
        while (skipCount > 0 && oQueue.count > 1 && queue.pop(oQueue) !== null) {
            --skipCount;
        }

        let context = null;
        oQueue.paused = false;
        // Stop processing the commands if the queue is paused.
        while (!oQueue.paused && (context = queue.pop(oQueue)) !== null) {
            if (!processCommand(context)) {
                return;
            }
        }
    };

    /**
     * Pause the the commands processing, and restart after running a provided callback.
     *
     * The provided callback will be passed another callback to call to restart the processing.
     *
     * @param {object} oQueue A queue containing the commands to execute.
     * @param {function} fCallback The callback to call.
     *
     * @return {true}
     */
    self.pause = (oQueue, fCallback) => {
        oQueue.paused = true;
        fCallback((skipCount = 0) => self.processQueue(oQueue, skipCount));
    };

    /**
     * Queue and process the commands in the response.
     *
     * @param {object} oRequest The request context object.
     *
     * @return {true}
     */
    self.processCommands = (oRequest) => {
        const { response: { content } = {} } = oRequest;
        if (!types.isObject(content)) {
            return;
        }

        const { debug: { message } = {}, jxn: { commands = [] } = {} } = content;
        message && log.console(message);

        cbk.execute(oRequest, 'onProcessing');

        // Create a queue for the commands in the response.
        const oQueue = queue.create(config.commandQueueSize);
        oQueue.sequence = 0;
        commands.forEach(command => queue.push(oQueue, {
            sequence: oQueue.sequence++,
            command: {
                name: '*unknown*',
                ...command,
            },
            request: oRequest,
            queue: oQueue,
        }));
        // Add a last command to clear the queue
        queue.push(oQueue, {
            sequence: oQueue.sequence,
            command: {
                name: 'response.complete',
                fullName: 'Response Complete',
            },
            request: oRequest,
            queue: oQueue,
        });

        self.processQueue(oQueue);
    };
})(jaxon.ajax.command, jaxon.config, jaxon.parser.attr, jaxon.ajax.callback,
    jaxon.utils.queue, jaxon.utils.dom, jaxon.utils.types, jaxon.utils.log);


/**
 * Class: jaxon.ajax.parameters
 *
 * global: jaxon
 */

(function(self, types, dom, version) {
    /**
     * The array of data bags
     *
     * @type {object}
     */
    const databags = {};

    /**
     * Set the values in an entry in the databag.
     *
     * @param {string} sBagName The data bag name.
     * @param {string} sBagKey The data bag entry key.
     * @param {mixed} xValue The entry value.
     *
     * @return {bool}
     */
    self.setBagEntry = (sBagName, sBagKey, xValue) => {
        if (databags[sBagName] === undefined) {
            return false;
        }

        databags[sBagName][sBagKey] = xValue;
        return true;
    };

    /**
     * Get the values in an entry in the databag.
     *
     * @param {string} sBagName The data bag name.
     * @param {string} sBagKey The data bag entry key.
     *
     * @return {mixed}
     */
    self.getBagEntry = (sBagName, sBagKey) => databags[sBagName] === undefined ?
        undefined : databags[sBagName][sBagKey];

    /**
     * Set a value in the databag.
     *
     * @param {string} sBagName The data bag name.
     * @param {string} sBagKey The data bag entry key.
     * @param {string} sDataKey The data bag value key.
     * @param {mixed} xValue The entry value.
     *
     * @return {bool}
     */
    self.setBagValue = (sBagName, sBagKey, sDataKey, xValue) => {
        const xBagEntry = self.getBagEntry(sBagName, sBagKey);
        // We need an object to get the data key from.
        if (xBagEntry === undefined || !types.isObject(xBagEntry)) {
            return false;
        }

        const xBag = dom.getInnerObject(sDataKey, xBagEntry);
        if (xBag === null) {
            return false;
        }

        xBag.node[xBag.attr] = xValue;
        return true;
    };

    /**
     * Get a single value from the databag.
     *
     * @param {string} sBagName The data bag name.
     * @param {string} sBagKey The data bag entry key.
     * @param {string} sDataKey The data bag value key.
     * @param {mixed} xDefault The default value.
     *
     * @return {mixed}
     */
    self.getBagValue = (sBagName, sBagKey, sDataKey, xDefault) => {
        const xBagEntry = self.getBagEntry(sBagName, sBagKey);
        // We need an object to get the data key from.
        if (xBagEntry === undefined || !types.isObject(xBagEntry)) {
            return xDefault;
        }

        return dom.findObject(sDataKey, xBagEntry) ?? xDefault;
    };

    /**
     * Save data in the databags.
     *
     * @param {object} oValues The databags values.
     *
     * @return {void}
     */
    self.setBags = (oValues) => {
        // Make sure the values are objects.
        if (types.isObject(oValues)) {
            Object.keys(oValues).forEach(sBagName => {
                if (types.isObject(oValues[sBagName])) {
                    databags[sBagName] = oValues[sBagName];
                }
            });
        }
    };

    /**
     * Get the values in a databag.
     *
     * @param {string} sBagName The data bag name.
     *
     * @return {object}
     */
    self.getBag = (sBagName) => databags[sBagName];

    /**
     * Get multiple values from the databag.
     *
     * @param {array} aBags The data bag names.
     *
     * @return {object}
     */
    const getBags = (aBags) => aBags.reduce((oValues, sBagName) =>
        databags[sBagName] === undefined || databags[sBagName] === null ? oValues : {
            ...oValues,
            [sBagName]: databags[sBagName],
        }, {});

    /**
     * Check the validity of a call argument.
     *
     * @param {mixed} xArg
     *
     * @return {bool}
     */
    const callArgIsValid = (xArg) => xArg !== undefined && !types.isFunction(xArg);

    /**
     * Encode a parameter for the request.
     *
     * @param {object} xParam request parameter.
     *
     * @return {string}
     */
    const encodeParameter = (xParam) => encodeURIComponent(JSON.stringify(xParam));

    /**
     * Sets the request parameters in a container.
     *
     * @param {object} oRequest The request object
     * @param {object} oRequest.func The function to call on the server app.
     * @param {object} oRequest.parameters The parameters to pass to the function.
     * @param {array=} oRequest.bags The keys of values to get from the data bag.
     * @param {callable} fSetter A function that sets a single parameter
     *
     * @return {void}
     */
    const setParams = ({ func, parameters, bags = [] }, fSetter) => {
        const dNow = new Date();
        fSetter('jxnr', dNow.getTime());
        fSetter('jxnv', version.number);
        // The parameters value was assigned from the js "arguments" var in a function. So it
        // is an array-like object, that we need to convert to a true array => [...parameters].
        // See https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Functions/arguments
        fSetter('jxncall', encodeParameter({
            ...func,
            args: [...parameters].filter(xArg => callArgIsValid(xArg)),
        }));
        // Add the databag values, if there's any.
        if (bags.length > 0) {
            fSetter('jxnbags', encodeParameter(getBags(bags)));
        }
    };

    /**
     * Processes request specific parameters and store them in a FormData object.
     *
     * @param {object} oRequest
     *
     * @return {FormData}
     */
    const setFormDataParams = (oRequest) => {
        oRequest.requestData = new FormData();
        setParams(oRequest, (sParam, sValue) => oRequest.requestData.append(sParam, sValue));

        // Files to upload
        const { name: field, files } = oRequest.upload.input;
        if(files) {
            // The "files" var is an array-like object, that we need to convert to a true array.
            [...files].forEach(file => oRequest.requestData.append(field, file));
        }
    };

    /**
     * Processes request specific parameters and store them in an URL encoded string.
     *
     * @param {object} oRequest
     *
     * @return {string}
     */
    const setUrlEncodedParams = (oRequest) => {
        const rd = [];
        setParams(oRequest, (sParam, sValue) => rd.push(sParam + '=' + sValue));

        if (oRequest.method === 'POST') {
            oRequest.requestData = rd.join('&');
            return;
        }

        // Move the parameters to the URL for HTTP GET requests
        oRequest.requestURI += (oRequest.requestURI.indexOf('?') === -1 ? '?' : '&') + rd.join('&');
        oRequest.requestData = ''; // The request body is empty
    };

    /**
     * Check if the request has files to upload.
     *
     * @param {object} oRequest The request object
     * @param {object} oRequest.upload The upload object
     *
     * @return {boolean}
     */
    const hasUpload = ({ upload: { form, input } = {} }) => form && input;

    /**
     * Processes request specific parameters and generates the temporary
     * variables needed by jaxon to initiate and process the request.
     * Note:
     * This is called once per request; upon a request failure, this will not be called for additional retries.
     *
     * @param {object} oRequest The request object
     *
     * @return {void}
     */
    self.process = (oRequest) => hasUpload(oRequest) ?
        setFormDataParams(oRequest) : setUrlEncodedParams(oRequest);
})(jaxon.ajax.parameters, jaxon.utils.types, jaxon.utils.dom, jaxon.version);


/**
 * Class: jaxon.ajax.request
 *
 * global: jaxon
 */

(function(self, config, params, rsp, cbk, upload, queue) {
    /**
     * The queues that hold synchronous requests as they are sent and processed.
     *
     * @var {object}
     */
    self.q = {
        send: queue.create(config.requestQueueSize),
        recv: queue.create(config.requestQueueSize * 2),
    };

    /**
     * Copy the value of the csrf meta tag to the request headers.
     *
     * @param {string} sTagName The request context object.
     *
     * @return {void}
     */
    self.setCsrf = (sTagName) => {
        const metaTags = config.baseDocument.getElementsByTagName('meta') || [];
        for (const metaTag of metaTags) {
            if (metaTag.getAttribute('name') === sTagName) {
                const csrfToken = metaTag.getAttribute('content');
                if ((csrfToken)) {
                    config.postHeaders['X-CSRF-TOKEN'] = csrfToken;
                }
                return;
            }
        }
    };

    /**
     * Set the options in the request object
     *
     * @param {object} oRequest The request context object.
     *
     * @returns {void}
     */
    const setRequestOptions = (oRequest) => {
        if (config.requestURI === undefined) {
            throw { code: 10005 };
        }

        const aHeaders = ['commonHeaders', 'postHeaders', 'getHeaders'];
        aHeaders.forEach(sHeader => oRequest[sHeader] = { ...config[sHeader], ...oRequest[sHeader] });

        const oDefaultOptions = {
            statusMessages: config.statusMessages,
            waitCursor: config.waitCursor,
            mode: config.defaultMode,
            method: config.defaultMethod,
            requestURI: config.requestURI,
            httpVersion: config.defaultHttpVersion,
            contentType: config.defaultContentType,
            requestRetry: config.defaultRetry,
            maxObjectDepth: config.maxObjectDepth,
            maxObjectSize: config.maxObjectSize,
            upload: false,
            aborted: false,
            response: {
                convertToJson: config.convertResponseToJson,
            },
        };
        Object.keys(oDefaultOptions).forEach(sOption =>
            oRequest[sOption] = oRequest[sOption] ?? oDefaultOptions[sOption]);

        oRequest.method = oRequest.method.toUpperCase();
        if (oRequest.method !== 'GET') {
            oRequest.method = 'POST'; // W3C: Method is case sensitive
        }
    };

    /**
     * Initialize a request object.
     *
     * @param {object} oRequest An object that specifies call specific settings that will,
     *      in addition, be used to store all request related values.
     *      This includes temporary values used internally by jaxon.
     *
     * @returns {void}
     */
    self.initialize = (oRequest) => {
        setRequestOptions(oRequest);
        cbk.initCallbacks(oRequest);
        cbk.execute(oRequest, 'onInitialize');

        // Set upload data in the request.
        upload.initialize(oRequest);
    };

    /**
     * Prepare a request, by setting the HTTP options, handlers and processor.
     *
     * @param {object} oRequest The request context object.
     *
     * @return {void}
     */
    self.prepare = (oRequest) => {
        cbk.execute(oRequest, 'onPrepare');

        oRequest.httpRequestOptions = {
            ...config.httpRequestOptions,
            method: oRequest.method,
            headers: {
                ...oRequest.commonHeaders,
                ...(oRequest.method === 'POST' ? oRequest.postHeaders : oRequest.getHeaders),
            },
            body: oRequest.requestData,
        };

        oRequest.response = rsp.create(oRequest);
    };

    /**
     * Send a request.
     *
     * @param {object} oRequest The request context object.
     *
     * @returns {void}
     */
    self._send = (oRequest) => {
        // The onResponseDelay and onExpiration aren't called immediately, but a timer
        // is set to call them later, using delays that are set in the config.
        cbk.execute(oRequest, 'onResponseDelay');
        cbk.execute(oRequest, 'onExpiration');
        cbk.execute(oRequest, 'onRequest');

        fetch(oRequest.requestURI, oRequest.httpRequestOptions)
            .then(oRequest.response.converter)
            .then(oRequest.response.handler)
            .catch(oRequest.response.errorHandler);
    };

    /**
     * Create a request object and submit the request using the specified request type;
     * all request parameters should be finalized by this point.
     * Upon failure of a POST, this function will fall back to a GET request.
     *
     * @param {object} oRequest The request context object.
     *
     * @returns {void}
     */
    const submit = (oRequest) => {
        self.prepare(oRequest);
        self._send(oRequest);
    };

    /**
     * Create a request object and submit the request using the specified request type.
     *
     * @param {object} oRequest The request context object.
     *
     * @returns {void}
     */
    self.submit = (oRequest) => {
        cbk.execute(oRequest, 'onProcessParams');
        params.process(oRequest);

        while (oRequest.requestRetry-- > 0) {
            try {
                submit(oRequest);
                return;
            }
            catch (e) {
                cbk.execute(oRequest, 'onFailure');
                if (oRequest.requestRetry <= 0) {
                    throw e;
                }
            }
        }
    };

    /**
     * Abort the request.
     *
     * @param {object} oRequest The request context object.
     *
     * @returns {void}
     */
    self.abort = (oRequest) => {
        oRequest.aborted = true;
        rsp.complete(oRequest);
    };

    /**
     * Initiates a request to the server.
     *
     * @param {object} func An object containing the name of the function to
     *      execute on the server. The standard request is: {jxnfun:'function_name'}
     * @param {object=} funcArgs A request object which may contain call specific parameters.
     *      This object will be used by jaxon to store all the request parameters as well as
     *      temporary variables needed during the processing of the request.
     *
     * @returns {void}
     */
    self.execute = (func, funcArgs) => {
        if (func === undefined) {
            return;
        }

        const oRequest = funcArgs ?? {};
        oRequest.func = func;
        self.initialize(oRequest);

        // The request is submitted only if there is no pending requests in the outgoing queue.
        const bSubmit = queue.empty(self.q.send);
        // Synchronous requests are always queued. Asynchronous requests are queued if the send
        // queue is not empty, which means there is at least one pending synchronous request.
        oRequest.queued = false;
        if (!bSubmit || oRequest.mode === 'synchronous') {
            queue.push(self.q.send, oRequest);
            oRequest.queued = true;
        }

        bSubmit && self.submit(oRequest);
    };
})(jaxon.ajax.request, jaxon.config, jaxon.ajax.parameters, jaxon.ajax.response,
    jaxon.ajax.callback, jaxon.ajax.upload, jaxon.utils.queue);


/**
 * Class: jaxon.ajax.response
 *
 * global: jaxon
 */

(function(self, command, req, cbk, queue) {
    /**
     * This array contains a list of codes which will be returned from the server upon
     * successful completion of the server portion of the request.
     *
     * These values should match those specified in the HTTP standard.
     *
     * @var {array}
     */
    const successCodes = [0, 200];

    // 10.4.1 400 Bad Request
    // 10.4.2 401 Unauthorized
    // 10.4.3 402 Payment Required
    // 10.4.4 403 Forbidden
    // 10.4.5 404 Not Found
    // 10.4.6 405 Method Not Allowed
    // 10.4.7 406 Not Acceptable
    // 10.4.8 407 Proxy Authentication Required
    // 10.4.9 408 Request Timeout
    // 10.4.10 409 Conflict
    // 10.4.11 410 Gone
    // 10.4.12 411 Length Required
    // 10.4.13 412 Precondition Failed
    // 10.4.14 413 Request Entity Too Large
    // 10.4.15 414 Request-URI Too Long
    // 10.4.16 415 Unsupported Media Type
    // 10.4.17 416 Requested Range Not Satisfiable
    // 10.4.18 417 Expectation Failed
    // 10.5 Server Error 5xx
    // 10.5.1 500 Internal Server Error
    // 10.5.2 501 Not Implemented
    // 10.5.3 502 Bad Gateway
    // 10.5.4 503 Service Unavailable
    // 10.5.5 504 Gateway Timeout
    // 10.5.6 505 HTTP Version Not Supported

    /**
     * This array contains a list of status codes returned by the server to indicate
     * that the request failed for some reason.
     *
     * @var {array}
     */
    const errorCodes = [400, 401, 402, 403, 404, 500, 501, 502, 503];

    // 10.3.1 300 Multiple Choices
    // 10.3.2 301 Moved Permanently
    // 10.3.3 302 Found
    // 10.3.4 303 See Other
    // 10.3.5 304 Not Modified
    // 10.3.6 305 Use Proxy
    // 10.3.7 306 (Unused)
    // 10.3.8 307 Temporary Redirect

    /**
     * An array of status codes returned from the server to indicate a request for redirect to another URL.
     *
     * Typically, this is used by the server to send the browser to another URL.
     * This does not typically indicate that the jaxon request should be sent to another URL.
     *
     * @var {array}
     */
    const redirectCodes = [301, 302, 307];

    /**
     * Check if a status code indicates a success.
     *
     * @param {int} nStatusCode A status code.
     *
     * @return {bool}
     */
    self.isSuccessCode = nStatusCode => successCodes.indexOf(nStatusCode) >= 0;

    /**
     * Check if a status code indicates a redirect.
     *
     * @param {int} nStatusCode A status code.
     *
     * @return {bool}
     */
    self.isRedirectCode = nStatusCode => redirectCodes.indexOf(nStatusCode) >= 0;

    /**
     * Check if a status code indicates an error.
     *
     * @param {int} nStatusCode A status code.
     *
     * @return {bool}
     */
    self.isErrorCode = nStatusCode => errorCodes.indexOf(nStatusCode) >= 0;

    /**
     * This is the JSON response processor.
     *
     * @param {object} oRequest The request context object.
     * @param {object} oResponse The response context object.
     * @param {object} oResponse.http The response object.
     * @param {integer} oResponse.http.status The response status.
     * @param {object} oResponse.http.headers The response headers.
     *
     * @return {true}
     */
    const jsonProcessor = (oRequest, { http: { status, headers } }) => {
        if (self.isSuccessCode(status)) {
            cbk.execute(oRequest, 'onSuccess');
            // Queue and process the commands in the response.
            command.processCommands(oRequest);
            return true;
        }
        if (self.isRedirectCode(status)) {
            cbk.execute(oRequest, 'onRedirect');
            self.complete(oRequest);
            window.location = headers.get('location');
            return true;
        }
        if (self.isErrorCode(status)) {
            cbk.execute(oRequest, 'onFailure');
            self.complete(oRequest);
            return true;
        }
        return true;
    };

    /**
     * Process the response.
     *
     * @param {object} oRequest The request context object.
     *
     * @return {mixed}
     */
    self.received = (oRequest) => {
        const { aborted, response: oResponse } = oRequest;
        // Sometimes the response.received gets called when the request is aborted
        if (aborted) {
            return null;
        }

        // The response is successfully received, clear the timers for expiration and delay.
        cbk.clearTimer(oRequest, 'onExpiration');
        cbk.clearTimer(oRequest, 'onResponseDelay');
        cbk.execute(oRequest, 'onResponseReceived');

        return oResponse.processor(oRequest, oResponse);
    };

    /**
     * Prepare a request, by setting the handlers and processor.
     *
     * @param {object} oRequest The request context object.
     *
     * @return {void}
     */
    self.create = (oRequest) => ({
        processor: jsonProcessor,
        ...oRequest.response,
        converter: (http) => {
            // Save the reponse object
            oRequest.response.http = http;
            // Get the response content
            return oRequest.response.convertToJson ? http.json() : http.text();
        },
        handler: (content) => {
            oRequest.response.content = content;
            // Synchronous request are processed immediately.
            // Asynchronous request are processed only if the queue is empty.
            if (queue.empty(req.q.send) || oRequest.mode === 'synchronous') {
                self.received(oRequest);
                return;
            }
            queue.push(req.q.recv, oRequest);
        },
        errorHandler: (error) => {
            cbk.execute(oRequest, 'onFailure');
            throw error;
        },
    });

    /**
     * Clean up the request object.
     *
     * @param {object} oRequest The request context object.
     *
     * @returns {void}
     */
    const cleanUp = (oRequest) => {
        // clean up -- these items are restored when the request is initiated
        delete oRequest.func;
        delete oRequest.requestURI;
        delete oRequest.requestData;
        delete oRequest.httpRequestOptions;
        delete oRequest.response;
        delete oRequest.callbacks;
        delete oRequest.commonHeaders;
        delete oRequest.postHeaders;
        delete oRequest.getHeaders;
    };

    /**
     * Attempt to pop the next asynchronous request.
     *
     * @param {object} oQueue The queue object you would like to modify.
     *
     * @returns {object|null}
     */
    const popAsyncRequest = oQueue => {
        if (queue.empty(oQueue) || queue.peek(oQueue).mode === 'synchronous') {
            return null;
        }
        return queue.pop(oQueue);
    }

    /**
     * Called by the response command queue processor when all commands have been processed.
     *
     * @param {object} oRequest The request context object.
     *
     * @return {void}
     */
    self.complete = (oRequest) => {
        cbk.execute(oRequest, 'onComplete');

        cleanUp(oRequest);

        // All the requests and responses queued while waiting must now be processed.
        if(oRequest.mode === 'synchronous') {
            // Remove the current request from the send queues.
            queue.pop(req.q.send);
            // Process the asynchronous responses received while waiting.
            while((recvRequest = popAsyncRequest(req.q.recv)) !== null) {
                self.received(recvRequest);
            }
            // Submit the asynchronous requests sent while waiting.
            while((sendRequest = popAsyncRequest(req.q.send)) !== null) {
                req.submit(sendRequest);
            }
            // Submit the next synchronous request, if there's any.
            if((sendRequest = queue.peek(req.q.send)) !== null) {
                req.submit(sendRequest);
            }
        }
    };
})(jaxon.ajax.response, jaxon.ajax.command, jaxon.ajax.request,
    jaxon.ajax.callback, jaxon.utils.queue);


/**
 * Class: jaxon.ajax.upload
 *
 * global: jaxon
 */

(function(self, dom, log) {
    /**
     * @param {object} oRequest A request object, created initially by a call to <jaxon.ajax.request.initialize>
     * @param {string=} oRequest.upload The HTML file upload field id
     *
     * @returns {boolean}
     */
    const initRequest = (oRequest) => {
        if (!oRequest.upload) {
            return false;
        }

        oRequest.upload = {
            id: oRequest.upload,
            input: null,
            form: null,
        };
        const input = dom.$(oRequest.upload.id);

        if (!input) {
            log.error('Unable to find input field for file upload with id ' + oRequest.upload.id);
            return false;
        }
        if (input.type !== 'file') {
            log.error('The upload input field with id ' + oRequest.upload.id + ' is not of type file');
            return false;
        }
        if (input.files.length === 0) {
            log.error('There is no file selected for upload in input field with id ' + oRequest.upload.id);
            return false;
        }
        if (input.name === undefined) {
            log.error('The upload input field with id ' + oRequest.upload.id + ' has no name attribute');
            return false;
        }
        oRequest.upload.input = input;
        oRequest.upload.form = input.form;
        return true;
    };

    /**
     * Check upload data and initialize the request.
     *
     * @param {object} oRequest A request object, created initially by a call to <jaxon.ajax.request.initialize>
     *
     * @returns {void}
     */
    self.initialize = (oRequest) => {
        // The content type shall not be set when uploading a file with FormData.
        // It will be set by the browser.
        if (!initRequest(oRequest)) {
            oRequest.postHeaders['content-type'] = oRequest.contentType;
        }
    }
})(jaxon.ajax.upload, jaxon.utils.dom, jaxon.utils.log);


/**
 * Class: jaxon.cmd.dialog
 *
 * global: jaxon
 */

(function(self, dialog, parser, command) {
    /**
     * Prompt the user with the specified question, if the user responds by clicking cancel,
     * then skip the specified number of commands in the response command queue.
     * If the user clicks Ok, the command processing resumes normal operation.
     *
     * @param {object} args The command arguments.
     * @param {integer} args.count The number of commands to skip.
     * @param {string} args.lib The dialog library to use.
     * @param {object} args.question The question to ask.
     * @param {object} context The command context.
     * @param {object} context.queue The command queue.
     *
     * @returns {true} The queue processing is temporarily paused.
     */
    self.execConfirm = ({ count: skipCount, lib: sLibName, question }, { queue: oQueue }) => {
        // The command queue processing is paused, and will be restarted
        // after the confirm question is answered.
        command.pause(oQueue, (restart) => {
            dialog.confirm(sLibName, {
                ...question,
                text: parser.makePhrase(question.phrase),
            }, {
                yes: () => restart(),
                no: () => restart(skipCount),
            });
        });
        return true;
    };

    /**
     * Add an event handler to the specified target.
     *
     * @param {object} args The command arguments.
     * @param {string} args.lib The dialog library to use.
     * @param {object} args.message The message content
     *
     * @returns {true} The operation completed successfully.
     */
    self.showAlert = ({ lib: sLibName, message }) => {
        dialog.alert(sLibName, {
            ...message,
            text: parser.makePhrase(message.phrase),
        });
        return true;
    };

    /**
     * Remove an event handler from an target.
     *
     * @param {object} args The command arguments.
     * @param {string} args.lib The dialog library to use.
     * @param {object} args.dialog The dialog content
     *
     * @returns {true} The operation completed successfully.
     */
    self.showModal = ({ lib: sLibName, dialog: oDialog }) => {
        dialog.show(sLibName, oDialog);
        return true;
    };

    /**
     * Set an event handler with arguments to the specified target.
     *
     * @param {object} args The command arguments.
     * @param {string} args.lib The dialog library to use.
     *
     * @returns {true} The operation completed successfully.
     */
    self.hideModal = ({ lib: sLibName }) => {
        dialog.hide(sLibName);
        return true;
    };
})(jaxon.cmd.dialog, jaxon.dialog, jaxon.parser.call, jaxon.ajax.command);


/**
 * Class: jaxon.cmd.event
 *
 * global: jaxon
 */

(function(self, call, dom, str) {
    /**
     * Add an event handler to the specified target.
     *
     * @param {object} args The command arguments.
     * @param {string} args.event The name of the event.
     * @param {string} args.func The name of the function to be called
     * @param {object} context The command context.
     * @param {Element} context.target The target DOM element.
     *
     * @returns {true} The operation completed successfully.
     */
    self.addHandler = ({ event: sEvent, func: sFuncName }, { target }) => {
        target.addEventListener(str.stripOnPrefix(sEvent), dom.findFunction(sFuncName), false)
        return true;
    };

    /**
     * Remove an event handler from an target.
     *
     * @param {object} args The command arguments.
     * @param {string} args.event The name of the event.
     * @param {string} args.func The name of the function to be removed
     * @param {object} context The command context.
     * @param {Element} context.target The target DOM element.
     *
     * @returns {true} The operation completed successfully.
     */
    self.removeHandler = ({ event: sEvent, func: sFuncName }, { target }) => {
       target.removeEventListener(str.stripOnPrefix(sEvent), dom.findFunction(sFuncName), false);
       return true;
    };

    /**
     * Call an event handler.
     *
     * @param {string} event The name of the event
     * @param {object} func The expression to be executed in the event handler
     * @param {object} target The target element
     *
     * @returns {void}
     */
    const callEventHandler = (event, func, target) =>
        call.execExpr({ _type: 'expr', ...func }, { event, target });

    /**
     * Add an event handler with arguments to the specified target.
     *
     * @param {object} args The command arguments.
     * @param {string} args.event The name of the event
     * @param {object} args.func The event handler
     * @param {object|false} args.options The handler options
     * @param {object} context The command context.
     * @param {Element} context.target The target DOM element.
     *
     * @returns {true} The operation completed successfully.
     */
    self.addEventHandler = ({ event: sEvent, func, options }, { target }) => {
        target.addEventListener(str.stripOnPrefix(sEvent),
            (event) => callEventHandler(event, func, target), options ?? false);
        return true;
    };

    /**
     * Set an event handler with arguments to the specified target.
     *
     * @param {object} args The command arguments.
     * @param {string} args.event The name of the event
     * @param {object} args.func The event handler
     * @param {object} context The command context.
     * @param {Element} context.target The target DOM element.
     *
     * @returns {true} The operation completed successfully.
     */
    self.setEventHandler = ({ event: sEvent, func }, { target }) => {
        target[str.addOnPrefix(sEvent)] = (event) => callEventHandler(event, func, target);
        return true;
    };
})(jaxon.cmd.event, jaxon.parser.call, jaxon.utils.dom, jaxon.utils.string);


/**
 * Class: jaxon.cmd.
 *
 * global: jaxon
 */

(function(self, attr, command, dom, types, baseDocument) {
    /**
     * Assign an element's attribute to the specified value.
     *
     * @param {object} oQueue The command queue.
     * @param {Element} xTarget The target DOM element.
     * @param {object} xElt The attribute.
     * @param {string} xElt.node The node of the attribute.
     * @param {object} xElt.attr The name of the attribute.
     * @param {mixed} xValue The new value of the attribute.
     *
     * @returns {void}
     */
    const setNodeAttr = (oQueue, xTarget, { node: xNode, attr: sAttr }, xValue) => {
        if (sAttr !== 'outerHTML' || !xTarget.parentNode) {
            xNode[sAttr] = xValue;
            // Process Jaxon custom attributes in the new node HTML content.
            if (sAttr === 'innerHTML') {
                attr.process(xTarget, false);
            }
            return;
        }

        // The command queue processing is paused, and will be restarted
        // after the mutation observer is called.
        command.pause(oQueue, (restart) => {
            // When setting the outerHTML value, we need to have a parent node, and to
            // get the newly inserted node, where we'll process our custom attributes.
            // The initial target node is actually removed from the DOM, thus cannot be used.
            (new MutationObserver((aMutations, xObserver) => {
                xObserver.disconnect();
                // Process Jaxon custom attributes in the new node HTML content.
                xTarget = aMutations.length > 0 && aMutations[0].addedNodes?.length > 0 ?
                    aMutations[0].addedNodes[0] : null;
                if (xTarget) {
                    attr.process(xTarget, true);
                }
                // Restart the command queue processing.
                restart();
            })).observe(xNode.parentNode, { attributes: false, childList: true, subtree: false });
            // Now change the DOM node outerHTML value, which will call the mutation observer.
            xNode.outerHTML = xValue;
        });
    };

    /**
     * Assign an element's attribute to the specified value.
     *
     * @param {object} args The command arguments.
     * @param {string} args.attr The name of the attribute to set.
     * @param {string} args.value The new value to be applied.
     * @param {object} context The command context.
     * @param {Element} context.target The target DOM element.
     * @param {object} context.queue The command queue.
     *
     * @returns {true} The operation completed successfully.
     */
    self.assign = ({ attr, value }, { target, queue: oQueue }) => {
        const xElt = dom.getInnerObject(attr, target);
        if (xElt !== null) {
            setNodeAttr(oQueue, target, xElt, value);
        }
        return true;
    };

    /**
     * Append the specified value to an element's attribute.
     *
     * @param {object} args The command arguments.
     * @param {string} args.attr The name of the attribute to append to.
     * @param {string} args.value The new value to be appended.
     * @param {object} context The command context.
     * @param {Element} context.target The target DOM element.
     * @param {object} context.queue The command queue.
     *
     * @returns {true} The operation completed successfully.
     */
    self.append = ({ attr, value }, { target, queue: oQueue }) => {
        const xElt = dom.getInnerObject(attr, target);
        if (xElt !== null) {
            setNodeAttr(oQueue, target, xElt, xElt.node[xElt.attr] + value);
        }
        return true;
    };

    /**
     * Prepend the specified value to an element's attribute.
     *
     * @param {object} args The command arguments.
     * @param {string} args.attr The name of the attribute.
     * @param {string} args.value The new value to be prepended.
     * @param {object} context The command context.
     * @param {Element} context.target The target DOM element.
     * @param {object} context.queue The command queue.
     *
     * @returns {true} The operation completed successfully.
     */
    self.prepend = ({ attr, value }, { target, queue: oQueue }) => {
        const xElt = dom.getInnerObject(attr, target);
        if (xElt !== null) {
            setNodeAttr(oQueue, target, xElt, value + xElt.node[xElt.attr]);
        }
        return true;
    };

    /**
     * Replace a text in the value of a given attribute in an element
     *
     * @param {object} oQueue The command queue.
     * @param {Element} xTarget The target DOM element.
     * @param {object} xElt The value returned by the dom.getInnerObject() function
     * @param {string} sSearch The text to search
     * @param {string} sReplace The text to use as replacement
     *
     * @returns {void}
     */
    const replaceText = (oQueue, xTarget, xElt, sSearch, sReplace) => {
        const bFunction = types.isFunction(xElt.node[xElt.attr]);
        const sCurText = bFunction ? xElt.node[xElt.attr].join('') : xElt.node[xElt.attr];
        const sNewText = sCurText.replaceAll(sSearch, sReplace);
        if (bFunction || dom.willChange(xElt.node, xElt.attr, sNewText)) {
            setNodeAttr(oQueue, xTarget, xElt, sNewText);
        }
    };

    /**
     * Search and replace the specified text.
     *
     * @param {object} args The command arguments.
     * @param {string} args.attr The name of the attribute to be set.
     * @param {string} args.search The search text and replacement text.
     * @param {string} args.replace The search text and replacement text.
     * @param {object} context The command context.
     * @param {Element} context.target The target DOM element.
     * @param {object} context.queue The command queue.
     *
     * @returns {true} The operation completed successfully.
     */
    self.replace = ({ attr, search, replace }, { target, queue: oQueue }) => {
        const xElt = dom.getInnerObject(attr, target);
        if (xElt !== null) {
            replaceText(oQueue, target, xElt, attr === 'innerHTML' ?
                dom.getBrowserHTML(search) : search, replace);
        }
        return true;
    };

    /**
     * Clear an element.
     *
     * @param {object} args The command arguments.
     * @param {object} context The command context.
     *
     * @returns {true} The operation completed successfully.
     */
    self.clear = (args, context) => {
        self.assign({ ...args, value: '' }, context);
        return true;
    };

    /**
     * Delete an element.
     *
     * @param {object} args The command arguments.
     * @param {object} context The command context.
     * @param {Element} context.target The target DOM element.
     *
     * @returns {true} The operation completed successfully.
     */
    self.remove = (args, { target }) => {
        target.remove();
        return true;
    };

    /**
     * @param {string} sTag The tag name for the new element.
     * @param {string} sId The id attribute of the new element.
     *
     * @returns {object}
     */
    const createNewTag = (sTag, sId) => {
        const newTag = baseDocument.createElement(sTag);
        newTag.setAttribute('id', sId);
        return newTag;
    };

    /**
     * Create a new element and append it to the specified parent element.
     *
     * @param {object} args The command arguments.
     * @param {string} args.tag.name The tag name for the new element.
     * @param {string} args.tag.id The id attribute of the new element.
     * @param {object} context The command context.
     * @param {Element} context.target The target DOM element.
     *
     * @returns {true} The operation completed successfully.
     */
    self.create = ({ tag: { id: sId, name: sTag } }, { target }) => {
        target && target.appendChild(createNewTag(sTag, sId));
        return true;
    };

    /**
     * Insert a new element before the specified element.
     *
     * @param {object} args The command arguments.
     * @param {string} args.tag.name The tag name for the new element.
     * @param {string} args.tag.id The id attribute of the new element.
     * @param {object} context The command context.
     * @param {Element} context.target The target DOM element.
     *
     * @returns {true} The operation completed successfully.
     */
    self.insertBefore = ({ tag: { id: sId, name: sTag } }, { target }) => {
        target && target.parentNode &&
            target.parentNode.insertBefore(createNewTag(sTag, sId), target);
        return true;
    };

    /**
     * Insert a new element after the specified element.
     *
     * @param {object} args The command arguments.
     * @param {string} args.tag.name The tag name for the new element.
     * @param {string} args.tag.id The id attribute of the new element.
     * @param {object} context The command context.
     * @param {Element} context.target The target DOM element.
     *
     * @returns {true} The operation completed successfully.
     */
    self.insertAfter = ({ tag: { id: sId, name: sTag } }, { target }) => {
        target && target.parentNode &&
            target.parentNode.insertBefore(createNewTag(sTag, sId), target.nextSibling);
        return true;
    };

    /**
     * Bind a DOM node to a component.
     *
     * @param {object} args The command arguments.
     * @param {object} args.component The component.
     * @param {string} args.component.name The component name.
     * @param {string=} args.component.item The component item.
     * @param {object} args.context The initial context to execute the command.
     * @param {object} context The command context.
     * @param {Element} context.target The target DOM element.
     *
     * @returns {true} The operation completed successfully.
     */
    self.bind = ({ component: { name: sName, item: sItem } }, { target: xTarget }) => {
        attr.bind(xTarget, sName, sItem);
        return true;
    };
})(jaxon.cmd.node, jaxon.parser.attr, jaxon.ajax.command, jaxon.utils.dom, jaxon.utils.types,
    jaxon.config.baseDocument);


/**
 * Class: jaxon.cmd.script
 *
 * global: jaxon
 */

(function(self, call, parameters, command, types) {
    /**
     * Call a javascript function with a series of parameters using the current script context.
     *
     * @param {object} args The command arguments.
     * @param {string} args.func The name of the function to call.
     * @param {array} args.args  The parameters to pass to the function.
     * @param {object} args.context The initial context to execute the command.
     * @param {object} context The command context.
     * @param {Element} context.target The target DOM element.
     *
     * @returns {true} The operation completed successfully.
     */
    self.execCall = ({ func, args, context }, { target }) => {
        call.execCall({ _type: 'func', _name: func, args }, { target, ...context });
        return true;
    };

    /**
     * Execute a javascript expression using the current script context.
     *
     * @param {object} args The command arguments.
     * @param {string} args.expr The json formatted expression to execute.
     * @param {object} args.context The initial context to execute the command.
     * @param {object} context The command context.
     * @param {Element} context.target The target DOM element.
     *
     * @returns {true} The operation completed successfully.
     */
    self.execExpr = ({ expr, context }, { target }) => {
        call.execExpr(expr, { target, ...context });
        return true;
    };

    /**
     * Causes the processing of items in the queue to be delayed for the specified amount of time.
     * This is an asynchronous operation, therefore, other operations will be given an opportunity
     * to execute during this delay.
     *
     * @param {object} args The command arguments.
     * @param {integer} args.duration The number of 10ths of a second to sleep.
     * @param {object} context The Response command object.
     * @param {object} context.queue The command queue.
     *
     * @returns {true}
     */
    self.sleep = ({ duration }, { queue: oQueue }) => {
        // The command queue is paused, and will be restarted after the specified delay.
        command.pause(oQueue, (restart) => setTimeout(() => restart(), duration * 100));
        return true;
    };

    /**
     * Redirects the browser to the specified URL.
     *
     * @param {object} args The command arguments.
     * @param {string} args.url The new URL to redirect to
     * @param {integer} args.delay The time to wait before the redirect.
     *
     * @returns {true} The operation completed successfully.
     */
    self.redirect = ({ url: sUrl, delay: nDelay }) => {
        // In no delay is provided, then use a 5ms delay.
        window.setTimeout(() => window.location = sUrl, nDelay <= 0 ? 5 : nDelay * 1000);
        return true;
    };

    /**
     * Update the databag content.
     *
     * @param {object} args The command arguments.
     * @param {string} args.values The databag values.
     *
     * @returns {true} The operation completed successfully.
     */
    self.setDatabag = ({ values }) => {
        parameters.setBags(values);
        return true;
    };

    /**
     * Replace the page number argument with the current page number value
     *
     * @param {array} aArgs
     * @param {object} oLink
     *
     * @returns {array}
     */
    const getCallArgs = (aArgs, oLink) => aArgs.map(xArg =>
        types.isObject(xArg) && xArg._type === 'page' ?
        parseInt(oLink.parentNode.getAttribute('data-page')) : xArg);

    /**
     * Set event handlers on pagination links.
     *
     * @param {object} args The command arguments.
     * @param {object} args.func The page call expression
     * @param {object} context The command context.
     * @param {Element} context.target The target DOM element.
     *
     * @returns {true} The operation completed successfully.
     */
    self.paginate = ({ func: oCall }, { target }) => {
        const aLinks = target.querySelectorAll(`li.enabled > a`);
        const { args: aArgs } = oCall;
        aLinks.forEach(oLink => oLink.addEventListener('click', () => call.execCall({
            ...oCall,
            _type: 'func',
            args: getCallArgs(aArgs, oLink),
        })));
        return true;
    };
})(jaxon.cmd.script, jaxon.parser.call, jaxon.ajax.parameters, jaxon.ajax.command, jaxon.utils.types);


/*
    File: jaxon.js

    This file contains the definition of the main jaxon javascript core.

    This is the client side code which runs on the web browser or similar web enabled application.
    Include this in the HEAD of each page for which you wish to use jaxon.
*/

/** global: jaxon */

/**
 * Initiates a request to the server.
 */
jaxon.request = jaxon.ajax.request.execute;

/**
 * Registers a new command handler.
 * Shortcut to <jaxon.ajax.command.register>
 */
jaxon.register = jaxon.ajax.command.register;

/**
 * Shortcut to <jaxon.utils.dom.$>.
 */
jaxon.$ = jaxon.utils.dom.$;

/**
 * Shortcut to <jaxon.ajax.request.setCsrf>.
 */
jaxon.setCsrf = jaxon.ajax.request.setCsrf;

/**
 * Shortcut to the JQuery selector function>.
 */
jaxon.jq = jaxon.parser.query.jq;

/**
 * Shortcut to <jaxon.parser.call.execExpr>.
 */
jaxon.exec = jaxon.parser.call.execExpr;

/**
 * Shortcut to <jaxon.dialog.confirm>.
 */
jaxon.confirm = jaxon.dialog.confirm;

/**
 * Shortcut to <jaxon.dialog.alert>.
 */
jaxon.alert = jaxon.dialog.alert;

/**
 * Shortcut to <jaxon.utils.form.getValues>.
 */
jaxon.getFormValues = jaxon.utils.form.getValues;

/**
 * Shortcut to <jaxon.ajax.parameters.getBag>.
 */
jaxon.bag.getBag = jaxon.ajax.parameters.getBag;

/**
 * Shortcut to <jaxon.ajax.parameters.setBagEntry>.
 */
jaxon.bag.setEntry = jaxon.ajax.parameters.setBagEntry;

/**
 * Shortcut to <jaxon.ajax.parameters.getBagEntry>.
 */
jaxon.bag.getEntry = jaxon.ajax.parameters.getBagEntry;

/**
 * Shortcut to <jaxon.ajax.parameters.setBagValue>.
 */
jaxon.bag.setValue = jaxon.ajax.parameters.setBagValue;

/**
 * Shortcut to <jaxon.ajax.parameters.getBagValue>.
 */
jaxon.bag.getValue = jaxon.ajax.parameters.getBagValue;

/**
 * Shortcut to <jaxon.parser.attr.process>.
 */
jaxon.processCustomAttrs = jaxon.parser.attr.process;

/**
 * Shortcut to <jaxon.utils.log.logger>.
 */
jaxon.logger = jaxon.utils.log.logger;

/**
 * Indicates if jaxon module is loaded.
 */
jaxon.isLoaded = true;

/**
 * Register the command handlers provided by the library, and initialize the message object.
 */
(function(register, cmd, ajax, log) {
    // Pseudo command needed to complete queued commands processing.
    register('response.complete', (args, { request }) => {
        ajax.response.complete(request);
        return true;
    }, 'Response complete');

    register('node.assign', cmd.node.assign, 'Node::Assign');
    register('node.append', cmd.node.append, 'Node::Append');
    register('node.prepend', cmd.node.prepend, 'Node::Prepend');
    register('node.replace', cmd.node.replace, 'Node::Replace');
    register('node.clear', cmd.node.clear, 'Node::Clear');
    register('node.remove', cmd.node.remove, 'Node::Remove');
    register('node.create', cmd.node.create, 'Node::Create');
    register('node.insert.before', cmd.node.insertBefore, 'Node::InsertBefore');
    register('node.insert.after', cmd.node.insertAfter, 'Node::InsertAfter');
    register('node.bind', cmd.node.bind, 'Node::Bind');

    register('script.exec.call', cmd.script.execCall, 'Script::ExecJsonCall');
    register('script.exec.expr', cmd.script.execExpr, 'Script::ExecJsonExpr');
    register('script.redirect', cmd.script.redirect, 'Script::Redirect');
    register('script.sleep', cmd.script.sleep, 'Script::Sleep');

    register('handler.event.set', cmd.event.setEventHandler, 'Script::SetEventHandler');
    register('handler.event.add', cmd.event.addEventHandler, 'Script::AddEventHandler');
    register('handler.add', cmd.event.addHandler, 'Script::AddHandler');
    register('handler.remove', cmd.event.removeHandler, 'Script::RemoveHandler');

    register('script.debug', ({ message }) => {
        log.consoleMode().debug(message);
        return true;
    }, 'Debug message');

    // Pagination
    register('pg.paginate', cmd.script.paginate, 'Paginator::Paginate');
    // Data bags
    register('databag.set', cmd.script.setDatabag, 'Databag::SetValues');
    // register('databag.clear', cmd.script.clearDatabag, 'Databag::ClearValue');
    // Dialogs
    register('dialog.confirm', cmd.dialog.execConfirm, 'Dialog::Confirm');
    register('dialog.alert.show', cmd.dialog.showAlert, 'Dialog::ShowAlert');
    register('dialog.modal.show', cmd.dialog.showModal, 'Dialog::ShowModal');
    register('dialog.modal.hide', cmd.dialog.hideModal, 'Dialog::HideModal');
})(jaxon.register, jaxon.cmd, jaxon.ajax, jaxon.utils.log);
