/*
    Class: jaxon.config

    This class contains all the default configuration settings.  These
    are application level settings; however, they can be overridden
    by including a jaxon.config definition prior to including the
    <jaxon_core.js> file, or by specifying the appropriate configuration
    options on a per call basis.
*/
const jaxon = {};

/*
    Class: jaxon.config

    This class contains all the default configuration settings.  These
    are application level settings; however, they can be overridden
    by including a jaxon.config definition prior to including the
    <jaxon_core.js> file, or by specifying the appropriate configuration
    options on a per call basis.
*/
jaxon.config = {};

/*
Class: jaxon.debug
*/
jaxon.debug = {
    /*
    Class: jaxon.debug.verbose

    Provide a high level of detail which can be used to debug hard to find problems.
    */
    verbose: {}
};

/*
Class: jaxon.ajax
*/
jaxon.ajax = {};

/*
Class: jaxon.tools

This contains utility functions which are used throughout
the jaxon core.
*/
jaxon.tools = {};

/*
Class: jaxon.cmd

Contains the functions for page content, layout, functions and events.
*/
jaxon.cmd = {};

/*
Function: jaxon.config.setDefault

This function will set a default configuration option if it is not already set.

Parameters:
option - (string):
The name of the option that will be set.

defaultValue - (unknown):
The value to use if a value was not already set.
*/
jaxon.config.setDefault = function(option, defaultValue) {
    if ('undefined' == typeof jaxon.config[option])
        jaxon.config[option] = defaultValue;
};

/*
Object: commonHeaders

An array of header entries where the array key is the header
option name and the associated value is the value that will
set when the request object is initialized.

These headers will be set for both POST and GET requests.
*/
jaxon.config.setDefault('commonHeaders', {
    'If-Modified-Since': 'Sat, 1 Jan 2000 00:00:00 GMT'
});

/*
Object: postHeaders

An array of header entries where the array key is the header
option name and the associated value is the value that will
set when the request object is initialized.
*/
jaxon.config.setDefault('postHeaders', {});

/*
Object: getHeaders

An array of header entries where the array key is the header
option name and the associated value is the value that will
set when the request object is initialized.
*/
jaxon.config.setDefault('getHeaders', {});

/*
Boolean: waitCursor

true - jaxon should display a wait cursor when making a request
false - jaxon should not show a wait cursor during a request
*/
jaxon.config.setDefault('waitCursor', false);

/*
Boolean: statusMessages

true - jaxon should update the status bar during a request
false - jaxon should not display the status of the request
*/
jaxon.config.setDefault('statusMessages', false);

/*
Object: baseDocument

The base document that will be used throughout the code for
locating elements by ID.
*/
jaxon.config.setDefault('baseDocument', document);

/*
String: requestURI

The URI that requests will be sent to.
*/
jaxon.config.setDefault('requestURI', jaxon.config.baseDocument.URL);

/*
String: defaultMode

The request mode.

'asynchronous' - The request will immediately return, the
response will be processed when (and if) it is received.

'synchronous' - The request will block, waiting for the
response.  This option allows the server to return
a value directly to the caller.
*/
jaxon.config.setDefault('defaultMode', 'asynchronous');

/*
String: defaultHttpVersion

The Hyper Text Transport Protocol version designated in the
header of the request.
*/
jaxon.config.setDefault('defaultHttpVersion', 'HTTP/1.1');

/*
String: defaultContentType

The content type designated in the header of the request.
*/
jaxon.config.setDefault('defaultContentType', 'application/x-www-form-urlencoded');

/*
Integer: defaultResponseDelayTime

The delay time, in milliseconds, associated with the
<jaxon.callback.onRequestDelay> event.
*/
jaxon.config.setDefault('defaultResponseDelayTime', 1000);

/*
Integer: defaultExpirationTime

The amount of time to wait, in milliseconds, before a request
is considered expired.  This is used to trigger the
<jaxon.callback.onExpiration event.
*/
jaxon.config.setDefault('defaultExpirationTime', 10000);

/*
String: defaultMethod

The method used to send requests to the server.

'POST' - Generate a form POST request
'GET' - Generate a GET request; parameters are appended
to the <jaxon.config.requestURI> to form a URL.
*/
jaxon.config.setDefault('defaultMethod', 'POST'); // W3C: Method is case sensitive

/*
Integer: defaultRetry

The number of times a request should be retried
if it expires.
*/
jaxon.config.setDefault('defaultRetry', 5);

/*
Object: defaultReturnValue

The value returned by <jaxon.request> when in asynchronous
mode, or when a syncrhonous call does not specify the
return value.
*/
jaxon.config.setDefault('defaultReturnValue', false);

/*
Integer: maxObjectDepth

The maximum depth of recursion allowed when serializing
objects to be sent to the server in a request.
*/
jaxon.config.setDefault('maxObjectDepth', 20);

/*
Integer: maxObjectSize

The maximum number of members allowed when serializing
objects to be sent to the server in a request.
*/
jaxon.config.setDefault('maxObjectSize', 2000);

jaxon.config.setDefault('responseQueueSize', 1000);

jaxon.config.setDefault('requestQueueSize', 1000);

/*
Class: jaxon.config.status

Provides support for updating the browser's status bar during
the request process.  By splitting the status bar functionality
into an object, the jaxon developer has the opportunity to
customize the status bar messages prior to sending jaxon requests.
*/
jaxon.config.status = {
    /*
    Function: update

    Constructs and returns a set of event handlers that will be
    called by the jaxon framework to set the status bar messages.
    */
    update: function() {
        return {
            onRequest: function() {
                window.status = 'Sending Request...';
            },
            onWaiting: function() {
                window.status = 'Waiting for Response...';
            },
            onProcessing: function() {
                window.status = 'Processing...';
            },
            onComplete: function() {
                window.status = 'Done.';
            }
        }
    },
    /*
    Function: dontUpdate

    Constructs and returns a set of event handlers that will be
    called by the jaxon framework where status bar updates
    would normally occur.
    */
    dontUpdate: function() {
        return {
            onRequest: function() {},
            onWaiting: function() {},
            onProcessing: function() {},
            onComplete: function() {}
        }
    }
};

/*
Class: jaxon.config.cursor

Provides the base functionality for updating the browser's cursor
during requests.  By splitting this functionalityh into an object
of it's own, jaxon developers can now customize the functionality
prior to submitting requests.
*/
jaxon.config.cursor = {
    /*
    Function: update

    Constructs and returns a set of event handlers that will be
    called by the jaxon framework to effect the status of the
    cursor during requests.
    */
    update: function() {
        return {
            onWaiting: function() {
                if (jaxon.config.baseDocument.body)
                    jaxon.config.baseDocument.body.style.cursor = 'wait';
            },
            onComplete: function() {
                jaxon.config.baseDocument.body.style.cursor = 'auto';
            }
        }
    },
    /*
    Function: dontUpdate

    Constructs and returns a set of event handlers that will
    be called by the jaxon framework where cursor status changes
    would typically be made during the handling of requests.
    */
    dontUpdate: function() {
        return {
            onWaiting: function() {},
            onComplete: function() {}
        }
    }
};


jaxon.tools.ajax = {
    /*
    Function: jaxon.tools.ajax.createRequest

    Construct an XMLHttpRequest object dependent on the capabilities of the browser.

    Returns:
    object - Javascript XHR object.
    */
    createRequest: function() {
        if ('undefined' != typeof XMLHttpRequest) {
            jaxon.tools.ajax.createRequest = function() {
                return new XMLHttpRequest();
            }
        } else if ('undefined' != typeof ActiveXObject) {
            jaxon.tools.ajax.createRequest = function() {
                try {
                    return new ActiveXObject('Msxml2.XMLHTTP.4.0');
                } catch (e) {
                    jaxon.tools.ajax.createRequest = function() {
                        try {
                            return new ActiveXObject('Msxml2.XMLHTTP');
                        } catch (e2) {
                            jaxon.tools.ajax.createRequest = function() {
                                return new ActiveXObject('Microsoft.XMLHTTP');
                            }
                            return jaxon.tools.ajax.createRequest();
                        }
                    }
                    return jaxon.tools.ajax.createRequest();
                }
            }
        } else if (window.createRequest) {
            jaxon.tools.ajax.createRequest = function() {
                return window.createRequest();
            };
        } else {
            jaxon.tools.ajax.createRequest = function() {
                throw { code: 10002 };
            };
        }

        // this would seem to cause an infinite loop, however, the function should
        // be reassigned by now and therefore, it will not loop.
        return jaxon.tools.ajax.createRequest();
    }
};


jaxon.tools.array = {
    /*
    Function jaxon.tools.array.is_in

    Looks for a value within the specified array and, if found, returns true; otherwise it returns false.

    Parameters:
        array - (object): The array to be searched.
        valueToCheck - (object): The value to search for.

    Returns:
        true : The value is one of the values contained in the array.
        false : The value was not found in the specified array.
    */
    is_in: function(array, valueToCheck) {
        let i = 0;
        const l = array.length;
        while (i < l) {
            if (array[i] == valueToCheck)
                return true;
            ++i;
        }
        return false;
    }
};


jaxon.tools.dom = {
    /*
    Function: jaxon.tools.dom.$

    Shorthand for finding a uniquely named element within the document.

    Parameters:
    sId - (string):
        The unique name of the element (specified by the ID attribute), not to be confused
        with the name attribute on form elements.

    Returns:
    object - The element found or null.

    Note:
        This function uses the <jaxon.config.baseDocument> which allows <jaxon> to operate on the
        main window document as well as documents from contained iframes and child windows.

    See also:
        <jaxon.$> and <jxn.$>
    */
    $: function(sId) {
        if (!sId)
            return null;
        //sId not an string so return it maybe its an object.
        if (typeof sId != 'string')
            return sId;

        const oDoc = jaxon.config.baseDocument;

        const obj = oDoc.getElementById(sId);
        if (obj)
            return obj;

        if (oDoc.all)
            return oDoc.all[sId];

        return obj;
    },

    /*
    Function: jaxon.tools.dom.getBrowserHTML

    Insert the specified string of HTML into the document, then extract it.
    This gives the browser the ability to validate the code and to apply any transformations it deems appropriate.

    Parameters:
    sValue - (string):
        A block of html code or text to be inserted into the browser's document.

    Returns:
    The (potentially modified) html code or text.
    */
    getBrowserHTML: function(sValue) {
        const oDoc = jaxon.config.baseDocument;
        if (!oDoc.body)
            return '';

        const elWorkspace = jaxon.$('jaxon_temp_workspace');
        if (!elWorkspace) {
            elWorkspace = oDoc.createElement('div');
            elWorkspace.setAttribute('id', 'jaxon_temp_workspace');
            elWorkspace.style.display = 'none';
            elWorkspace.style.visibility = 'hidden';
            oDoc.body.appendChild(elWorkspace);
        }
        elWorkspace.innerHTML = sValue;
        const browserHTML = elWorkspace.innerHTML;
        elWorkspace.innerHTML = '';

        return browserHTML;
    },

    /*
    Function: jaxon.tools.dom.willChange

    Tests to see if the specified data is the same as the current value of the element's attribute.

    Parameters:
    element - (string or object):
        The element or it's unique name (specified by the ID attribute)
    attribute - (string):
        The name of the attribute.
    newData - (string):
        The value to be compared with the current value of the specified element.

    Returns:
    true - The specified value differs from the current attribute value.
    false - The specified value is the same as the current value.
    */
    willChange: function(element, attribute, newData) {
        if ('string' == typeof element)
            element = jaxon.$(element);
        if (element) {
            let oldData;
            // eval('oldData=element.' + attribute);
            oldData = element[attribute];
            return (newData != oldData);
        }
        return false;
    },

    /*
    Function: jaxon.tools.dom.findFunction

    Find a function using its name as a string.

    Parameters:
    sFuncName - (string): The name of the function to find.

    Returns:
    Functiion - The function with the given name.
    */
    findFunction: function (sFuncName) {
        let context = window;
        const namespaces = sFuncName.split(".");
        for(const i = 0; i < namespaces.length && context != undefined; i++) {
            context = context[namespaces[i]];
        }
        return context;
    }
};


