const ICON_SRC =
    "data:image/svg+xml;base64,PHN2ZyB3aWR0aD0nMTInIGhlaWdodD0nMTInIHZpZXdCb3g9JzAgMCAxMiAxMicgZmlsbD0nbm9uZScgeG1sbnM9J2h0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnJz48cGF0aCBkPSdNNC4yNzA1NCAxLjAwNTk5QzQuMzM4NTMgMC45OTk5NTIgNC40MTcxMiAwLjk5OTk3OSA0LjQ4NTY3IDFMNy45NTgxMSAxQzguMDAzNSAwLjk5OTk4NiA4LjA1OTU0IDAuOTk5OTY0IDguMTA5NjIgMS4wMDM2OEM4LjE2OTAyIDEuMDA4MDkgOC4yNTA3OCAxLjAxOTI2IDguMzM5MjggMS4wNTc2NkM4LjQ1NjQ3IDEuMTA4NTIgOC41NTc3NyAxLjE5MDYzIDguNjMyMzMgMS4yOTUyQzguNjg4NjIgMS4zNzQxNyA4LjcxNzE1IDEuNDUyMzkgOC43MzQzIDEuNTEwMDJDOC43NDg3NSAxLjU1ODYyIDguNzYwODkgMS42MTM5MSA4Ljc3MDcyIDEuNjU4NjlMOS4zNTUwNiA0LjMxNTc5SDguMzk0NjRMNy44NzM3OSAxLjk0NzM3SDQuNjE1MjNMNS40MjYwMSA1LjYzNDA5QzUuNDgyMTcgNS44ODk0NyA1LjMyMjgyIDYuMTQyNSA1LjA3MDA5IDYuMTk5MjVDNC44MTczNiA2LjI1NiA0LjU2Njk1IDYuMDk0OTggNC41MTA3OSA1LjgzOTZMMy42NzE5IDIuMDI1MDlDMy42NTcgMS45NTc0OCAzLjYzOTkzIDEuODc5OTYgMy42MzEwMSAxLjgxMTU5QzMuNjIxMDQgMS43MzUxMyAzLjYxMzAxIDEuNjE3NjEgMy42NTUxNyAxLjQ4ODI2QzMuNzA4MjIgMS4zMjU1IDMuODE3NyAxLjE4NzU5IDMuOTYzNDMgMS4wOTk5NkM0LjA3OTI0IDEuMDMwMzEgNC4xOTQ1MSAxLjAxMjc0IDQuMjcwNTQgMS4wMDU5OVonIGZpbGw9J3doaXRlJy8+PHBhdGggZD0nTTUuODgzNjEgNS41MzEzM0w1LjcyMDQ2IDQuNzg5NDdIMTJWNi4yMTA1M0gxMS4yOTY4TDEwLjczNzEgOS4yMjcyMkMxMC42NTM5IDkuNjc1MyAxMC4yNjY3IDEwIDkuODE1NTggMTBIMy44NzEzN0MzLjQyMDIxIDEwIDMuMDMzMDIgOS42NzUzIDIuOTQ5ODggOS4yMjcyMkwyLjM5MDExIDYuMjEwNTNIMS42ODY5NVY0Ljc4OTQ3SDMuNzk5NjJMNC4wNTMxNiA1Ljk0MjM2QzQuMTY1NDkgNi40NTMxMSA0LjY2NjMgNi43NzUxNSA1LjE3MTc3IDYuNjYxNjVDNS42NzcyMyA2LjU0ODE1IDUuOTk1OTMgNi4wNDIwOSA1Ljg4MzYxIDUuNTMxMzNaJyBmaWxsPSd3aGl0ZScvPjxwYXRoIGZpbGwtcnVsZT0nZXZlbm9kZCcgY2xpcC1ydWxlPSdldmVub2RkJyBkPSdNNC4xMjUyMiA4SDEuMDMxMzFWNi44SDQuMTI1MjJWOFonIGZpbGw9J3doaXRlJy8+PHBhdGggZmlsbC1ydWxlPSdldmVub2RkJyBjbGlwLXJ1bGU9J2V2ZW5vZGQnIGQ9J000LjEyNTIyIDEwSDBWOC44SDQuMTI1MjJWMTBaJyBmaWxsPSd3aGl0ZScvPjwvc3ZnPg==";

const createYaKitButton = () => {
    let button = document.createElement("button");
    button.setAttribute("id", "yastore-checkout-button");
    let icon = document.createElement("img");
    icon.setAttribute("src", ICON_SRC);
    let buttonText = document.createElement("div");
    buttonText.innerText = "Купить в 1 клик";
    button.append(icon);
    button.append(buttonText);
    button.classList.add("btn-ya-checkout");
    button.onclick = function () {
        const clientID = getMetricaClientID();
        BX.ajax
            .runAction("yastore:checkout.Checkout.basketToCheckout", {
                getParameters: { metricaClientId: clientID },
            })
            .then(
                function (response) {
                    if (
                        response.status === "success" &&
                        response.data &&
                        response.data.status === "success"
                    ) {
                        window.location.href =
                            response.data.url +
                            buildMetricExtraParams(clientID);
                    } else if (response.status === "success" && response.data) {
                        console.log("error: " + (response.data.message || response.data));
                    }
                },
                function (response) {
                    console.log(response);
                }
            );
    };

    return button;
};

