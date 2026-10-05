<div {{ $attributes->merge(['id' => $id]) }}></div>
@once
<script src="{{ $scriptUrl() }}"{!! $nonceAttribute() !!}></script>
@endonce
<script{!! $nonceAttribute() !!}>
(function () {
    var options = {{ Illuminate\Support\Js::from($options()) }};
    var onResponse = {{ Illuminate\Support\Js::from($onResponse) }};
    var urls = {{ Illuminate\Support\Js::from(['success' => $successUrl, 'fail' => $failUrl]) }};

    options.onResponse = function (type, body) {
        if (onResponse && typeof window[onResponse] === 'function') {
            window[onResponse](type, body);
        }

        if (urls[type]) {
            var url = new URL(urls[type], window.location.href);
            url.searchParams.set('checkout_id', options.checkoutId);
            window.location.assign(url.toString());
        }
    };

    SumUpCard.mount(options);
})();
</script>