jaxon.tools.form = {
    /*
    Function: jaxon.tools.form.getValues

    Build an associative array of form elements and their values from the specified form.

    Parameters:
    element - (string): The unique name (id) of the form to be processed.
    disabled - (boolean, optional): Include form elements which are currently disabled.
    prefix - (string, optional): A prefix used for selecting form elements.

    Returns:
    An associative array of form element id and value.
    */
    getValues: function(parent) {
        const submitDisabledElements = (arguments.length > 1 && arguments[1] == true);

        const prefix = (arguments.length > 2) ? arguments[2] : '';

        if ('string' == typeof parent)
            parent = jaxon.$(parent);

        const aFormValues = {};

        //        JW: Removing these tests so that form values can be retrieved from a specified
        //        container element like a DIV, regardless of whether they exist in a form or not.
        //
        //        if (parent.tagName)
        //            if ('FORM' == parent.tagName.toUpperCase())
        if (parent && parent.childNodes)
            jaxon.tools.form._getValues(aFormValues, parent.childNodes, submitDisabledElements, prefix);

        return aFormValues;
    },

    /*
    Function: jaxon.tools.form._getValues

    Used internally by <jaxon.tools.form.getValues> to recursively get the value
    of form elements.  This function will extract all form element values
    regardless of the depth of the element within the form.
    */
    _getValues: function(aFormValues, children, submitDisabledElements, prefix) {
        const iLen = children.length;
        for (let i = 0; i < iLen; ++i) {
            const child = children[i];
            if (('undefined' != typeof child.childNodes) && (child.type != 'select-one') && (child.type != 'select-multiple'))
                jaxon.tools.form._getValues(aFormValues, child.childNodes, submitDisabledElements, prefix);
            jaxon.tools.form._getValue(aFormValues, child, submitDisabledElements, prefix);
        }
    },

    /*
    Function: jaxon.tools.form._getValue

    Used internally by <jaxon.tools.form._getValues> to extract a single form value.
    This will detect the type of element (radio, checkbox, multi-select) and add it's value(s) to the form values array.

    Modified version for multidimensional arrays
    */
    _getValue: function(aFormValues, child, submitDisabledElements, prefix) {
        if (!child.name)
            return;

        if ('PARAM' == child.tagName) return;

        if (child.disabled)
            if (true == child.disabled)
                if (false == submitDisabledElements)
                    return;

        if (prefix != child.name.substring(0, prefix.length))
            return;

        if (child.type)
        {
            if (child.type == 'radio' || child.type == 'checkbox')
                if (false == child.checked)
                    return;
            if (child.type == 'file')
                return;
        }

        const name = child.name;

        let values = [];

        if ('select-multiple' == child.type) {
            const jLen = child.length;
            for (let j = 0; j < jLen; ++j) {
                const option = child.options[j];
                if (true == option.selected)
                    values.push(option.value);
            }
        } else {
            values = child.value;
        }

        const keyBegin = name.indexOf('[');
        /* exists name/object before the Bracket?*/
        if (0 <= keyBegin) {
            let n = name;
            let k = n.substr(0, n.indexOf('['));
            let a = n.substr(n.indexOf('['));
            if (typeof aFormValues[k] == 'undefined')
                aFormValues[k] = {};
            let p = aFormValues; // pointer reset
            while (a.length != 0) {
                const sa = a.substr(0, a.indexOf(']') + 1);

                const lk = k; //save last key
                const lp = p; //save last pointer

                a = a.substr(a.indexOf(']') + 1);
                p = p[k];
                k = sa.substr(1, sa.length - 2);
                if (k == '') {
                    if ('select-multiple' == child.type) {
                        k = lk; //restore last key
                        p = lp;
                    } else {
                        k = p.length;
                    }
                }
                if (typeof k == 'undefined') {
                    /*check against the global aFormValues Stack wich is the next(last) usable index */
                    k = 0;
                    for (let i in lp[lk]) k++;
                }
                if (typeof p[k] == 'undefined') {

                    p[k] = {};
                }
            }
            p[k] = values;
        } else {
            aFormValues[name] = values;
        }
    }
};


jaxon.tools.queue = {
    /**
     * Construct and return a new queue object.
     *
     * @param integer size The number of entries the queue will be able to hold.
     *
     * @returns object
     */
    create: function(size) {
        return {
            start: 0,
            count: 0,
            size: size,
            end: 0,
            elements: [],
            timeout: null
        }
    },

    /**
     * Check id a queue is empty.
     *
     * @param object oQueue The queue to check.
     *
     * @returns boolean
     */
    empty: function(oQueue) {
        return (oQueue.count <= 0);
    },

    /**
     * Check id a queue is empty.
     *
     * @param object oQueue The queue to check.
     *
     * @returns boolean
     */
    full: function(oQueue) {
        return (oQueue.count >= oQueue.size);
    },

    /**
     * Push a new object into the tail of the buffer maintained by the specified queue object.
     *
     * @param object oQueue The queue in which you would like the object stored.
     * @param object obj    The object you would like stored in the queue.
     *
     * @returns integer The number of entries in the queue.
     */
    push: function(oQueue, obj) {
        // No push if the queue is full.
        if(jaxon.tools.queue.full(oQueue)) {
            throw { code: 10003 };
        }

        oQueue.elements[oQueue.end] = obj;
        if(++oQueue.end >= oQueue.size) {
            oQueue.end = 0;
        }
        return ++oQueue.count;
    },

    /**
     * Push a new object into the head of the buffer maintained by the specified queue object.
     *
     * This effectively pushes an object to the front of the queue... it will be processed first.
     *
     * @param object oQueue The queue in which you would like the object stored.
     * @param object obj    The object you would like stored in the queue.
     *
     * @returns integer The number of entries in the queue.
     */
    pushFront: function(oQueue, obj) {
        // No push if the queue is full.
        if(jaxon.tools.queue.full(oQueue)) {
            throw { code: 10003 };
        }

        // Simply push if the queue is empty
        if(jaxon.tools.queue.empty(oQueue)) {
            return jaxon.tools.queue.push(oQueue, obj);
        }

        // Put the object one position back.
        if(--oQueue.start < 0) {
            oQueue.start = oQueue.size - 1;
        }
        oQueue.elements[oQueue.start] = obj;
        return ++oQueue.count;
    },

    /**
     * Attempt to pop an object off the head of the queue.
     *
     * @param object oQueue The queue object you would like to modify.
     *
     * @returns object|null
     */
    pop: function(oQueue) {
        if(jaxon.tools.queue.empty(oQueue)) {
            return null;
        }

        let obj = oQueue.elements[oQueue.start];
        delete oQueue.elements[oQueue.start];
        if(++oQueue.start >= oQueue.size) {
            oQueue.start = 0;
        }
        oQueue.count--;
        return obj;
    },

    /**
     * Attempt to pop an object off the head of the queue.
     *
     * @param object oQueue The queue object you would like to modify.
     *
     * @returns object|null
     */
    peek: function(oQueue) {
        if(jaxon.tools.queue.empty(oQueue)) {
            return null;
        }
        return oQueue.elements[oQueue.start];
    }
};


jaxon.tools.string = {
    /*
    Function: jaxon.tools.string.doubleQuotes

    Replace all occurances of the single quote character with a double quote character.

    Parameters:
    haystack - The source string to be scanned.

    Returns:  false on error
    string - A new string with the modifications applied.
    */
    doubleQuotes: function(haystack) {
        if (typeof haystack == 'undefined') return false;
        return haystack.replace(new RegExp("'", 'g'), '"');
    },

    /*
    Function: jaxon.tools.string.singleQuotes

    Replace all occurances of the double quote character with a single quote character.

    haystack - The source string to be scanned.

    Returns:
    string - A new string with the modification applied.
    */
    singleQuotes: function(haystack) {
        if (typeof haystack == 'undefined') return false;
        return haystack.replace(new RegExp('"', 'g'), "'");
    },

    /*
    Function: jaxon.tools.string.stripOnPrefix

    Detect, and if found, remove the prefix 'on' from the specified string.
    This is used while working with event handlers.

    Parameters:
    sEventName - (string): The string to be modified.

    Returns:
    string - The modified string.
    */
    stripOnPrefix: function(sEventName) {
        sEventName = sEventName.toLowerCase();
        if (0 == sEventName.indexOf('on'))
            sEventName = sEventName.replace(/on/, '');

        return sEventName;
    },

    /*
    Function: jaxon.tools.string.addOnPrefix

    Detect, and add if not found, the prefix 'on' from the specified string.
    This is used while working with event handlers.

    Parameters:
    sEventName - (string): The string to be modified.

    Returns:
    string - The modified string.
    */
    addOnPrefix: function(sEventName) {
        sEventName = sEventName.toLowerCase();
        if (0 != sEventName.indexOf('on'))
            sEventName = 'on' + sEventName;

        return sEventName;
    }
};

/**
 * String functions for Jaxon
 * See http://javascript.crockford.com/remedial.html for more explanation
 */

/**
 * Substitute variables in the string
 *
 * @return string
 */
if (!String.prototype.supplant) {
    String.prototype.supplant = function(o) {
        return this.replace(
            /\{([^{}]*)\}/g,
            function(a, b) {
                const r = o[b];
                return typeof r === 'string' || typeof r === 'number' ? r : a;
            }
        );
    };
}


jaxon.tools.upload = {
    /*
    Function: jaxon.tools.upload.createIframe

    Create an iframe for file upload.
    */
    createIframe: function(oRequest) {
        const target = 'jaxon_upload_' + oRequest.upload.id;
        // Delete the iframe, in the case it already exists
        jaxon.cmd.node.remove(target);
        // Create the iframe.
        jaxon.cmd.node.insert(oRequest.upload.form, 'iframe', target);
        oRequest.upload.iframe = jaxon.tools.dom.$(target);
        oRequest.upload.iframe.name = target;
        oRequest.upload.iframe.style.display = 'none';
        // Set the form attributes
        oRequest.upload.form.method = 'POST';
        oRequest.upload.form.enctype = 'multipart/form-data';
        oRequest.upload.form.action = jaxon.config.requestURI;
        oRequest.upload.form.target = target;
        return true;
    },

    /*
    Function: jaxon.tools.upload._initialize

    Check upload data and initialize the request.
    */
    _initialize: function(oRequest) {
        if (!oRequest.upload) {
            return false;
        }
        oRequest.upload = { id: oRequest.upload, input: null, form: null, ajax: oRequest.ajax };

        const input = jaxon.tools.dom.$(oRequest.upload.id);
        if (!input) {
            console.log('Unable to find input field for file upload with id ' + oRequest.upload.id);
            return false;
        }
        if (input.type !== 'file') {
            console.log('The upload input field with id ' + oRequest.upload.id + ' is not of type file');
            return false;
        }
        if (input.files.length === 0) {
            console.log('There is no file selected for upload in input field with id ' + oRequest.upload.id);
            return false;
        }
        if (typeof input.name === 'undefined') {
            console.log('The upload input field with id ' + oRequest.upload.id + ' has no name attribute');
            return false;
        }
        oRequest.upload.input = input;
        oRequest.upload.form = input.form;
        // Having the input field is enough for upload with FormData (Ajax).
        if (oRequest.upload.ajax != false)
            return true;
        // For upload with iframe, we need to get the form too.
        if (!input.form) {
            // Find the input form
            let form = input;
            while (form !== null && form.nodeName !== 'FORM')
                form = form.parentNode;
            if (form === null) {
                console.log('The upload input field with id ' + oRequest.upload.id + ' is not in a form');
                return false;
            }
            oRequest.upload.form = form;
        }
        // If FormData feature is not available, files are uploaded with iframes.
        jaxon.tools.upload.createIframe(oRequest);

        return true;
    },

    /*
    Function: jaxon.tools.upload.initialize

    Check upload data and initialize the request.

    Parameters:

    oRequest - A request object, created initially by a call to <jaxon.ajax.request.initialize>
    */
    initialize: function(oRequest) {
        jaxon.tools.upload._initialize(oRequest);

        // The content type is not set when uploading a file with FormData.
        // It will be set by the browser.
        if (!oRequest.upload || !oRequest.upload.ajax || !oRequest.upload.input) {
            oRequest.append('postHeaders', {
                'content-type': oRequest.contentType
            });
        }
    }
};


