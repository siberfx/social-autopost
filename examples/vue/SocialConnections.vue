<!--
  A "Social connections" page in Vue 3: one card per enabled network.

  With Inertia, pass the cards as props from your controller:
      return Inertia::render('SocialConnections', [
          'connections' => SocialProps::connections(),
          'flash' => SocialProps::flash(),
      ]);
  Without Inertia, render <SocialConnections /> and it fetches GET /social/connections itself.
-->
<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { type Flash, type SocialConnection, type Tone, request } from './social';

const props = withDefaults(defineProps<{
    connections?: SocialConnection[];
    flash?: Flash | null;
    /** The package's JSON route: routes.prefix + "/connections". */
    endpoint?: string;
}>(), { flash: null, endpoint: '/social/connections' });

const connections = ref<SocialConnection[] | null>(props.connections ?? null);
const flash = ref<Flash | null>(props.flash);
const busy = ref<string | null>(null);

const tones: Record<Tone, string> = {
    success: 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-950 dark:text-green-300',
    warning: 'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-950 dark:text-amber-300',
    danger: 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-950 dark:text-red-300',
    neutral: 'bg-gray-50 text-gray-600 ring-gray-500/20 dark:bg-gray-800 dark:text-gray-300',
};

// The OAuth callback returns here with ?social=…&state=…; the endpoint turns it into a message.
async function load(withCallbackQuery = false) {
    const { body } = await request<{ data: SocialConnection[]; flash: Flash | null }>(
        props.endpoint + (withCallbackQuery ? window.location.search : ''),
    );
    connections.value = body.data;
    if (body.flash) flash.value = body.flash;
}

onMounted(() => {
    if (!props.connections) void load(true);
    // Drop ?social=…&state=… so a reload does not show the message again.
    const url = new URL(window.location.href);
    if (url.searchParams.has('social')) {
        ['social', 'state', 'reason'].forEach((key) => url.searchParams.delete(key));
        window.history.replaceState(window.history.state, '', url);
    }
});

async function disconnect(card: SocialConnection) {
    if (!window.confirm(card.confirm_disconnect)) return;

    busy.value = card.network;
    try {
        const { ok, body } = await request<{ message: string }>(card.urls.disconnect, 'DELETE');
        flash.value = { type: ok ? 'success' : 'error', message: body.message };
        await load();
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <div class="mx-auto max-w-5xl space-y-6 px-4 py-10">
        <header>
            <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">Social connections</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Connect the accounts your content is shared to.</p>
        </header>

        <div v-if="flash" role="status" class="rounded-lg px-4 py-3 text-sm"
             :class="flash.type === 'success' ? 'bg-green-50 text-green-800 dark:bg-green-950 dark:text-green-200' : 'bg-red-50 text-red-800 dark:bg-red-950 dark:text-red-200'">
            {{ flash.message }}
        </div>

        <p v-if="connections === null" class="text-sm text-gray-500">Loading…</p>

        <p v-else-if="connections.length === 0" class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 dark:border-gray-700">
            No network is switched on. Set LINKEDIN_ENABLED=true (or X_, FACEBOOK_, INSTAGRAM_, TIKTOK_) in .env.
        </p>

        <div class="grid gap-4 md:grid-cols-2">
            <section v-for="card in connections ?? []" :key="card.network"
                     class="space-y-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <h2 class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-gray-100">
                    <!-- The logo is SVG markup from your own config, not user input. -->
                    <span class="size-5 shrink-0" :class="{ 'dark:invert': card.brand.dark }" :style="{ color: card.brand.color }" v-html="card.brand.logo" />
                    {{ card.label }}
                </h2>

                <p v-if="card.hint" class="-mt-2 text-xs text-gray-500 dark:text-gray-400">{{ card.hint }}</p>

                <div v-if="card.connection.connected" class="flex flex-wrap items-center gap-4">
                    <img v-if="card.connection.picture" :src="card.connection.picture" alt="" class="size-12 rounded-full object-cover" referrerpolicy="no-referrer">
                    <span v-else aria-hidden="true" class="flex size-12 items-center justify-center rounded-full text-sm font-semibold text-white" :style="{ backgroundColor: card.brand.color }">
                        {{ (card.connection.name ?? card.label).slice(0, 1).toUpperCase() }}
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">{{ card.connection.name ?? `${card.label} account` }}</p>
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset" :class="tones[card.badge.tone]">{{ card.badge.label }}</span>
                        </div>
                        <p v-if="card.connection.expires_at" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Valid until {{ new Date(card.connection.expires_at).toLocaleDateString() }}
                        </p>
                    </div>

                    <div v-if="card.can_manage" class="flex gap-2">
                        <a :href="card.urls.connect" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">Reconnect</a>
                        <button type="button" :disabled="busy === card.network" @click="disconnect(card)"
                                class="rounded-lg border border-red-300 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 disabled:opacity-50 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-950">
                            Disconnect
                        </button>
                    </div>
                </div>

                <div v-else class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-gray-500 dark:text-gray-400">Connect a {{ card.label }} account to share your content.</p>
                    <!-- A plain link, not fetch(): the browser leaves for the network's login page. -->
                    <a v-if="card.can_manage" :href="card.urls.connect" class="rounded-lg px-4 py-2 text-sm font-semibold text-white" :style="{ backgroundColor: card.brand.color }">
                        Connect {{ card.label }}
                    </a>
                </div>
            </section>
        </div>
    </div>
</template>
