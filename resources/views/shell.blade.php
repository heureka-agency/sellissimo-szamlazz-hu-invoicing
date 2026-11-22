@extends('statamic::layout')
@push('head')
<style>
.sz-page {
    max-width: 58rem;
    margin: 0 auto 4rem;
    color: inherit;
}
.sz-page header,.sz-page section {
    margin-bottom: 1.3rem;
}
.sz-page h1 {
    font-size: 1.65rem;
    font-weight: 600;
    line-height: 1.3;
    margin: .4rem 0;
}
.sz-page h2 {
    font-size: 1.15rem;
    font-weight: 600;
    margin-bottom: 1rem;
}
.sz-page .sz-card {
    min-width: 0;
    border: 1px solid #8885;
    border-radius: .7rem;
    padding: 1.25rem;
    margin-top: 1rem;
}
.sz-page form, .sz-page .sz-fields {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 1rem;
}
.sz-page label {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: .3rem;
}
.sz-page .sz-check {
    display: flex;
    align-items: center;
    gap: .5rem;
}
.sz-page input:not([type=checkbox]),.sz-page select,.sz-page textarea {
    padding: .45rem .6rem;
    border: 1px solid #8888;
    border-radius: .35rem;
    background: transparent;
    color: inherit;
    min-width: 0;
    max-width: 100%;
}
.sz-page textarea {
    min-height: 5rem;
}
.sz-page .sz-button {
    border: 1px solid #8888;
    border-radius: .4rem;
    padding: .4rem .8rem;
    width: fit-content;
    font-weight: 550;
    cursor: pointer;
}
.sz-page .sz-primary {
    background: #285649;
    color: #fff;
    border-color: #285649;
}
.sz-page a {
    text-decoration: underline;
    text-underline-offset: .2em;
}
.sz-page .sz-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit,minmax(min(13rem,100%),1fr));
    gap: 1rem;
}
.sz-page .sz-table {
    overflow-x: auto;
}
.sz-page table {
    width: 100%;
    border-collapse: collapse;
}
.sz-page th,.sz-page td {
    text-align: start;
    padding: .5rem;
    border-bottom: 1px solid #8884;
}
.sz-page small {
    display: block;
}
.sz-page .sz-message {
    border-inline-start: 3px solid currentColor;
    padding: .5rem .8rem;
    margin: 1rem 0;
}
</style>
@endpush
@section('content')
<main class="sz-page">
@if($errors->any())
    <div class="sz-message" role="alert">
        @hasSection('validation-summary')
            @yield('validation-summary')
        @else
            @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        @endif
    </div>
@endif
@if(session('notice'))<div class="sz-message" role="status">{{ session('notice') }}</div>@endif
@yield('invoice-content')
</main>
@endsection
