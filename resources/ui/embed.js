/*
 * ReserveWorx embed helper. Loaded on operators' own websites from
 * https://<their booking domain>/embed.js.
 *
 * - <iframe data-reserveworx> grows to fit the booking page, which reports its
 *   height with postMessage({type: "reserveworx:height"}).
 * - <a data-reserveworx-popup href="..."> opens the booking page in a window
 *   over the site. Without this script the link still opens in a new tab.
 *
 * No cookies, no tracking, no dependencies.
 */
(function () {
    'use strict';

    if (window.ReserveWorxEmbed) {
        return;
    }

    var overlay = null;
    var lastFocus = null;

    function frames() {
        return document.querySelectorAll('iframe[data-reserveworx]');
    }

    window.addEventListener('message', function (event) {
        var data = event.data;

        if (!data || typeof data !== 'object' || data.type !== 'reserveworx:height') {
            return;
        }

        var height = Number(data.height);

        if (!isFinite(height) || height <= 0) {
            return;
        }

        var list = frames();

        for (var i = 0; i < list.length; i++) {
            if (list[i].contentWindow === event.source && !list[i].hasAttribute('data-reserveworx-fixed')) {
                list[i].style.height = Math.min(Math.max(Math.ceil(height), 320), 20000) + 'px';
            }
        }
    });

    function close() {
        if (!overlay) {
            return;
        }

        overlay.parentNode.removeChild(overlay);
        overlay = null;
        document.documentElement.style.overflow = '';
        document.removeEventListener('keydown', onKey, true);

        if (lastFocus && lastFocus.focus) {
            lastFocus.focus();
        }
    }

    function onKey(event) {
        if (event.key === 'Escape') {
            close();
        }
    }

    function open(url, label) {
        close();
        lastFocus = document.activeElement;

        overlay = document.createElement('div');
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', label || 'Book online');
        overlay.style.cssText = 'position:fixed;inset:0;z-index:2147483000;background:rgba(17,24,39,.6);display:flex;align-items:center;justify-content:center;padding:0';

        var small = window.matchMedia('(max-width: 640px)').matches;
        var panel = document.createElement('div');
        panel.style.cssText = small
            ? 'position:relative;width:100%;height:100%;background:#fff;overflow:hidden'
            : 'position:relative;width:min(34rem,94vw);height:min(52rem,92vh);background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 20px 50px rgba(0,0,0,.3)';

        var button = document.createElement('button');
        button.type = 'button';
        button.setAttribute('aria-label', 'Close');
        button.innerHTML = '&times;';
        button.style.cssText = 'position:absolute;top:10px;right:10px;z-index:2;width:40px;height:40px;border:0;border-radius:50%;background:rgba(17,24,39,.75);color:#fff;font:400 26px/40px sans-serif;cursor:pointer;padding:0';
        button.addEventListener('click', close);

        var frame = document.createElement('iframe');
        frame.src = url;
        frame.title = label || 'Book online';
        frame.setAttribute('data-reserveworx', '');
        frame.setAttribute('data-reserveworx-fixed', '');
        frame.style.cssText = 'display:block;width:100%;height:100%;border:0';

        panel.appendChild(button);
        panel.appendChild(frame);
        overlay.appendChild(panel);
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) {
                close();
            }
        });

        document.body.appendChild(overlay);
        document.documentElement.style.overflow = 'hidden';
        document.addEventListener('keydown', onKey, true);
        button.focus();
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        var trigger = target && target.closest ? target.closest('[data-reserveworx-popup]') : null;

        if (!trigger || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey) {
            return;
        }

        var url = trigger.getAttribute('href') || trigger.getAttribute('data-reserveworx-popup');

        if (!url || url.indexOf('http') !== 0) {
            return;
        }

        event.preventDefault();
        open(url, trigger.textContent ? trigger.textContent.trim() : '');
    });

    window.ReserveWorxEmbed = { open: open, close: close };
})();
