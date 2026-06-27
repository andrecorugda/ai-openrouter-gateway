{{-- Delegated copy-to-clipboard for one-time token notifications.
     Injected into the panel layout via a render hook (NOT a Livewire component
     view, where inline <script> would not execute). Filament 4/5 strips
     x-on:click / @click from action extraAttributes but keeps data-*, so the
     copy button carries only data-ai-gateway-copy and this listener does the
     work. Requires a secure context (https / localhost) for navigator.clipboard;
     falls back to execCommand otherwise. --}}
<script>
    (function () {
        if (window.__aiGatewayCopyBound) { return; }
        window.__aiGatewayCopyBound = true;
        document.addEventListener('click', function (e) {
            var el = e.target.closest('[data-ai-gateway-copy]');
            if (! el) { return; }
            e.preventDefault();
            var value = el.getAttribute('data-ai-gateway-copy');
            var done = function () {
                var original = el.innerHTML;
                el.innerHTML = 'Copied!';
                setTimeout(function () { el.innerHTML = original; }, 1200);
            };
            var fallback = function () {
                var ta = document.createElement('textarea');
                ta.value = value;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); done(); } catch (err) {}
                ta.remove();
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(value).then(done).catch(fallback);
            } else {
                fallback();
            }
        });
    })();
</script>
