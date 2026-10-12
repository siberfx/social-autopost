<!--
  A "Share" button for one model, in Vue 3. Build its props on the server, where the URL is signed:
      'share' => SocialProps::share($post),               // every enabled, connected network
      'shareOnX' => SocialProps::share($post, 'x'),       // one network
  then: <ShareButton :share="share" />
-->
<script setup lang="ts">
import { ref } from 'vue';
import { type SocialShare, request } from './social';

const props = defineProps<{ share: SocialShare }>();

const busy = ref(false);
const message = ref<{ ok: boolean; text: string } | null>(null);

async function post() {
    if (!window.confirm(props.share.confirm)) return;

    busy.value = true;
    try {
        const { ok, body } = await request<{ message: string }>(props.share.url, 'POST');
        message.value = { ok, text: body.message };
    } catch {
        message.value = { ok: false, text: 'The share could not be sent. Try again.' };
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <span class="inline-flex flex-col gap-1">
        <button type="button" :title="share.title" :disabled="busy || share.disabled_reason !== null" @click="post"
                class="rounded-lg bg-gray-900 px-3 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white dark:text-gray-900">
            {{ busy ? 'Sharing…' : share.text }}
        </button>
        <small v-if="message" :class="message.ok ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400'">{{ message.text }}</small>
    </span>
</template>
