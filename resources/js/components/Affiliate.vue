<template>
    <div class="tw:container tw:w-full tw:lg:w-4/5 tw:mx-auto">
        <div class="tw:flex tw:flex-col tw:items-center tw:justify-center tw:gap-2">
            <h1 class="tw:mb-2">{{ __('Bunny Account Required') }}</h1>
            <p class="tw:text-sm">{{ __("To use this addon, you'll need a Bunny.net account. Make sure to add your credentials to the .env file.") }}</p>
            <p class="tw:text-sm">{{ __("If you don't have one yet, you can sign up using our affiliate link.") }}</p>
            <a href="https://bunny.net?ref=uhvsqhaw0n" target="_blank" class="btn-primary tw:my-2">
                {{ __('Sign Up Now') }}
            </a>
            <p class="tw:text-sm">{{ __("By doing so, you'll be supporting the development of this addon at no extra cost to you.") }}</p>
            <p class="tw:text-sm"><b>{{ __('Thank you for your support!') }}</b></p>
        </div>
    </div>
</template>
