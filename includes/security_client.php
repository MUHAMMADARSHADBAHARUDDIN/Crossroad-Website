<?php
require_once __DIR__ . '/security.php';
startSecureSession();
$csrfToken = ensureCsrfToken();
header('Content-Type: application/javascript; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
?>
(function(){
    'use strict';

    if(window.CrossroadSecurityLoaded){
        return;
    }
    window.CrossroadSecurityLoaded = true;

    var csrfToken = <?php echo json_encode($csrfToken); ?>;
    var csrfName = 'csrf_token';

    window.CrossroadSecurity = window.CrossroadSecurity || {};
    window.CrossroadSecurity.csrfToken = csrfToken;
    window.CrossroadSecurity.csrfName = csrfName;

    function isUnsafe(method){
        method = (method || 'GET').toUpperCase();
        return ['POST','PUT','PATCH','DELETE'].indexOf(method) !== -1;
    }

    function isSameOrigin(url){
        try{
            var parsed = new URL(url || window.location.href, window.location.href);
            return parsed.origin === window.location.origin;
        }catch(e){
            return true;
        }
    }

    function appendToString(body){
        var prefix = body && body.length ? '&' : '';
        return body + prefix + encodeURIComponent(csrfName) + '=' + encodeURIComponent(csrfToken);
    }

    function appendToken(body){
        if(body instanceof FormData){
            if(!body.has(csrfName)){
                body.append(csrfName, csrfToken);
            }
            return body;
        }

        if(body instanceof URLSearchParams){
            if(!body.has(csrfName)){
                body.append(csrfName, csrfToken);
            }
            return body;
        }

        if(typeof body === 'string'){
            return body.indexOf(csrfName + '=') === -1 ? appendToString(body) : body;
        }

        if(!body){
            var params = new URLSearchParams();
            params.append(csrfName, csrfToken);
            return params;
        }

        return body;
    }

    document.addEventListener('submit', function(event){
        var form = event.target;

        if(!form || !form.tagName || form.tagName.toLowerCase() !== 'form'){
            return;
        }

        var method = (form.getAttribute('method') || 'GET').toUpperCase();
        var action = form.getAttribute('action') || window.location.href;

        if(isUnsafe(method) && isSameOrigin(action) && !form.querySelector('input[name="' + csrfName + '"]')){
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = csrfName;
            input.value = csrfToken;
            form.appendChild(input);
        }
    }, true);

    if(window.fetch){
        var originalFetch = window.fetch;
        window.fetch = function(input, init){
            init = init || {};
            var method = init.method || (input && input.method) || 'GET';
            var url = (typeof input === 'string') ? input : (input && input.url) || window.location.href;

            if(isUnsafe(method) && isSameOrigin(url)){
                init.headers = new Headers(init.headers || (input && input.headers) || {});
                init.headers.set('X-CSRF-Token', csrfToken);
                init.body = appendToken(init.body);
            }

            return originalFetch.call(this, input, init);
        };
    }

    if(window.XMLHttpRequest){
        var originalOpen = XMLHttpRequest.prototype.open;
        var originalSend = XMLHttpRequest.prototype.send;

        XMLHttpRequest.prototype.open = function(method, url){
            this._crossroadMethod = method || 'GET';
            this._crossroadUrl = url || window.location.href;
            return originalOpen.apply(this, arguments);
        };

        XMLHttpRequest.prototype.send = function(body){
            if(isUnsafe(this._crossroadMethod) && isSameOrigin(this._crossroadUrl)){
                try{
                    this.setRequestHeader('X-CSRF-Token', csrfToken);
                }catch(e){}
                body = appendToken(body);
            }

            return originalSend.call(this, body);
        };
    }




    function injectResponsiveCss(){
        if(document.getElementById('crossroad-responsive-runtime-css')){
            return;
        }

        var style = document.createElement('style');
        style.id = 'crossroad-responsive-runtime-css';
        style.textContent = '\n@media (max-width: 768px){\n.table-responsive,#assetInventoryTable_wrapper,#serverInventoryTable_wrapper,#contractsTable_wrapper,#trackingTable_wrapper{overflow-x:auto!important;width:100%!important;-webkit-overflow-scrolling:touch!important;}\ntable.dataTable,.table{min-width:720px!important;}\n.main,.main.expanded{overflow-x:hidden!important;margin-left:0!important;}\n.btn-group,.dt-buttons{display:flex!important;flex-wrap:wrap!important;gap:6px!important;}\n.modal-dialog{max-width:calc(100% - 1rem)!important;}\n}\n';
        document.head.appendChild(style);
    }

    function setupResponsiveShell(){
        var sidebar = document.getElementById('sidebar');
        var main = document.getElementById('main') || document.querySelector('.main');
        var btn = document.getElementById('menuBtn');

        if(!sidebar || !main || !btn){
            return;
        }

        function isMobile(){
            return window.matchMedia && window.matchMedia('(max-width: 768px)').matches;
        }

        function closeMobileMenu(){
            sidebar.classList.remove('mobile-open');
            document.body.classList.remove('sidebar-open');
            btn.classList.remove('active');
        }

        function applyLayout(){
            if(isMobile()){
                sidebar.classList.add('collapsed');
                main.classList.add('expanded');
                closeMobileMenu();
            }
        }

        window.toggleSidebar = function(){
            if(isMobile()){
                sidebar.classList.toggle('mobile-open');
                document.body.classList.toggle('sidebar-open', sidebar.classList.contains('mobile-open'));
                btn.classList.toggle('active', sidebar.classList.contains('mobile-open'));
                return;
            }

            sidebar.classList.toggle('collapsed');
            main.classList.toggle('expanded');
            btn.classList.toggle('active');
        };

        document.addEventListener('click', function(event){
            if(!isMobile() || !sidebar.classList.contains('mobile-open')){
                return;
            }

            if(sidebar.contains(event.target) || btn.contains(event.target)){
                return;
            }

            closeMobileMenu();
        });

        sidebar.querySelectorAll('a').forEach(function(link){
            link.addEventListener('click', closeMobileMenu);
        });

        window.addEventListener('resize', applyLayout);
        applyLayout();
    }

    function runUiEnhancements(){
        injectResponsiveCss();
        setupResponsiveShell();
    }

    if(document.readyState === 'loading'){
        document.addEventListener('DOMContentLoaded', runUiEnhancements);
    }else{
        runUiEnhancements();
    }

})();