jaxon.cmd.delay = {
    /**
     * Attempt to pop the next asynchronous request.
     *
     * @param object oQueue The queue object you would like to modify.
     *
     * @returns object|null
     */
    popAsyncRequest: function(oQueue) {
        if(jaxon.tools.queue.empty(oQueue))
        {
            return null;
        }
        if(jaxon.tools.queue.peek(oQueue).mode === 'synchronous')
        {
            return null;
        }
        return jaxon.tools.queue.pop(oQueue);
    },

    /**
     * Maintains a retry counter for the given object.
     *
     * @param command object    The object to track the retry count for.
     * @param count integer     The number of times the operation should be attempted before a failure is indicated.
     *
     * @returns boolean
     *      true - The object has not exhausted all the retries.
     *      false - The object has exhausted the retry count specified.
     */
    retry: function(command, count) {
        let retries = command.retries;
        if(retries) {
            --retries;
            if(1 > retries) {
                return false;
            }
        } else {
            retries = count;
        }
        command.retries = retries;
        // This command must be processed again.
        command.requeue = true;
        return true;
    },

    /**
     * Set or reset a timeout that is used to restart processing of the queue.
     *
     * This allows the queue to asynchronously wait for an event to occur (giving the browser time
     * to process pending events, like loading files)
     *
     * @param response object   The queue to process.
     * @param when integer      The number of milliseconds to wait before starting/restarting the processing of the queue.
     */
    setWakeup: function(response, when) {
        if (response.timeout !== null) {
            clearTimeout(response.timeout);
            response.timeout = null;
        }
        response.timout = setTimeout(function() {
            jaxon.ajax.response.process(response);
        }, when);
    },

    /**
     * The function to run after the confirm question, for the comfirmCommands.
     *
     * @param command object    The object to track the retry count for.
     * @param count integer     The number of commands to skip.
     * @param skip boolean      Skip the commands or not.
     *
     * @returns boolean
     */
    confirmCallback: function(command, count, skip) {
        if(skip === true) {
            // The last entry in the queue is not a user command.
            // Thus it cannot be skipped.
            while (count > 0 && command.response.count > 1 &&
                jaxon.tools.queue.pop(command.response) !== null) {
                --count;
            }
        }
        // Run a different command depending on whether this callback executes
        // before of after the confirm function returns;
        if(command.requeue === true) {
            // Before => the processing is delayed.
            jaxon.cmd.delay.setWakeup(command.response, 30);
        } else {
            // After => the processing is executed.
            jaxon.ajax.response.process(command.response);
        }
    },

    /**
     * Ask a confirm question and skip the specified number of commands if the answer is ok.
     *
     * The processing of the queue after the question is delayed so it occurs after this function returns.
     * The 'command.requeue' attribute is used to determine if the confirmCallback is called
     * before (when using the blocking confirm() function) or after this function returns.
     * @see confirmCallback
     *
     * @param command object    The object to track the retry count for.
     * @param question string   The question to ask to the user.
     * @param count integer     The number of commands to skip.
     *
     * @returns boolean
     */
    confirm: function(command, count, question) {
        // This will be checked in the callback.
        command.requeue = true;
        jaxon.ajax.message.confirm(question, '', function() {
            jaxon.cmd.delay.confirmCallback(command, count, false);
        }, function() {
            jaxon.cmd.delay.confirmCallback(command, count, true);
        });
        // This command must not be processed again.
        command.requeue = false;
        return false;
    }
};


jaxon.cmd.event = {
    /*
    Function: jaxon.cmd.event.setEvent

    Set an event handler.

    Parameters:

    command - (object): Response command object.
    - id: Element ID
    - prop: Event
    - data: Code

    Returns:

    true - The operation completed successfully.
    */
    setEvent: function(command) {
        command.fullName = 'setEvent';
        const target = command.id;
        const sEvent = command.prop;
        const code = command.data;
        // force to get the target
        if (typeof target === 'string')
            target = jaxon.$(target);
        sEvent = jaxon.tools.string.addOnPrefix(sEvent);
        code = jaxon.tools.string.doubleQuotes(code);
        eval('target.' + sEvent + ' = function(e) { ' + code + '; }');
        return true;
    },

    /*
    Function: jaxon.cmd.event.addHandler

    Add an event handler to the specified target.

    Parameters:

    command - (object): Response command object.
    - id: The id of, or the target itself
    - prop: The name of the event.
    - data: The name of the function to be called

    Returns:

    true - The operation completed successfully.
    */
    addHandler: function(command) {
        if (window.addEventListener) {
            jaxon.cmd.event.addHandler = function(command) {
                command.fullName = 'addHandler';
                const target = command.id;
                const sEvent = command.prop;
                const sFuncName = command.data;
                if (typeof target === 'string')
                    target = jaxon.$(target);
                sEvent = jaxon.tools.string.stripOnPrefix(sEvent);
                eval('target.addEventListener("' + sEvent + '", ' + sFuncName + ', false);');
                return true;
            }
        } else {
            jaxon.cmd.event.addHandler = function(command) {
                command.fullName = 'addHandler';
                const target = command.id;
                const sEvent = command.prop;
                const sFuncName = command.data;
                if (typeof target === 'string')
                    target = jaxon.$(target);
                sEvent = jaxon.tools.string.addOnPrefix(sEvent);
                eval('target.attachEvent("' + sEvent + '", ' + sFuncName + ', false);');
                return true;
            }
        }
        return jaxon.cmd.event.addHandler(command);
    },

    /*
    Function: jaxon.cmd.event.removeHandler

    Remove an event handler from an target.

    Parameters:

    command - (object): Response command object.
    - id: The id of, or the target itself
    - prop: The name of the event.
    - data: The name of the function to be removed

    Returns:

    true - The operation completed successfully.
    */
    removeHandler: function(command) {
        if (window.removeEventListener) {
            jaxon.cmd.event.removeHandler = function(command) {
                command.fullName = 'removeHandler';
                const target = command.id;
                const sEvent = command.prop;
                const sFuncName = command.data;
                if (typeof target === 'string')
                    target = jaxon.$(target);
                sEvent = jaxon.tools.string.stripOnPrefix(sEvent);
                eval('target.removeEventListener("' + sEvent + '", ' + sFuncName + ', false);');
                return true;
            }
        } else {
            jaxon.cmd.event.removeHandler = function(command) {
                command.fullName = 'removeHandler';
                const target = command.id;
                const sEvent = command.prop;
                const sFuncName = command.data;
                if (typeof target === 'string')
                    target = jaxon.$(target);
                sEvent = jaxon.tools.string.addOnPrefix(sEvent);
                eval('target.detachEvent("' + sEvent + '", ' + sFuncName + ', false);');
                return true;
            }
        }
        return jaxon.cmd.event.removeHandler(command);
    }
};


jaxon.cmd.form = {
    /*
    Function: jaxon.cmd.form.getInput

    Create and return a form input element with the specified parameters.

    Parameters:

    type - (string):  The type of input element desired.
    name - (string):  The value to be assigned to the name attribute.
    id - (string):  The value to be assigned to the id attribute.

    Returns:

    object - The new input element.
    */
    getInput: function(type, name, id) {
        if ('undefined' == typeof window.addEventListener) {
            jaxon.cmd.form.getInput = function(type, name, id) {
                return jaxon.config.baseDocument.createElement('<input type="' + type + '" name="' + name + '" id="' + id + '">');
            }
        } else {
            jaxon.cmd.form.getInput = function(type, name, id) {
                const oDoc = jaxon.config.baseDocument;
                const Obj = oDoc.createElement('input');
                Obj.setAttribute('type', type);
                Obj.setAttribute('name', name);
                Obj.setAttribute('id', id);
                return Obj;
            }
        }
        return jaxon.cmd.form.getInput(type, name, id);
    },

    /*
    Function: jaxon.cmd.form.createInput

    Create a new input element under the specified parent.

    Parameters:

    objParent - (string or object):  The name of, or the element itself
        that will be used as the reference for the insertion.
    sType - (string):  The value to be assigned to the type attribute.
    sName - (string):  The value to be assigned to the name attribute.
    sId - (string):  The value to be assigned to the id attribute.

    Returns:

    true - The operation completed successfully.
    */
    createInput: function(command) {
        command.fullName = 'createInput';
        const objParent = command.id;

        const sType = command.type;
        const sName = command.data;
        const sId = command.prop;
        if ('string' == typeof objParent)
            objParent = jaxon.$(objParent);
        const target = jaxon.cmd.form.getInput(sType, sName, sId);
        if (objParent && target) {
            objParent.appendChild(target);
        }
        return true;
    },

    /*
    Function: jaxon.cmd.form.insertInput

    Insert a new input element before the specified element.

    Parameters:

    objSibling - (string or object):  The name of, or the element itself
        that will be used as the reference for the insertion.
    sType - (string):  The value to be assigned to the type attribute.
    sName - (string):  The value to be assigned to the name attribute.
    sId - (string):  The value to be assigned to the id attribute.

    Returns:

    true - The operation completed successfully.
    */
    insertInput: function(command) {
        command.fullName = 'insertInput';
        const objSibling = command.id;
        const sType = command.type;
        const sName = command.data;
        const sId = command.prop;
        if ('string' == typeof objSibling)
            objSibling = jaxon.$(objSibling);
        const target = jaxon.cmd.form.getInput(sType, sName, sId);
        if (target && objSibling && objSibling.parentNode)
            objSibling.parentNode.insertBefore(target, objSibling);
        return true;
    },

    /*
    Function: jaxon.cmd.form.insertInputAfter

    Insert a new input element after the specified element.

    Parameters:

    objSibling - (string or object):  The name of, or the element itself
        that will be used as the reference for the insertion.
    sType - (string):  The value to be assigned to the type attribute.
    sName - (string):  The value to be assigned to the name attribute.
    sId - (string):  The value to be assigned to the id attribute.

    Returns:

    true - The operation completed successfully.
    */
    insertInputAfter: function(command) {
        command.fullName = 'insertInputAfter';
        const objSibling = command.id;
        const sType = command.type;
        const sName = command.data;
        const sId = command.prop;
        if ('string' == typeof objSibling)
            objSibling = jaxon.$(objSibling);
        const target = jaxon.cmd.form.getInput(sType, sName, sId);
        if (target && objSibling && objSibling.parentNode)
            objSibling.parentNode.insertBefore(target, objSibling.nextSibling);
        return true;
    }
};


