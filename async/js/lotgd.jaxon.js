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

if(JaxonLotgd.Async.Handler.Mail === undefined) {
    JaxonLotgd.Async.Handler.Mail = {};
}
JaxonLotgd.Async.Handler.Mail.mailStatus = function() {
    return jaxon.request({ jxncls: 'Lotgd.Async.Handler.Mail', jxnmthd: 'mailStatus' }, { parameters: arguments });
};
if(JaxonLotgd.Async.Handler.Timeout === undefined) {
    JaxonLotgd.Async.Handler.Timeout = {};
}
JaxonLotgd.Async.Handler.Timeout.timeoutStatus = function() {
    return jaxon.request({ jxncls: 'Lotgd.Async.Handler.Timeout', jxnmthd: 'timeoutStatus' }, { parameters: arguments });
};
if(JaxonLotgd.Async.Handler.Commentary === undefined) {
    JaxonLotgd.Async.Handler.Commentary = {};
}
JaxonLotgd.Async.Handler.Commentary.commentaryText = function() {
    return jaxon.request({ jxncls: 'Lotgd.Async.Handler.Commentary', jxnmthd: 'commentaryText' }, { parameters: arguments });
};
JaxonLotgd.Async.Handler.Commentary.commentaryRefresh = function() {
    return jaxon.request({ jxncls: 'Lotgd.Async.Handler.Commentary', jxnmthd: 'commentaryRefresh' }, { parameters: arguments });
};
JaxonLotgd.Async.Handler.Commentary.pollUpdates = function() {
    return jaxon.request({ jxncls: 'Lotgd.Async.Handler.Commentary', jxnmthd: 'pollUpdates' }, { parameters: arguments });
};

jaxon.dialogs = {};

jaxon.dom.ready(function() {
jaxon.command.handler.register("jquery", (args) => jaxon.cmd.script.execute(args));

jaxon.command.handler.register("bags.set", (args) => {
        for (const bag in args.data) {
            jaxon.ajax.parameters.bags[bag] = args.data[bag];
        }
    });
});

    jaxon.command.handler.register("rd", (command) => {
        const { data: sUrl, delay: nDelay } = command;
        if (nDelay <= 0) {
            window.location = sUrl;
            return true;
        }
        window.setTimeout(() => window.location = sUrl, nDelay * 1000);
        return true;
    });