const yaKitButtonExists = () => {
    return document.getElementById("yastore-checkout-button") !== null;
};

const addYaKitButton = (container) => {
    if (yaKitButtonExists()) {
        return;
    }
    if (!container) {
        return;
    }
    let button = createYaKitButton();
    if (typeof YAKIT_BUTTON_INSERT_AFTER !== "undefined" && YAKIT_BUTTON_INSERT_AFTER && YAKIT_BUTTON_INSERT_AFTER.trim()) {
        let sibling = document.querySelector(YAKIT_BUTTON_INSERT_AFTER);
        if (sibling && sibling.nextSibling) {
            container.insertBefore(button, sibling.nextSibling);
        } else if (sibling && sibling.parentNode) {
            sibling.parentNode.insertBefore(button, sibling.nextSibling);
        } else {
            container.appendChild(button);
        }
    } else {
        container.appendChild(button);
    }
};

BX.ready(function () {
    if (typeof YAKIT_BUTTON_CSS !== "undefined" && YAKIT_BUTTON_CSS) {
        try {
            var css = YAKIT_BUTTON_CSS.replace(/\\n/g, "\n").replace(/\\r/g, "\r").replace(/\\\\/g, "\\").trim();
            if (css.indexOf("{") === -1) {
                css = "#yastore-checkout-button { " + css + " }";
            }
            var s = document.createElement("style");
            s.id = "yastore-checkout-button-styles";
            s.textContent = css;
            document.head.appendChild(s);
        } catch (e) {}
    }

    var button_selector_anchor = (typeof BUTTON_ANCHOR !== "undefined" && BUTTON_ANCHOR && BUTTON_ANCHOR.trim())
        ? BUTTON_ANCHOR
        : ".basket-checkout-section-inner";

    var container = null;
    if (typeof YAKIT_BUTTON_INSERT_AFTER !== "undefined" && YAKIT_BUTTON_INSERT_AFTER && YAKIT_BUTTON_INSERT_AFTER.trim()) {
        var insertAfterEl = document.querySelector(YAKIT_BUTTON_INSERT_AFTER);
        if (insertAfterEl && insertAfterEl.parentNode) {
            container = insertAfterEl.parentNode;
        }
    }
    if (!container) {
        container = document.querySelector(button_selector_anchor);
    }
    addYaKitButton(container);

    var observer = new MutationObserver(function () {
        if (yaKitButtonExists()) {
            return;
        }
        var container = null;
        if (typeof YAKIT_BUTTON_INSERT_AFTER !== "undefined" && YAKIT_BUTTON_INSERT_AFTER && YAKIT_BUTTON_INSERT_AFTER.trim()) {
            var insertAfterEl = document.querySelector(YAKIT_BUTTON_INSERT_AFTER);
            if (insertAfterEl && insertAfterEl.parentNode) {
                container = insertAfterEl.parentNode;
            }
        }
        if (!container) {
            container = document.querySelector(button_selector_anchor);
        }
        if (container === null) {
            return;
        }
        addYaKitButton(container);
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true,
    });
});

function findYandexMetrikaCounters() {
    var counters = [];
    for (var key in window) {
        if (/^yaCounter\d+$/.test(key)) {
            var id = parseInt(key.replace("yaCounter", ""), 10);
            if (!isNaN(id)) counters.push(id);
        }
    }
    return counters;
}

function findYandexMetrikaInScripts() {
    var ids = {};
    var scripts = document.querySelectorAll("script");
    for (var i = 0; i < scripts.length; i++) {
        var script = scripts[i];
        var html = script.innerHTML || script.outerHTML;
        var matches = html.match(/id\s*[:=]\s*(\d{6,})/g);
        if (matches) {
            for (var j = 0; j < matches.length; j++) {
                var m = matches[j].match(/(\d{6,})/);
                if (m) ids[m[1]] = true;
            }
        }
    }
    return Object.keys(ids).map(function (id) { return parseInt(id, 10); });
}

function findYandexMetrikaCounterID() {
    var counterIDs = findYandexMetrikaCounters();
    if (!counterIDs || counterIDs.length === 0) {
        counterIDs = findYandexMetrikaInScripts();
    }
    if (counterIDs && counterIDs.length > 0) {
        return counterIDs[0];
    }
    return null;
}

function generateClientID() {
    var getRandom = function (min, max) {
        return Math.floor(Math.random() * (max - min)) + min;
    };
    var generateNewUid = function () {
        return [Math.round(Date.now() / 1000), getRandom(1000000, 999999999)].join("");
    };
    return generateNewUid();
}

function getMetricaClientID() {
    var counterID = findYandexMetrikaCounterID();
    if (!counterID) {
        return generateClientID();
    }
    var yaID;
    try {
        if (typeof ym === "function") {
            ym(counterID, "getClientID", function (clientID) {
                yaID = clientID;
            });
        }
    } catch (e) {
        // ignore
    }
    if (!yaID) {
        return generateClientID();
    }
    return yaID;
}

function buildMetricExtraParams(clientID) {
    var domain = window.location.hostname;
    var result = "&from=button&src=" + encodeURIComponent(domain);
    if (clientID) {
        result += "&metricClientId=" + encodeURIComponent(clientID);
    }
    return result;
}