jaxon.cmd.node = {
    /*
    Function: jaxon.cmd.node.assign

    Assign an element's attribute to the specified value.

    Parameters:

    element - (object):  The HTML element to effect.
    property - (string):  The name of the attribute to set.
    data - (string):  The new value to be applied.

    Returns:

    true - The operation completed successfully.
    */
    assign: function(element, property, data) {
        if ('string' == typeof element)
            element = jaxon.$(element);

        switch (property) {
            case 'innerHTML':
                element.innerHTML = data;
                break;
            case 'outerHTML':
                if ('undefined' == typeof element.outerHTML) {
                    const r = jaxon.config.baseDocument.createRange();
                    r.setStartBefore(element);
                    const df = r.createContextualFragment(data);
                    element.parentNode.replaceChild(df, element);
                } else element.outerHTML = data;
                break;
            default:
                if (jaxon.tools.dom.willChange(element, property, data))
                    eval('element.' + property + ' = data;');
                break;
        }
        return true;
    },

    /*
    Function: jaxon.cmd.node.append

    Append the specified value to an element's attribute.

    Parameters:

    element - (object):  The HTML element to effect.
    property - (string):  The name of the attribute to append to.
    data - (string):  The new value to be appended.

    Returns:

    true - The operation completed successfully.
    */
    append: function(element, property, data) {
        if ('string' == typeof element)
            element = jaxon.$(element);

        // Check if the insertAdjacentHTML() function is available
        if((window.insertAdjacentHTML) || (element.insertAdjacentHTML))
            if(property == 'innerHTML')
                element.insertAdjacentHTML('beforeend', data);
            else if(property == 'outerHTML')
                element.insertAdjacentHTML('afterend', data);
            else
                element[property] += data;
        else
            eval('element.' + property + ' += data;');
        return true;
    },

    /*
    Function: jaxon.cmd.node.prepend

    Prepend the specified value to an element's attribute.

    Parameters:

    element - (object):  The HTML element to effect.
    property - (string):  The name of the attribute.
    data - (string):  The new value to be prepended.

    Returns:

    true - The operation completed successfully.
    */
    prepend: function(element, property, data) {
        if ('string' == typeof element)
            element = jaxon.$(element);

        eval('element.' + property + ' = data + element.' + property);
        return true;
    },

    /*
    Function: jaxon.cmd.node.replace

    Search and replace the specified text.

    Parameters:

    element - (string or object):  The name of, or the element itself which is to be modified.
    sAttribute - (string):  The name of the attribute to be set.
    aData - (array):  The search text and replacement text.

    Returns:

    true - The operation completed successfully.
    */
    replace: function(element, sAttribute, aData) {
        const sReplace = aData['r'];
        const sSearch = (sAttribute === 'innerHTML') ?
            jaxon.tools.dom.getBrowserHTML(aData['s']) : aData['s'];

        if (typeof element === 'string')
            element = jaxon.$(element);

        eval('let txt = element.' + sAttribute);

        let bFunction = false;
        if (typeof txt === 'function') {
            txt = txt.join('');
            bFunction = true;
        }

        let start = txt.indexOf(sSearch);
        if (start > -1) {
            const newTxt = [];
            while (start > -1) {
                const end = start + sSearch.length;
                newTxt.push(txt.substr(0, start));
                newTxt.push(sReplace);
                txt = txt.substr(end, txt.length - end);
                start = txt.indexOf(sSearch);
            }
            newTxt.push(txt);
            newTxt = newTxt.join('');

            if (bFunction) {
                eval('element.' + sAttribute + '=newTxt;');
            } else if (jaxon.tools.dom.willChange(element, sAttribute, newTxt)) {
                eval('element.' + sAttribute + '=newTxt;');
            }
        }
        return true;
    },

    /*
    Function: jaxon.cmd.node.remove

    Delete an element.

    Parameters:

    element - (string or object):  The name of, or the element itself which will be deleted.

    Returns:

    true - The operation completed successfully.
    */
    remove: function(element) {
        if ('string' == typeof element)
            element = jaxon.$(element);

        if (element && element.parentNode && element.parentNode.removeChild)
            element.parentNode.removeChild(element);

        return true;
    },

    /*
    Function: jaxon.cmd.node.create

    Create a new element and append it to the specified parent element.

    Parameters:

    objParent - (string or object):  The name of, or the element itself
        which will contain the new element.
    sTag - (string):  The tag name for the new element.
    sId - (string):  The value to be assigned to the id attribute of the new element.

    Returns:

    true - The operation completed successfully.
    */
    create: function(objParent, sTag, sId) {
        if ('string' == typeof objParent)
            objParent = jaxon.$(objParent);
        const target = jaxon.config.baseDocument.createElement(sTag);
        target.setAttribute('id', sId);
        if (objParent)
            objParent.appendChild(target);
        return true;
    },

    /*
    Function: jaxon.cmd.node.insert

    Insert a new element before the specified element.

    Parameters:

    objSibling - (string or object):  The name of, or the element itself
        that will be used as the reference point for insertion.
    sTag - (string):  The tag name for the new element.
    sId - (string):  The value that will be assigned to the new element's id attribute.

    Returns:

    true - The operation completed successfully.
    */
    insert: function(objSibling, sTag, sId) {
        if ('string' == typeof objSibling)
            objSibling = jaxon.$(objSibling);
        const target = jaxon.config.baseDocument.createElement(sTag);
        target.setAttribute('id', sId);
        objSibling.parentNode.insertBefore(target, objSibling);
        return true;
    },

    /*
    Function: jaxon.cmd.node.insertAfter

    Insert a new element after the specified element.

    Parameters:

    objSibling - (string or object):  The name of, or the element itself
        that will be used as the reference point for insertion.
    sTag - (string):  The tag name for the new element.
    sId - (string):  The value that will be assigned to the new element's id attribute.

    Returns:

    true - The operation completed successfully.
    */
    insertAfter: function(objSibling, sTag, sId) {
        if ('string' == typeof objSibling)
            objSibling = jaxon.$(objSibling);
        const target = jaxon.config.baseDocument.createElement(sTag);
        target.setAttribute('id', sId);
        objSibling.parentNode.insertBefore(target, objSibling.nextSibling);
        return true;
    },

    /*
    Function: jaxon.cmd.node.contextAssign

    Assign a value to a named member of the current script context object.

    Parameters:

    command - (object):  The response command object which will contain the
        following:

        - command.prop: (string):  The name of the member to assign.
        - command.data: (string or object):  The value to assign to the member.
        - command.context: (object):  The current script context object which
            is accessable via the 'this' keyword.

    Returns:

    true - The operation completed successfully.
    */
    contextAssign: function(command) {
        command.fullName = 'context assign';

        const code = [];
        code.push('this.');
        code.push(command.prop);
        code.push(' = data;');
        code = code.join('');
        command.context.jaxonDelegateCall = function(data) {
            eval(code);
        }
        command.context.jaxonDelegateCall(command.data);
        return true;
    },

    /*
    Function: jaxon.cmd.node.contextAppend

    Appends a value to a named member of the current script context object.

    Parameters:

    command - (object):  The response command object which will contain the
        following:

        - command.prop: (string):  The name of the member to append to.
        - command.data: (string or object):  The value to append to the member.
        - command.context: (object):  The current script context object which
            is accessable via the 'this' keyword.

    Returns:

    true - The operation completed successfully.
    */
    contextAppend: function(command) {
        command.fullName = 'context append';

        const code = [];
        code.push('this.');
        code.push(command.prop);
        code.push(' += data;');
        code = code.join('');
        command.context.jaxonDelegateCall = function(data) {
            eval(code);
        }
        command.context.jaxonDelegateCall(command.data);
        return true;
    },

    /*
    Function: jaxon.cmd.node.contextPrepend

    Prepend a value to a named member of the current script context object.

    Parameters:

    command - (object):  The response command object which will contain the
        following:

        - command.prop: (string):  The name of the member to prepend to.
        - command.data: (string or object):  The value to prepend to the member.
        - command.context: (object):  The current script context object which
            is accessable via the 'this' keyword.

    Returns:

    true - The operation completed successfully.
    */
    contextPrepend: function(command) {
        command.fullName = 'context prepend';

        const code = [];
        code.push('this.');
        code.push(command.prop);
        code.push(' = data + this.');
        code.push(command.prop);
        code.push(';');
        code = code.join('');
        command.context.jaxonDelegateCall = function(data) {
            eval(code);
        }
        command.context.jaxonDelegateCall(command.data);
        return true;
    }
};


jaxon.cmd.script = {
    /*
    Function: jaxon.cmd.script.includeScriptOnce

    Add a reference to the specified script file if one does not already exist in the HEAD of the current document.

    This will effecitvely cause the script file to be loaded in the browser.

    Parameters:

    fileName - (string):  The URI of the file.

    Returns:

    true - The reference exists or was added.
    */
    includeScriptOnce: function(command) {
        command.fullName = 'includeScriptOnce';
        const fileName = command.data;
        // Check for existing script tag for this file.
        const oDoc = jaxon.config.baseDocument;
        const loadedScripts = oDoc.getElementsByTagName('script');
        const iLen = loadedScripts.length;
        for (let i = 0; i < iLen; ++i) {
            const script = loadedScripts[i];
            if (script.src) {
                if (0 <= script.src.indexOf(fileName))
                    return true;
            }
        }
        return jaxon.cmd.script.includeScript(command);
    },

    /*
    Function: jaxon.cmd.script.includeScript

    Adds a SCRIPT tag referencing the specified file.
    This effectively causes the script to be loaded in the browser.

    Parameters:

    command (object) - Xajax response object

    Returns:

    true - The reference was added.
    */
    includeScript: function(command) {
        command.fullName = 'includeScript';
        const oDoc = jaxon.config.baseDocument;
        const objHead = oDoc.getElementsByTagName('head');
        const objScript = oDoc.createElement('script');
        objScript.src = command.data;
        if ('undefined' == typeof command.type) objScript.type = 'text/javascript';
        else objScript.type = command.type;
        if ('undefined' != typeof command.type) objScript.setAttribute('id', command.elm_id);
        objHead[0].appendChild(objScript);
        return true;
    },

    /*
    Function: jaxon.cmd.script.removeScript

    Locates a SCRIPT tag in the HEAD of the document which references the specified file and removes it.

    Parameters:

    command (object) - Xajax response object

    Returns:

    true - The script was not found or was removed.
    */
    removeScript: function(command) {
        command.fullName = 'removeScript';
        const fileName = command.data;
        const unload = command.unld;
        const oDoc = jaxon.config.baseDocument;
        const loadedScripts = oDoc.getElementsByTagName('script');
        const iLen = loadedScripts.length;
        for (let i = 0; i < iLen; ++i) {
            const script = loadedScripts[i];
            if (script.src) {
                if (0 <= script.src.indexOf(fileName)) {
                    if ('undefined' != typeof unload) {
                        const _command = {};
                        _command.data = unload;
                        _command.context = window;
                        jaxon.cmd.script.execute(_command);
                    }
                    const parent = script.parentNode;
                    parent.removeChild(script);
                }
            }
        }
        return true;
    },

    /*
    Function: jaxon.cmd.script.sleep

    Causes the processing of items in the queue to be delayed for the specified amount of time.
    This is an asynchronous operation, therefore, other operations will be given an opportunity
    to execute during this delay.

    Parameters:

    command - (object):  The response command containing the following parameters.
        - command.prop: The number of 10ths of a second to sleep.

    Returns:

    true - The sleep operation completed.
    false - The sleep time has not yet expired, continue sleeping.
    */
    sleep: function(command) {
        command.fullName = 'sleep';
        // inject a delay in the queue processing
        // handle retry counter
        if (jaxon.cmd.delay.retry(command, command.prop)) {
            jaxon.cmd.delay.setWakeup(command.response, 100);
            return false;
        }
        // wake up, continue processing queue
        return true;
    },

    /*
    Function: jaxon.cmd.script.alert

    Show the specified message.

    Parameters:

    command (object) - jaxon response object

    Returns:

    true - The operation completed successfully.
    */
    alert: function(command) {
        command.fullName = 'alert';
        jaxon.ajax.message.info(command.data);
        return true;
    },

    /*
    Function: jaxon.cmd.script.confirm

    Prompt the user with the specified question, if the user responds by clicking cancel,
    then skip the specified number of commands in the response command queue.
    If the user clicks Ok, the command processing resumes normal operation.

    Parameters:

    command (object) - jaxon response object

    Returns:

    false - Stop the processing of the command queue until the user answers the question.
    */
    confirm: function(command) {
        command.fullName = 'confirm';
        jaxon.cmd.delay.confirm(command, command.count, command.data);
        return false;
    },

    /*
    Function: jaxon.cmd.script.execute

    Execute the specified string of javascript code, using the current script context.

    Parameters:

    command - The response command object containing the following:
        - command.data: (string):  The javascript to be evaluated.
        - command.context: (object):  The javascript object that to be referenced as 'this' in the script.

    Returns:

    unknown - A value set by the script using 'returnValue = '
    true - If the script does not set a returnValue.
    */
    execute: function(command) {
        command.fullName = 'execute Javascript';
        const returnValue = true;
        command.context = command.context ? command.context : {};
        command.context.jaxonDelegateCall = function() {
            eval(command.data);
        };
        command.context.jaxonDelegateCall();
        return returnValue;
    },

    /*
    Function: jaxon.cmd.script.waitFor

    Test for the specified condition, using the current script context;
    if the result is false, sleep for 1/10th of a second and try again.

    Parameters:

    command - The response command object containing the following:

        - command.data: (string):  The javascript to evaluate.
        - command.prop: (integer):  The number of 1/10ths of a second to wait before giving up.
        - command.context: (object):  The current script context object which is accessable in
            the javascript being evaulated via the 'this' keyword.

    Returns:

    false - The condition evaulates to false and the sleep time has not expired.
    true - The condition evaluates to true or the sleep time has expired.
    */
    waitFor: function(command) {
        command.fullName = 'waitFor';

        let bResult = false;
        const cmdToEval = 'bResult = (';
        cmdToEval += command.data;
        cmdToEval += ');';
        try {
            command.context.jaxonDelegateCall = function() {
                eval(cmdToEval);
            }
            command.context.jaxonDelegateCall();
        } catch (e) {}
        if (false == bResult) {
            // inject a delay in the queue processing
            // handle retry counter
            if (jaxon.cmd.delay.retry(command, command.prop)) {
                jaxon.cmd.delay.setWakeup(command.response, 100);
                return false;
            }
            // give up, continue processing queue
        }
        return true;
    },

    /*
    Function: jaxon.cmd.script.call

    Call a javascript function with a series of parameters using the current script context.

    Parameters:

    command - The response command object containing the following:
        - command.data: (array):  The parameters to pass to the function.
        - command.func: (string):  The name of the function to call.
        - command.context: (object):  The current script context object which is accessable in the
            function name via the 'this keyword.

    Returns:

    true - The call completed successfully.
    */
    call: function(command) {
        command.fullName = 'call js function';

        const parameters = command.data;

        const scr = new Array();
        scr.push(command.func);
        scr.push('(');
        if ('undefined' != typeof parameters) {
            if ('object' == typeof parameters) {
                const iLen = parameters.length;
                if (0 < iLen) {
                    scr.push('parameters[0]');
                    for (let i = 1; i < iLen; ++i)
                        scr.push(', parameters[' + i + ']');
                }
            }
        }
        scr.push(');');
        command.context.jaxonDelegateCall = function() {
            eval(scr.join(''));
        }
        command.context.jaxonDelegateCall();
        return true;
    },

    /*
    Function: jaxon.cmd.script.setFunction

    Constructs the specified function using the specified javascript as the body of the function.

    Parameters:

    command - The response command object which contains the following:

        - command.func: (string):  The name of the function to construct.
        - command.data: (string):  The script that will be the function body.
        - command.context: (object):  The current script context object
            which is accessable in the script name via the 'this' keyword.

    Returns:

    true - The function was constructed successfully.
    */
    setFunction: function(command) {
        command.fullName = 'setFunction';

        const code = new Array();
        code.push(command.func);
        code.push(' = function(');
        if ('object' == typeof command.prop) {
            let separator = '';
            for (let m in command.prop) {
                code.push(separator);
                code.push(command.prop[m]);
                separator = ',';
            }
        } else code.push(command.prop);
        code.push(') { ');
        code.push(command.data);
        code.push(' }');
        command.context.jaxonDelegateCall = function() {
            eval(code.join(''));
        }
        command.context.jaxonDelegateCall();
        return true;
    },

    /*
    Function: jaxon.cmd.script.wrapFunction

    Construct a javascript function which will call the original function with the same name,
    potentially executing code before and after the call to the original function.

    Parameters:

    command - (object):  The response command object which will contain the following:

        - command.func: (string):  The name of the function to be wrapped.
        - command.prop: (string):  List of parameters used when calling the function.
        - command.data: (array):  The portions of code to be called before, after
            or even between calls to the original function.
        - command.context: (object):  The current script context object which is
            accessable in the function name and body via the 'this' keyword.

    Returns:

    true - The wrapper function was constructed successfully.
    */
    wrapFunction: function(command) {
        command.fullName = 'wrapFunction';

        const code = new Array();
        code.push(command.func);
        code.push(' = jaxon.cmd.script.makeWrapper(');
        code.push(command.func);
        code.push(', command.prop, command.data, command.type, command.context);');
        command.context.jaxonDelegateCall = function() {
            eval(code.join(''));
        }
        command.context.jaxonDelegateCall();
        return true;
    },

    /*
    Function: jaxon.cmd.script.makeWrapper


    Helper function used in the wrapping of an existing javascript function.

    Parameters:

    origFun - (string):  The name of the original function.
    args - (string):  The list of parameters used when calling the function.
    codeBlocks - (array):  Array of strings of javascript code to be executed
        before, after and perhaps between calls to the original function.
    returnVariable - (string):  The name of the variable used to retain the
        return value from the call to the original function.
    context - (object):  The current script context object which is accessable
        in the function name and body via the 'this' keyword.

    Returns:

    object - The complete wrapper function.
    */
    makeWrapper: function(origFun, args, codeBlocks, returnVariable, context) {
        const originalCall = (returnVariable.length > 0 ? returnVariable + ' = ' : '') +
            origFun + '(' + args + '); ';

        let code = 'wrapper = function(' + args + ') { ';

        if (returnVariable.length > 0) {
            code += ' let ' + returnVariable + ' = null;';
        }
        let separator = '';
        const bLen = codeBlocks.length;
        for (let b = 0; b < bLen; ++b) {
            code += separator + codeBlocks[b];
            separator = originalCall;
        }
        if (returnVariable.length > 0) {
            code += ' return ' + returnVariable + ';';
        }
        code += ' } ';

        let wrapper = null;
        context.jaxonDelegateCall = function() {
            eval(code);
        }
        context.jaxonDelegateCall();
        return wrapper;
    }
};


