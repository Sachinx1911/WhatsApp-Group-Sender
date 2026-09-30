<div class="grid min-h-screen lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
    {{-- Brand panel (desktop only) --}}
    <aside class="relative hidden overflow-hidden bg-navy px-12 py-12 text-white lg:flex lg:flex-col">
        <div class="pointer-events-none absolute -right-24 -top-24 size-80 rounded-full bg-primary/20"></div>
        <div class="pointer-events-none absolute -bottom-32 -left-20 size-96 rounded-full bg-primary/10"></div>

        <div class="relative flex items-center gap-3">
            <span class="grid size-11 place-items-center rounded-xl bg-primary">
                <x-lucide-graduation-cap class="size-6" />
            </span>
            <div>
                <p class="text-lg font-semibold leading-tight">Education Hub</p>
                <p class="text-xs text-slate-400">WhatsApp Group Sender</p>
            </div>
        </div>

        <div class="relative my-auto max-w-md">
            <h2 class="text-[32px] font-semibold leading-tight">
                Share study material with every batch, in one go.
            </h2>
            <p class="mt-4 text-slate-400">
                Compose once, attach an image or PDF, and deliver it to your WhatsApp student groups with full tracking.
            </p>

            <ul class="mt-10 space-y-5">
                @foreach ([
                    ['users', 'Send to 250+ groups', 'Pick one batch, a category, or all groups at once.'],
                    ['file-text', 'Images & PDFs', 'Current affairs, practice papers and notes, in Marathi or English.'],
                    ['circle-check', 'Track every delivery', 'Live progress, send history and one-click retry.'],
                ] as [$icon, $heading, $text])
                    <li class="flex gap-4">
                        <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-white/10 text-blue-300">
                            <x-dynamic-component :component="'lucide-'.$icon" class="size-5" />
                        </span>
                        <div>
                            <p class="font-medium">{{ $heading }}</p>
                            <p class="mt-0.5 text-[13px] text-slate-400">{{ $text }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>

        <p class="relative text-xs text-slate-500">Runs locally on this computer · WhatsApp Web linked device</p>
    </aside>

    {{-- Sign-in form --}}
    <main class="flex items-center justify-center px-4 py-10 sm:px-8">
        <div class="w-full max-w-[400px]">
            <div class="mb-8 flex items-center gap-3 lg:hidden">
                <span class="grid size-10 place-items-center rounded-xl bg-primary text-white">
                    <x-lucide-graduation-cap class="size-5" />
                </span>
                <div>
                    <p class="font-semibold leading-tight">Education Hub</p>
                    <p class="text-xs text-muted">WhatsApp Group Sender</p>
                </div>
            </div>

            <h1 class="text-[28px] font-semibold">Welcome back</h1>
            <p class="mt-1 text-muted">Sign in to manage and send content to your groups.</p>

            <form wire:submit="login" class="mt-8 space-y-5" novalidate>
                @error('email')
                    <div role="alert" class="flex items-start gap-2.5 rounded-xl border border-danger/20 bg-danger-soft px-3.5 py-3 text-[13px] text-danger">
                        <x-lucide-circle-alert class="mt-px size-4 shrink-0" />
                        <span>{{ $message }}</span>
                    </div>
                @enderror

                <div>
                    <label for="email" class="mb-1.5 block text-[13px] font-medium">Email address</label>
                    <div class="relative">
                        <x-lucide-mail class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-muted" />
                        <input wire:model="email" id="email" type="email" autocomplete="username" autofocus required
                            placeholder="admin@educationhub.local"
                            @class([
                                'w-full rounded-xl border bg-white py-2.5 pl-10 pr-3.5 outline-none transition placeholder:text-slate-400 focus:border-primary focus:ring-4 focus:ring-primary/10',
                                'border-danger' => $errors->has('email'),
                                'border-line' => ! $errors->has('email'),
                            ])>
                    </div>
                </div>

                <div x-data="{ show: false }">
                    <label for="password" class="mb-1.5 block text-[13px] font-medium">Password</label>
                    <div class="relative">
                        <x-lucide-lock class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-muted" />
                        <input wire:model="password" id="password" :type="show ? 'text' : 'password'" type="password"
                            autocomplete="current-password" required placeholder="Enter your password"
                            class="w-full rounded-xl border border-line bg-white py-2.5 pl-10 pr-11 outline-none transition placeholder:text-slate-400 focus:border-primary focus:ring-4 focus:ring-primary/10">
                        <button type="button" x-on:click="show = !show"
                            class="absolute right-2 top-1/2 grid size-8 -translate-y-1/2 place-items-center rounded-lg text-muted transition hover:bg-canvas hover:text-ink"
                            :aria-label="show ? 'Hide password' : 'Show password'">
                            <x-lucide-eye x-show="!show" class="size-4" />
                            <x-lucide-eye-off x-show="show" x-cloak class="size-4" />
                        </button>
                    </div>
                    @error('password')
                        <p class="mt-1.5 text-xs text-danger">{{ $message }}</p>
                    @enderror
                </div>

                <label class="flex w-fit cursor-pointer items-center gap-2.5 text-[13px] text-muted">
                    <input wire:model="remember" type="checkbox"
                        class="size-4 rounded border-line text-primary accent-primary focus:ring-primary/20">
                    Keep me signed in on this computer
                </label>

                <button type="submit" wire:loading.attr="disabled" wire:target="login"
                    class="flex w-full items-center justify-center gap-2 rounded-xl bg-primary px-4 py-2.5 font-medium text-white transition hover:bg-primary-hover disabled:cursor-wait disabled:opacity-75">
                    <x-lucide-loader-circle wire:loading wire:target="login" class="size-4 animate-spin" />
                    <span wire:loading.remove wire:target="login">Sign in</span>
                    <span wire:loading wire:target="login">Signing in...</span>
                </button>
            </form>

            <details class="mt-8 rounded-xl border border-line bg-white px-4 py-3 text-[13px] text-muted">
                <summary class="cursor-pointer select-none font-medium text-ink">Forgot your password?</summary>
                <p class="mt-2">
                    Set a new <code class="rounded bg-canvas px-1 py-0.5 text-xs text-ink">ADMIN_PASSWORD</code> in the
                    <code class="rounded bg-canvas px-1 py-0.5 text-xs text-ink">.env</code> file, then run:
                </p>
                <code class="mt-2 block rounded-lg bg-navy px-3 py-2 text-xs text-slate-200">php artisan db:seed --class=AdminUserSeeder</code>
            </details>
        </div>
    </main>
</div>
