@component('mail::message')

[{{ '@' . $user->username }}]({{ $user->url }})  发布文章，已触发敏感词过滤。

Thanks.

{{ config('app.name') }}
@endcomponent