jaxon.cmd.style = {
    /*
    Function: jaxon.cmd.style.add

    Add a LINK reference to the specified .css file if it does not already exist in the HEAD of the current document.

    Parameters:

    filename - (string):  The URI of the .css file to reference.
    media - (string):  The media type of the css file (print/screen/handheld,..)

    Returns:

    true - The operation completed successfully.
    */
    add: function(fileName, media) {
        const oDoc = jaxon.config.baseDocument;
        const oHeads = oDoc.getElementsByTagName('head');
        const oHead = oHeads[0];
        const oLinks = oHead.getElementsByTagName('link');

        const found = false;
        const iLen = oLinks.length;
        for (let i = 0; i < iLen && false == found; ++i)
            if (0 <= oLinks[i].href.indexOf(fileName) && oLinks[i].media == media)
                found = true;

        if (false == found) {
            const oCSS = oDoc.createElement('link');
            oCSS.rel = 'stylesheet';
            oCSS.type = 'text/css';
            oCSS.href = fileName;
            oCSS.media = media;
            oHead.appendChild(oCSS);
        }

        return true;
    },

    /*
    Function: jaxon.cmd.style.remove

    Locate and remove a LINK reference from the current document's HEAD.

    Parameters:

    filename - (string):  The URI of the .css file.

    Returns:

    true - The operation completed successfully.
    */
    remove: function(fileName, media) {
        const oDoc = jaxon.config.baseDocument;
        const oHeads = oDoc.getElementsByTagName('head');
        const oHead = oHeads[0];
        const oLinks = oHead.getElementsByTagName('link');

        let i = 0;
        while (i < oLinks.length)
            if (0 <= oLinks[i].href.indexOf(fileName) && oLinks[i].media == media)
                oHead.removeChild(oLinks[i]);
            else ++i;

        return true;
    },

    /*
    Function: jaxon.cmd.style.waitForCSS

    Attempt to detect when all .css files have been loaded once they are referenced by a LINK tag
    in the HEAD of the current document.

    Parameters:

    command - (object):  The response command object which will contain the following:
        - command.prop - (integer):  The number of 1/10ths of a second to wait before giving up.

    Returns:

    true - The .css files appear to be loaded.
    false - The .css files do not appear to be loaded and the timeout has not expired.
    */
    waitForCSS: function(command) {
        const oDocSS = jaxon.config.baseDocument.styleSheets;
        const ssEnabled = [];
        let iLen = oDocSS.length;
        for (let i = 0; i < iLen; ++i) {
            ssEnabled[i] = 0;
            try {
                ssEnabled[i] = oDocSS[i].cssRules.length;
            } catch (e) {
                try {
                    ssEnabled[i] = oDocSS[i].rules.length;
                } catch (e) {}
            }
        }

        const ssLoaded = true;
        iLen = ssEnabled.length;
        for (let i = 0; i < iLen; ++i)
            if (0 == ssEnabled[i])
                ssLoaded = false;

        if (false == ssLoaded) {
            // inject a delay in the queue processing
            // handle retry counter
            if (jaxon.cmd.delay.retry(command, command.prop)) {
                jaxon.cmd.delay.setWakeup(command.response, 10);
                return false;
            }
            // give up, continue processing queue
        }
        return true;
    }
};


jaxon.cmd.tree = {
    startResponse: function(command) {
        jxnElm = [];
    },

    createElement: function(command) {
        eval(
            [command.tgt, ' = document.createElement(command.data)']
            .join('')
        );
    },

    setAttribute: function(command) {
        command.context.jaxonDelegateCall = function() {
            eval(
                [command.tgt, '.setAttribute(command.key, command.data)']
                .join('')
            );
        }
        command.context.jaxonDelegateCall();
    },

    appendChild: function(command) {
        command.context.jaxonDelegateCall = function() {
            eval(
                [command.par, '.appendChild(', command.data, ')']
                .join('')
            );
        }
        command.context.jaxonDelegateCall();
    },

    insertBefore: function(command) {
        command.context.jaxonDelegateCall = function() {
            eval(
                [command.tgt, '.parentNode.insertBefore(', command.data, ', ', command.tgt, ')']
                .join('')
            );
        }
        command.context.jaxonDelegateCall();
    },

    insertAfter: function(command) {
        command.context.jaxonDelegateCall = function() {
            eval(
                [command.tgt, 'parentNode.insertBefore(', command.data, ', ', command.tgt, '.nextSibling)']
                .join('')
            );
        }
        command.context.jaxonDelegateCall();
    },

    appendText: function(command) {
        command.context.jaxonDelegateCall = function() {
            eval(
                [command.par, '.appendChild(document.createTextNode(command.data))']
                .join('')
            );
        }
        command.context.jaxonDelegateCall();
    },

    removeChildren: function(command) {
        let skip = command.skip || 0;
        let remove = command.remove || -1;
        let element = null;
        command.context.jaxonDelegateCall = function() {
            eval(['element = ', command.data].join(''));
        }
        command.context.jaxonDelegateCall();
        const children = element.childNodes;
        for (let i in children) {
            if (isNaN(i) == false && children[i].nodeType == 1) {
                if (skip > 0) skip = skip - 1;
                else if (remove != 0) {
                    if (remove > 0)
                        remove = remove - 1;
                    element.removeChild(children[i]);
                }
            }
        }
    },

    endResponse: function(command) {
        jxnElm = [];
    }
};


jaxon.ajax.callback = {
    /*
    Function: jaxon.ajax.callback.create

    Create a blank callback object.
    Two optional arguments let you set the delay time for the onResponseDelay and onExpiration events.

    Returns:

    object - The callback object.
    */
    create: function() {
        const xc = jaxon.config;
        const xcb = jaxon.ajax.callback;

        const oCB = {};
        oCB.timers = {};

        oCB.timers.onResponseDelay = xcb.setupTimer((arguments.length > 0) ?
            arguments[0] : xc.defaultResponseDelayTime);

        oCB.timers.onExpiration = xcb.setupTimer((arguments.length > 1) ?
            arguments[1] : xc.defaultExpirationTime);

        oCB.onPrepare = null;
        oCB.onRequest = null;
        oCB.onResponseDelay = null;
        oCB.onExpiration = null;
        oCB.beforeResponseProcessing = null;
        oCB.onFailure = null;
        oCB.onRedirect = null;
        oCB.onSuccess = null;
        oCB.onComplete = null;

        return oCB;
    },

    /*
    Function: jaxon.ajax.callback.setupTimer

    Create a timer to fire an event in the future.
    This will be used fire the onRequestDelay and onExpiration events.

    Parameters:

    iDelay - (integer):  The amount of time in milliseconds to delay.

    Returns:

    object - A callback timer object.
    */
    setupTimer: function(iDelay) {
        return { timer: null, delay: iDelay };
    },

    /*
    Function: jaxon.ajax.callback.clearTimer

    Clear a callback timer for the specified function.

    Parameters:

    oCallback - (object):  The callback object (or objects) that
        contain the specified function timer to be cleared.
    sFunction - (string):  The name of the function associated
        with the timer to be cleared.
    */
    clearTimer: function(oCallback, sFunction) {
        // The callback object is recognized by the presence of the timers attribute.
        if ('undefined' == typeof oCallback.timers) {
            for (let i = 0; i < oCallback.length; ++i) {
                jaxon.ajax.callback.clearTimer(oCallback[i], sFunction);
            }
            return;
        }

        if ('undefined' != typeof oCallback.timers[sFunction]) {
            clearTimeout(oCallback.timers[sFunction].timer);
        }
    },

    /*
    Function: jaxon.ajax.callback.execute

    Execute a callback event.

    Parameters:

    oCallback - (object):  The callback object (or objects) which
        contain the event handlers to be executed.
    sFunction - (string):  The name of the event to be triggered.
    args - (object):  The request object for this request.
    */
    execute: function(oCallback, sFunction, args) {
        // The callback object is recognized by the presence of the timers attribute.
        if ('undefined' == typeof oCallback.timers) {
            for (let i = 0; i < oCallback.length; ++i) {
                jaxon.ajax.callback.execute(oCallback[i], sFunction, args);
            }
            return;
        }

        if ('undefined' == typeof oCallback[sFunction] ||
            'function' != typeof oCallback[sFunction]) {
            return;
        }

        let func = oCallback[sFunction];
        if ('undefined' != typeof oCallback.timers[sFunction]) {
            oCallback.timers[sFunction].timer = setTimeout(function() {
                func(args);
            }, oCallback.timers[sFunction].delay);
        } else {
            func(args);
        }
    }
};


jaxon.ajax.handler = {
    /*
    Object: jaxon.ajax.handler.handlers

    An array that is used internally in the jaxon.fn.handler object
    to keep track of command handlers that have been registered.
    */
    handlers: [],

    /*
    Function: jaxon.ajax.handler.execute

    Perform a lookup on the command specified by the response command
    object passed in the first parameter.  If the command exists, the
    function checks to see if the command references a DOM object by
    ID; if so, the object is located within the DOM and added to the
    command data.  The command handler is then called.

    If the command handler returns true, it is assumed that the command
    completed successfully.  If the command handler returns false, then the
    command is considered pending; jaxon enters a wait state.  It is up
    to the command handler to set an interval, timeout or event handler
    which will restart the jaxon response processing.

    Parameters:

    obj - (object):  The response command to be executed.

    Returns:

    true - The command completed successfully.
    false - The command signalled that it needs to pause processing.
    */
    execute: function(command) {
        if (jaxon.ajax.handler.isRegistered(command)) {
            // it is important to grab the element here as the previous command
            // might have just created the element
            if (command.id) {
                command.target = jaxon.$(command.id);
            }
            // process the command
            return jaxon.ajax.handler.call(command);
        }
        return true;
    },

    /*
    Function: jaxon.ajax.handler.register

    Registers a new command handler.
    */
    register: function(shortName, func) {
        jaxon.ajax.handler.handlers[shortName] = func;
    },

    /*
    Function: jaxon.ajax.handler.unregister

    Unregisters and returns a command handler.

    Parameters:
        shortName - (string): The name of the command handler.

    Returns:
        func - (function): The unregistered function.
    */
    unregister: function(shortName) {
        const func = jaxon.ajax.handler.handlers[shortName];
        delete jaxon.ajax.handler.handlers[shortName];
        return func;
    },

    /*
    Function: jaxon.ajax.handler.isRegistered


    Parameters:
        command - (object):
            - cmd: The Name of the function.

    Returns:

    boolean - (true or false): depending on whether a command handler has
    been created for the specified command (object).

    */
    isRegistered: function(command) {
        return (jaxon.ajax.handler.handlers[command.cmd]) ? true : false;
    },

    /*
    Function: jaxon.ajax.handler.call

    Calls the registered command handler for the specified command
    (you should always check isRegistered before calling this function)

    Parameters:
        command - (object):
            - cmd: The Name of the function.

    Returns:
        true - (boolean) :
    */
    call: function(command) {
        return jaxon.ajax.handler.handlers[command.cmd](command);
    }
};

