<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-[Space_Grotesk] text-2xl font-bold text-slate-900 leading-tight">
                    Detection Center
                </h2>
                <p class="mt-1 text-sm text-slate-500">
                    Submit a link, screenshot, or video for AI-fake analysis.
                </p>
            </div>
            <a
                href="{{ route('dashboard') }}"
                class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
            >
                Back to Dashboard
            </a>
        </div>
    </x-slot>

    <div class="space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        @if (session('status'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                {{ session('status') }}
            </div>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h3 class="font-[Space_Grotesk] text-xl font-semibold text-slate-900">New Detection Request</h3>
            <p class="mt-1 text-sm text-slate-600">
                Add at least one input source: a URL or media file upload.
            </p>

            <form method="POST" action="{{ route('detections.store') }}" enctype="multipart/form-data" class="mt-6 space-y-5">
                @csrf

                <div>
                    <label for="source_url" class="mb-1 block text-sm font-semibold text-slate-700">Source Link (optional)</label>
                    <input
                        id="source_url"
                        type="url"
                        name="source_url"
                        value="{{ old('source_url') }}"
                        placeholder="https://example.com/post-or-media-link"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 focus:border-cyan-500 focus:outline-none focus:ring-4 focus:ring-cyan-100"
                    >
                    @error('source_url')
                        <p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="media_file" class="mb-1 block text-sm font-semibold text-slate-700">Screenshot or Video (optional)</label>
                    <input
                        id="media_file"
                        type="file"
                        name="media_file"
                        accept="image/*,video/*"
                        class="block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 file:me-3 file:rounded-md file:border-0 file:bg-cyan-600 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-white hover:file:bg-cyan-700"
                    >
                    <p class="mt-1 text-xs text-slate-500">Allowed: jpg, jpeg, png, webp, gif, mp4, mov, webm, m4v (max 50MB)</p>
                    @error('media_file')
                        <p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="notes" class="mb-1 block text-sm font-semibold text-slate-700">Notes (optional)</label>
                    <textarea
                        id="notes"
                        name="notes"
                        rows="4"
                        placeholder="Context about the content, source account, or why it looks suspicious."
                        class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 focus:border-cyan-500 focus:outline-none focus:ring-4 focus:ring-cyan-100"
                    >{{ old('notes') }}</textarea>
                    @error('notes')
                        <p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                    If both link and file are provided, the uploaded file is prioritized for media analysis.
                </div>

                <button
                    type="submit"
                    class="inline-flex items-center justify-center rounded-lg bg-gradient-to-r from-cyan-600 to-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-md transition hover:opacity-95"
                >
                    Run Detection
                </button>
            </form>
        </section>
    </div>
</x-app-layout>
