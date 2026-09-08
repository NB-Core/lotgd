/**
 * translation for: jaxon v.x.x
 * @version: 1.0.0
 * @author: mic <info@joomx.com>
 * @copyright jaxon project
 * @license GNU/GPL
 * @package jaxon x.x.x
 * @since v.x.x.x
 * save as UTF-8
 */

if ('undefined' != typeof jaxon.debug) {
    /*
        Array: text
    */
    jaxon.debug.messages = {
        warning: 'WARNING: ',
        error: 'ERROR: ',
        heading: 'JAXON DEBUG MESSAGE:\n',
        request: {
            uri: 'URI: ',
            init: 'INITIALIZING REQUEST',
            creating: 'INITIALIZING REQUEST OBJECT',
            starting: 'STARTING JAXON REQUEST',
            preparing: 'PREPARING REQUEST',
            calling: 'CALLING: ',
            sending: 'SENDING REQUEST',
            sent: 'SENT [{length} bytes]'
        },
        response: {
            long: '...\n[LONG RESPONSE]\n...',
            success: 'RECEIVED [status: {status}, size: {length} bytes, time: {duration}ms]:\n',
            content: 'The server returned the following HTTP status: {status}\nRECEIVED:\n{text}',
            redirect: 'The server returned a redirect to:<br />{location}',
            no_processor: 'No response processor is available to process the response from the server.\n',
            check_errors: '.\nCheck for error messages from the server.'
        },
        processing: {
            parameters: 'PROCESSING PARAMETERS [{count}]',
            no_parameters: 'NO PARAMETERS TO PROCESS',
            calling: 'STARTING JAXON CALL (deprecated: use jaxon.request instead)',
            calling: 'JAXON CALL ({cmd}, {options})',
            done: 'DONE [{duration}ms]'
        }
    };

    /*
        Array: exceptions
    */
    jaxon.debug.exceptions = [];
    jaxon.debug.exceptions[10001] = 'Invalid response XML: The response contains an unknown tag: {data}.';
    jaxon.debug.exceptions[10002] = 'GetRequestObject: XMLHttpRequest is not available, jaxon is disabled.';
    jaxon.debug.exceptions[10003] = 'Queue overflow: Cannot push object onto queue because it is full.';
    jaxon.debug.exceptions[10004] = 'Invalid response XML: The response contains an unexpected tag or text: {data}.';
    jaxon.debug.exceptions[10005] = 'Invalid request URI: Invalid or missing URI; autodetection failed; please specify a one explicitly.';
    jaxon.debug.exceptions[10006] = 'Invalid response command: Malformed response command received.';
    jaxon.debug.exceptions[10007] = 'Invalid response command: Command [{data}] is not a known command.';
    jaxon.debug.exceptions[10008] = 'Element with ID [{data}] not found in the document.';
    jaxon.debug.exceptions[10009] = 'Invalid request: Missing function name parameter.';
    jaxon.debug.exceptions[10010] = 'Invalid request: Missing function object parameter.';

    jaxon.debug.lang = {
        isLoaded: true
    };
}

if (typeof jaxon.config != 'undefined' && typeof jaxon.config.status != 'undefined') {
    /*
        Object: update
    */
    jaxon.config.status.update = function() {
        return {
            onRequest: function() {
                window.status = 'Sending request...';
            },
            onWaiting: function() {
                window.status = 'Waiting for response...';
            },
            onProcessing: function() {
                window.status = 'Processing...';
            },
            onComplete: function() {
                window.status = 'Done.';
            }
        }
    }
}