jaxon.ajax.handler.register('rcmplt', function(command) {
    jaxon.ajax.response.complete(command.request);
    return true;
});

jaxon.ajax.handler.register('css', function(command) {
    command.fullName = 'includeCSS';
    if ('undefined' == typeof command.media)
        command.media = 'screen';
    return jaxon.cmd.style.add(command.data, command.media);
});
jaxon.ajax.handler.register('rcss', function(command) {
    command.fullName = 'removeCSS';
    if ('undefined' == typeof command.media)
        command.media = 'screen';
    return jaxon.cmd.style.remove(command.data, command.media);
});
jaxon.ajax.handler.register('wcss', function(command) {
    command.fullName = 'waitForCSS';
    return jaxon.cmd.style.waitForCSS(command);
});

jaxon.ajax.handler.register('as', function(command) {
    command.fullName = 'assign/clear';
    try {
        return jaxon.cmd.node.assign(command.target, command.prop, command.data);
    } catch (e) {
        // do nothing, if the debug module is installed it will
        // catch and handle the exception
    }
    return true;
});
jaxon.ajax.handler.register('ap', function(command) {
    command.fullName = 'append';
    return jaxon.cmd.node.append(command.target, command.prop, command.data);
});
jaxon.ajax.handler.register('pp', function(command) {
    command.fullName = 'prepend';
    return jaxon.cmd.node.prepend(command.target, command.prop, command.data);
});
jaxon.ajax.handler.register('rp', function(command) {
    command.fullName = 'replace';
    return jaxon.cmd.node.replace(command.id, command.prop, command.data);
});
jaxon.ajax.handler.register('rm', function(command) {
    command.fullName = 'remove';
    return jaxon.cmd.node.remove(command.id);
});
jaxon.ajax.handler.register('ce', function(command) {
    command.fullName = 'create';
    return jaxon.cmd.node.create(command.id, command.data, command.prop);
});
jaxon.ajax.handler.register('ie', function(command) {
    command.fullName = 'insert';
    return jaxon.cmd.node.insert(command.id, command.data, command.prop);
});
jaxon.ajax.handler.register('ia', function(command) {
    command.fullName = 'insertAfter';
    return jaxon.cmd.node.insertAfter(command.id, command.data, command.prop);
});

jaxon.ajax.handler.register('DSR', jaxon.cmd.tree.startResponse);
jaxon.ajax.handler.register('DCE', jaxon.cmd.tree.createElement);
jaxon.ajax.handler.register('DSA', jaxon.cmd.tree.setAttribute);
jaxon.ajax.handler.register('DAC', jaxon.cmd.tree.appendChild);
jaxon.ajax.handler.register('DIB', jaxon.cmd.tree.insertBefore);
jaxon.ajax.handler.register('DIA', jaxon.cmd.tree.insertAfter);
jaxon.ajax.handler.register('DAT', jaxon.cmd.tree.appendText);
jaxon.ajax.handler.register('DRC', jaxon.cmd.tree.removeChildren);
jaxon.ajax.handler.register('DER', jaxon.cmd.tree.endResponse);

jaxon.ajax.handler.register('c:as', jaxon.cmd.node.contextAssign);
jaxon.ajax.handler.register('c:ap', jaxon.cmd.node.contextAppend);
jaxon.ajax.handler.register('c:pp', jaxon.cmd.node.contextPrepend);

jaxon.ajax.handler.register('s', jaxon.cmd.script.sleep);
jaxon.ajax.handler.register('ino', jaxon.cmd.script.includeScriptOnce);
jaxon.ajax.handler.register('in', jaxon.cmd.script.includeScript);
jaxon.ajax.handler.register('rjs', jaxon.cmd.script.removeScript);
jaxon.ajax.handler.register('wf', jaxon.cmd.script.waitFor);
jaxon.ajax.handler.register('js', jaxon.cmd.script.execute);
jaxon.ajax.handler.register('jc', jaxon.cmd.script.call);
jaxon.ajax.handler.register('sf', jaxon.cmd.script.setFunction);
jaxon.ajax.handler.register('wpf', jaxon.cmd.script.wrapFunction);
jaxon.ajax.handler.register('al', jaxon.cmd.script.alert);
jaxon.ajax.handler.register('cc', jaxon.cmd.script.confirm);

jaxon.ajax.handler.register('ci', jaxon.cmd.form.createInput);
jaxon.ajax.handler.register('ii', jaxon.cmd.form.insertInput);
jaxon.ajax.handler.register('iia', jaxon.cmd.form.insertInputAfter);

jaxon.ajax.handler.register('ev', jaxon.cmd.event.setEvent);

jaxon.ajax.handler.register('ah', jaxon.cmd.event.addHandler);
jaxon.ajax.handler.register('rh', jaxon.cmd.event.removeHandler);

jaxon.ajax.handler.register('dbg', function(command) {
    command.fullName = 'debug message';
    console.log(command.data);
    return true;
});


jaxon.ajax.message = {
    /*
    Function: jaxon.ajax.message.success

    Print a success message on the screen.

    Parameters:
        content - (string):  The message content.
        title - (string):  The message title.
    */
    success: function(content, title) {
        alert(content);
    },

    /*
    Function: jaxon.ajax.message.info

    Print an info message on the screen.

    Parameters:
        content - (string):  The message content.
        title - (string):  The message title.
    */
    info: function(content, title) {
        alert(content);
    },

    /*
    Function: jaxon.ajax.message.warning

    Print a warning message on the screen.

    Parameters:
        content - (string):  The message content.
        title - (string):  The message title.
    */
    warning: function(content, title) {
        alert(content);
    },

    /*
    Function: jaxon.ajax.message.error

    Print an error message on the screen.

    Parameters:
        content - (string):  The message content.
        title - (string):  The message title.
    */
    error: function(content, title) {
        alert(content);
    },

    /*
    Function: jaxon.ajax.message.confirm

    Print an error message on the screen.

    Parameters:
        question - (string):  The confirm question.
        title - (string):  The confirm title.
        yesCallback - (Function): The function to call if the user answers yes.
        noCallback - (Function): The function to call if the user answers no.
    */
    confirm: function(question, title, yesCallback, noCallback) {
        if(confirm(question)) {
            yesCallback();
        } else if(noCallback != undefined) {
            noCallback();
        }
    }
};


jaxon.ajax.parameters = {
    /**
     * The array of data bags
     * @type {object}
     */
    bags: {},

    /**
     * Stringify a parameter of an ajax call.
     *
     * @param {*} oVal - The value to be stringified
     *
     * @returns {string}
     */
    stringify: function(oVal) {
        if (oVal === undefined ||  oVal === null) {
            return '*';
        }
        const sType = typeof oVal;
        if (sType === 'object') {
            try {
                return encodeURIComponent(JSON.stringify(oVal));
            } catch (e) {
                oVal = '';
                // do nothing, if the debug module is installed
                // it will catch the exception and handle it
            }
        }
        oVal = encodeURIComponent(oVal);
        if (sType === 'string') {
            return 'S' + oVal;
        }
        if (sType === 'boolean') {
            return 'B' + oVal;
        }
        if (sType === 'number') {
            return 'N' + oVal;
        }
        return oVal;
    },

    /*
    Function: jaxon.ajax.parameters.toFormData

    Processes request specific parameters and store them in a FormData object.

    Parameters:

    oRequest - A request object, created initially by a call to <jaxon.ajax.request.initialize>
    */
    toFormData: function(oRequest) {
        const rd = new FormData();
        rd.append('jxnr', oRequest.dNow.getTime());

        // Files to upload
        const input = oRequest.upload.input;
        for (const file of input.files) {
            rd.append(input.name, file);
        }

        for (let sCommand in oRequest.functionName) {
            rd.append(sCommand, encodeURIComponent(oRequest.functionName[sCommand]));
        }

        if (oRequest.parameters) {
            for (const oVal of oRequest.parameters) {
                rd.append('jxnargs[]', jaxon.ajax.parameters.stringify(oVal));
            }
        }

        if (oRequest.bags) {
            const oValues = {};
            for (const sBag of oRequest.bags) {
                oValues[sBag] = jaxon.ajax.parameters.bags[sBag] ?? '*';
            }
            rd.append('jxnbags', jaxon.ajax.parameters.stringify(oValues));
        }

        oRequest.requestURI = oRequest.URI;
        oRequest.requestData = rd;
    },

    /*
    Function: jaxon.ajax.parameters.toUrlEncoded

    Processes request specific parameters and store them in an URL encoded string.

    Parameters:

    oRequest - A request object, created initially by a call to <jaxon.ajax.request.initialize>
    */
    toUrlEncoded: function(oRequest) {
        const rd = [];
        rd.push('jxnr=' + oRequest.dNow.getTime());

        for (const sCommand in oRequest.functionName) {
            rd.push(sCommand + '=' + encodeURIComponent(oRequest.functionName[sCommand]));
        }

        if (oRequest.parameters) {
            for (const oVal of oRequest.parameters) {
                rd.push('jxnargs[]=' + jaxon.ajax.parameters.stringify(oVal));
            }
        }

        if (oRequest.bags) {
            const oValues = {};
            for (const sBag of oRequest.bags) {
                oValues[sBag] = jaxon.ajax.parameters.bags[sBag] ?? '*';
            }
            rd.push('jxnbags=' + jaxon.ajax.parameters.stringify(oValues));
        }

        oRequest.requestURI = oRequest.URI;

        if ('GET' === oRequest.method) {
            oRequest.requestURI += oRequest.requestURI.indexOf('?') === -1 ? '?' : '&';
            oRequest.requestURI += rd.join('&');
            rd = [];
        }

        oRequest.requestData = rd.join('&');
    },

    /*
    Function: jaxon.ajax.parameters.process

    Processes request specific parameters and generates the temporary
    variables needed by jaxon to initiate and process the request.

    Parameters:

    oRequest - A request object, created initially by a call to <jaxon.ajax.request.initialize>

    Note:
    This is called once per request; upon a request failure, this
    will not be called for additional retries.
    */
    process: function(oRequest) {
        const func = (oRequest.upload && oRequest.upload.ajax && oRequest.upload.input) ?
            jaxon.ajax.parameters.toFormData : jaxon.ajax.parameters.toUrlEncoded;
        // Make request parameters.
        oRequest.dNow = new Date();
        func(oRequest);
        delete oRequest.dNow;
    }
};


jaxon.ajax.request = {
    /*
    Function: jaxon.ajax.request.initialize

    Initialize a request object, populating default settings, where
    call specific settings are not already provided.

    Parameters:

    oRequest - (object):  An object that specifies call specific settings
        that will, in addition, be used to store all request related
        values.  This includes temporary values used internally by jaxon.
    */
    initialize: function(oRequest) {
        const xx = jaxon;
        const xc = xx.config;

        oRequest.append = function(opt, def) {
            if('undefined' == typeof this[opt])
                this[opt] = {};
            for (const itmName in def)
                if('undefined' == typeof this[opt][itmName])
                    this[opt][itmName] = def[itmName];
        };

        oRequest.append('commonHeaders', xc.commonHeaders);
        oRequest.append('postHeaders', xc.postHeaders);
        oRequest.append('getHeaders', xc.getHeaders);

        oRequest.set = function(option, defaultValue) {
            if('undefined' == typeof this[option])
                this[option] = defaultValue;
        };

        oRequest.set('statusMessages', xc.statusMessages);
        oRequest.set('waitCursor', xc.waitCursor);
        oRequest.set('mode', xc.defaultMode);
        oRequest.set('method', xc.defaultMethod);
        oRequest.set('URI', xc.requestURI);
        oRequest.set('httpVersion', xc.defaultHttpVersion);
        oRequest.set('contentType', xc.defaultContentType);
        oRequest.set('retry', xc.defaultRetry);
        oRequest.set('returnValue', xc.defaultReturnValue);
        oRequest.set('maxObjectDepth', xc.maxObjectDepth);
        oRequest.set('maxObjectSize', xc.maxObjectSize);
        oRequest.set('context', window);
        oRequest.set('upload', false);
        oRequest.set('aborted', false);

        const xcb = xx.ajax.callback;
        const lcb = xcb.create();

        lcb.take = function(frm, opt) {
            if('undefined' != typeof frm[opt]) {
                lcb[opt] = frm[opt];
                lcb.hasEvents = true;
            }
            delete frm[opt];
        };

        lcb.take(oRequest, 'onPrepare');
        lcb.take(oRequest, 'onRequest');
        lcb.take(oRequest, 'onResponseDelay');
        lcb.take(oRequest, 'onExpiration');
        lcb.take(oRequest, 'beforeResponseProcessing');
        lcb.take(oRequest, 'onFailure');
        lcb.take(oRequest, 'onRedirect');
        lcb.take(oRequest, 'onSuccess');
        lcb.take(oRequest, 'onComplete');

        if('undefined' != typeof oRequest.callback) {
            // Add the timers attribute, if it is not defined.
            if('undefined' == typeof oRequest.callback.timers) {
                oRequest.callback.timers = [];
            }
            if(lcb.hasEvents) {
                oRequest.callback = [oRequest.callback, lcb];
            }
        } else {
            oRequest.callback = lcb;
        }

        oRequest.status = (oRequest.statusMessages) ?
            xc.status.update() :
            xc.status.dontUpdate();

        oRequest.cursor = (oRequest.waitCursor) ?
            xc.cursor.update() :
            xc.cursor.dontUpdate();

        oRequest.method = oRequest.method.toUpperCase();
        if('GET' != oRequest.method)
            oRequest.method = 'POST'; // W3C: Method is case sensitive

        oRequest.requestRetry = oRequest.retry;

        // Look for upload parameter
        oRequest.ajax = !!window.FormData;
        jaxon.tools.upload.initialize(oRequest);

        delete oRequest['append'];
        delete oRequest['set'];
        delete lcb['take'];

        if('undefined' == typeof oRequest.URI)
            throw { code: 10005 };
    },

    /*
    Function: jaxon.ajax.request.prepare

    Prepares the XMLHttpRequest object for this jaxon request.

    Parameters:

    oRequest - (object):  An object created by a call to <jaxon.ajax.request.initialize>
        which already contains the necessary parameters and temporary variables
        needed to initiate and process a jaxon request.

    Note:
    This is called each time a request object is being prepared for a call to the server.
    If the request is retried, the request must be prepared again.
    */
    prepare: function(oRequest) {
        const xx = jaxon;
        const xt = xx.tools;
        const xcb = xx.ajax.callback;
        const gcb = xx.callback;
        const lcb = oRequest.callback;

        xcb.execute([gcb, lcb], 'onPrepare', oRequest);

        // Check if the request must be aborted
        if(oRequest.aborted === true) {
            return false;
        }

        oRequest.request = xt.ajax.createRequest();

        oRequest.setRequestHeaders = function(headers) {
            if('object' === typeof headers) {
                for (let optionName in headers)
                    this.request.setRequestHeader(optionName, headers[optionName]);
            }
        };
        oRequest.setCommonRequestHeaders = function() {
            this.setRequestHeaders(this.commonHeaders);
        };
        oRequest.setPostRequestHeaders = function() {
            this.setRequestHeaders(this.postHeaders);
        };
        oRequest.setGetRequestHeaders = function() {
            this.setRequestHeaders(this.getHeaders);
        };

        // if('asynchronous' == oRequest.mode) {
            // references inside this function should be expanded
            // IOW, don't use shorthand references like xx for jaxon
        /*} else {
            oRequest.finishRequest = function() {
                return jaxon.ajax.response.received(oRequest);
            };
        }*/
        oRequest.request.onreadystatechange = function() {
            if(oRequest.request.readyState !== 4) {
                return;
            }
            // Synchronous request are processed immediately.
            // Asynchronous request are processed only if the queue is empty.
            if(jaxon.tools.queue.empty(jaxon.cmd.delay.q.send) ||
                'synchronous' == oRequest.mode) {
                jaxon.ajax.response.received(oRequest);
            } else {
                jaxon.tools.queue.push(jaxon.cmd.delay.q.recv, oRequest);
            }
        };
        oRequest.finishRequest = function() {
            return this.returnValue;
        };

        if('undefined' !== typeof oRequest.userName && 'undefined' !== typeof oRequest.password) {
            oRequest.open = function() {
                this.request.open(
                    this.method,
                    this.requestURI,
                    true, // 'asynchronous' == this.mode,
                    oRequest.userName,
                    oRequest.password);
            };
        } else {
            oRequest.open = function() {
                this.request.open(
                    this.method,
                    this.requestURI,
                    true); // 'asynchronous' == this.mode);
            };
        }

        if('POST' == oRequest.method) { // W3C: Method is case sensitive
            oRequest.applyRequestHeaders = function() {
                this.setCommonRequestHeaders();
                try {
                    this.setPostRequestHeaders();
                } catch (e) {
                    this.method = 'GET';
                    this.requestURI += this.requestURI.indexOf('?') == -1 ? '?' : '&';
                    this.requestURI += this.requestData;
                    this.requestData = '';
                    if(0 == this.requestRetry) this.requestRetry = 1;
                    throw e;
                }
            }
        } else {
            oRequest.applyRequestHeaders = function() {
                this.setCommonRequestHeaders();
                this.setGetRequestHeaders();
            };
        }

        // No request is submitted while there are pending requests in the outgoing queue.
        let submitRequest = jaxon.tools.queue.empty(jaxon.cmd.delay.q.send);
        if('synchronous' === oRequest.mode) {
            // Synchronous requests are always queued, in both send and recv queues.
            jaxon.tools.queue.push(jaxon.cmd.delay.q.send, oRequest);
            jaxon.tools.queue.push(jaxon.cmd.delay.q.recv, oRequest);
        } else if(!submitRequest) {
            // Asynchronous requests are queued in send queue only if they are not submitted.
            jaxon.tools.queue.push(jaxon.cmd.delay.q.send, oRequest);
        }
        return submitRequest;
    },

    /*
    Function: jaxon.ajax.request.submit

    Create a request object and submit the request using the specified request type;
    all request parameters should be finalized by this point.
    Upon failure of a POST, this function will fall back to a GET request.

    Parameters:
    oRequest - (object):  The request context object.
    */
    submit: function(oRequest) {
        oRequest.status.onRequest();

        const xx = jaxon;
        const xcb = xx.ajax.callback;
        const gcb = xx.callback;
        const lcb = oRequest.callback;

        xcb.execute([gcb, lcb], 'onResponseDelay', oRequest);
        xcb.execute([gcb, lcb], 'onExpiration', oRequest);
        xcb.execute([gcb, lcb], 'onRequest', oRequest);

        oRequest.open();
        oRequest.applyRequestHeaders();

        oRequest.cursor.onWaiting();
        oRequest.status.onWaiting();

        if(oRequest.upload !== false && !oRequest.upload.ajax && oRequest.upload.form) {
            // The request will be sent after the files are uploaded
            oRequest.upload.iframe.onload = function() {
                jaxon.ajax.response.upload(oRequest);
            }
            // Submit the upload form
            oRequest.upload.form.submit();
        } else {
            jaxon.ajax.request._send(oRequest);
        }

        // synchronous mode causes response to be processed immediately here
        return oRequest.finishRequest();
    },

    /*
    Function: jaxon.ajax.request._send

    This function is used internally by jaxon to initiate a request to the server.

    Parameters:

    oRequest - (object):  The request context object.
    */
    _send: function(oRequest) {
        // this may block if synchronous mode is selected
        oRequest.request.send(oRequest.requestData);
    },

    /*
    Function: jaxon.ajax.request.abort

    Abort the request.

    Parameters:

    oRequest - (object):  The request context object.
    */
    abort: function(oRequest) {
        oRequest.aborted = true;
        oRequest.request.abort();
        jaxon.ajax.response.complete(oRequest);
    },

    /*
    Function: jaxon.ajax.request.execute

    Initiates a request to the server.

    Parameters:

    functionName - (object):  An object containing the name of the function to execute
    on the server. The standard request is: {jxnfun:'function_name'}

    functionArgs - (object, optional):  A request object which
        may contain call specific parameters.  This object will be
        used by jaxon to store all the request parameters as well
        as temporary variables needed during the processing of the
        request.

    */
    execute: function(functionName, functionArgs) {
        if(functionName === undefined)
            return false;

        const oRequest = functionArgs ?? {};
        oRequest.functionName = functionName;

        const xx = jaxon;
        xx.ajax.request.initialize(oRequest);
        xx.ajax.parameters.process(oRequest);

        while (oRequest.requestRetry > 0) {
            try {
                if(xx.ajax.request.prepare(oRequest))
                {
                    --oRequest.requestRetry;
                    return xx.ajax.request.submit(oRequest);
                }
                return null;
            } catch (e) {
                jaxon.ajax.callback.execute([jaxon.callback, oRequest.callback], 'onFailure', oRequest);
                if(oRequest.requestRetry === 0)
                    throw e;
            }
        }
    }
};


jaxon.ajax.response = {
    /*
    Function: jaxon.ajax.response.received

    Process the response.

    Parameters:

    oRequest - (object):  The request context object.
    */
    received: function(oRequest) {
        const xx = jaxon;
        const xcb = xx.ajax.callback;
        const gcb = xx.callback;
        const lcb = oRequest.callback;
        // sometimes the responseReceived gets called when the request is aborted
        if (oRequest.aborted) {
            return null;
        }

        // Create a response queue for this request.
        oRequest.response = xx.tools.queue.create(xx.config.responseQueueSize);

        xcb.clearTimer([gcb, lcb], 'onExpiration');
        xcb.clearTimer([gcb, lcb], 'onResponseDelay');
        xcb.execute([gcb, lcb], 'beforeResponseProcessing', oRequest);

        const fProc = xx.ajax.response.processor(oRequest);
        if (null == fProc) {
            xcb.execute([gcb, lcb], 'onFailure', oRequest);
            xx.ajax.response.complete(oRequest);
            return;
        }

        return fProc(oRequest);
    },

    /*
    Function: jaxon.ajax.response.complete

    Called by the response command queue processor when all commands have been processed.

    Parameters:

    oRequest - (object):  The request context object.
    */
    complete: function(oRequest) {
        jaxon.ajax.callback.execute(
            [jaxon.callback, oRequest.callback],
            'onComplete',
            oRequest
        );
        oRequest.cursor.onComplete();
        oRequest.status.onComplete();
        // clean up -- these items are restored when the request is initiated
        delete oRequest['functionName'];
        delete oRequest['requestURI'];
        delete oRequest['requestData'];
        delete oRequest['requestRetry'];
        delete oRequest['request'];
        delete oRequest['response'];
        delete oRequest['set'];
        delete oRequest['open'];
        delete oRequest['setRequestHeaders'];
        delete oRequest['setCommonRequestHeaders'];
        delete oRequest['setPostRequestHeaders'];
        delete oRequest['setGetRequestHeaders'];
        delete oRequest['applyRequestHeaders'];
        delete oRequest['finishRequest'];
        delete oRequest['status'];
        delete oRequest['cursor'];

        // All the requests queued while waiting must now be processed.
        if('synchronous' == oRequest.mode) {
            let jq = jaxon.tools.queue;
            let jd = jaxon.cmd.delay;
            // Remove the current request from the send and recv queues.
            jq.pop(jd.q.send);
            jq.pop(jd.q.recv);
            // Process the asynchronous requests received while waiting.
            while((recvRequest = jd.popAsyncRequest(jd.q.recv)) != null) {
                jaxon.ajax.response.received(recvRequest);
            }
            // Submit the asynchronous requests sent while waiting.
            while((nextRequest = jd.popAsyncRequest(jd.q.send)) != null) {
                jaxon.ajax.request.submit(nextRequest);
            }
            // Submit the next synchronous request, if there's any.
            if((nextRequest = jq.peek(jd.q.send)) != null) {
                jaxon.ajax.request.submit(nextRequest);
            }
        }
    },

    /*
    Function: jaxon.ajax.response.process

    While entries exist in the queue, pull and entry out and process it's command.
    When a command returns false, the processing is halted.

    Parameters:

    response - (object): The response, which is a queue containing the commands to execute.
    This should have been created by calling <jaxon.tools.queue.create>.

    Returns:

    true - The queue was fully processed and is now empty.
    false - The queue processing was halted before the queue was fully processed.

    Note:

    - Use <jaxon.cmd.delay.setWakeup> or call this function to cause the queue processing to continue.
    - This will clear the associated timeout, this function is not designed to be reentrant.
    - When an exception is caught, do nothing; if the debug module is installed, it will catch the exception and handle it.
    */
    process: function(response) {
        if (null != response.timeout) {
            clearTimeout(response.timeout);
            response.timeout = null;
        }
        let command = null;
        while ((command = jaxon.tools.queue.pop(response)) != null) {
            try {
                if (false == jaxon.ajax.handler.execute(command)) {
                    if(command.requeue == true) {
                        jaxon.tools.queue.pushFront(response, command);
                    } else {
                        delete command;
                    }
                    return false;
                }
            } catch (e) {
                console.log(e);
            }
            delete command;
        }
        return true;
    },

    /*
    Function: jaxon.ajax.response.processFragment

    Parse the JSON response into a series of commands.

    Parameters:
    oRequest - (object):  The request context object.
    */
    processFragment: function(nodes, seq, oRet, oRequest) {
        const xx = jaxon;
        const xt = xx.tools;
        for (nodeName in nodes) {
            if ('jxnobj' == nodeName) {
                for (a in nodes[nodeName]) {
                    /*
                    prevents from using not numbered indexes of 'jxnobj'
                    nodes[nodeName][a]= "0" is an valid jaxon response stack item
                    nodes[nodeName][a]= "pop" is an method from somewhere but not from jxnobj
                    */
                    if (parseInt(a) != a) continue;

                    const command = nodes[nodeName][a];
                    command.fullName = '*unknown*';
                    command.sequence = seq;
                    command.response = oRequest.response;
                    command.request = oRequest;
                    command.context = oRequest.context;
                    xt.queue.push(oRequest.response, command);
                    ++seq;
                }
            } else if ('jxnrv' == nodeName) {
                oRet = nodes[nodeName];
            } else if ('debugmsg' == nodeName) {
                txt = nodes[nodeName];
            } else {
                throw { code: 10004, data: command.fullName };
            }
        }
        return oRet;
    },

    /*
    Function: jaxon.ajax.response.processor

    This function attempts to determine, based on the content type of the reponse, what processor
    should be used for handling the response data.

    The default jaxon response will be text/json which will invoke the json response processor.
    Other response processors may be added in the future.  The user can specify their own response
    processor on a call by call basis.

    Parameters:

    oRequest - (object):  The request context object.
    */
    processor: function(oRequest) {
        if ('undefined' != typeof oRequest.responseProcessor) {
            return oRequest.responseProcessor;
        }

        let cTyp = oRequest.request.getResponseHeader('content-type');
        if(!cTyp) {
            return null;
        }
        let responseText = '';
        if(0 <= cTyp.indexOf('application/json')) {
            responseText = oRequest.request.responseText;
        }
        else if(0 <= cTyp.indexOf('text/html')) {
            responseText = oRequest.request.responseText;
            // Verify if there is any other output before the Jaxon response.
            let jsonStart = responseText.indexOf('{"jxnobj"');
            if(jsonStart < 0) { // No jaxon data in the response
                return null;
            }
            if(jsonStart > 0) {
                responseText = responseText.substr(jsonStart);
            }
        }

        try {
            oRequest.request.responseJSON = JSON.parse(responseText);
            return jaxon.ajax.response.json;
        } catch (ex) {
            return null; // Cannot decode JSON response
        }
    },

    /*
    Function: jaxon.ajax.response.json

    This is the JSON response processor.

    Parameters:

    oRequest - (object):  The request context object.
    */
    json: function(oRequest) {

        const xx = jaxon;
        const xt = xx.tools;
        const xcb = xx.ajax.callback;
        const gcb = xx.callback;
        const lcb = oRequest.callback;

        let oRet = oRequest.returnValue;

        if (xt.array.is_in(xx.ajax.response.successCodes, oRequest.request.status)) {
            xcb.execute([gcb, lcb], 'onSuccess', oRequest);

            let seq = 0;
            if ('object' == typeof oRequest.request.responseJSON &&
                'object' == typeof oRequest.request.responseJSON.jxnobj) {
                oRequest.status.onProcessing();
                oRet = xx.ajax.response.processFragment(oRequest.request.responseJSON, seq, oRet, oRequest);
            } else {}

            const command = {};
            command.fullName = 'Response Complete';
            command.sequence = seq;
            command.request = oRequest;
            command.context = oRequest.context;
            command.cmd = 'rcmplt';
            xt.queue.push(oRequest.response, command);

            // do not re-start the queue if a timeout is set
            if (null == oRequest.response.timeout) {
                xx.ajax.response.process(oRequest.response);
            }
        } else if (xt.array.is_in(xx.ajax.response.redirectCodes, oRequest.request.status)) {
            xcb.execute([gcb, lcb], 'onRedirect', oRequest);
            window.location = oRequest.request.getResponseHeader('location');
            xx.ajax.response.complete(oRequest);
        } else if (xt.array.is_in(xx.ajax.response.errorsForAlert, oRequest.request.status)) {
            xcb.execute([gcb, lcb], 'onFailure', oRequest);
            xx.ajax.response.complete(oRequest);
        }

        return oRet;
    },

    /*
    Function: jaxon.ajax.response.upload

    Process the file upload response received in an iframe.

    Parameters:

    oRequest - (object):  The request context object.
    */
    upload: function(oRequest) {
        const xx = jaxon;
        const xcb = xx.ajax.callback;
        const gcb = xx.callback;
        const lcb = oRequest.callback;

        let endRequest = false;
        const res = oRequest.upload.iframe.contentWindow.res;
        if (!res || !res.code) {
            // Show the error message with the selected dialog library
            jaxon.ajax.message.error('The server returned an invalid response');
            // End the request
            endRequest = true;
        } else if (res.code === 'error') {
            // Todo: show the error message with the selected dialog library
            jaxon.ajax.message.error(res.msg);
            // End the request
            endRequest = true;
        }

        if (endRequest) {
            // End the request
            xcb.clearTimer([gcb, lcb], 'onExpiration');
            xcb.clearTimer([gcb, lcb], 'onResponseDelay');
            xcb.execute([gcb, lcb], 'onFailure', oRequest);
            jaxon.ajax.response.complete(oRequest);
            return;
        }

        if (res.code === 'success') {
            oRequest.requestData += '&jxnupl=' + encodeURIComponent(res.upl);
            jaxon.ajax.request._send(oRequest);
        }
    },

    /*
    Object: jaxon.ajax.response.successCodes

    This array contains a list of codes which will be returned from the server upon
    successful completion of the server portion of the request.

    These values should match those specified in the HTTP standard.
    */
    successCodes: ['0', '200'],

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

    /*
    Object: jaxon.ajax.response.errorsForAlert

    This array contains a list of status codes returned by the server to indicate that
    the request failed for some reason.
    */
    errorsForAlert: ['400', '401', '402', '403', '404', '500', '501', '502', '503'],

    // 10.3.1 300 Multiple Choices
    // 10.3.2 301 Moved Permanently
    // 10.3.3 302 Found
    // 10.3.4 303 See Other
    // 10.3.5 304 Not Modified
    // 10.3.6 305 Use Proxy
    // 10.3.7 306 (Unused)
    // 10.3.8 307 Temporary Redirect

    /*
    Object: jaxon.ajax.response.redirectCodes

    An array of status codes returned from the server to indicate a request for redirect to another URL.

    Typically, this is used by the server to send the browser to another URL.
    This does not typically indicate that the jaxon request should be sent to another URL.
    */
    redirectCodes: ['301', '302', '307']
};


/**
 * Class: jaxon.dom
 */
jaxon.dom = {};

/**
 * Plain javascript replacement for jQuery's .ready() function.
 * See https://github.com/jfriend00/docReady for a detailed description, copyright and license information.
 */
(function(funcName, baseObj) {
    "use strict";
    // The public function name defaults to window.docReady
    // but you can modify the last line of this function to pass in a different object or method name
    // if you want to put them in a different namespace and those will be used instead of
    // window.docReady(...)
    funcName = funcName || "docReady";
    baseObj = baseObj || window;
    let readyList = [];
    let readyFired = false;
    let readyEventHandlersInstalled = false;

    // call this when the document is ready
    // this function protects itself against being called more than once
    function ready() {
        if (!readyFired) {
            // this must be set to true before we start calling callbacks
            readyFired = true;
            for (let i = 0; i < readyList.length; i++) {
                // if a callback here happens to add new ready handlers,
                // the docReady() function will see that it already fired
                // and will schedule the callback to run right after
                // this event loop finishes so all handlers will still execute
                // in order and no new ones will be added to the readyList
                // while we are processing the list
                readyList[i].fn.call(window, readyList[i].ctx);
            }
            // allow any closures held by these functions to free
            readyList = [];
        }
    }

    function readyStateChange() {
        if (document.readyState === "complete") {
            ready();
        }
    }

    // This is the one public interface
    // docReady(fn, context);
    // the context argument is optional - if present, it will be passed
    // as an argument to the callback
    baseObj[funcName] = function(callback, context) {
        // if ready has already fired, then just schedule the callback
        // to fire asynchronously, but right away
        if (readyFired) {
            setTimeout(function() { callback(context); }, 1);
            return;
        } else {
            // add the function and context to the list
            readyList.push({ fn: callback, ctx: context });
        }
        // if document already ready to go, schedule the ready function to run
        // IE only safe when readyState is "complete", others safe when readyState is "interactive"
        if (document.readyState === "complete" || (!document.attachEvent && document.readyState === "interactive")) {
            setTimeout(ready, 1);
        } else if (!readyEventHandlersInstalled) {
            // otherwise if we don't have event handlers installed, install them
            if (document.addEventListener) {
                // first choice is DOMContentLoaded event
                document.addEventListener("DOMContentLoaded", ready, false);
                // backup is window load event
                window.addEventListener("load", ready, false);
            } else {
                // must be IE
                document.attachEvent("onreadystatechange", readyStateChange);
                window.attachEvent("onload", ready);
            }
            readyEventHandlersInstalled = true;
        }
    }
})("ready", jaxon.dom);


/*
    File: jaxon.js

    This file contains the definition of the main jaxon javascript core.

    This is the client side code which runs on the web browser or similar web enabled application.
    Include this in the HEAD of each page for which you wish to use jaxon.

    Title: jaxon core javascript library

    Please see <copyright.inc.php> for a detailed description, copyright and license information.
*/

/*
    @package jaxon
    @version $Id: jaxon.core.js 327 2007-02-28 16:55:26Z calltoconstruct $
    @copyright Copyright (c) 2005-2007 by Jared White & J. Max Wilson
    @copyright Copyright (c) 2008-2010 by Joseph Woolley, Steffen Konerow, Jared White  & J. Max Wilson
    @license http://www.jaxonproject.org/bsd_license.txt BSD License
*/

/*
Class: jaxon.callback

The global callback object which is active for every request.
*/
jaxon.callback = jaxon.ajax.callback.create();

/*
Class: jaxon
*/

/*
Function: jaxon.request

Initiates a request to the server.
*/
jaxon.request = jaxon.ajax.request.execute;

/*
Object: jaxon.response

The response queue that holds response commands, once received
from the server, until they are processed.
*/
// jaxon.response = jaxon.tools.queue.create(jaxon.config.responseQueueSize);

/*
Function: jaxon.register

Registers a new command handler.
Shortcut to <jaxon.ajax.handler.register>
*/
jaxon.register = jaxon.ajax.handler.register;

/*
Function: jaxon.$

Shortcut to <jaxon.tools.dom.$>.
*/
jaxon.$ = jaxon.tools.dom.$;

/*
Function: jaxon.getFormValues

Shortcut to <jaxon.tools.form.getValues>.
*/
jaxon.getFormValues = jaxon.tools.form.getValues;

/*
Object: jaxon.msg

Prints various types of messages on the user screen.
*/
jaxon.msg = jaxon.ajax.message;

/*
Object: jaxon.js

Shortcut to <jaxon.cmd.script>.
*/
jaxon.js = jaxon.cmd.script;

/*
Boolean: jaxon.isLoaded

true - jaxon module is loaded.
*/
jaxon.isLoaded = true;

/*
Object: jaxon.cmd.delay.q

The queues that hold synchronous requests as they are sent and processed.
*/
jaxon.cmd.delay.q = {
    send: jaxon.tools.queue.create(jaxon.config.requestQueueSize),
    recv: jaxon.tools.queue.create(jaxon.config.requestQueueSize * 2)
};


/*
Class: jaxon.command

This class is defined for compatibility with previous versions,
since its functions are used in other packages.
*/
jaxon.command = {
    /*
    Class: jaxon.command.handler
    */
    handler: {},

    /*
    Function: jaxon.command.handler.register

    Registers a new command handler.
    */
    handler: {
        register: jaxon.ajax.handler.register
    },

    /*
    Function: jaxon.command.create

    Creates a new command (object) that will be populated with
    command parameters and eventually passed to the command handler.
    */
    create: function(sequence, request, context) {
        return {
            cmd: '*',
            fullName: '* unknown command name *',
            sequence: sequence,
            request: request,
            context: context
        };
    }
};

/*
Class: jxn

Contains shortcut's to frequently used functions.
*/
const jxn = {
    /*
    Function: jxn.$

    Shortcut to <jaxon.tools.dom.$>.
    */
    $: jaxon.tools.dom.$,

    /*
    Function: jxn.getFormValues

    Shortcut to <jaxon.tools.form.getValues>.
    */
    getFormValues: jaxon.tools.form.getValues,

    request: jaxon.request
};
